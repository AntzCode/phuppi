<?php

/**
 * P2PRelay.php
 *
 * Store-and-forward relay used when a direct WebRTC connection between two browsers cannot be
 * established. A channel has two one-way queues of opaque (browser-encrypted) batches, each
 * numbered by the writer. The reader acknowledges by asking for "everything after N", which
 * lets the server delete batches as soon as they are consumed, so disk use stays at a few batches.
 *
 * Runs on plain PHP and SQLite: no daemons, no WebSockets, no cron (cleanup runs opportunistically).
 *
 * @package Phuppi\Service
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.9.0
 */

namespace Phuppi\Service;

use Flight;

class P2PRelay
{
    /** Directions: guest to host and host to guest. */
    public const DIR_G2H = 'g2h';
    public const DIR_H2G = 'h2g';

    /** How far ahead of the last contiguous batch a writer may be (concurrent uploads). */
    public const MAX_AHEAD = 8;

    /** Objects deleted per cleanup run on a storage connector (each is a request), so no request runs long. */
    private const S3_DELETE_BUDGET = 300;

    /** Seconds an unreferenced object on the storage connector must be old before cleanup removes it. */
    private const ORPHAN_AGE = 900;

    /** Free disk space that is always left alone when buffering (bytes). */
    public const DISK_RESERVE_BYTES = 67108864;

    /** Largest batch the relay will ever accept, whatever PHP allows. */
    public const MAX_BATCH_BYTES = 1048576;

    /** Smallest batch size the client is told to use. */
    public const MIN_BATCH_BYTES = 65536;

    /** Open channels allowed per session. */
    public const MAX_CHANNELS_PER_SESSION = 10;

    /** Default cap on encrypted bytes held on the server at once per session. */
    public const DEFAULT_MAX_BYTES = 1073741824;

    /** Seconds without activity after which a channel is deleted. */
    public const IDLE_TTL = 600;

    /** Seconds a closed channel is kept so the other side can see it closed. */
    public const CLOSED_TTL = 120;

    /**
     * Seconds without activity after which a share or receive session expires. Any server activity
     * (PIN check, relay traffic, adding files to a drop, downloading) extends it. A drop and its files
     * are deleted with the session.
     */
    public const SESSION_IDLE_TTL = 7200;

    /** Seconds an expired session row is kept, so visitors see "expired" rather than "not found". */
    public const EXPIRED_ROW_TTL = 86400;

    /** Seconds between opportunistic cleanup runs. */
    private const GC_INTERVAL = 60;

    public const RESULT_OK = 'ok';
    public const RESULT_FULL = 'full';
    public const RESULT_ORDER = 'order';
    public const RESULT_CAP = 'cap';
    public const RESULT_CLOSED = 'closed';
    public const RESULT_MISSING = 'missing';
    public const RESULT_TOO_LARGE = 'too_large';

    /**
     * Whether the relay is enabled by the administrator (default on).
     */
    public static function isEnabled(): bool
    {
        $value = self::getSetting('p2p_relay_enabled');
        return $value === null || $value === '' || $value === '1';
    }

    /**
     * Maximum encrypted bytes the server holds at once for one share or receive session. A live relay
     * buffers up to this much ahead of a slower reader (the writer waits when it is full, so any size
     * of file can pass through); a drop, which is kept until the session expires, cannot exceed it.
     */
    public static function maxBytes(): int
    {
        $value = self::getSetting('p2p_relay_max_bytes');
        return ($value !== null && ctype_digit((string) $value) && (int) $value > 0)
            ? (int) $value
            : self::DEFAULT_MAX_BYTES;
    }

    /**
     * Largest request body (encrypted batch) the client should send, derived from PHP's limits.
     */
    public static function maxBatchBytes(): int
    {
        $limits = array_filter([
            self::parseIniBytes(ini_get('post_max_size')),
            self::parseIniBytes(ini_get('upload_max_filesize')),
        ]);
        $limit = $limits ? min($limits) : self::MAX_BATCH_BYTES;
        // Leave headroom for the request envelope
        $usable = (int) floor($limit * 0.9) - 1024;

        return max(self::MIN_BATCH_BYTES, min(self::MAX_BATCH_BYTES, $usable));
    }

