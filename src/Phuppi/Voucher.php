<?php

namespace Phuppi;

use Flight;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;
use Phuppi\Permissions\FilePermission;

class Voucher
{
    public $id;
    public $user_id;
    public $voucher_code;
    public $session_id;
    public $created_at;
    public $updated_at;
    public $expires_at;
    public $redeemed_at;
    public $deleted_at;
    public $valid_for;
    public $notes;

    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->user_id = $data['user_id'] ?? null;
        $this->voucher_code = $data['voucher_code'] ?? '';
        $this->session_id = $data['session_id'] ?? '';
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;
        $this->expires_at = $data['expires_at'] ?? null;
        $this->redeemed_at = $data['redeemed_at'] ?? null;
        $this->deleted_at = $data['deleted_at'] ?? null;
        $this->valid_for = $data['valid_for'] ?? null;
        $this->notes = $data['notes'] ?? '';
    }

    public function load(int $id): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM vouchers WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($data) {
            $this->id = $data['id'];
            $this->user_id = $data['user_id'];
            $this->voucher_code = $data['voucher_code'];
            $this->session_id = $data['session_id'];
            $this->created_at = $data['created_at'];
            $this->updated_at = $data['updated_at'];
            $this->expires_at = $data['expires_at'];
            $this->redeemed_at = $data['redeemed_at'];
            $this->deleted_at = $data['deleted_at'];
            $this->valid_for = $data['valid_for'];
            $this->notes = $data['notes'];
            return true;
        }
        return false;
    }

    public static function findByCode(string $code): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM vouchers WHERE voucher_code = ?');
        $stmt->execute([$code]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    public function getPermissions(): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT permission_name, permission_value FROM voucher_permissions WHERE voucher_id = ?');
        $stmt->execute([$this->id]);
        $perms = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $permissions = [];
        foreach ($perms as $perm) {
            $permissions[$perm['permission_name']] = $perm['permission_value'];
        }
        return $permissions;
    }

    public function hasPermission(UserPermission|VoucherPermission|FilePermission $permission): bool
    {
        $perms = $this->getPermissions();
        $permValue = is_string($permission) ? $permission : $permission->value;
        return isset($perms[$permValue]) && $perms[$permValue] === 'allow';
    }

    public function isExpired(): bool
    {
        if ($this->expires_at) {
            return strtotime($this->expires_at) < time();
        }
        return false;
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function save(): bool
    {
        $db = Flight::db();
        if ($this->id) {
            // Update
            $stmt = $db->prepare('UPDATE vouchers SET user_id = ?, voucher_code = ?, session_id = ?, updated_at = ?, expires_at = ?, redeemed_at = ?, deleted_at = ?, valid_for = ?, notes = ? WHERE id = ?');
            return $stmt->execute([
                $this->user_id,
                $this->voucher_code,
                $this->session_id,
                $this->updated_at ?? date('Y-m-d H:i:s'),
                $this->expires_at,
                $this->redeemed_at,
                $this->deleted_at,
                $this->valid_for,
                $this->notes,
                $this->id
            ]);
        } else {
            // Insert
            $stmt = $db->prepare('INSERT INTO vouchers (user_id, voucher_code, session_id, created_at, updated_at, expires_at, valid_for, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            $result = $stmt->execute([
                $this->user_id,
                $this->voucher_code,
                $this->session_id,
                $now,
                $now,
                $this->expires_at,
                $this->valid_for,
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

    public function setPermissions(array $permissions): void
    {
        $db = Flight::db();
        // Delete existing
        $stmt = $db->prepare('DELETE FROM voucher_permissions WHERE voucher_id = ?');
        $stmt->execute([$this->id]);
        // Insert new
        $stmt = $db->prepare('INSERT INTO voucher_permissions (voucher_id, permission_name, permission_value) VALUES (?, ?, ?)');
        foreach ($permissions as $name => $value) {
            $stmt->execute([$this->id, $name, $value]);
        }
    }
}