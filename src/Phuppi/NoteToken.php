<?php

namespace Phuppi;

use Flight;

class NoteToken
{
    public $id;
    public $note_id;
    public $voucher_id;
    public $token;
    public $created_at;
    public $expires_at;

    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->note_id = $data['note_id'] ?? null;
        $this->voucher_id = $data['voucher_id'] ?? null;
        $this->token = $data['token'] ?? '';
        $this->created_at = $data['created_at'] ?? null;
        $this->expires_at = $data['expires_at'] ?? null;
    }

    public function load(int $id): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM note_tokens WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($data) {
            $this->id = $data['id'];
            $this->note_id = $data['note_id'];
            $this->voucher_id = $data['voucher_id'];
            $this->token = $data['token'];
            $this->created_at = $data['created_at'];
            $this->expires_at = $data['expires_at'];
            return true;
        }
        return false;
    }

    public static function findByToken(string $token): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM note_tokens WHERE token = ? AND (expires_at IS NULL OR expires_at > datetime("now"))');
        $stmt->execute([$token]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    public function isExpired(): bool
    {
        if ($this->expires_at) {
            return strtotime($this->expires_at) < time();
        }
        return false;
    }

    public function save(): bool
    {
        $db = Flight::db();
        if ($this->id) {
            // Update
            $stmt = $db->prepare('UPDATE note_tokens SET note_id = ?, voucher_id = ?, token = ?, expires_at = ? WHERE id = ?');
            return $stmt->execute([
                $this->note_id,
                $this->voucher_id,
                $this->token,
                $this->expires_at,
                $this->id
            ]);
        } else {
            // Insert
            $stmt = $db->prepare('INSERT INTO note_tokens (note_id, voucher_id, token, created_at, expires_at) VALUES (?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            $result = $stmt->execute([
                $this->note_id,
                $this->voucher_id,
                $this->token,
                $now,
                $this->expires_at
            ]);
            if ($result) {
                $this->id = $db->lastInsertId();
                $this->created_at = $now;
            }
            return $result;
        }
    }

    public function delete(): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM note_tokens WHERE id = ?');
        return $stmt->execute([$this->id]);
    }
}