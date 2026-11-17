# Phuppi end-to-end tests

Browser tests driven by [Playwright](https://playwright.dev). Everything here is
dev-only and self-contained: nothing in `tests/` is needed to run Phuppi, and
`./dev deploy` only ships `src/` and `public/`.

## What runs

`docker-compose.yml` starts an isolated stack on its own ports, so it can run
alongside the development stack:

| Service  | Port  | Purpose                                                  |
|----------|-------|----------------------------------------------------------|
| `app`    | 8089  | Phuppi with a throwaway database (tmpfs, wiped on `down`) |
| `peerjs` | 9089  | Local PeerJS signaling server                            |
| `garage` | 3909  | S3-compatible storage for the S3 specs (set up by `garage-init`) |

The tests point the app's PeerJS client at the local server and drop the public
STUN servers (see `support/receive.js`), so P2P transfers run without internet
access. The first run installs Phuppi with the `e2e-admin` account.

The S3 specs switch the active connector to the stack's Garage (`s3-dev`) and back.
Presigned upload URLs name `http://garage:3900`, so Chromium is started with
`--host-resolver-rules` to reach the published port (see `playwright.config.js`),
which means no hosts-file entry is needed.

## Running

```bash
cd tests/e2e
npm install
npm run setup        # downloads Chromium for Playwright (once)
npm run stack:up     # build and start the test stack
npm test             # run all specs
npm run stack:down   # stop and wipe the test stack
```

Other useful commands:

```bash
npx playwright test specs/reverse-p2p.spec.js   # one spec file
npm run test:headed                             # watch the browsers
npm run report                                  # open the last HTML report
```

Ports, URLs and the admin login can be overridden with `E2E_APP_PORT`,
`E2E_PEERJS_PORT`, `E2E_APP_URL`, `E2E_PEERJS_HOST`, `E2E_ADMIN_USERNAME` and
`E2E_ADMIN_PASSWORD`.

## Layout

- `specs/` — test files
- `support/` — shared setup (install/login, PeerJS redirect, receive-flow helpers)

## Not covered

- Real network conditions: Playwright's network throttling does not apply to
  WebRTC data channels. Use `tc netem` on the Docker network for latency and
  packet loss.
- Real mobile devices: device emulation only changes viewport and user agent.