    /**
     * Creates a channel for a session and returns its ID.
     *
     * @return string|null The channel ID, or null when the session has too many open channels.
     */
    public static function createChannel(string $type, string $token): ?string
    {
        self::gc();
        $db = Flight::db();

        $stmt = $db->prepare('SELECT COUNT(*) FROM p2p_relay_channels WHERE session_type = ? AND session_token = ? AND closed = 0 AND is_drop = 0');
        $stmt->execute([$type, $token]);
        $open = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($open >= self::MAX_CHANNELS_PER_SESSION) {
            return null;
        }

        $id = bin2hex(random_bytes(12));
        $now = time();
        $stmt = $db->prepare('INSERT INTO p2p_relay_channels (id, session_type, session_token, created_at, last_activity) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$id, $type, $token, $now, $now]);

        return $id;
    }

    /**
     * Lists open channels of a session created after the given row ID.
     *
     * @return array<int, array{rid: int, id: string}>
     */
    public static function listChannels(string $type, string $token, int $afterRid): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT rid, id FROM p2p_relay_channels WHERE session_type = ? AND session_token = ? AND closed = 0 AND is_drop = 0 AND rid > ? ORDER BY rid ASC LIMIT 50');
        $stmt->execute([$type, $token, $afterRid]);

        return array_map(fn ($row) => ['rid' => (int) $row['rid'], 'id' => $row['id']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Returns the session's drop channel, creating it when $create is set and none exists.
     * A drop keeps everything the host writes until the session expires, so a guest can download
     * later (once or repeatedly) while the sender is offline.
     *
     * @return array|null The channel row, or null when there is none (and $create is false).
     */
    public static function findOrCreateDrop(string $type, string $token, bool $create): ?array
    {
        self::gc();
        $db = Flight::db();

        $stmt = $db->prepare('SELECT * FROM p2p_relay_channels WHERE session_type = ? AND session_token = ? AND is_drop = 1 AND closed = 0 ORDER BY rid DESC LIMIT 1');
        $stmt->execute([$type, $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row || !$create) {
            return $row ?: null;
        }

        $id = bin2hex(random_bytes(12));
        $now = time();
        $db->prepare('INSERT INTO p2p_relay_channels (id, session_type, session_token, created_at, last_activity, is_drop) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$id, $type, $token, $now, $now]);

        return self::findChannel($id, $type, $token);
    }

    /**
     * Extends a session's expiry to SESSION_IDLE_TTL from now. Sessions that already expired are
     * not revived. Skips the write when the expiry is already within a minute of the target.
     */
    public static function touchSession(string $type, string $token): void
    {
        $table = self::sessionTable($type);
        if ($table === null) {
            return;
        }
        $target = date('Y-m-d H:i:s', time() + self::SESSION_IDLE_TTL);
        $threshold = date('Y-m-d H:i:s', time() + self::SESSION_IDLE_TTL - 60);
        $now = date('Y-m-d H:i:s');

        $stmt = Flight::db()->prepare("UPDATE {$table} SET expires_at = ? WHERE token = ? AND is_active = 1 AND expires_at > ? AND expires_at < ?");
        $stmt->execute([$target, $token, $now, $threshold]);
    }

    private static function sessionTable(string $type): ?string
    {
        return ['share' => 'p2p_shared_files', 'receive' => 'p2p_receive_sessions'][$type] ?? null;
    }

    /**
     * Finds a channel, checking that it belongs to the session.
     */
    public static function findChannel(string $id, string $type, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            return null;
        }
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM p2p_relay_channels WHERE id = ? AND session_type = ? AND session_token = ?');
        $stmt->execute([$id, $type, $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row ?: null;
    }

    /**
     * Stores a batch of a direction.
     *
     * Batches may arrive up to MAX_AHEAD sequence numbers ahead of the last contiguous one (a browser
     * uploads several at once); the reader only ever sees the contiguous run. A repeated sequence
     * number (a retried request) is accepted without storing it twice.
     *
     * The object is written before the database transaction, so a slow storage connector (S3) never
     * holds the database lock.
     *
     * @return string One of the RESULT_* constants.
     */
    public static function append(string $channelId, string $dir, int $seq, string $body): string
    {
        if (!self::validDirection($dir)) {
            return self::RESULT_MISSING;
        }
        $length = strlen($body);
        if ($body === '' || $length > self::MAX_BATCH_BYTES) {
            return self::RESULT_TOO_LARGE;
        }

        $db = Flight::db();
        $row = self::loadChannel($db, $channelId);
        if (!$row) {
            return self::RESULT_MISSING;
        }
        if ((int) $row['closed'] === 1) {
            return self::RESULT_CLOSED;
        }

        $last = (int) $row[$dir . '_last'];
        if ($seq <= $last || self::batchExists($db, $channelId, $dir, $seq)) {
            return self::RESULT_OK;
        }
        if ($seq > $last + self::MAX_AHEAD) {
            return self::RESULT_ORDER;
        }
        $room = self::roomFor($db, $row, $length, $seq === $last + 1);
        if ($room !== self::RESULT_OK) {
            return $room;
        }

        $backend = self::currentBackend();
        self::writeObject($channelId, $dir, $seq, $body);

        $db->exec('BEGIN IMMEDIATE');
        try {
            $row = self::loadChannel($db, $channelId);
            if (!$row) {
                $result = self::RESULT_MISSING;
            } elseif ((int) $row['closed'] === 1) {
                $result = self::RESULT_CLOSED;
            } elseif (self::batchExists($db, $channelId, $dir, $seq)) {
                // A retry stored it first: the object is the same, keep it
                $db->exec('COMMIT');
                return self::RESULT_OK;
            } else {
                $result = self::roomFor($db, $row, $length, $seq === (int) $row[$dir . '_last'] + 1);
            }

            if ($result !== self::RESULT_OK) {
                $db->exec('ROLLBACK');
                self::removeObject($channelId, $dir, $seq, $backend);
                return $result;
            }

            $db->prepare('INSERT INTO p2p_relay_batches (channel_id, dir, seq, size, backend) VALUES (?, ?, ?, ?, ?)')
                ->execute([$channelId, $dir, $seq, $length, $backend]);

            // Advance the contiguous run the reader may consume
            $contiguous = (int) $row[$dir . '_last'];
            while (self::batchExists($db, $channelId, $dir, $contiguous + 1)) {
                $contiguous++;
            }
            $db->prepare("UPDATE p2p_relay_channels SET {$dir}_last = ?, bytes_total = bytes_total + ?, bytes_stored = bytes_stored + ?, last_activity = ? WHERE id = ?")
                ->execute([$contiguous, $length, $length, time(), $channelId]);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            self::removeObject($channelId, $dir, $seq, $backend);
            throw $e;
        }

        return self::RESULT_OK;
    }

    /**
     * Whether the session has room for another batch: RESULT_OK, RESULT_FULL (a live relay buffer is
     * full, the writer should wait) or RESULT_CAP (a drop cannot hold more).
     */
    private static function roomFor(\PDO $db, array $row, int $length, bool $isNext): string
    {
        $isDrop = (int) $row['is_drop'] === 1;
        $stored = self::sessionStored($db, $row);

        if ($isDrop && $stored + $length > self::maxBytes()) {
            // A drop is kept until the session expires, so it can never make room
            return self::RESULT_CAP;
        }
        if (!$isDrop && !$isNext && $stored > 0 && $stored + $length > self::maxBytes()) {
            // Buffer full: the writer waits until the reader has consumed some. The batch the reader needs
            // next is always accepted (otherwise concurrent uploads could fill the buffer with later batches
            // and block it for good), so the limit can be exceeded by at most a batch.
            return self::RESULT_FULL;
        }
        if (!self::hasDiskSpace($length)) {
            return $isDrop ? self::RESULT_CAP : self::RESULT_FULL;
        }

        return self::RESULT_OK;
    }

    private static function loadChannel(\PDO $db, string $channelId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM p2p_relay_channels WHERE id = ?');
        $stmt->execute([$channelId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        // Finish the read before writing: an open cursor keeps a read snapshot, and upgrading a stale
        // snapshot to a write fails at once with "database is locked" (the busy timeout does not apply)
        $stmt->closeCursor();

        return $row ?: null;
    }

    private static function batchExists(\PDO $db, string $channelId, string $dir, int $seq): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM p2p_relay_batches WHERE channel_id = ? AND dir = ? AND seq = ?');
        $stmt->execute([$channelId, $dir, $seq]);
        $exists = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $exists;
    }

    /**
     * Encrypted bytes currently held on disk by all channels of the row's session. Counting the whole
     * session (not one channel) stops an automatic retry on a fresh channel from getting a new allowance.
     */
    private static function sessionStored(\PDO $db, array $row): int
    {
        $stmt = $db->prepare('SELECT COALESCE(SUM(bytes_stored), 0) FROM p2p_relay_channels WHERE session_type = ? AND session_token = ?');
        $stmt->execute([$row['session_type'], $row['session_token']]);
        $total = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $total;
    }

    /**
     * Whether the disk can take another batch while keeping a reserve free, so buffering never fills it.
     * Unknown free space (the function failing) is treated as fine.
     */
    private static function hasDiskSpace(int $length): bool
    {
        if (self::s3() !== null) {
            return true;
        }
        $base = self::basePath();
        $free = self::quiet(fn () => disk_free_space(is_dir($base) ? $base : dirname($base)));

        return $free === false || $free > $length + self::DISK_RESERVE_BYTES;
    }

    /**
     * Returns the batch after $after in a direction and deletes everything up to $after (a drop keeps
     * everything until the session expires).
     *
     * @return array{state: string, seq?: int, body?: string} state is "data", "empty", "closed" or "missing".
     */
    public static function fetch(string $channelId, string $dir, int $after): array
    {
        if (!self::validDirection($dir)) {
            return ['state' => self::RESULT_MISSING];
        }

        $db = Flight::db();
        $row = self::loadChannel($db, $channelId);
        if (!$row) {
            return ['state' => self::RESULT_MISSING];
        }

        $last = (int) $row[$dir . '_last'];
        $acked = (int) $row[$dir . '_acked'];
        $after = max(0, $after);

        if ((int) $row['is_drop'] === 1) {
            // A drop is retained until the session expires, so reading never deletes anything
            $db->prepare('UPDATE p2p_relay_channels SET last_activity = ? WHERE id = ?')->execute([time(), $channelId]);
        } elseif ($after > $acked) {
            $ack = min($after, $last);
            $stmt = $db->prepare('SELECT seq, size, backend FROM p2p_relay_batches WHERE channel_id = ? AND dir = ? AND seq <= ?');
            $stmt->execute([$channelId, $dir, $ack]);
            $consumed = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $freed = array_sum(array_column($consumed, 'size'));
            $db->exec('BEGIN IMMEDIATE');
            try {
                $db->prepare('DELETE FROM p2p_relay_batches WHERE channel_id = ? AND dir = ? AND seq <= ?')->execute([$channelId, $dir, $ack]);
                $db->prepare("UPDATE p2p_relay_channels SET {$dir}_acked = ?, bytes_stored = MAX(0, bytes_stored - ?), last_activity = ? WHERE id = ?")
                    ->execute([$ack, $freed, time(), $channelId]);
                $db->exec('COMMIT');
            } catch (\Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }

            // Deleting objects (a request each on S3) happens after the response has been sent
            self::defer(function () use ($channelId, $dir, $consumed) {
                foreach ($consumed as $batch) {
                    self::removeObject($channelId, $dir, (int) $batch['seq'], $batch['backend']);
                }
            });
        } else {
            $db->prepare('UPDATE p2p_relay_channels SET last_activity = ? WHERE id = ?')->execute([time(), $channelId]);
        }

        $next = $after + 1;
        if ($next <= $last) {
            $info = $db->prepare('SELECT backend FROM p2p_relay_batches WHERE channel_id = ? AND dir = ? AND seq = ?');
            $info->execute([$channelId, $dir, $next]);
            $backend = $info->fetchColumn();
            $info->closeCursor();
            $body = $backend === false ? false : self::readObject($channelId, $dir, $next, $backend);
            if ($body !== false) {
                return ['state' => 'data', 'seq' => $next, 'body' => $body];
            }
        }

        return ['state' => ((int) $row['closed'] === 1) ? self::RESULT_CLOSED : 'empty'];
    }

    /**
     * Closes a channel and deletes its stored batches. The row stays briefly so the other side sees it.
     */
    public static function close(string $channelId): void
    {
        $db = Flight::db();
        $db->prepare('UPDATE p2p_relay_channels SET closed = 1, last_activity = ? WHERE id = ?')->execute([time(), $channelId]);
        // Many objects may need deleting: do it after the response, the cleanup finishes whatever is left
        self::defer(function () use ($channelId) {
            self::removeChannelData(Flight::db(), $channelId, self::deleteBudget());
        });
    }

    /**
     * Deletes idle and closed channels. Throttled, because shared hosting has no cron.
     */
    public static function gc(bool $force = false): void
    {
        $base = self::basePath();
        $marker = $base . DIRECTORY_SEPARATOR . '.gc';
        if (!$force && is_file($marker) && (time() - (int) self::quiet(fn () => filemtime($marker))) < self::GC_INTERVAL) {
            return;
        }
        if (!is_dir($base)) {
            self::quiet(fn () => mkdir($base, 0775, true));
        }
        self::quiet(fn () => touch($marker));

        $db = Flight::db();
        $now = time();
        $stmt = $db->prepare('SELECT id FROM p2p_relay_channels WHERE is_drop = 0 AND ((closed = 0 AND last_activity < ?) OR (closed = 1 AND last_activity < ?))');
        $stmt->execute([$now - self::IDLE_TTL, $now - self::CLOSED_TTL]);
        $expired = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $stmt->closeCursor();
        foreach ($expired as $id) {
            self::dropChannel($db, $id);
        }

        self::gcDrops($db, $now);
        self::gcSessions($db);
        self::gcOrphans($db, $base);
    }

    /**
     * Deletes a channel's data and then its row. On a storage connector the data is removed a few
     * hundred objects per run, so the row stays until nothing is left.
     */
    private static function dropChannel(\PDO $db, string $channelId): void
    {
        if (self::removeChannelData($db, $channelId, self::deleteBudget()) === 0) {
            $db->prepare('DELETE FROM p2p_relay_channels WHERE id = ?')->execute([$channelId]);
        }
    }

    private static function deleteBudget(): int
    {
        return self::s3() !== null ? self::S3_DELETE_BUDGET : PHP_INT_MAX;
    }

    /**
     * Deletes up to $budget stored batches of a channel (objects and rows).
     *
     * @return int Batches still left.
     */
    private static function removeChannelData(\PDO $db, string $channelId, int $budget): int
    {
        $stmt = $db->prepare('SELECT dir, seq, backend FROM p2p_relay_batches WHERE channel_id = ? LIMIT ' . (int) min($budget, 1000000));
        $stmt->execute([$channelId]);
        $batches = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        foreach ($batches as $batch) {
            self::removeObject($channelId, $batch['dir'], (int) $batch['seq'], $batch['backend']);
            $db->prepare('DELETE FROM p2p_relay_batches WHERE channel_id = ? AND dir = ? AND seq = ?')->execute([$channelId, $batch['dir'], $batch['seq']]);
        }

        $count = $db->prepare('SELECT COUNT(*) FROM p2p_relay_batches WHERE channel_id = ?');
        $count->execute([$channelId]);
        $remaining = (int) $count->fetchColumn();
        $count->closeCursor();

        if ($remaining === 0) {
            self::deleteFiles($channelId);
            $db->prepare('UPDATE p2p_relay_channels SET bytes_stored = 0 WHERE id = ?')->execute([$channelId]);
        }

        return $remaining;
    }

    /**
     * Removes data nothing refers to any more (for example after a crash): channel directories on disk
     * whose row is gone, and (on a storage connector) objects without a batch row. Objects are only
     * removed once old, because a batch is written just before its row.
     */
    private static function gcOrphans(\PDO $db, string $base): void
    {
        foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (preg_match('/^[a-f0-9]{24}$/', $id) && (time() - (int) self::quiet(fn () => filemtime($dir))) > self::IDLE_TTL) {
                $check = $db->prepare('SELECT COUNT(*) FROM p2p_relay_channels WHERE id = ?');
                $check->execute([$id]);
                $exists = (int) $check->fetchColumn();
                $check->closeCursor();
                if ($exists === 0) {
                    self::deleteFiles($id);
                }
            }
        }

        $s3 = self::s3();
        if ($s3 === null) {
            return;
        }

        $tokenFile = $base . DIRECTORY_SEPARATOR . '.s3-sweep';
        $token = is_file($tokenFile) ? trim((string) self::quiet(fn () => file_get_contents($tokenFile))) : '';
        $prefix = $s3->getRelativePath('p2p-relay/');
        try {
            $page = $s3->getClient()->listObjectsV2(['Prefix' => $prefix, 'MaxKeys' => 200, 'ContinuationToken' => $token ?: null]);
        } catch (\Throwable $e) {
            return;
        }
        foreach ($page['Contents'] as $object) {
            $relative = substr($object['Key'], strlen($prefix));
            if (!preg_match('~^([a-f0-9]{24})/(g2h|h2g)-(\d+)\.bin$~', $relative, $m)) {
                continue;
            }
            $old = empty($object['LastModified']) || (time() - strtotime($object['LastModified'])) > self::ORPHAN_AGE;
            if ($old && !self::batchExists($db, $m[1], $m[2], (int) $m[3])) {
                self::removeObject($m[1], $m[2], (int) $m[3], self::currentBackend());
            }
        }
        self::quiet(fn () => file_put_contents($tokenFile, (string) ($page['NextContinuationToken'] ?? '')));
    }

    /**
     * Deletes drops that sat idle past the session TTL, or whose session expired, was cancelled or
     * no longer exists. The drop is the only thing on the server that holds file data.
     */
    private static function gcDrops(\PDO $db, int $now): void
    {
        $stmt = $db->query('SELECT id, session_type, session_token, last_activity, closed FROM p2p_relay_channels WHERE is_drop = 1');
        $drops = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        foreach ($drops as $drop) {
            $remove = (int) $drop['closed'] === 1 || (int) $drop['last_activity'] < $now - self::SESSION_IDLE_TTL;

            if (!$remove) {
                $table = self::sessionTable($drop['session_type']);
                if ($table === null) {
                    $remove = true;
                } else {
                    $check = $db->prepare("SELECT is_active, expires_at FROM {$table} WHERE token = ?");
                    $check->execute([$drop['session_token']]);
                    $session = $check->fetch(\PDO::FETCH_ASSOC);
                    $check->closeCursor();
                    $remove = !$session || (int) $session['is_active'] !== 1
                        || ($session['expires_at'] && strtotime($session['expires_at']) < $now);
                }
            }

            if ($remove) {
                self::dropChannel($db, $drop['id']);
            }
        }
    }

    /**
     * Removes share and receive sessions that expired more than EXPIRED_ROW_TTL ago, together with
     * their connection history. Cancelled sessions go at the same time.
     */
    private static function gcSessions(\PDO $db): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::EXPIRED_ROW_TTL);

        $stmt = $db->prepare('SELECT id FROM p2p_shared_files WHERE expires_at < ?');
        $stmt->execute([$cutoff]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $stmt->closeCursor();
        foreach ($ids as $id) {
            $db->prepare('DELETE FROM p2p_connections WHERE p2p_token_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM p2p_shared_files WHERE id = ?')->execute([$id]);
        }

        $db->prepare('DELETE FROM p2p_receive_sessions WHERE expires_at < ?')->execute([$cutoff]);
    }

    /**
     * Deletes everything of a channel on the local disk (batches, temp files) and its directory.
     */
    public static function deleteFiles(string $channelId): void
    {
        $dir = self::channelPath($channelId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            self::quiet(fn () => unlink($file));
        }
        self::quiet(fn () => rmdir($dir));
    }

    /**
     * Runs a filesystem call that may legitimately fail because the relay races with itself by
     * design (one request may delete a batch another is acknowledging). Returns false on failure.
     * Clears PHP's "last error" so the app's shutdown handler does not log the expected warning
     * as a fatal error.
     *
     * @return mixed The call's result, or false on failure.
     */
    private static function quiet(callable $call)
    {
        try {
            $result = @$call();
        } catch (\Throwable $e) {
            $result = false;
        }
        error_clear_last();

        return $result;
    }

    private static function validDirection(string $dir): bool
    {
        return $dir === self::DIR_G2H || $dir === self::DIR_H2G;
    }

    // -------------------------------------------------------------- storage backend
    //
    // Batches live on the web server's disk (data/p2p-relay), or, when the active storage connector is
    // S3, in the bucket under p2p-relay/<channel>/ so a large transfer never touches the host's disk.

    /** @var array<int, callable> */
    private static array $deferred = [];

    /** @var array<string, \Phuppi\Storage\S3Storage|null> */
    private static array $s3Backends = [];

    /**
     * Name recorded with each batch: "local", or the active storage connector's name when it is S3.
     */
    private static function currentBackend(): string
    {
        $name = (string) (Flight::get('active_storage_connector') ?? '');
        return ($name !== '' && self::s3For($name) !== null) ? $name : 'local';
    }

    /**
     * The S3 connector with this name, or null for "local" (or a connector that is not S3 or is gone).
     */
    private static function s3For(string $backend): ?\Phuppi\Storage\S3Storage
    {
        if ($backend === 'local') {
            return null;
        }
        if (!array_key_exists($backend, self::$s3Backends)) {
            try {
                $storage = \Phuppi\Storage\StorageFactory::createConnectorByName($backend);
                self::$s3Backends[$backend] = $storage instanceof \Phuppi\Storage\S3Storage ? $storage : null;
            } catch (\Throwable $e) {
                self::$s3Backends[$backend] = null;
            }
        }

        return self::$s3Backends[$backend];
    }

    /** The active S3 connector, or null when the local disk is used. */
    private static function s3(): ?\Phuppi\Storage\S3Storage
    {
        return self::s3For(self::currentBackend());
    }

    private static function objectName(string $channelId, string $dir, int $seq): string
    {
        return 'p2p-relay/' . $channelId . '/' . $dir . '-' . $seq . '.bin';
    }

    /**
     * Stores one batch on the active backend. Throws when it cannot.
     */
    private static function writeObject(string $channelId, string $dir, int $seq, string $body): void
    {
        $s3 = self::s3();
        if ($s3 === null) {
            self::writeBatch($channelId, $dir, $seq, $body);
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'p2prelay');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            throw new \RuntimeException('Unable to buffer relay batch');
        }
        try {
            $s3->getClient()->putObject($s3->getRelativePath(self::objectName($channelId, $dir, $seq)), $tmp, 'application/octet-stream');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to store relay batch: ' . $e->getMessage(), 0, $e);
        } finally {
            self::quiet(fn () => unlink($tmp));
        }
    }

    /**
     * Reads one batch, or false when it is not there.
     *
     * @return string|false
     */
    private static function readObject(string $channelId, string $dir, int $seq, string $backend)
    {
        $s3 = self::s3For($backend);
        if ($s3 === null) {
            return self::quiet(fn () => file_get_contents(self::batchPath($channelId, $dir, $seq)));
        }

        try {
            $stream = $s3->getClient()->getObject($s3->getRelativePath(self::objectName($channelId, $dir, $seq)))['Body'];
            $data = stream_get_contents($stream);
            fclose($stream);

            return $data === false ? false : $data;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Deletes one batch. Missing objects are fine: the relay races with itself by design.
     */
    private static function removeObject(string $channelId, string $dir, int $seq, string $backend): void
    {
        $s3 = self::s3For($backend);
        if ($s3 === null) {
            self::quiet(fn () => unlink(self::batchPath($channelId, $dir, $seq)));
            return;
        }

        try {
            $s3->getClient()->deleteObject($s3->getRelativePath(self::objectName($channelId, $dir, $seq)));
        } catch (\Throwable $e) {
            // Left for the orphan sweep
        }
    }

    /**
     * Runs work after the response has been sent (when PHP can release the client early), so a reader
     * is not kept waiting for deletions.
     */
    private static function defer(callable $work): void
    {
        if (!self::$deferred) {
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                foreach (self::$deferred as $job) {
                    try {
                        $job();
                    } catch (\Throwable $e) {
                        // The cleanup sweep catches whatever is left
                    }
                }
                self::$deferred = [];
                error_clear_last();
            });
        }
        self::$deferred[] = $work;
    }

    private static function writeBatch(string $channelId, string $dir, int $seq, string $body): void
    {
        $channelDir = self::channelPath($channelId);
        if (!is_dir($channelDir) && !self::quiet(fn () => mkdir($channelDir, 0775, true)) && !is_dir($channelDir)) {
            throw new \RuntimeException('Unable to create relay directory');
        }
        $path = self::batchPath($channelId, $dir, $seq);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $body) === false || !rename($tmp, $path)) {
            self::quiet(fn () => unlink($tmp));
            throw new \RuntimeException('Unable to store relay batch');
        }
    }

    private static function basePath(): string
    {
        $dataPath = Flight::get('flight.data.path') ?: dirname(__DIR__, 3) . '/data';
        return $dataPath . DIRECTORY_SEPARATOR . 'p2p-relay';
    }

    private static function channelPath(string $channelId): string
    {
        return self::basePath() . DIRECTORY_SEPARATOR . $channelId;
    }

    private static function batchPath(string $channelId, string $dir, int $seq): string
    {
        return self::channelPath($channelId) . DIRECTORY_SEPARATOR . $dir . '-' . $seq . '.bin';
    }

    private static function getSetting(string $name): ?string
    {
        $stmt = Flight::db()->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $value === false ? null : (string) $value;
    }

    private static function parseIniBytes($value): int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }

        return $number;
    }
}
