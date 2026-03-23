# P2P File Re-transfer Issue Analysis and Plan

## Problem Statement

When a recipient refreshes the page after entering the PIN (or after downloading some files), the system re-transfers files that have already been completed. This results in:
1. Duplicated files in the completed list
2. Wasted bandwidth and time
3. Poor user experience

## Root Cause Analysis

The issue stems from a race condition in the page load sequence:

```
1. Page loads (PIN not verified)
2. User enters PIN and submits
3. Page reloads with PIN verified
4. P2P connection initializes
5. loadCompletedFiles() loads completed files from IndexedDB (ASYNC)
6. P2P connection opens, sends request-files
7. Sender starts sending ALL files (including already completed ones)
8. Recipient receives file-start messages for already completed files
9. Duplication occurs!
```

The `completedFileIds` Set is populated AFTER the P2P connection is established, but the file transfer happens before that async operation completes.

## Solution Plan

### Phase 1: Fix Race Condition (Critical)

**1.1 Move completed file loading earlier**
- Load completed files BEFORE initializing P2P connection
- Populate `completedFileIds` Set synchronously at page start
- This ensures the check works immediately when file-start messages arrive

**1.2 Skip already-completed files when receiving**
- In `file-start` handler, check if file is in `completedFileIds` Set
- If already completed, skip processing entirely (don't add to receivedFiles array)
- Also skip adding to the received list in the UI

### Phase 2: Optimize Resume Request (Important)

**2.1 Filter resume-request to exclude completed files**
- In resume-request, only include files that are NOT in completedFileIds
- This reduces unnecessary network traffic
- Sender receives accurate information about what to transfer

**2.2 Remove files from received list when completed**
- Already implemented in previous changes
- Remove individual items from received list when file completes
- Hide the received list container when all files are complete

### Phase 3: Edge Case Handling

**3.1 Handle "download all" after refresh**
- After refresh, completed files should already be in the list
- "Download All as ZIP" should work immediately
- After downloading, clean up IndexedDB properly

## Implementation Steps

### Step 1: Modify `loadCompletedFiles()` call location
- Move the call to `loadCompletedFiles()` to happen BEFORE `initializeP2PReceive()`
- Make it populate `completedFileIds` Set synchronously if possible

### Step 2: Ensure file-start check works
- Verify the `completedFileIds.has(fileId)` check is in place
- Add logic to skip adding to receivedFiles when file is already completed

### Step 3: Update resume-request logic
- Filter the files to only include non-completed files in resume-request

## Expected Outcome

After implementation:
1. When page refreshes with PIN verified, completed files load immediately
2. No re-transfer of already-downloaded files occurs
3. Received list properly removes items as files complete
4. Received list container hides when all transfers complete

## Files to Modify

- `src/views/p2p-receive.latte` - Main receiver logic
