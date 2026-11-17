# P2P PHP Relay Fallback - Technical Plan

**Author:** Anthony Gallon
**Owner/Licensor:** AntzCode Ltd https://www.antzcode.com
**Date:** 2026-11-06
**Status:** Implemented in 2.9.0 (see the notes at the end for where the build differs from this plan)
**Replaces:** the TURN server plan and issue (`p2p-turn-server-plan.md`, `p2p-turn-server-issue.md`)

---

## 1. Problem

P2P transfers use PeerJS (WebRTC) with public STUN servers only. Direct connections fail on symmetric NAT,
carrier-grade NAT (mobile) and firewalls that block UDP, so sharing only reliably works on the same LAN.

A TURN relay is the standard fix, but Phuppi is meant to run on budget shared PHP hosting reached over FTP:
no daemons, no open UDP ports, no WebSockets. TURN cannot run there, and a hosted TURN service means a third-party
account for every installation.

## 2. Solution

When the WebRTC connection cannot be established, both browsers switch to a **store-and-forward relay implemented in
plain PHP over HTTPS**. We are only moving a file from A to B, so the relay is a small bounded message mailbox, not a
protocol implementation.

- The existing transfer protocol (`request-files`, `resume-request`, `file-start`, `file-chunk`, `file-complete`,
  `all-complete`) is unchanged. Only the transport underneath it changes.
- Chunks are encrypted in the browser, so the host never sees file content.
- The relay holds only a small window of unread data, so disk use stays at a few MB regardless of file size.

## 3. Design

### 3.1 Transport abstraction (client)

Both sides currently use a PeerJS `DataConnection` directly (`conn.send`, `conn.on('data' | 'open' | 'close' | 'error')`,
`conn.close`). Add `public/assets/p2p-transport.js` exporting:

- `connectWithFallback(...)`: tries WebRTC; on timeout (about 10 s) or ICE failure, returns a relay connection.
- `RelayConnection`: an object with the same surface as a PeerJS `DataConnection` (`send`, `on`, `close`, `open` event),
  so the page code that handles messages does not change.

The four pages (`p2p-sender.latte`, `p2p-receive.latte`, `p2p/receive/waiting.latte`, `p2p/receive/send.latte`) stop
carrying their own STUN list and `new Peer` boilerplate where practical and use the shared helper.

### 3.2 Relay endpoints (server)

A relay "channel" is one recipient connection under one share/receive session token. Each channel has two one-way
queues (`s2r` sender to receiver, `r2s` receiver to sender). Messages carry a monotonically increasing `seq`.

| Route | Purpose |
|-------|---------|
| `POST /api/p2p/relay/@token/@channel/@dir` | Append one framed batch. Rejected (`429`/`409`) while the unread window is full (backpressure). |
| `GET /api/p2p/relay/@token/@channel/@dir?after=<seq>&wait=<s>` | Return messages with `seq > after` (short wait of at most about 5 s when empty). |
| `POST /api/p2p/relay/@token/@channel/@dir/ack` | Receiver acknowledges `seq`; server deletes acknowledged data. |
| `DELETE /api/p2p/relay/@token/@channel` | Close the channel and delete everything. |
| `GET /api/p2p/relay/capabilities` | Max chunk size derived from `post_max_size` / `upload_max_filesize` / memory limit. |

Authorization reuses the existing session token and PIN verification of the P2P share and receive sessions. No new
credentials. A channel is created through the existing connect endpoints.

### 3.3 Framing and chunking

- The transfer protocol sends 16 KB chunks over the data channel. Over HTTP, batch them: one request carries up to
  about 512 KB (never more than the server-reported maximum), sent as `application/octet-stream` with a small header
  describing the contained messages. No base64, no multipart.
- JSON control messages (`file-start` etc.) are sent as their own small frames in order with the chunks.

### 3.4 Backpressure and limits

- Unread window per direction: 8 batches (about 4 MB). A sender that is ahead gets `429` and retries after a short delay.
- Per-session byte cap and rate limit (per IP and per token) so the relay is not an open file dump.
- Expiry: a channel with no activity for 10 minutes is deleted.

### 3.5 Storage and cleanup

