/**
 * p2p-transport.js
 *
 * Fallback transport for P2P transfers. When two browsers cannot open a direct WebRTC connection
 * (symmetric NAT, CGNAT, firewalls), the transfer runs through a small store-and-forward relay on
 * the Phuppi server instead. The relay only ever sees AES-GCM encrypted batches: the key lives in
 * the share link's URL fragment (#k=...), which browsers never send to a server.
 *
 * RelayConnection mimics the parts of a PeerJS DataConnection the P2P pages use
 * (send, close, open, peer and on('open' | 'data' | 'close' | 'error')), so the transfer protocol
 * code does not change.
 *
 * Roles: the "guest" opens a channel (the visitor who entered the PIN), the "host" is the logged-in
 * session owner who watches for new channels.
 */
(function (global) {
    'use strict';

    const encoder = new TextEncoder();
    const decoder = new TextDecoder();

    const IV_BYTES = 12;
    const TAG_BYTES = 16;
    const ENVELOPE_BYTES = 64;
    const POLL_IDLE_MIN_MS = 300;
    const POLL_IDLE_MAX_MS = 1500;
    const MAX_NETWORK_RETRIES = 8;
    const DEFAULT_FALLBACK_MS = 10000;
    const MAX_PARALLEL_POSTS = 4;

    // ---------------------------------------------------------------- keys

    function bytesToBase64Url(bytes) {
        let binary = '';
        for (let i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function base64UrlToBytes(text) {
        const padded = text.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((text.length + 3) % 4);
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }

    /** The relay needs WebCrypto, which browsers only offer on HTTPS (and localhost). */
    function isSupported() {
        return !!(global.crypto && global.crypto.subtle && global.isSecureContext);
    }

    /** Creates a new random key and returns it as URL-safe text. */
    function createKey() {
        return bytesToBase64Url(global.crypto.getRandomValues(new Uint8Array(32)));
    }

    /** Returns the key from the page's URL fragment (#k=...), or null. */
    function keyFromHash() {
        const match = /(?:^#|&)k=([A-Za-z0-9_-]{43})(?:&|$)/.exec(global.location.hash || '');
        return match ? match[1] : null;
    }

    /** The fragment to append to a share link so the other side can decrypt. */
    function keyFragment(key) {
        return key ? '#k=' + key : '';
    }

    function importKey(keyText) {
        return global.crypto.subtle.importKey('raw', base64UrlToBytes(keyText), 'AES-GCM', false, ['encrypt', 'decrypt']);
    }

    // ------------------------------------------------------------- framing

    function toUint8(data) {
        if (data instanceof Uint8Array) {
            return data;
        }
        if (data instanceof ArrayBuffer) {
            return new Uint8Array(data);
        }
        if (ArrayBuffer.isView(data)) {
            return new Uint8Array(data.buffer, data.byteOffset, data.byteLength);
        }
        return null;
    }

    /** Encodes one message as: u32 header length, JSON header, optional binary payload. */
    function encodeMessage(message) {
        const header = Object.assign({}, message);
        let payload = null;
        if (message.data !== undefined && toUint8(message.data)) {
            payload = toUint8(message.data);
            delete header.data;
            header.__payload = true;
        }
        const headerBytes = encoder.encode(JSON.stringify(header));
        const frame = new Uint8Array(4 + headerBytes.length + (payload ? payload.length : 0));
        new DataView(frame.buffer).setUint32(0, headerBytes.length);
        frame.set(headerBytes, 4);
        if (payload) {
            frame.set(payload, 4 + headerBytes.length);
        }
        return frame;
    }

    function decodeMessage(frame) {
        const headerLength = new DataView(frame.buffer, frame.byteOffset, frame.byteLength).getUint32(0);
        const header = JSON.parse(decoder.decode(frame.subarray(4, 4 + headerLength)));
        if (header.__payload) {
            delete header.__payload;
            // slice() copies, so consumers get an ArrayBuffer that holds only the payload
            header.data = frame.slice(4 + headerLength).buffer;
        }
        return header;
    }

    /** Packs frames as: (u32 length, frame)... */
    function packFrames(frames) {
        let total = 0;
        for (const frame of frames) {
            total += 4 + frame.length;
        }
        const out = new Uint8Array(total);
        const view = new DataView(out.buffer);
        let offset = 0;
        for (const frame of frames) {
            view.setUint32(offset, frame.length);
            out.set(frame, offset + 4);
            offset += 4 + frame.length;
        }
        return out;
    }

    function unpackFrames(bytes) {
        const frames = [];
        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        let offset = 0;
        while (offset + 4 <= bytes.length) {
            const length = view.getUint32(offset);
            frames.push(bytes.subarray(offset + 4, offset + 4 + length));
            offset += 4 + length;
        }
        return frames;
    }

    // -------------------------------------------------------------- helpers

    // A small in-page log (the "Connection details" box) so a stuck transfer can be diagnosed on a phone
    const logLines = [];

    function log(message) {
        const line = new Date().toTimeString().slice(0, 8) + ' ' + message;
        logLines.push(line);
        if (logLines.length > 80) {
            logLines.shift();
        }
        try {
            console.log('[P2P] ' + message);
            const el = global.document && global.document.getElementById('p2p-debug-log');
            if (el) {
                el.textContent = logLines.join('\n');
            }
        } catch (err) {
            // Logging must never break a transfer
        }
    }

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    let capabilitiesPromise = null;

    /** Fetches the relay's limits once per page. Resolves null when the relay is unavailable. */
    function capabilities() {
        if (!capabilitiesPromise) {
            capabilitiesPromise = fetch('/api/p2p/relay/capabilities', { cache: 'no-store' })
                .then((response) => (response.ok ? response.json() : null))
                .catch(() => null)
                .then((caps) => {
                    log('relay capabilities: ' + (caps ? 'enabled=' + caps.enabled + ', batch=' + caps.maxBatchBytes : 'unavailable'));
                    return caps;
                });
        }
        return capabilitiesPromise;
    }

    /** True when this page can use the relay (HTTPS, enabled by the admin). */
    async function isAvailable(key) {
        if (!isSupported() || !key) {
            log('relay not usable: ' + (!isSupported() ? 'page is not a secure context (HTTPS needed)' : 'the link has no #k= key'));
            return false;
        }
        const caps = await capabilities();
        return !!(caps && caps.enabled);
    }

    // ------------------------------------------------------ RelayConnection

    class RelayConnection {
        /**
         * @param {Object} options
         * @param {string} options.type Session type, "share" or "receive".
         * @param {string} options.token Session token.
         * @param {string} options.channel Channel ID.
         * @param {string} options.role "guest" or "host".
         * @param {CryptoKey} options.cryptoKey Decryption/encryption key.
         * @param {number} options.maxBatchBytes Largest encrypted batch the server accepts.
         * @param {string} options.peer Remote peer label (mirrors DataConnection.peer).
         */
        constructor(options) {
            this.isRelay = true;
            this.type = options.type;
            this.token = options.token;
            this.channel = options.channel;
            this.role = options.role;
            this.cryptoKey = options.cryptoKey;
            this.maxBatchBytes = options.maxBatchBytes;
            this.peer = options.peer;
            this.open = false;
            this.failed = false;

            // A drop keeps what the host wrote until the session expires: the host only writes
            // (and may leave), the guest only reads, and neither deletes the channel when closing.
            this.isDrop = !!options.drop;
            this._writeOnly = this.isDrop && this.role === 'host';
            this._readOnly = this.isDrop && this.role === 'guest';
            this._pollMaxMs = this.isDrop ? 4000 : POLL_IDLE_MAX_MS;

            this._listeners = {};
            this._outbox = [];
            this._outboxBytes = 0;
            this._sendSeq = options.startSeq || 0;
            this._recvSeq = 0;
            this._flushing = false;
            this._stopped = false;
            this._flushTimer = null;
            this._drainWaiters = [];

            this._writeDir = this.role === 'host' ? 'h2g' : 'g2h';
            this._readDir = this.role === 'host' ? 'g2h' : 'h2g';

            this._onPageHide = () => this.close();
            global.addEventListener('pagehide', this._onPageHide);
        }

        on(event, listener) {
            (this._listeners[event] = this._listeners[event] || []).push(listener);
            return this;
        }

        _emit(event, payload) {
            for (const listener of this._listeners[event] || []) {
                try {
                    listener(payload);
                } catch (err) {
                    console.error('[RELAY] listener error', err);
                }
            }
        }

        /** Starts the connection. Called after the owner registered its listeners. */
        start() {
            setTimeout(() => {
                if (this._stopped) {
                    return;
                }
                this.open = true;
                this._emit('open');
                if (!this._writeOnly) {
                    this._pollLoop();
                }
            }, 0);
        }

        /** Queues a message for the other side. Messages are batched into few HTTP requests. */
        send(message) {
            if (!this.open || this._readOnly) {
                return;
            }
            const frame = encodeMessage(message);
            this._outbox.push(frame);
            this._outboxBytes += frame.length + 4;
            if (!this._flushTimer && !this._flushing) {
                this._flushTimer = setTimeout(() => {
                    this._flushTimer = null;
                    this._flush();
                }, 0);
            }
        }

        /** Bytes queued but not yet accepted by the relay. */
        get bufferedBytes() {
            return this._outboxBytes;
        }

        /**
         * Resolves once the queue has drained below a threshold, so a fast sender cannot pile a
         * whole file into memory while the relay's small window is full.
         */
        waitForDrain(thresholdBytes) {
            const limit = typeof thresholdBytes === 'number' ? thresholdBytes : this.maxBatchBytes * 2;
            if (this._outboxBytes <= limit || !this.open) {
                return Promise.resolve();
            }
            return new Promise((resolve) => this._drainWaiters.push({ limit, resolve }));
        }

        _notifyDrain() {
            this._drainWaiters = this._drainWaiters.filter((waiter) => {
                if (this._outboxBytes <= waiter.limit || !this.open) {
                    waiter.resolve();
                    return false;
                }
                return true;
            });
        }

        close() {
            if (this._stopped) {
                return;
            }
            this._stopped = true;
            const wasOpen = this.open;
            this.open = false;
            global.removeEventListener('pagehide', this._onPageHide);
            this._notifyDrain();
            if (!this.isDrop) {
                try {
                    fetch(this._url(''), { method: 'DELETE', keepalive: true });
                } catch (err) {
                    // Best effort: the relay deletes idle channels itself
                }
            }
            if (wasOpen) {
                this._emit('close');
            }
        }

        /**
         * Waits until everything queued was stored by the server, then stops the connection without
         * deleting the channel (for a drop that must outlive the page).
         */
        async finish() {
            await this.waitForDrain(0);
            if (this._stopped) {
                return;
            }
            this._stopped = true;
            this.open = false;
            global.removeEventListener('pagehide', this._onPageHide);
            this._emit('close');
        }

        /** The other side closed (or the relay dropped the channel). */
        _remoteClosed() {
            if (this._stopped) {
                return;
            }
            this._stopped = true;
            this.open = false;
            global.removeEventListener('pagehide', this._onPageHide);
            this._notifyDrain();
            this._emit('close');
        }

        _fail(type, detail) {
            if (this._stopped) {
                return;
            }
            console.error('[RELAY] ' + type, detail || '');
            log('relay failed: ' + type + (detail && detail.message ? ' (' + detail.message + ')' : ''));
            this.failed = true;
            this._emit('error', { type: type });
            this.close();
        }

        _url(suffix, params) {
            const query = new URLSearchParams(Object.assign({ role: this.role }, params || {}));
            return '/api/p2p/relay/' + this.type + '/' + this.token + '/' + this.channel + suffix + '?' + query.toString();
        }

        _aad(dir, seq) {
            return encoder.encode(this.channel + '|' + dir + '|' + seq);
        }

        async _encrypt(plain, seq) {
            const iv = global.crypto.getRandomValues(new Uint8Array(IV_BYTES));
            const cipher = new Uint8Array(await global.crypto.subtle.encrypt(
                { name: 'AES-GCM', iv: iv, additionalData: this._aad(this._writeDir, seq) },
                this.cryptoKey,
                plain
            ));
            const out = new Uint8Array(IV_BYTES + cipher.length);
            out.set(iv, 0);
            out.set(cipher, IV_BYTES);
            return out;
        }

        async _decrypt(buffer, seq) {
            const bytes = new Uint8Array(buffer);
            const plain = await global.crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: bytes.subarray(0, IV_BYTES), additionalData: this._aad(this._readDir, seq) },
                this.cryptoKey,
                bytes.subarray(IV_BYTES)
            );
            return new Uint8Array(plain);
        }

        async _flush() {
            if (this._flushing) {
                return;
            }
            this._flushing = true;
            try {
                const plainLimit = this.maxBatchBytes - IV_BYTES - TAG_BYTES - ENVELOPE_BYTES;
                // Several batches are in flight at once, so the request latency of the server (or of S3
                // behind it) does not cap a fast sender. The server accepts them slightly out of order.
                const inflight = new Set();

                while (this.open && (this._outbox.length || inflight.size)) {
                    while (this.open && this._outbox.length && inflight.size < MAX_PARALLEL_POSTS) {
                        const frames = [];
                        let size = 0;
                        while (this._outbox.length) {
                            const next = this._outbox[0];
                            if (frames.length && size + 4 + next.length > plainLimit) {
                                break;
                            }
                            frames.push(this._outbox.shift());
                            size += 4 + next.length;
                        }

                        const seq = ++this._sendSeq;
                        const task = (async () => {
                            try {
                                const body = await this._encrypt(packFrames(frames), seq);
                                if (await this._post(seq, body)) {
                                    this._outboxBytes -= size;
                                    this._notifyDrain();
                                }
                            } catch (err) {
                                this._fail('relay-send-failed', err);
                            }
                        })().finally(() => inflight.delete(task));
                        inflight.add(task);
                    }
                    if (inflight.size) {
                        await Promise.race(inflight);
                    }
                }
                await Promise.all(inflight);
            } catch (err) {
                this._fail('relay-send-failed', err);
            } finally {
                this._flushing = false;
                // Messages queued while the last batch was being posted
                if (this.open && this._outbox.length && !this._flushTimer) {
                    this._flushTimer = setTimeout(() => {
                        this._flushTimer = null;
                        this._flush();
                    }, 0);
                }
            }
        }

        /** Posts one batch, waiting while the relay's window is full. Returns false when it gave up. */
        async _post(seq, body) {
            let retryDelay = 250;
            let failures = 0;
            while (!this._stopped) {
                let response;
                try {
                    response = await fetch(this._url('/data', { seq: seq }), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/octet-stream' },
                        body: body,
                    });
                } catch (err) {
                    if (++failures > MAX_NETWORK_RETRIES) {
                        this._fail('relay-network-error', err);
                        return false;
                    }
                    await sleep(Math.min(5000, 500 * failures));
                    continue;
                }

                if (response.status === 204) {
                    return true;
                }
                if (response.status !== 429) {
                    log('relay POST ' + this._writeDir + ' #' + seq + ' -> HTTP ' + response.status);
                }
                if (response.status === 429) {
                    await sleep(retryDelay);
                    retryDelay = Math.min(1000, retryDelay * 1.5);
                    continue;
                }
                if (response.status === 410 || response.status === 404) {
                    this._remoteClosed();
                    return false;
                }
                if (response.status === 413) {
                    this._fail('relay-limit-reached');
                    return false;
                }
                this._fail('relay-rejected-' + response.status);
                return false;
            }
            return false;
        }

        async _pollLoop() {
            let idle = POLL_IDLE_MIN_MS;
            let failures = 0;
            while (!this._stopped) {
                let response;
                try {
                    response = await fetch(this._url('/data', { after: this._recvSeq }), { cache: 'no-store' });
                } catch (err) {
                    if (++failures > MAX_NETWORK_RETRIES) {
                        this._fail('relay-network-error', err);
                        return;
                    }
                    await sleep(Math.min(5000, 500 * failures));
                    continue;
                }
                failures = 0;

                if (response.status === 200) {
                    const seq = parseInt(response.headers.get('X-Relay-Seq'), 10);
                    try {
                        const plain = await this._decrypt(await response.arrayBuffer(), seq);
                        this._recvSeq = seq;
                        for (const frame of unpackFrames(plain)) {
                            this._emit('data', decodeMessage(frame));
                        }
                    } catch (err) {
                        // A wrong key or a tampered batch: stop rather than store garbage
                        this._fail('relay-decrypt-failed', err);
                        return;
                    }
                    idle = 0;
                    continue;
                }

                if (response.status === 204 && response.headers.get('X-Relay-State') !== 'closed') {
                    await sleep(idle || POLL_IDLE_MIN_MS);
                    idle = Math.min(this._pollMaxMs, Math.max(POLL_IDLE_MIN_MS, idle * 1.5));
                    continue;
                }

                log('relay poll ' + this._readDir + ' -> HTTP ' + response.status + ' (' + (response.headers.get('X-Relay-State') || 'no state') + '), closing');
                this._remoteClosed();
                return;
            }
        }
    }

    // --------------------------------------------------------- entry points

    /**
     * Guest side: opens a channel on the relay and returns a connection ready to be wired up.
     * Call connection.start() after registering the 'open' listener.
     */
    async function openGuestChannel(options) {
        const caps = await capabilities();
        if (!caps || !caps.enabled) {
            throw new Error('relay-unavailable');
        }
        const response = await fetch('/api/p2p/relay/' + options.type + '/' + options.token + '/channels', { method: 'POST' });
        log('relay channel create -> HTTP ' + response.status);
        if (!response.ok) {
            throw new Error('relay-channel-failed-' + response.status);
        }
        const body = await response.json();

        return new RelayConnection({
            type: options.type,
            token: options.token,
            channel: body.channel,
            role: 'guest',
            cryptoKey: await importKey(options.key),
            maxBatchBytes: caps.maxBatchBytes,
            peer: options.peer || 'relay',
        });
    }

    /**
     * Host side: polls for channels guests opened and hands each to onConnection as a connection
     * (already started after the callback returns).
     *
     * @returns {{stop: function}} Handle to stop watching.
     */
    function watchGuestChannels(options) {
        let after = 0;
        let stopped = false;
        let lastStatus = 0;
        const intervalMs = options.intervalMs || 2500;

        (async function loop() {
            const caps = await capabilities();
            if (!caps || !caps.enabled) {
                return;
            }
            const cryptoKey = await importKey(options.key);

            while (!stopped) {
                try {
                    const response = await fetch(
                        '/api/p2p/relay/' + options.type + '/' + options.token + '/channels?after=' + after,
                        { cache: 'no-store' }
                    );
                    if (response.ok) {
                        const body = await response.json();
                        for (const channel of body.channels) {
                            log('relay: recipient channel ' + channel.id + ' opened');
                            after = Math.max(after, channel.rid);
                            const connection = new RelayConnection({
                                type: options.type,
                                token: options.token,
                                channel: channel.id,
                                role: 'host',
                                cryptoKey: cryptoKey,
                                maxBatchBytes: caps.maxBatchBytes,
                                peer: 'relay-' + channel.id,
                            });
                            options.onConnection(connection);
                            connection.start();
                        }
                    } else {
                        if (lastStatus !== response.status) {
                            log('relay watch -> HTTP ' + response.status);
                            lastStatus = response.status;
                        }
                        if (response.status === 403 || response.status === 404 || response.status === 410) {
                            return;
                        }
                    }
                } catch (err) {
                    // Network blip: try again on the next tick
                }
                await sleep(intervalMs);
            }
        })();

        return { stop: () => { stopped = true; } };
    }

    /**
     * Host side: opens (creating it when needed) the session's drop for writing. Files sent through
     * the returned connection stay on the server, encrypted, until the session expires, so the
     * recipient can download later. Call connection.start() after registering listeners, send
     * messages, then await connection.finish() to stop once everything was stored.
     */
    async function openDropWriter(options) {
        const caps = await capabilities();
        if (!caps || !caps.enabled) {
            throw new Error('relay-unavailable');
        }
        const response = await fetch(
            '/api/p2p/relay/' + options.type + '/' + options.token + '/drop?role=host',
            { method: 'POST' }
        );
        if (!response.ok) {
            throw new Error('relay-drop-failed-' + response.status);
        }
        const drop = await response.json();

        return new RelayConnection({
            type: options.type,
            token: options.token,
            channel: drop.channel,
            role: 'host',
            drop: true,
            startSeq: drop.last,
            cryptoKey: await importKey(options.key),
            maxBatchBytes: caps.maxBatchBytes,
            peer: 'drop-' + drop.channel,
        });
    }

    /** Guest side: whether the session has a drop to download from. */
    async function hasDrop(options) {
        try {
            const response = await fetch(
                '/api/p2p/relay/' + options.type + '/' + options.token + '/drop?role=guest',
                { cache: 'no-store' }
            );
            if (!response.ok) {
                return false;
            }
            const drop = await response.json();
            return !!(drop.exists && drop.last > 0);
        } catch (err) {
            return false;
        }
    }

    /**
     * Guest side: opens the session's drop for reading, or resolves null when there is none.
     * The connection replays everything stored and then keeps polling for files added later.
     */
    async function openDropReader(options) {
        const caps = await capabilities();
        if (!caps || !caps.enabled) {
            return null;
        }
        const response = await fetch(
            '/api/p2p/relay/' + options.type + '/' + options.token + '/drop?role=guest',
            { cache: 'no-store' }
        );
        if (!response.ok) {
            return null;
        }
        const drop = await response.json();
        if (!drop.exists) {
            return null;
        }

        return new RelayConnection({
            type: options.type,
            token: options.token,
            channel: drop.channel,
            role: 'guest',
            drop: true,
            cryptoKey: await importKey(options.key),
            maxBatchBytes: caps.maxBatchBytes,
            peer: options.peer || 'drop',
        });
    }

    /** Host side: deletes the session's drop and everything stored in it. */
    async function deleteDrop(options) {
        const response = await fetch(
            '/api/p2p/relay/' + options.type + '/' + options.token + '/drop?role=host',
            { method: 'DELETE' }
        );
        return response.ok;
    }

    /**
     * Guest side: calls onFallback once if the direct connection has not opened in time or failed.
     *
     * @param {Object} conn PeerJS DataConnection that was just created.
     * @param {Function} onFallback Called with a reason string.
     * @returns {{cancel: function}} Handle to cancel the watch.
     */
    function watchDirectConnection(conn, onFallback) {
        let done = false;
        const timeoutMs = global.P2P_FALLBACK_MS || DEFAULT_FALLBACK_MS;
        const finish = (reason) => {
            if (done) {
                return;
            }
            done = true;
            clearTimeout(timer);
            if (reason) {
                onFallback(reason);
            }
        };
        const timer = setTimeout(() => finish('timeout'), timeoutMs);
        conn.on('open', () => finish(null));
        conn.on('error', () => finish('error'));
        return { cancel: () => finish(null) };
    }

    global.P2PTransport = {
        RelayConnection: RelayConnection,
        isSupported: isSupported,
        isAvailable: isAvailable,
        createKey: createKey,
        keyFromHash: keyFromHash,
        keyFragment: keyFragment,
        capabilities: capabilities,
        log: log,
        openGuestChannel: openGuestChannel,
        openDropWriter: openDropWriter,
        openDropReader: openDropReader,
        hasDrop: hasDrop,
        deleteDrop: deleteDrop,
        watchGuestChannels: watchGuestChannels,
        watchDirectConnection: watchDirectConnection,
    };
})(window);
