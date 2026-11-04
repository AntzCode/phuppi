# Mobile App with Share-to-Phuppi Integration - Implementation Plan

**Author:** Anthony Gallon  
**Contact:** https://github.com/AntzCode  
**Created:** 2026-11-04  
**Status:** Draft for Review

## 1. Executive Summary

Build a cross-platform mobile app (Android and iOS) so a user can share photos, videos and files into Phuppi from the system share sheet, and pick files from the device to upload.

The plan is phased. Phase 0 is a Progressive Web App share target: Android only, but it needs little work and proves the server-side pieces. The real cross-platform app follows, built with Capacitor around the existing Phuppi web UI plus native share receivers. The decision to build the cross-platform app has been made. **Decided:** the app is a Capacitor shell that loads the live Phuppi site (see section 4, Phase 1); the UI is not rewritten. The framework is still to be confirmed in the Phase 1 spike (section 10).

---

## 2. Current State

| Area | State |
|------|-------|
| Web app manifest / service worker | None |
| Authentication | Cookie sessions plus "Remember Me" tokens (`remember_tokens`). No API tokens. |
| Uploads (local storage) | `POST /files`, multipart, field `file` (one or many) |
| Uploads (S3 connectors) | `POST /files` returns presigned PUT URLs, the browser uploads straight to the bucket, then `POST /files/register` records the file |
| Upload size | PHP `upload_max_filesize` / `post_max_size` are 20G in the dev config |
| UI | Server-rendered Latte plus Preact, responsive (stacks on phones) |
| Existing "receive" feature | Reverse P2P receiving (`/p2p/receive`), unrelated to uploads to the account |

Consequences:
- The existing upload client (`filelist.latte`) already handles both storage flows with progress, so the app should reuse it rather than re-implement uploads.
- A non-browser client needs a way to authenticate without cookies (section 5).
- Presigned uploads from a different origin need CORS on the bucket (the dev Garage setup already sets it).

---

## 3. Framework Options

| Option | Pros | Cons |
|--------|------|------|
| **Capacitor (recommended)** | Reuses the existing web UI and upload client. One codebase for Android and iOS. Native plugins for share-in and background upload. Fast to start. | Share-in on iOS needs a native Share Extension (Swift) alongside the app. A thin web wrapper must offer real native value to pass App Store review; the share extension and background upload provide that. |
| Flutter | Fast native-feeling UI, one codebase. Share-in plugins exist for both platforms (for example `receive_sharing_intent`). | Phuppi's UI would be rebuilt in Dart. Needs a proper REST API with token auth first. |
| React Native | Reuses the team's JavaScript and Preact knowledge. Share-in libraries exist. | UI rebuilt in React Native. Needs the REST API with token auth first. |
| Two native apps (Kotlin and Swift) | Best platform integration and background behavior | Two codebases, most work |

Recommendation: Capacitor. Phuppi's UI already works on phones, so the app's value is the share-in and upload plumbing, not a new UI. Revisit if the app later needs a fully native UI.

Library names above are starting points; confirm current maintenance and iOS support during the Phase 1 spike before committing.

---

## 4. Phases

### Phase 0: PWA share target (Android quick win)

Goal: Phuppi appears in the Android share sheet for users who installed it to the home screen, and the receiving flow is proven.

1. Add `public/manifest.webmanifest` (name, icons, `display: standalone`, `start_url`) and link it from `layout.latte`.
2. Add a service worker that:
   - handles `POST /share-target` (declared in the manifest `share_target` with `enctype: multipart/form-data` and a `files` param accepting `image/*`, `video/*` and `*/*`),
   - stores the shared files in IndexedDB (or Cache Storage) so they survive a login redirect and large uploads,
   - redirects to `GET /share-target/receive`.
3. Add the receiving page `/share-target/receive`: shows the pending files, uploads them with the existing uploader (local and S3 flows, with progress), clears them on success, and offers retry on failure.
4. If the user is not logged in, keep the stored files, send them to `/login`, and return to the receiving page afterwards.

Notes: Web Share Target works on Chrome for Android once the PWA is installed; it is not available on iOS Safari. It needs HTTPS (localhost is exempt for development).

### Phase 1: Capacitor app shell and share-in

1. Create the Capacitor project (`mobile/` in the repository, or a sibling repository) targeting Android and iOS. **Decision:** the shell loads the live Phuppi site (Capacitor `server.url`), so the existing server-rendered pages and Preact code are used as they are and nothing in the UI is rewritten. Bundling a static copy of the UI that talks to a JSON API is not planned; it would mean rebuilding every server-rendered page and would only be worth it if offline use becomes a requirement.
2. **First-launch server screen:** Phuppi is self-hosted, so there is no single address. On first launch the app asks for the Phuppi URL, checks it (HTTPS, reachable, looks like Phuppi), stores it, and points the web view at it. Add a way to change the server later, and handle an unreachable server with a clear offline screen and retry.
3. **Android share-in:** register an intent filter for `ACTION_SEND` and `ACTION_SEND_MULTIPLE` (`image/*`, `video/*`, `*/*`). Hand the received file URIs to the web layer or the native uploader.
4. **iOS share-in:** add a Share Extension target. It copies the shared items into an App Group container and opens the app, which then picks them up. This needs an Apple Developer account, App Groups and a Swift extension.
5. Reuse the Phase 0 receiving page, so shared files go through the same upload code.
6. Add a native "upload from device" entry (the system photo picker and file picker) in the app.
7. Check the S3 presigned flow from the WebView origin (CORS), or use a native HTTP upload that bypasses it.