- Batches are stored as individual files under `data/p2p-relay/<token>/<channel>/<dir>/<seq>.bin`. Metadata
  (`seq`, acked high-water mark, last activity) is kept in SQLite (new migration `016_add_p2p_relay_channels_table`).
- No cron on shared hosting: expired channels and orphaned files are garbage-collected opportunistically on relay
  requests (cheap check, at most once a minute).

### 3.6 Polling

Short polls (1 to 2 s idle interval, backing off when idle, immediately re-polling after data) plus an optional wait of
at most 5 s. Never long-poll for long: budget hosts have very few PHP workers.

### 3.7 End-to-end encryption

- The sender generates an AES-GCM key (WebCrypto) per session and puts it in the share URL **fragment** (`#k=...`).
  Fragments are never sent to the server. The key is also embedded in the QR code and short-link target.
- Each batch is encrypted with a fresh IV (sequence number as part of the IV) and authenticated.
- WebCrypto needs a secure context (HTTPS). Over plain HTTP the relay is disabled and the UI says why (the share
  target and the dev stack already require HTTPS).
- The URL fragment is also used for the 6-digit PIN flow unchanged. The PIN gates access, the fragment key gives
  confidentiality. Short links must preserve the fragment.

### 3.8 Fallback logic and UI

1. Both sides start WebRTC as today.
2. If the connection does not open within about 10 s, or ICE reports failure, the receiver starts the relay and the
   sender is told through the existing `connect` API (the sender polls the relay channel too).
3. The UI shows "Direct" or "Via relay" and warns that the relay is slower and counts toward the host's bandwidth.
4. Admin setting (Settings page) `p2p_relay_enabled` (default on) and `p2p_relay_max_bytes` (default 1 GB) per session.

## 4. Environments and limits to handle

| Concern | Handling |
|---------|----------|
| `post_max_size` as low as 2 MB | Capabilities endpoint reports the real max; client sizes batches below it |
| 30 s PHP time limit, few workers | Short polls, no long-poll, no streaming responses |
| Small disk quota | Bounded window, delete on ack, expiry GC |
| Host bandwidth caps | Documented; relay is only a fallback; max bytes per session |
| No cron | Opportunistic GC |
| No HTTPS | Relay disabled with an explanation (no E2E encryption possible) |

## 5. Optional later work

- Optional TURN URL and credentials setting for installations that have a TURN server.
- Replace the public PeerJS broker with PHP polling signaling to remove the last external dependency.

## 6. Implementation phases

### Phase 1: Relay core (server)
- [ ] Migration `016_add_p2p_relay_channels_table`
- [ ] `Phuppi\Service\P2PRelay` (channel create/append/read/ack/close, window and caps, GC)
- [ ] Relay endpoints in `P2PController` or a new `P2PRelayController`, routes in `routes.php`
- [ ] Capabilities endpoint

### Phase 2: Client transport
- [ ] `p2p-transport.js`: `RelayConnection` (framing, polling, backoff, encryption)
- [ ] `connectWithFallback`
- [ ] Move the STUN list into the shared helper

### Phase 3: Page integration
- [ ] Sender (`p2p-sender.latte`) and receiver (`p2p-receive.latte`)
- [ ] Reverse flow (`p2p/receive/waiting.latte`, `p2p/receive/send.latte`)
- [ ] Key in the URL fragment, QR code and short link, "via relay" indicator

### Phase 4: Tests and docs
- [ ] e2e: forced-fallback transfer (WebRTC disabled in the browser context), hash check of a multi-MB file
- [ ] e2e: backpressure and cleanup (channel files removed after completion)
- [ ] PHP unit-level checks for window and expiry logic if a harness exists
- [ ] Settings UI, README section, CHANGELOG entry

## 7. Risks

| Risk | Mitigation |
|------|------------|
| Host throttles or kills many short requests | Backoff, small request count per second, documented |
| Relay used as free file dump | Token-gated, per-session cap, expiry |
| Key lost in a redirect or short link | Fragment-preserving redirects, tested |
| Shared hosts lacking `random_bytes`/SQLite | Already required by Phuppi |

## 8. Implementation notes (differences from the plan above)

- **No separate ack or wait endpoints.** A reader acknowledges by asking for "everything after N"
  (`GET .../data?after=N`), which deletes batches up to N. There is no server-side wait; clients poll
  every 0.3 to 1.5 s.
