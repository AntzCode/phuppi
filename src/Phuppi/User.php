<?php

namespace Phuppi;

use Flight;

class User
{
    public $id;
    public $username;
    public $password;
    public $created_at;
    public $updated_at;
    public $disabled_at;
    public $session_expires_at;
    public $notes;

    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->username = $data['username'] ?? '';
        $this->password = $data['password'] ?? '';
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;
        $this->disabled_at = $data['disabled_at'] ?? null;
        $this->session_expires_at = $data['session_expires_at'] ?? null;
        $this->notes = $data['notes'] ?? '';
    }

    public function load(int $id): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($data) {
            $this->id = $data['id'];
            $this->username = $data['username'];
            $this->password = $data['password'];
            $this->created_at = $data['created_at'];
            $this->updated_at = $data['updated_at'];
            $this->disabled_at = $data['disabled_at'];
            $this->session_expires_at = $data['session_expires_at'];
            $this->notes = $data['notes'];
            return true;
        }
        return false;
    }

    public static function findByUsername(string $username): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    public static function findById(int $id): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    public function authenticate(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function save(): bool
    {
        $db = Flight::db();
        if ($this->id) {
            // Update
            $stmt = $db->prepare('UPDATE users SET username = ?, password = ?, updated_at = ?, disabled_at = ?, session_expires_at = ?, notes = ? WHERE id = ?');
            return $stmt->execute([
                $this->username,
                $this->password,
                $this->updated_at ?? date('Y-m-d H:i:s'),
                $this->disabled_at,
                $this->session_expires_at,
                $this->notes,
                $this->id
            ]);
        } else {
            // Insert
            $stmt = $db->prepare('INSERT INTO users (username, password, created_at, updated_at, notes) VALUES (?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            $result = $stmt->execute([
                $this->username,
                $this->password,
                $now,
                $now,
                $this->notes
            ]);
            if ($result) {
                $this->id = $db->lastInsertId();
                $this->created_at = $now;
                $this->updated_at = $now;
            }
            return $result;
        }
    }

    public function getRoles(): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?');
        $stmt->execute([$this->id]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles());
    }
}
