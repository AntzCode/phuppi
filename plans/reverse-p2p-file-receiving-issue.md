## Summary

Implement a "Reverse P2P" file receiving feature where a logged-in user (User A) can create a receive session and share a URL or QR code with an unregistered user (User B). User B can then select files from their device and send them directly to User A via P2P connection, without the files touching Phuppi servers.

## Background

Currently, the P2P file sharing feature allows a logged-in user to send files to another person. This feature implements the reverse scenario: a logged-in user wants to receive files from someone who doesn't have a Phuppi account.

Use cases:
- A teacher receiving homework files from students
- A doctor receiving medical files from patients
- A business owner receiving documents from clients
- Anyone needing to receive files from guests without requiring them to create an account

## Feature Requirements

### Core Functionality

| Requirement | Description |
|-------------|-------------|
| Receive Session | User A creates a P2P receive session |
| Share URL/QR | User A gets shareable URL or QR code |
| Guest Sender | User B (no login required) opens receive page |
| File Selection | User B selects files from their device |
| P2P Transfer | Files transfer directly browser-to-browser via WebRTC |
| No Server Upload | Files never touch Phuppi servers |
| Download to User A | User A receives downloaded files |
| Session Expiry | Receive sessions expire after configurable time |

### User Flow

1. **User A** (logged in) clicks "Receive Files" and creates a session
2. **Phuppi Server** generates unique token, shortcode, and PIN
3. **User A** sees QR code, share URL, and PIN displayed
4. **User A** shares URL or shows QR code to User B (external)
5. **User B** accesses receive URL or scans QR code
6. **User B** sees "Send Files" page, enters PIN
7. **User B** selects files to send
8. **P2P Connection** established between browsers
9. **Files transferred** directly (P2P) from User B to User A
10. **User A** downloads received files

## Technical Implementation

### Database Schema

New table `p2p_receive_sessions`:

```sql
CREATE TABLE p2p_receive_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    shortcode VARCHAR(12) NOT NULL UNIQUE,
    peerjs_id VARCHAR(64) NULL,
    pin VARCHAR(2) NOT NULL,
    pin_attempts TINYINT DEFAULT 0,
    pin_locked_at DATETIME NULL,
    files_metadata TEXT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### API Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/api/p2p/receive/create` | Create P2P receive session | Required |
| GET | `/api/p2p/receive/@token` | Get session metadata | Public |
| GET | `/p2p/receive/@shortcode` | Render receive page | Public |
| DELETE | `/api/p2p/receive/@token` | Cancel session | Required (owner) |
| POST | `/api/p2p/receive/@token/verify-pin` | Verify PIN | Public |
| POST | `/api/p2p/receive/@token/connect` | Register PeerJS | Public |
| POST | `/api/p2p/receive/@token/complete` | Complete & save metadata | Public |
| GET | `/api/p2p/receive` | List user's receive sessions | Required |

### Technical Notes

- Works in offline/local network mode only (same as existing P2P)
- Uses WebRTC with STUN for same-WiFi connections
- Uses PeerJS for different networks (same as existing P2P)
- 2-digit PIN required for MITM protection (same as existing P2P)
- No file size or quantity limits at this stage
- Files never touch Phuppi servers

### Files to Create

- `src/migrations/015_add_p2p_receive_sessions_table.php`
- `src/Phuppi/P2PReceiveSession.php`
- `src/views/p2p-receive-create.latte`
- `src/views/p2p-receive-waiting.latte`
- `src/views/p2p-send-guest.latte`

### Files to Modify

- `src/Phuppi/Controllers/P2PController.php` - Add receive session methods
- `src/routes.php` - Add routes for receive endpoints

### Components to Reuse

- QR code generation (`qrcode.js`)
- WebRTC/P2P engine (PeerJS)
- PIN verification logic
- PeerJS integration
- STUN server configuration
- IndexedDB chunk storage (`p2p-chunk-storage.js`)

## Implementation Plan

### Phase 1: Database & Backend Foundation

- [ ] Create migration `015_add_p2p_receive_sessions_table.php` (initially had incorrect number)
- [ ] Create `P2PReceiveSession` model class
- [ ] Add receive session methods to `P2PController`
- [ ] Add routes for P2P receive endpoints
- [ ] Implement shortcode generation utility
- [ ] Add list/delete functionality for User A's receive sessions

### Phase 2: Receiver Interface (User A)

- [ ] Add "Receive Files" button to file list UI
- [ ] Create session creation API call
- [ ] Create `p2p-receive-create.latte` template
- [ ] Implement QR code generation
- [ ] Display shareable URL with copy button
- [ ] Display PIN on receiver's screen
- [ ] Add "waiting for files" status UI
- [ ] Implement file download after transfer

### Phase 3: Sender Interface (User B - Guest)

- [ ] Create `p2p-send-guest.latte` template (note: was incorrectly listed as p2p-send.guest.latte)
- [ ] Implement PIN entry UI
- [ ] Create file selection interface
- [ ] Implement file list display with sizes
- [ ] Add "Send Files" button

### Phase 4: P2P Transfer Engine

- [ ] Extend `phuppi-p2p.js` for receive mode
- [ ] Implement file chunking for large files
- [ ] Add progress tracking UI
- [ ] Implement transfer verification
- [ ] Implement transfer resume for interrupted transfers
- [ ] Save metadata to database after transfer
- [ ] Implement download to receiver's device

### Phase 5: Polish & Testing

- [ ] Add transfer progress indicators
- [ ] Handle connection failures gracefully
- [ ] Add expiration cleanup job
- [ ] Cross-browser testing
- [ ] Mobile device testing

## Benefits

| Benefit | Description |
|---------|-------------|
| No guest account needed | Anyone can send files without logging in |
| Privacy | Files never touch Phuppi servers |
| No internet required | Works over local network |
| Fast | Limited only by WiFi speed |
| Secure | PIN-based MITM protection |
| Convenient | QR code + URL sharing options |

## Future Enhancements (Post-MVP)

- Transfer resume for interrupted transfers
- Multiple senders to same session
- Folder support for bulk file transfer

## Dependencies

| Library | Purpose | Source |
|---------|---------|--------|
| qrcode.js | QR code generation | Bundled |
| peerjs | WebRTC signaling | CDN |
| JSZip | ZIP file creation | Bundled |
| FileSaver.js | Save downloaded files | Bundled |
| qrcode-scanner | Camera QR scanning | Bundled |
