# Phuppi Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
