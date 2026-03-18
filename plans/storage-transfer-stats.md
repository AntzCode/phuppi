# Storage Transfer Statistics Plan

## Overview

This document outlines the implementation plan for recording egress and ingress statistics for each storage connector in the Phuppi application.

## Requirements Summary

- Record all file transfers to/from storage connectors
- Track at per-connector, per-file, and per-user/voucher granularity
- Accurate chunk-level tracking (not assume entire file was transferred)
- Separate SQLite database optimized for high-write volume
- Focus on recording stats only (reporting will come later)

## Scope of Integration

The following operations need to be instrumented:

### Ingress Operations (Data coming INTO storage)

| Operation | Location | Notes |
|-----------|----------|-------|
| File Upload (Local) | `FileController::uploadFile()` | Full file uploaded |
| File Upload (S3 presigned) | `FileController::registerUploadedFile()` | After S3 upload completes |
| Preview Image Generation | `PreviewGenerator::generate()` | Reads original, writes preview |
| Video Preview Generation | `VideoPreviewGenerator::generate()` | Reads original, writes MP4 + poster |
| Storage Migration (destination) | `StorageFactory::migrate()` | Writes to destination connector |

### Egress Operations (Data going OUT of storage)

| Operation | Location | Notes |
|-----------|----------|-------|
| File Download | `FileController::getFile()` | Chunked streaming |
| File Stream Inline | `FileController::streamInline()` | Chunked streaming |
| Batch Download (ZIP) | `FileController::downloadMultipleFiles()` | Reads files for ZIP |
| Preview Image Serving | `FileController::getPreview()` | Serves thumbnail |
| Video Preview Streaming | `FileController::getVideoPreview()` | Chunked streaming |
| Video Poster Serving | `FileController::getVideoPoster()` | Serves poster image |
| Storage Migration (source) | `StorageFactory::migrate()` | Reads from source connector |

## Architecture

### Database

- **File**: `data/transfers.sqlite` (separate from main database)
- **Optimization**: WAL mode, batch inserts, minimal indexing for write performance

### Schema

```sql
CREATE TABLE transfer_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    connector_name VARCHAR(255) NOT NULL,
    file_id INTEGER,
    user_id INTEGER,
    voucher_id INTEGER,
    direction VARCHAR(10) NOT NULL, -- 'ingress' or 'egress'
    operation_type VARCHAR(50) NOT NULL, -- 'upload', 'download', 'stream', 'preview_generate', 'preview_serve', 'migration'
    bytes_transferred INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Index for querying by connector (most common query)
CREATE INDEX idx_transfer_stats_connector ON transfer_stats(connector_name, created_at);
-- Index for querying by file
CREATE INDEX idx_transfer_stats_file ON transfer_stats(file_id, created_at);
```

### Operation Types

| Type | Direction | Description |
|------|-----------|-------------|
| `upload` | ingress | Direct file upload |
| `download` | egress | Full file download |
| `stream` | egress | Streaming playback (video/audio) |
| `preview_generate_ingress` | ingress | Reading original for preview generation |
| `preview_generate_egress` | egress | Writing generated preview |
| `preview_serve` | egress | Serving preview/thumbnail image |
| `migration_ingress` | ingress | Writing during storage migration |
| `migration_egress` | egress | Reading during storage migration |

## Implementation Details

### 1. New Database Connection

Add to `bootstrap.php`:
- Register new PDO for transfers database
- Configure with WAL mode, busy timeout
- Create database file if not exists

### 2. TransferStats Service

Create `src/Phuppi/Service/TransferStats.php`:
- `record(connectorName, fileId, userId, voucherId, direction, operationType, bytesTransferred)`
- Batch recording capability for high-volume scenarios
- Getter for current connector name from Flight

### 3. Instrumentation Points

#### FileController Changes

**uploadFile()**: After successful upload, record ingress with full file size

**registerUploadedFile()**: After file verified in S3, record ingress with filesize from request

**getFile()**: 
- Track bytes actually sent in streaming loop
- Record egress with actual bytes transferred (may be less than file size for partial downloads)

**streamInline()**: Same as getFile() - track actual bytes streamed

**downloadMultipleFiles()**: 
- Track each file read for ZIP creation as egress
- Total ZIP size as egress

**getPreview()**: Record egress for serving thumbnail

**getVideoPreview()**: Track bytes streamed for video

**getVideoPoster()**: Record egress for serving poster

#### PreviewGenerator Changes

**generate()**: 
- Track bytes read from original (ingress)
- Track bytes written to preview (egress)

#### VideoPreviewGenerator Changes

**generate()**: 
- Track bytes read from original (ingress)
- Track bytes written to MP4 (egress)
- Track bytes written to poster (egress)

#### StorageFactory Changes

**migrate()**:
- Track bytes read from source connector (egress from source)
- Track bytes written to destination connector (ingress to destination)

### 4. Tracking Actual Chunks

For streaming operations, the code already reads in chunks (8MB). The stats recording should happen inside the streaming loop to capture actual bytes transferred, not assumed file size.

Example pattern:
```php
$chunkSize = 8388608; // 8MB
$totalBytesTransferred = 0;
while (!$stream->eof() && $contentLength > 0) {
    $readSize = min($chunkSize, $contentLength);
    $chunk = $stream->read($readSize);
    // ... output chunk ...
    $totalBytesTransferred += strlen($chunk);
    $contentLength -= strlen($chunk);
}
// Record after streaming completes
TransferStats::record(
    connector: $connectorName,
    fileId: $file->id,
    userId: $file->user_id,
    voucherId: $file->voucher_id,
    direction: 'egress',
    operationType: 'stream',
    bytesTransferred: $totalBytesTransferred
);
```

## Migration Required

A new migration file `008_add_transfer_stats_table.php` will:
1. Create the transfers SQLite database file
2. Create the transfer_stats table
3. Set up indexes

## Future Considerations (Out of Scope)

- API for querying stats
- Admin UI for viewing reports
- Cleanup/archival of old stats
- Aggregation tables for faster reporting
