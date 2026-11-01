# Dev S3 Storage Replacement and Zip Download Fixes - Implementation Plan

**Author:** Anthony Gallon  
**Contact:** https://github.com/AntzCode  
**Created:** 2026-10-28  
**Status:** Draft for Review

## 1. Summary

Two related pieces of work, to be done after the current reverse-P2P change set is tested and committed:

- **Part A:** Replace MinIO in the development Docker stack. The `minio/minio` and `minio/mc` images were removed from Docker Hub (September 2026) and can no longer be pulled from quay.io either. The services are currently disabled behind a `minio` compose profile.
- **Part B:** Fix "Zip & Download" (`FileController::downloadMultipleFiles()`), which hides storage failures and sends the zip twice.

Part A should come first: the dev database has all its files on the MinIO connector, so nothing (including the zip download) can be tested against real data until storage works again.

---

## 2. Current State

### 2.1 Dev storage

| Item | State |
|------|-------|
| `minio`, `minio-init` services | Disabled via `profiles: [minio]` in `docker-compose.yml` |
| `phuppi` → `depends_on: minio` | Commented out |
| Active storage connector (dev DB) | `minio-default` |
| Files on `minio-default` | 53 (all files in the dev DB) |
| Data volume `phuppi_minio_data` | Intact, 963 MB, in MinIO's on-disk format |
| Auto-created connector (`src/bootstrap.php`) | Endpoint hard-coded to `http://minio:9000`; ignores `MINIO_ENDPOINT` |
| S3 client addressing | Path-style for every non-DO endpoint (`S3Storage`, `use_path_style_endpoint`) |

Consequences while MinIO is off: file downloads, previews, thumbnails and zip downloads fail for all 53 files.

The data in `phuppi_minio_data` is stored in MinIO's own on-disk format, so only a MinIO server can read it. Another S3 server cannot use the volume directly. The files must be copied out through a running MinIO.

### 2.2 Zip & Download

`POST /files/download` → `FileController::downloadMultipleFiles()`:

1. **Silent skips.** Files that are missing in storage, or whose stream can't be read, are skipped with at most a log line. Some failures produce a partial zip with no warning; all failures produce an empty archive, which PHP never writes to disk, so the user sees the generic 500 "Zip file was not created successfully".
2. **Zip sent twice.** After the chunked `fread` loop streams the file, `readfile($zipPath)` sends it again. `Content-Length` is set, so browsers ignore the extra bytes, but they waste bandwidth and can break keep-alive connections.
3. **Errors not visible in logs.** `phuppi.log` only shows info-level lines in the dev stack, so the `error()` calls in this handler never appear. Check the logger's level configuration.
4. **Validation inconsistency.** Missing IDs return `Flight::halt(400, …)` (plain text) while other errors return JSON; the frontend expects JSON.

Note: every FileController read goes through the *active* connector (`Flight::storage()`), not each file's `storage_connector`. This is by design (the storage migration tool keeps files on the active connector) and is out of scope here.

---

## 3. Part A: Replace MinIO in the Dev Stack

### 3.1 Choice of server

