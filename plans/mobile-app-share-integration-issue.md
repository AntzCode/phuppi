# Mobile App with Share-to-Phuppi Integration

## Summary

Add a cross-platform (Android and iOS) mobile app for Phuppi whose headline feature is **sharing straight into Phuppi from the phone**: take a photo, tap Share, choose Phuppi, and the file uploads. The same entry point works from the gallery, the Files app and any other app that can share files. The app also lets a user pick files from the device and upload them.

## Background

Today Phuppi is a web app. On a phone the user must open the site, log in, open the upload form and pick the file. Phones are where most photos and videos are created, so the quickest path from "I just took this" to "it is in Phuppi" should be one share-sheet tap.

Phuppi already has the server side of this:
- Uploads: `POST /files` (local storage) and the presigned-URL flow (`POST /files` followed by `POST /files/register`) for S3-compatible storage.
- Login with sessions and "Remember Me" persistent login.

It does not have a web app manifest, a service worker, or API tokens for non-browser clients.

## Feature Requirements

### Core Functionality
- Phuppi appears in the Android share sheet and the iOS share sheet for images, videos and other files (single and multiple).
- Choosing Phuppi uploads the shared files to the user's account, with visible progress and a clear success or failure result.
- A user can also open the app, pick files from the device (including the system photo picker) and upload them.
- The app stays logged in, so a share never stops at a login screen when a valid session exists.
- Shared files are not lost if the user has to log in first or the network drops mid-upload.
- Large files (multi-gigabyte videos) upload reliably, using the existing S3 presigned flow where the storage connector is S3.

### Later
- Background and resumable uploads.
- Optional automatic upload of new photos.
- Notifications when an upload finishes.

### User Flow
1. The user takes a photo or selects files in the gallery.
2. They tap Share and choose Phuppi.
3. Phuppi opens (or shows a small confirmation sheet), uploads the files and reports the result.
4. The files appear in the user's file list.

## Approach

Phased, so the server work and the Android share flow are proven before the larger app build:

1. **Web groundwork (PWA share target)**: an installable web app with a Web Share Target. Android only, but cheap, and it builds the receiving page and server pieces the app needs.
2. **Cross-platform app shell**: a Capacitor app (Android and iOS) that loads the live Phuppi site, so the existing UI is reused unchanged, and adds native share-in on both platforms. The first launch asks for the Phuppi server address, because Phuppi is self-hosted.
3. **Device tokens**: app login that does not rely on browser cookies.
4. **Background uploads and auto-upload.**

See [`mobile-app-share-integration-plan.md`](mobile-app-share-integration-plan.md) for the detailed plan, options considered and open decisions.

## Dependencies

- HTTPS for the Phuppi site (already the case in production).
- An Apple Developer Program membership for iOS builds and distribution.
- A decision on the app framework (the plan recommends Capacitor).
