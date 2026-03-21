# P2P File Sharing via QR Code and Browser

## Summary

Implement a peer-to-peer (P2P) file sharing feature that allows registered users to share files directly browser-to-browser via WebRTC, without uploading to Phuppi servers. Users select files, generate a QR code or shareable URL, and recipients download directly from the sender's device.

## Background

Currently, all file sharing in Phuppi requires uploading files to the server. This feature will enable direct P2P transfers where:
- Files never touch Phuppi servers
- Transfer happens directly between browsers via WebRTC
- Works over same WiFi network without internet
- Uses QR codes for easy connection establishment

## Feature Requirements

### Core Functionality

| Requirement | Description |
|-------------|-------------|
| File Selection | Users can select multiple files from their device |
| P2P Transfer | Files transfer directly browser-to-browser via WebRTC |
| No Server Upload | Files never touch Phuppi servers |
| Token System | Unique token for batch share stored in database |
| Shortcode URL | Shareable URL generated for the batch |
| QR Code | Display QR code on sender's screen for easy scanning |
| Download Page | Recipient sees download page similar to existing batch share |
| Individual Download | Recipients can download files individually |
| Bulk Download | Recipients can download all files as ZIP |
| WiFi Hotspot Support | Works over WiFi hotspot without internet consumption |

### User Flow

1. **Sender** selects files & chooses "P2P Share"
2. **Server** generates unique token & shortcode
3. **Sender** sees QR code & share URL
4. **Recipient** scans QR OR opens share URL
5. **Server** serves download page (metadata only, no files)
6. **Recipient** enters PIN (MITM protection)
7. **P2P Connection** established between browsers
8. **Files transferred** directly (P2P)

## Technical Architecture

### Connection Methods (in priority order)

| Priority | Method | Use Case | Complexity |
|----------|--------|----------|------------|
| 1 | **WebRTC with STUN (Default)** | Same WiFi network - automatic discovery | Low |
| 2 | WebRTC with PeerJS | Different networks via cloud signaling | Medium |
| 3 | Local Network TCP | WiFi Hotspot fallback | Low |

**Default Behavior:** When both devices are connected to the same WiFi router, WebRTC with STUN servers will automatically discover local ICE candidates and establish a direct P2P connection. No user configuration required.

### MITM Protection: 2-Digit PIN

To prevent man-in-the-middle attacks, a **2-digit PIN** is displayed on sender's screen and must be entered by recipient before transfer:

1. **Sender** generates random 2-digit PIN (e.g., "42")
2. **PIN displayed** on sender's screen (large, readable font)
3. **QR code** contains PeerJS connection ID (NOT the PIN)
4. **Recipient** scans QR, opens download page
5. **Recipient** must enter the PIN shown on sender's screen
6. **Transfer only begins** after PIN validation
7. If PIN incorrect 3 times, connection is rejected (5-minute lockout)

### Database Schema

```sql
CREATE TABLE p2p_shared_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    shortcode VARCHAR(12) NOT NULL UNIQUE,
    peerjs_id VARCHAR(64) NULL,
    pin VARCHAR(2) NOT NULL,
    pin_attempts TINYINT DEFAULT 0,
    pin_locked_at DATETIME NULL,
    files_metadata TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### API Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/api/p2p/create` | Create P2P share session | Required |
| GET | `/api/p2p/@token` | Get share metadata | Public |
| GET | `/p2p/@shortcode` | Render download page | Public |
| DELETE | `/api/p2p/@token` | Cancel/expire share | Required (owner) |
| POST | `/api/p2p/@token/verify-pin` | Verify recipient's PIN | Public |
| POST | `/api/p2p/@token/connect` | Register PeerJS connection | Public |

## Implementation Plan

### Phase 1: Database & Backend Foundation

- [ ] Create migration `012_add_p2p_shared_files_table.php`
- [ ] Create `P2PShareToken` model class
- [ ] Create `P2PController` with CRUD operations
- [ ] Add routes for P2P endpoints in `routes.php`
- [ ] Implement shortcode generation utility

### Phase 2: Sender Interface

- [ ] Add "P2P Share" button to file list UI
- [ ] Create file selection interface
- [ ] Implement token creation API call
- [ ] Create `p2p-share.latte` template
- [ ] Implement QR code generation
- [ ] Display shareable URL with copy button

### Phase 3: Recipient Interface

- [ ] Create `p2p-receive.latte` template
- [ ] Implement file list display (from metadata)
- [ ] Add individual file download buttons
- [ ] Add bulk ZIP download option
- [ ] Implement QR code scanner (camera access)

### Phase 4: P2P Transfer Engine

- [ ] Create `phuppi-p2p.js` core module
- [ ] Implement local network TCP server (sender)
- [ ] Implement local network client (recipient)
- [ ] Add WebRTC DataChannel fallback
- [ ] Implement file chunking for large files
- [ ] Add progress tracking UI
- [ ] Implement transfer verification (checksum)

### Phase 5: Polish & Testing

- [ ] Add transfer progress indicators
- [ ] Handle connection failures gracefully
- [ ] Add expiration cleanup job
- [ ] Cross-browser testing (Chrome, Firefox, Safari)
- [ ] Mobile device testing
- [ ] WiFi hotspot scenario testing

## Files to Create

- `src/migrations/012_add_p2p_shared_files_table.php` - Database migration
- `src/Phuppi/P2PShareToken.php` - Model class
- `src/Phuppi/Controllers/P2PController.php` - Main controller
- `src/views/p2p-share.latte` - Sender's share page
- `src/views/p2p-receive.latte` - Recipient's download page
- `public/assets/js/phuppi-p2p.js` - Core WebRTC/P2P logic

## Dependencies

| Library | Purpose | Source |
|---------|---------|--------|
| qrcode.js | QR code generation | Bundled |
| peerjs | WebRTC signaling & data channels | CDN |
| JSZip | ZIP file creation | Bundled |
| FileSaver.js | Save downloaded files | Bundled |
| qrcode-scanner | Camera-based QR scanning | Bundled |

## Benefits

| Benefit | Description |
|---------|-------------|
| **Zero Setup** | Same WiFi = works automatically |
| **No Internet Required** | Transfer over local network |
| **Privacy** | Files never touch Phuppi servers |
| **Fast** | Limited only by WiFi speed |
| **Secure** | PIN-based MITM protection |
| **Convenient** | QR code + URL sharing options |

## Performance Targets

| Metric | Target |
|--------|--------|
| QR code generation | < 100ms |
| Token creation API | < 200ms |
| Download page render | < 500ms |
| P2P connection establishment | < 3s (local network) |
| Transfer speed | Limited by WiFi speed (20-100 MB/s) |
| Max file size | < 500MB per file (device memory limit) |

## Browser Compatibility

| Browser | Minimum Version |
|---------|-----------------|
| Chrome | 80+ |
| Firefox | 75+ |
| Safari | 14+ |
| Edge | 80+ |
| Mobile Chrome | 80+ |
| Mobile Safari | 14+ |

**Note:** Requires HTTPS for camera access (QR scanning) and WebRTC.

## Future Enhancements (Post-MVP)

- Multiple recipients - Allow multiple devices to connect simultaneously
- Transfer resume - Resume interrupted transfers
- Compression - Optional LZ4 compression for faster transfer
- Folder support - Share entire folders as ZIP
- AirDrop-like mode - Auto-discover nearby devices via Bluetooth
