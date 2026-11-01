# Phuppi Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.7.0] - 2026-11-01
### Added
- Reverse P2P file receiving - a logged-in user creates a receive session and shares its URL or QR code; a guest (no account needed) opens the page, enters the PIN, picks files on their device and sends them straight to the owner over WebRTC. Files never touch the Phuppi server.
  - "Receive Files" link in the main navigation for logged-in users
  - 2-digit PIN to protect against man-in-the-middle; the receiver's peer ID is hidden until the PIN is verified, and the PIN locks after repeated wrong attempts
  - Received files can be downloaded individually or as one zip
  - Database migration 015 (`src/migrations/015_add_p2p_receive_sessions_table.php`) adds the `p2p_receive_sessions` table
  - [`P2PReceiveSession`](src/Phuppi/P2PReceiveSession.php) model and `P2PController` actions; routes under `/p2p/receive/...` and `/api/p2p/receive/...`
  - Plan and issue: [`plans/reverse-p2p-file-receiving-plan.md`](plans/reverse-p2p-file-receiving-plan.md), [`plans/reverse-p2p-file-receiving-issue.md`](plans/reverse-p2p-file-receiving-issue.md)
- Dry-run mode for storage migration - a "Dry run" checkbox on the Settings → Migration tab reports what would be migrated (missing source files, files already at the destination, transfer limit) without copying anything or recording transfer statistics. Preview and video-preview files are not included in the dry-run byte count.
- Shift+click range selection in the file list - after clicking a file's checkbox, shift+click another checkbox on the same page to select every file between them.
- End-to-end test suite (Playwright, dev-only, in `tests/e2e/`) running against an isolated Docker stack with a local PeerJS server. Covers P2P sharing, reverse P2P, navigation, zip downloads, file selection, migration dry run and S3 storage (uploads through presigned URLs, ranged downloads, zip) against a Garage container. See [`tests/e2e/README.md`](tests/e2e/README.md).

