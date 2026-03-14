# Data Migration Transfer Limit Feature

## Overview
Add a transfer limit feature to the data migration page to help users manage S3 free tier bandwidth limits. Users can set a GB limit and priority for file transfers.

## Use Case
S3 has a free tier with limited data transfer. Users may want to migrate X GB this month, then the remainder in the following month. This feature allows them to:
1. Set a transfer limit in GB
2. Choose a transfer priority
3. Monitor progress against the limit
4. Resume remaining transfers next month

## Requirements
1. **Transfer Limit Control**: Slider + text input to set data transfer limit in GB (0-100GB)
2. **Transfer Counting**: Count each initiated transfer (based on file size) toward the limit
3. **Skip Existing Files**: Files already in destination are skipped and NOT counted toward limit
4. **Transfer Priority**: Allow users to choose priority: smallest first, largest first, newest first, oldest first
5. **Auto-Cancel**: When limit is reached, cancel remaining transfers and report transferred amount
6. **Keep It Simple**: Don't try to find the next file that fits - just stop when limit is reached

## Implementation Details

### Priority Sorting Logic
- **smallest_first**: Sort by filesize ASC (default)
- **largest_first**: Sort by filesize DESC
- **newest_first**: Sort by uploaded_at DESC
- **oldest_first**: Sort by uploaded_at ASC

### Transfer Limit Logic
1. Get file list from server
2. Sort by selected priority
3. For each file:
   - Check if exists in destination (skip if exists - don't count toward limit)
   - If not exists, check if adding this file would exceed limit
   - If would exceed, STOP and report
   - If within limit, initiate transfer and add to processed size
4. Report final statistics

### Important: Skipped Files Do NOT Count Toward Limit
Files that already exist in the destination (with same size) are skipped and do NOT count toward the transfer limit. Only files that are actually transferred use the S3 bandwidth/storage quota.

## Files Modified

### 1. `src/Phuppi/Controllers/SettingsController.php`
- `migrateFiles()` method: Added `transfer_limit_gb` and `transfer_priority` parameters
- `getMigrationFiles()` method: Added `transfer_priority` parameter, returns `uploaded_at` field

### 2. `src/Phuppi/Storage/StorageFactory.php`
- `migrate()` method:
  - Added parameters: `transferLimitGb` (float) and `transferPriority` (string)
  - Converts GB to bytes: `$limitBytes = $transferLimitGb * 1024 * 1024 * 1024`
  - Implements priority-based sorting
  - Checks destination existence before attempting transfer
  - Stops when limit is reached
  - Returns limit status: `limit_reached`, `limit_bytes`, `limit_bytes_remaining`

### 3. `src/views/settings.latte`
- Transfer limit slider (0-100GB) with synchronized text input
- Priority dropdown selector (Smallest/Largest/Newest/Oldest First)
- Progress display showing transfer vs limit
- JavaScript for sorting, limit tracking, and progress updates

## UI Description

### Transfer Limit Control
- **Slider**: Range 0-100 GB
- **Text Input**: Allows precise entry (0-1000 GB)
- **No Limit Option**: Set to 0 or leave empty for unlimited

### Priority Options
- **Smallest First**: Transfer smallest files first (default)
- **Largest First**: Transfer largest files first
- **Newest First**: Transfer most recently uploaded files first
- **Oldest First**: Transfer oldest files first

### Progress Display
During migration, shows:
- Files processed (migrated/skipped/errors)
- Current transfer amount vs limit (e.g., "Limit: 2.5 GB / 5.0 GB")
- Current file being processed

### Final Report
Shows:
- Total processed
- Migrated count
- Skipped count (files already in destination)
- Error count
- Whether limit was reached

## API Parameters

### Migrate Files Action
```
POST /admin/settings/storage
action: migrate
from_connector: string
to_connector: string
file_ids: string (comma-separated IDs, or single ID)
transfer_limit_gb: float (optional, 0 = no limit)
transfer_priority: string (optional, default: smallest_first)
```

### Get Migration Files Action
```
POST /admin/settings/storage
action: get_migration_files
from_connector: string
to_connector: string
transfer_priority: string (optional)
```

## Response Format

### Migration Results
```json
{
  "results": {
    "migrated": 5,
    "skipped": 2,
    "errors": [],
    "total_size": 5368709120,
    "processed_size": 2684354560,
    "limit_reached": false,
    "limit_bytes": 5368709120,
    "limit_bytes_remaining": 2684354560,
    "current_file": "example.jpg",
    "current_size": 1048576,
    "eta": 120
  }
}
```

## Bug Fix History
- **2024-03-14**: Fixed issue where skipped files were incorrectly counted toward transfer limit. Only actually migrated files now count toward the limit, since skipped files don't use any bandwidth (they already exist in destination).
