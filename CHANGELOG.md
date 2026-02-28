# Phuppi Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