| Option | Pros | Cons |
|--------|------|------|
| **Garage** (`dxflrs/garage:v2.4.1`) — recommended | Maintained, version can be pinned, small | Different setup: the layout, bucket and key are created with `garage` commands; key IDs are `GK…`-formatted; check how public-read bucket settings map |
| Chainguard MinIO (`cgr.dev/chainguard/minio`, `minio-client`) | Same server and config, can read the existing volume | Free tier is `latest` only (can't pin); images have no shell or `curl`, so the healthcheck and init script need rewriting |
| RustFS / SeaweedFS | S3-compatible | Younger (RustFS) or heavier to configure (SeaweedFS) |

Recommendation: **Garage for the long term, Chainguard MinIO once, to rescue the existing data** (step 3.2). Confirm image tags are still available when the work starts.

### 3.2 Rescue existing files (one-off)

1. Back up the volume first:
   ```bash
   docker run --rm -v phuppi_minio_data:/data -v "$PWD":/backup alpine \
     tar czf /backup/minio_data-backup.tgz -C /data .
   ```
2. Start a temporary MinIO from the Chainguard image against `phuppi_minio_data`, with the same credentials as `.env`.
3. In Phuppi **Settings → Storage**, use the existing storage migration tool to move all files from `minio-default` to `local-default`.
4. Verify file count, sizes and a few downloads, then set `local-default` as active and stop the temporary MinIO.

After this, files no longer depend on MinIO, and the new S3 server can start empty.

### 3.3 Add Garage to `docker-compose.yml`

- Replace the `minio` service with `garage` (pinned tag), with a `garage.toml` config file kept under `docker/garage/` and data in a named volume.
- Replace `minio-init` with a one-shot init service that:
  1. assigns the single-node layout and applies it,
  2. creates the bucket (`MINIO_BUCKETS`, default `phuppi-files`),
  3. imports or creates an access key and grants it read/write on the bucket.
- Keep the `minio` profile name for now, or rename it to `s3`, and update the comment block.
- Restore `depends_on` for the `phuppi` service.
- Add a healthcheck that works with the chosen image.

### 3.4 Configuration and connector

- Rename env vars to neutral names (`S3_DEV_ENDPOINT`, `S3_DEV_ACCESS_KEY`, …), or keep the `MINIO_*` names for compatibility; decide before implementing.
- Update `.env.example` with the new defaults (Garage key format).
- `src/bootstrap.php`: read the endpoint from the env var instead of hard-coding `http://minio:9000`; rename the auto-created connector (e.g. `s3-dev`) or update the existing `minio-default` row's config.
- Remove the `phuppi-files.minio` network alias if it's no longer needed. Path-style addressing doesn't use it.

### 3.5 Verify

- `docker compose up` from a clean state creates the bucket and key without manual steps.
- Settings → Storage → test connection on the dev S3 connector passes.
- Upload, download, preview, delete and storage migration (local ⇄ S3) all work.

---

## 4. Part B: Fix Zip & Download

### 4.1 Changes in `FileController::downloadMultipleFiles()`

1. Track failures per file (`missing in storage`, `stream unreadable`, `temp file failed`) instead of `continue`-ing silently.
2. If **no** file could be added, return `Flight::json(['error' => …, 'failed' => [names]], 502)` with a clear message (e.g. "None of the selected files could be read from storage").
3. If **some** files failed, choose one behavior (decide before implementing):
   - fail the whole request with the list of unreadable files (simplest, no surprise partial zips), or
   - include a `MISSING_FILES.txt` in the zip listing what was skipped.
4. Remove the duplicate `readfile($zipPath)` after the streaming loop.
5. Return JSON for the missing-IDs case (`Flight::json([...], 400)`) so the frontend can show it.
6. Wrap storage calls in `try/catch` so a connector exception (e.g. an unreachable S3 server) becomes the JSON error above rather than a fatal error.
7. Use `finally`-style cleanup so temp files and the zip are deleted on every path.

### 4.2 Frontend (`src/views/filelist.latte`, `case 'download'`)

- Show the server's `error` and the list of failed files in the alert or a modal.
- Add a loading state on the menu item while the zip is being built (large selections take time).

### 4.3 Logging

- Find out why `error()`-level lines don't appear in `data/logs/phuppi.log`, and fix the logger level/handler if needed.

---

## 5. Testing

Add to the e2e suite (`tests/e2e/`), which runs on local storage:

- **Zip happy path:** upload 2–3 files, select all, Zip & Download, verify file names and SHA-256 hashes inside the zip (reuse `downloadZipHashes()` from `support/receive.js`).
- **Missing file:** delete one file's object from storage (inside the app container), then zip it together with readable files; assert the chosen behavior (error listing the file, or `MISSING_FILES.txt`).
- **Response size:** assert the downloaded byte count equals `Content-Length`, to guard against the double-send coming back.

Optional: an e2e profile that runs the storage specs against the Garage S3 connector as well as local storage.

---

## 6. Implementation Checklist

### Part A: Dev storage
- [x] Back up `phuppi_minio_data`
- [x] Rescue the 53 files to `local-default` via a temporary Chainguard MinIO and the migration tool
- [x] Add pinned Garage service, config and init service to `docker-compose.yml`
- [x] Restore `depends_on`, add healthcheck
- [x] Decide on env var names; update `.env.example`
- [x] Make the bootstrap connector use the env endpoint
- [x] Verify upload/download/preview/migration against Garage

### Part B: Zip download
- [x] Per-file failure tracking and clear JSON errors
- [x] Decide on the partial-failure behavior
- [x] Remove duplicate `readfile()`
- [x] JSON error for missing IDs; `try/catch` around storage calls; reliable cleanup
- [x] Frontend error display and loading state
- [x] Fix error-level logging (no change needed: FileLogger has no level filter, error lines are written)
- [x] e2e tests: happy path, missing file, response size

## 7. Open Decisions

1. Garage vs Chainguard MinIO as the long-term dev S3 server.
2. Keep the `MINIO_*` env var names or rename them to neutral ones.
3. On partial failure: reject the whole zip, or include `MISSING_FILES.txt`.