### Changed
- Development Docker stack: MinIO is replaced by [Garage](https://garagehq.deuxfleurs.fr/) (`dxflrs/garage:v2.4.1`) as the S3-compatible dev storage, because the `minio/minio` and `minio/mc` images were removed from Docker Hub. Setup is automatic through the `garage-init` service (`docker/garage/init.sh`).
  - Environment variables renamed from `MINIO_*` to `S3_DEV_*` (`S3_DEV_ENDPOINT`, `S3_DEV_ACCESS_KEY`, `S3_DEV_SECRET_KEY`, `S3_DEV_BUCKET`, `S3_DEV_PATH_PREFIX`). The access key must be `GK` + 24 hex characters and the secret 64 hex characters, so update your `.env` from `.env.example`.
  - The auto-created dev connector is now named `s3-dev` (was `minio-default`) and reads its endpoint from `S3_DEV_ENDPOINT` instead of a hard-coded address.
  - Browser uploads to the dev bucket need `127.0.0.1 garage` in your hosts file (see the README). The init script also sets CORS rules on the bucket.
- The file list's upload form is now hidden when the page loads. A blue "Upload Files" button at the top right slides it into view (and hides it again); the animation is skipped for users who prefer reduced motion.
- "Zip & Download" error handling - the whole download now fails with a JSON error listing the unreadable files instead of silently returning a partial or empty zip. The file list shows the failed file names and a "Building zip..." state.

- S3 storage no longer uses the bundled AWS SDK. A small built-in client ([`S3Service`](src/Phuppi/Storage/S3Service.php), AWS Signature V4 over PHP curl) now handles S3, Digital Ocean Spaces, MinIO and Garage. Existing connectors keep working unchanged; downloads stream on demand (including HTTP Range requests) without loading files into memory. **The PHP `curl` extension is required for S3 connectors** (PHP `SimpleXML` is used to read bucket listings).

### Removed
- The bundled AWS SDK (`src/aws/`, 394 files, about 6 MB). The install is that much smaller. `S3Storage::getS3Client()` is now `getClient()` and returns the `S3Service`.

### Fixed
- "Zip & Download" sent the zip file twice, wasting bandwidth; temporary files are now always cleaned up, including on error paths.
- "Zip & Download" returned plain text instead of JSON when no file IDs were given.
- Settings → Data Repair: the orphaned files scan no longer lists generated preview files (the `previews/` and `video-previews/` folders inside each user's folder), so they can't be imported or flagged by mistake.

## [2.6.1] - 2026-03-25
### Added
- Secure Storage Connector API Keys - AES-256-GCM encryption to protect S3/DO Spaces API keys
  - Master key system with two setup options: environment variable (`PHUPPI_STORAGE_KEY_MASTER_KEY`) or key file (`data/storage-key.key`)
  - Cryptographically secure 256-bit key generation via Settings UI
  - Automatic encryption of API keys when adding/updating storage connectors
  - On-the-fly decryption when connecting to storage services
  - Migration 014 (`src/migrations/014_encrypt_storage_keys.php`) adds encrypted key storage fields
  - [`EncryptionHelper`](src/Phuppi/Helper/EncryptionHelper.php) class with AES-256-GCM encryption/decryption using OpenSSL
  - Settings UI with "Secure Keys" tab for key generation, configuration status, and key file management
  - Security status indicators: green (secure mode active), yellow (no master key), red (S3/DO connectors without secure mode)
  - Documentation at [`public/docs/secure-storage-keys.md`](public/docs/secure-storage-keys.md)
  - For existing installations: automatic migration to encrypt existing plaintext keys when master key is configured

### Fixed
- Storage connector preview generation - fixed issue where preview generation failed after switching between storage connectors by using the correct connector for each file based on per-file storage connector tracking

## [2.6.0] - 2026-03-21
### Added
- P2P File Sharing via QR Code and Browser - new feature allowing direct file transfer between devices without uploading to the server
  - Select files from your device and share directly with another person via WebRTC P2P connection
  - QR code for easy connection establishment
  - 2-digit PIN for MITM (Man-in-the-Middle) protection
  - Sender creates share session with files, generates QR code and PIN
  - Recipient scans QR code, enters PIN, receives files directly over WebRTC
  - Uses PeerJS for WebRTC signaling
  - All dependencies downloaded and hosted locally (no CDNs)
  - Database migration: `p2p_shared_files` table for share tokens and metadata
  - Session management: view and manage active P2P sessions from the main P2P page
  - Multiple recipients per session: sender can share files with multiple recipients simultaneously
  - Connection tracking: database table `p2p_connections` tracks individual recipient connections with status (connected/disconnected/completed)
  - New API endpoints for session and connection management:
    - `GET /api/p2p` - List all active P2P sessions for current user
    - `GET /api/p2p/@token/connections` - Get all connections for a session (owner only)
    - `POST /api/p2p/@token/connections` - Register a new recipient connection
    - `DELETE /api/p2p/@token/connections/@id` - Mark connection as disconnected
  - [`P2PConnection`](src/Phuppi/P2PConnection.php) model with full CRUD operations and connection status tracking
  - TURN server support planning - future feature to enable P2P connections in restricted network environments (behind corporate firewalls, CGNAT, symmetric NAT). See [`plans/p2p-turn-server-plan.md`](plans/p2p-turn-server-plan.md) and [`plans/p2p-turn-server-issue.md`](plans/p2p-turn-server-issue.md) for technical specification.

### Fixed
- Re-transfer bug: fixed race condition that caused already-downloaded files to be re-transferred when recipient refreshed the page. Completed files now load before P2P connection initializes, and the system properly skips already-completed files during transfer.
- Resume-request optimization: filtered out already-completed files from resume requests to reduce unnecessary network traffic

## [2.5.1] - 2026-03-21
### Added
- Advanced options for file upload - collapsible section with textarea for entering file notes. Notes are saved to the database and visible only to the file owner.
- Per-file storage connector tracking - the system now records which storage connector (local, MinIO, S3, DO Spaces) was used when uploading each file. Preview generation now uses the correct connector for each file, fixing issues where preview generation failed after switching between storage connectors.
- "Regenerate Failed Previews" button in Preview Settings - allows requeuing files that failed to render previews for retry
- Skip unsupported file types from preview generation - audio files and other unsupported types are now automatically skipped during preview generation (marked as completed without attempting preview creation)

### Fixed
- Batch share modal file list now collapsible - on mobile devices with many selected files, the modal was unusable because the file list pushed the textarea and submit button off-screen. File list is now collapsed by default with toggle to show/hide, and has max-height with scroll.
- File notes are now private - notes are no longer displayed on batch shared pages. Notes were being exposed to anyone with the share link, but they should only be visible to the file owner.
- Notes list empty/500 error when importing notes from another server - added robust UTF-8 encoding detection and conversion in NoteController. Notes imported from databases with different character encoding (e.g., ISO-8859-1) now display correctly. Uses mb_check_encoding() to detect invalid UTF-8, then mb_convert_encoding() with ISO-8859-1 fallback, then iconv with //IGNORE as final fallback.

## [2.5.0] - 2026-03-21
### Added
- Batch share thumbnails in file list - users can now see preview thumbnails when accessing batch shares via valid token
- Preview modal for batch shares - clicking on a file thumbnail opens a modal showing image/video/PDF previews
- Video playback support in batch share modal - uses transcoded video preview if available, falls back to original video
- Token-based access fixes - resolved 403 errors when accessing previews via batch share tokens by skipping redundant permission checks in preview endpoints

### Changed
- Updated batch-share.latte to use Preact with htm-standalone for interactive file list with preview modal
- Modified FileController to pass file metadata as JSON to batch-share template
- Unified share endpoints: single file shares now reuse batch share view/functionality for consistent UX - both `/files/shared/@token` and `/files/batch/@token` now render the same batch-share.latte template

### Fixed
- Invalid share token error page now uses phuppi layout template - when accessing `/files/shared/{token}` with an invalid or expired token, users see a styled error page with the phuppi logo, navigation, and error message instead of a plain white screen with error message

## [2.2.3] - 2026-03-18
### Added
- Record file transfer statistics for all ingress/egress data from data storage connectors
- Display file transfer statistics on storage connector list: data in/out since Calendar Month started and past 7 days
- Async deletion of transfer stats when files are deleted - prevents orphaned records and improves performance for large tables
- [`deleteByFileId()`](src/Phuppi/Service/TransferStats.php) method in TransferStats service for batch deletion of stats by file ID
- [`DeleteTransferStatsJob`](src/Phuppi/Queue/DeleteTransferStatsJob.php) class and queue system for asynchronous processing
- Migration 009: `delete_transfer_stats_jobs` table for queue job management
- CLI queue worker now processes delete transfer stats jobs
- Error details modal for storage connector migration - when errors occur during migration, an info icon appears next to the error count. Clicking it shows a modal with file id, filename, filesize, and error message for each error encountered. The modal updates dynamically as errors occur.

### Fixed
- Database connection handling in TransferStats service - now uses `Flight::transferStatsDb()` consistently for all operations
- Transfer limit counting to include bytes transferred from source even when transfers fail - previously, when a transfer failed partway through, the bytes already read from source were not counted against the transfer limit, causing discrepancies between source egress stats and the displayed transfer limit usage.

## [2.2.2] - 2026-03-14
### Added
- Storage migration advanced features
- Transfer limit control (slider + text input, 0-100GB) for managing S3 free tier bandwidth
- Transfer priority options: smallest first, largest first, newest first, oldest first
- Real-time progress display showing transfer vs limit
- Auto-stop when transfer limit is reached
- Migration tool now skips existing files without counting toward limit

## [2.2.1] - 2026-03-12
### Added
- Digital Ocean Spaces support (S3-compatible cloud storage)
- Storage connector management UI
- Migration tool for moving files between storage connectors

## [2.1.0] - 2026-02-28
### Added
- Automatic preview thumbnails for images, videos, and documents
- Configurable background queue processing: CLI worker (`src/bin/queue-worker.php`) or AJAX for shared hosting
- PreviewGenerator service with image resizing, video frame extraction (FFmpeg), document rendering
- Database tables: `preview_jobs`, `queue_locks`; migration [`src/migrations/004_add_preview_fields.php`](src/migrations/004_add_preview_fields.php)
- Admin settings: queue mode, preview dimensions/quality/format, regenerate/clear all previews
- Tools: `src/bin/regenerate-previews.php`
- UI updates: preview display/status in filelist, AJAX queue trigger
- Docker support: FFmpeg, Imagick, Poppler

### Changed
- Updated README.md and plans/preview-image-generation.md
- Enhanced FileController, SettingsController, Storage integration

## [2.0.0] - 2026-02-XX
### Added
- Batch file sharing
- URL shortener with expiration
- User password change
- SQLite WAL mode and concurrency improvements
- Large file download fixes

## [1.0.4] - YYYY-MM-DD
### Added
- Create sharable link (screenshot v1.0.4)

## [1.0.3] - YYYY-MM-DD
### Added
- Voucher management (screenshot v1.0.3)

[Full commit history](https://github.com/AntzCode/phuppi/commits/main)

Author: Anthony Gallon, Owner/Licensor: AntzCode Ltd <https://www.antzcode.com>, Contact: https://github.com/AntzCode
