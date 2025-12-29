# Upgrade Plan – Re‑building **phuppi** on a lightweight PHP framework

## 1. Goal
Migrate the existing **phuppi** application to a minimal, FTP‑deployable codebase (≈ 15 files) without Composer, while preserving every existing feature.

## 2. Chosen Framework
**Flight** – a single‑file micro‑framework (≈ 2 KB). No Composer required, perfect for plain‑PHP deployment.

## 3. Minimal Folder Structure (≈ 15 files)

```
phuppi/
│
├─ public/                     # Document root (uploaded via FTP)
│   ├─ index.php               # Front‑controller – boots Flight, loads config
│   ├─ .htaccess               # Rewrite all requests to index.php
│   ├─ assets/                 # CSS, JS, images, icons, screenshots
│   └─ file.php                # Download endpoint (token validation)
│
├─ src/                        # Application code (≈ 10 files)
│   ├─ Flight.php              # Flight framework core
│   ├─ config.php              # Global configuration (paths, DB, S3)
│   ├─ db.php                  # PDO wrapper for SQLite
│   ├─ auth.php                # Session handling, login/logout helpers
│   ├─ permissions.php         # Permission matrix & helper functions
│   ├─ upload.php              # Local & S3 upload helpers (pre‑signed URL)
│   ├─ voucher.php             # Voucher generation & validation
│   ├─ notes.php               # Notes & tagging DAO
│   ├─ routes.php              # All Flight route definitions
│   └─ helpers.php             # Misc utilities (human‑readable bytes, etc.)
│
├─ data/                       # Writable directory (SQLite DB, uploaded files)
│   ├─ FUPPI_DB.sqlite3
│   └─ uploadedFiles/          # Subfolders per user
│
└─ README.md                   # Upgrade documentation (see below)
```

*All files are plain PHP; **no `vendor/` directory is required.**

## 4. Feature Mapping (old → new)

| Existing component | New location | Role |
|--------------------|--------------|------|
| `html/src/config.php` | `src/config.php` | Global config |
| `html/src/functions.php` | `src/helpers.php` | Generic helpers |
| `html/index.php` (UI) | `public/index.php` + `src/routes.php` | Routes render the same templates |
| AWS SDK classes | `src/upload.php` (lightweight signer) | Pre‑signed URL generation |
| DB models (`User`, `UploadedFile`, `Tag`) | `src/db.php` + DAO functions | Direct PDO queries |
| Session & cookie handling | `src/auth.php` | `session_start()`, persistent cookie |
| Voucher logic | `src/voucher.php` | CRUD for vouchers |
| Tagging UI | unchanged HTML + new AJAX endpoints in `src/routes.php` | Same UI, lighter backend |

## 5. Migration Steps (high‑level)
1. **Copy** `Flight.php` into `src/`.
2. **Create** `public/index.php` – start session, load `src/config.php`, require `src/Flight.php`, then include `src/routes.php`.
3. **Define routes** in `src/routes.php` (login, upload, file list, tag actions, voucher actions, sharing‑link generation).
4. **Port authentication** (`src/auth.php`) – `password_hash()` + session variables.
5. **Permission checks** (`src/permissions.php`).
6. **S3 pre‑signed URL** (`src/upload.php`) – native `hash_hmac('sha256', …)` (no AWS SDK).
7. **Voucher system** (`src/voucher.php`).
8. **Move** static assets to `public/assets/`.
9. **Add** `.htaccess` in `public/` to rewrite all requests to `index.php`.
10. **Test** locally with the existing Docker environment.
11. **Export** `data/` (SQLite DB + uploaded files) for production.
12. **Deploy via FTP** – upload the whole `phuppi/` directory (≈ 15 files).

## 6. Deployment Checklist (FTP)

| Item | Path | Notes |
|------|------|-------|
| `.htaccess` | `public/.htaccess` | Enables clean URLs |
| `index.php` | `public/index.php` | Front‑controller |
| `file.php` | `public/file.php` | Token‑based download |
| `assets/` | `public/assets/` | All static files |
| `Flight.php` | `src/Flight.php` | Framework core |
| `config.php` | `src/config.php` | Edit for production values |
| `db.php` | `src/db.php` | PDO wrapper |
| `auth.php` | `src/auth.php` | Session helpers |
| `permissions.php` | `src/permissions.php` | Permission matrix |
| `upload.php` | `src/upload.php` | S3 signer |
| `voucher.php` | `src/voucher.php` | Voucher logic |
| `notes.php` | `src/notes.php` | Notes & tags DAO |
| `routes.php` | `src/routes.php` | All route definitions |
| `helpers.php` | `src/helpers.php` | Misc utilities |
| `FUPPI_DB.sqlite3` | `data/FUPPI_DB.sqlite3` | Existing DB (copy) |
| `uploadedFiles/` | `data/uploadedFiles/` | Existing uploaded files (optional) |
| `README.md` | `README.md` | Documentation (see below) |

## 7. Documentation (README excerpt)

```markdown
# phuppi – lightweight PHP rebuild

## Prerequisites
- PHP 8+ with `pdo_sqlite` and `openssl` extensions enabled
- Write permission for `data/` (SQLite DB & uploaded files)
- (Optional) AWS credentials for S3 remote storage

## Installation
1. Upload the entire **phuppi** directory via FTP.
2. Edit `src/config.php`:
   - Set `base_url` to your domain.
   - Provide S3 credentials if you will use remote storage.
3. Ensure the `data/` folder is writable by the web server.
4. Visit `https://your‑domain.com/` – the installer will run on first launch.

## File layout
```
public/          → web‑accessible files
src/             → application source (≈ 10 files)
data/            → SQLite DB + uploaded files
```

## Updating
- Pull the latest version from the repository.
- Replace only the files under `src/` and `public/` (no DB changes required).

## FAQ
- **Do I need Composer?** No – the framework is a single PHP file.
- **Can I disable S3?** Yes – set `file_storage_type` to `server_filesystem` in `config.php`.
- **How are vouchers created?** Via the admin UI → *Vouchers* → *Create*.

**Note:** The official project name is **phuppi** (the earlier “fuppi” name was only a code‑name). All documentation, folder names, and code comments should use **phuppi** exclusively.
```

## 8. Next Steps (still pending)

| # | Item |
|---|------|
| 7 | Plan migration of authentication & permission system (sessions, password hashing) |
| 8 | Plan file upload handling (local + S3 pre‑signed URLs) |
| 9 | Design voucher system implementation |
|10 | Design notes & tagging storage (SQLite) |
|11 | Create migration script for the SQLite DB |
|15 | Review the plan with the stakeholder |

---

**Result:** The plan now explicitly uses **phuppi** everywhere, outlines the lightweight Flight‑based architecture, and provides a clear, actionable roadmap for the migration and FTP deployment.

