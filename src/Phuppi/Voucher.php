<?php

/**
 * Voucher.php
 *
 * Voucher class for managing access vouchers with permissions and expiration in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi;

use Flight;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;

class Voucher
{
    /** @var int|null The unique identifier for the voucher. */
    public $id;

    /** @var int|null The user ID associated with the voucher. */
    public $user_id;

    /** @var string The voucher code. */
    public $voucher_code;

    /** @var string The session ID associated with the voucher. */
    public $session_id;

    /** @var string|null The creation timestamp of the voucher. */
    public $created_at;

    /** @var string|null The last update timestamp of the voucher. */
    public $updated_at;

    /** @var string|null The expiration timestamp of the voucher. */
    public $expires_at;

    /** @var string|null The redemption timestamp of the voucher. */
    public $redeemed_at;

    /** @var string|null The deletion timestamp of the voucher. */
    public $deleted_at;

    /** @var string|null The validity period for the voucher. */
    public $valid_for;

    /** @var string Additional notes for the voucher. */
    public $notes;

    /**
     * Constructs a Voucher object with optional data.
     * 
     * @param array $data Optional data to initialize the voucher.
     */
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

    /**
     * Loads a voucher from the database by ID.
     * 
     * @param int $id The voucher ID.
     * @return bool True if the voucher was loaded, false otherwise.
     */
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

    /**
     * Finds a voucher by code.
     * 
     * @param string $code The voucher code.
     * @return self|null The voucher object or null if not found.
     */
    public static function findByCode(string $code): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM vouchers WHERE voucher_code = ?');
        $stmt->execute([$code]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Finds a voucher by ID.
     * 
     * @param int $id The voucher ID.
     * @return self|null The voucher object or null if not found.
     */
    public static function findById(int $id): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM vouchers WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Finds all vouchers that are not deleted.
     * 
     * @return array Array of Voucher objects.
     */
    public static function findAll(): array
    {
        $db = Flight::db();
        $stmt = $db->query('SELECT * FROM vouchers WHERE deleted_at IS NULL ORDER BY created_at DESC');
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($row) => new self($row), $data);
    }

    /**
     * Gets the permissions associated with the voucher.
     * 
     * @return array Array of permission names and values.
     */
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

    /**
     * Checks if the voucher has a specific permission.
     * 
     * @param UserPermission|VoucherPermission|FilePermission $permission The permission to check.
     * @return bool True if the voucher has the permission, false otherwise.
     */
    public function hasPermission(UserPermission|VoucherPermission|FilePermission $permission): bool
    {
        $perms = $this->getPermissions();
        $permValue = is_string($permission) ? $permission : $permission->value;
        return isset($perms[$permValue]) && $perms[$permValue] === 'allow';
    }

    /**
     * Checks if the voucher has expired.
     * 
     * @return bool True if the voucher has expired, false otherwise.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at) {
            return strtotime($this->expires_at) < time();
        }
        return false;
    }

    /**
     * Checks if the voucher has been redeemed.
     * 
     * @return bool True if the voucher has been redeemed, false otherwise.
     */
    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    /**
     * Checks if the voucher has been deleted.
     * 
     * @return bool True if the voucher has been deleted, false otherwise.
     */
    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Saves the voucher to the database.
     * 
     * @return bool True if saved successfully, false otherwise.
     */
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

    /**
     * Sets the permissions for the voucher.
     * 
     * @param array $permissions Array of permission names and values.
     * @return void
     */
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

    /**
     * Gets file statistics for the voucher.
     * 
     * @return object Object with file_count and total_size.
     */
    public function getFileStats(): object
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT COUNT(*) as file_count, SUM(filesize) as total_size FROM uploaded_files WHERE voucher_id = ?');
        $stmt->execute([$this->id]);
        $stats = $stmt->fetch(\PDO::FETCH_OBJ);
        return (object) [
            'file_count' => (int) $stats->file_count,
            'total_size' => (int) $stats->total_size
        ];
    }

    /**
     * Deletes the voucher from the database.
     * 
     * @return bool True if deleted successfully, false otherwise.
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        $db = Flight::db();

        // Delete voucher permissions
        $stmt = $db->prepare('DELETE FROM voucher_permissions WHERE voucher_id = ?');
        $stmt->execute([$this->id]);

        // Delete voucher roles
        $stmt = $db->prepare('DELETE FROM voucher_roles WHERE voucher_id = ?');
        $stmt->execute([$this->id]);

        // Delete voucher
        $stmt = $db->prepare('UPDATE vouchers SET deleted_at = ? WHERE id = ?');
        return $stmt->execute([date('Y-m-d H:i:s'), $this->id]);
    }

    /**
     * Checks if the voucher can perform a permission on a subject.
     * 
     * @param FilePermission|NotePermission|UserPermission|VoucherPermission|string $permission The permission to check.
     * @param null|Note|UploadedFile|User|Voucher $subject The subject to check against.
     * @return bool True if the voucher can perform the permission, false otherwise.
     */
    public function can(FilePermission|NotePermission|UserPermission|VoucherPermission|string $permission, null|Note|UploadedFile|User|Voucher $subject = null): bool
    {
        if (is_string($permission)) {
            if ($permission === 'admin') {
                // voucher can never admin
                return false;
            }
            if (null === $permission = $this->permissionFromString($permission)) {
                return false;
            };
        }
        if ($permission === UserPermission::DELETE && $subject instanceof User && $subject->hasRole('admin')) {
            // cannot delete admin user
            return false;
        }
        $permissionChecker = PermissionChecker::forVoucher($this);
        return $permissionChecker->can($permission, $subject);
    }

    /**
     * Converts a permission string to a permission object.
     * 
     * @param string $permissionString The permission string.
     * @return mixed The permission object or null if not found.
     */
    public function permissionFromString($permissionString): FilePermission|NotePermission|UserPermission|VoucherPermission|null
    {
        switch (substr($permissionString, 0, strpos($permissionString, '_'))) {
            case 'file':
                return FilePermission::fromString($permissionString);
            case 'note':
                return NotePermission::fromString($permissionString);
            case 'user':
                return UserPermission::fromString($permissionString);
            case 'voucher':
                return VoucherPermission::fromString($permissionString);
        }
        return null;
    }

    /**
     * Gets the username of the user associated with the voucher.
     * 
     * @return string|null The username or null if not found.
     */
    public function getUsername(): ?string
    {
        if (!$this->user_id) {
            return null;
        }
        $db = Flight::db();
        $stmt = $db->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$this->user_id]);
        $user = $stmt->fetch(\PDO::FETCH_OBJ);
        return $user->username;
    }
}