### Phase 2: Device tokens (login without cookies)

1. Add a device-token table and migration (hashed token, user, device name, created, last used, revoked).
2. Add token issue and revoke endpoints: log in once with username and password, receive a long-lived device token, store it in the platform secure storage (Keychain / Keystore).
3. Add token authentication for the upload endpoints (`POST /files`, `POST /files/register`, presigned URL requests) alongside cookie sessions.
4. Show devices in the user's profile so a lost phone's token can be revoked.
5. Rate limiting and expiry policy for tokens.

### Phase 3: Background uploads and auto-upload

1. Native background/resumable uploads (Android WorkManager, iOS background `URLSession`), so uploads continue when the app is closed or the connection drops. Large files use multipart S3 uploads or a resumable server protocol (design in this phase).
2. Upload-complete and failure notifications.
3. Optional auto-upload of new photos from a chosen album, with Wi-Fi-only and charging-only settings.

---

## 5. Server Changes (summary)

| Change | Phase |
|--------|-------|
| `manifest.webmanifest` and link in layout | 0 |
| Service worker served from the site root (scope `/`) | 0 |
| `GET /share-target/receive` page and its uploader | 0 |
| Device-token table, issue/revoke endpoints, token auth on upload endpoints | 2 |
| Devices list in the user profile | 2 |
| Resumable or multipart upload support | 3 |

The existing upload endpoints and the S3 presigned flow are reused rather than replaced.

---

## 6. Risks

| Risk | Mitigation |
|------|------------|
| App Store review rejecting a thin web wrapper | Ship real native features (share extension, background upload, secure token storage). Confirm Apple's current guidelines early. |
| iOS share extension complexity (App Group, memory limits in the extension) | Spike it first. The extension only copies files into the shared container and opens the app. |
| Large video uploads (memory, timeouts, network drops) | Stream from disk, use the S3 presigned or multipart flow, add resumable uploads in Phase 3. |
| PWA share target is Android-only | Treat Phase 0 as groundwork; the Capacitor app covers iOS. |
| Apple Developer Program cost and account setup | Needed before any iOS distribution; start early. |
| Android and iOS background upload limits differ | Design Phase 3 per platform; do not assume desktop behavior. |
| Web view loads a remote site, so the app does nothing offline and relies on the server being reachable | Clear offline screen with retry; keep shared files queued on the device until the upload succeeds. Offline support is out of scope for now. |
| Presigned PUT from an app origin blocked by CORS | Bucket CORS rules (already set for the dev stack) or native uploads. |

---

## 7. Testing

- Phase 0: Playwright (service worker and share target are testable with Chromium): POST a multipart share to `/share-target`, check the files land on the receiving page and upload to the file list, for both local and S3 (Garage) storage. Test the not-logged-in path.
- Phase 2: e2e tests for token issue, use and revoke, and for rejected requests without a valid token.
- Phase 1 and 3: manual device testing on a real Android phone and an iPhone (share sheet, large video, airplane-mode interruption), plus Appium or Maestro smoke tests if worthwhile.

---

## 8. Implementation Checklist

### Phase 0: PWA share target
- [ ] Web app manifest and icons, linked from the layout
- [ ] Service worker with a share-target handler and file storage
- [ ] `/share-target/receive` page reusing the uploader (local and S3 flows)
- [ ] Not-logged-in path (keep files, log in, resume)
- [ ] e2e tests (local and S3)
- [ ] Changelog entry

### Phase 1: Capacitor app
- [ ] Spike: confirm the share-in plugin or write native receivers (Android and iOS)
- [ ] Capacitor project (Android and iOS) loading the live site
- [ ] First-launch server URL screen, change-server option and offline/unreachable screen
- [ ] Android intent filters and hand-off
- [ ] iOS Share Extension and App Group hand-off
- [ ] Device file/photo picker entry
- [ ] CORS or native upload for S3

### Phase 2: Device tokens
- [ ] Migration, model, issue/revoke endpoints
- [ ] Token auth on upload endpoints
- [ ] Devices list in the profile
- [ ] e2e tests

### Phase 3: Background uploads
- [ ] Per-platform background upload
- [ ] Resumable/multipart design
- [ ] Notifications
- [ ] Optional auto-upload

---

## 9. Benefits

1. One-tap sharing from any app into Phuppi, on both platforms.
2. The existing web UI and upload code are reused.
3. Phase 0 gives Android users the feature quickly, before the app exists.
4. Device tokens make the app independent of browser cookies and revocable per device.

---

## 10. Open Decisions

1. Framework: Capacitor (recommended) vs Flutter vs React Native vs native.
2. ~~Shell loads the live Phuppi site, or bundled assets that call the server API.~~ **Decided:** it loads the live site (section 4, Phase 1).
3. Distribution: Play Store and App Store, or TestFlight and direct APK only at first.
4. Minimum Android and iOS versions.
5. Token model: one long-lived token per device, or short-lived tokens with refresh.
6. How far Phase 3 goes at launch (background uploads only, or auto-upload too).
7. Whether Phase 0 ships on its own or only as groundwork.