- **Roles instead of sender/receiver.** The *host* is the logged-in session owner and the *guest* the visitor who
  verified the PIN, so one API serves both share and reverse-receive sessions
  (`/api/p2p/relay/@type/@token/...`, `type` is `share` or `receive`). The guest opens the channel, the host polls
  for new channels. Authorization is server-side (session owner, or a server-session PIN flag), not the PIN cookie.
- **Sequence numbers are chosen by the writer** (the server only accepts `last + 1`, so retries are safe).
- **The byte cap is per session**, summed over its remaining channels, so an automatic retry on a new channel does
  not get a fresh allowance.
- **Encryption:** a random 96-bit IV per batch, and the channel, direction and sequence number as authenticated data.
- **Settings:** `p2p_relay_enabled` and `p2p_relay_max_bytes` in the `settings` table, edited on Settings > P2P Relay.
- **SQLite:** read cursors must be closed before writing in the same request, otherwise a concurrent commit makes the
  write fail immediately with "database is locked" (the busy timeout does not apply to a stale read snapshot).
- **Known limitation:** after the relay fails (for example the size limit), the guest page keeps retrying every few
  seconds until the share expires; each retry fails at once.

## 9. Drop ("Leave files for later") and session expiry

Added in 2.9.0 after the relay itself.

- A **drop** is a relay channel flagged `is_drop` (migration 017). The host (sender) writes the same transfer
  messages (`file-start`, `file-chunk`, `file-complete`, `all-complete`) to its host-to-guest queue, but the queue has
  no window and reading never deletes batches. The guest reads it with `after=N` and decrypts in the browser, so the
  server only holds ciphertext. Only the host can write or delete a drop. The per-session byte cap still applies.
- Triggers: the sender's "Leave files for later" button, or a guest whose browser has no IndexedDB sending
  `request-drop` over a live connection (the sender answers `drop-ready` or `drop-unavailable`, in which case the guest
  keeps the files in memory). A guest that finds a drop with data when it opens the link uses it straight away; the
  10 second fallback also checks for a drop before opening a live relay channel.
- Files are kept until the session expires (not deleted after the first download), so recipients can resume or download
  again. The sender can delete them early.
- **Expiry:** share and receive sessions expire 2 hours after their last activity (previously 7 days after creation).
  `P2PRelay::touchSession` extends them on PIN checks, page loads, an open sender page (channel polling), stored or
  delivered data and drop requests. Idle polling by a guest does not extend them, so an abandoned tab cannot keep a drop
  alive.
- **GC** (opportunistic, at most once a minute): idle live channels (10 minutes), drops idle for 2 hours or whose session
  expired, was cancelled or is gone, and session rows (with connection history) 24 hours after expiry, so visitors still
  see "expired" for a while.
- Not done: adding more files to an existing session; the receive-session ("Receive Files") flow has no drop.

## 10. Buffering ahead and storage connectors

- The 8-batch window is gone. The relay now buffers up to `p2p_relay_max_bytes` of encrypted data per session
  (`bytes_stored`, written minus acknowledged; default 1 GB). A writer that finds the buffer full gets `429` and waits, so
  the sender runs at its own speed until the buffer fills and any file size still passes. The batch the reader needs next
  is always accepted, so concurrent uploads cannot fill the buffer with later batches and block it. A drop (which never
  frees space) answers `413` instead. The local disk keeps a 64 MB free-space reserve.
- `p2p_relay_batches` (migration 019) has a row per stored batch (size and backend). The server accepts batches up to
  `MAX_AHEAD` (8) sequence numbers ahead of the last contiguous one and advances `last` over the contiguous run, so the
  browser uploads 4 batches at once. The object is written before the database transaction, so a slow connector never
  holds the SQLite lock.
- Storage: with S3 as the active connector, batches are `p2p-relay/<channel>/<dir>-<seq>.bin` in the bucket; otherwise
  `data/p2p-relay`. Each batch row records its backend, so it is read and deleted from where it was written even if the
  active connector changes. Deleting acknowledged batches happens after the response (`fastcgi_finish_request` where
  available); cleanup removes at most 300 objects per run on S3 and sweeps unreferenced objects older than 15 minutes.
