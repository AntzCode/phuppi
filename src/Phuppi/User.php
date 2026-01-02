<?php

namespace Phuppi;

use Flight;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;

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

    public static function findAll(): array
    {
        $db = Flight::db();
        $stmt = $db->query('SELECT * FROM users ORDER BY username');
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($row) => new self($row), $data);
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
        // Hash password if not already hashed
        if (!password_get_info($this->password)['algo']) {
            $this->password = password_hash($this->password, PASSWORD_DEFAULT);
        }

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

    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        $db = Flight::db();

        // Delete user permissions
        $stmt = $db->prepare('DELETE FROM user_permissions WHERE user_id = ?');
        $stmt->execute([$this->id]);

        // Delete user roles
        $stmt = $db->prepare('DELETE FROM user_roles WHERE user_id = ?');
        $stmt->execute([$this->id]);

        // Delete user
        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        return $stmt->execute([$this->id]);

        // @TODO: Delete uploaded files - this should be handled by background job
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

    public function hasPermission(FilePermission|NotePermission|UserPermission|VoucherPermission $permission): bool
    {
        $permissionChecker = PermissionChecker::forUser($this);
        return $permissionChecker->hasPermission($permission);
    }

    public function getFileStats() {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT COUNT(*) as file_count, SUM(filesize) as total_size FROM uploaded_files WHERE user_id = ?');
        $stmt->execute([$this->id]);
        $stats = $stmt->fetch(\PDO::FETCH_OBJ);
        return (object) [
            'file_count' => (int) $stats->file_count,
            'total_size' => (int) $stats->total_size
        ];
    }

    public function allowedPermissions(): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT permission_name, permission_value FROM user_permissions WHERE user_id = ? AND permission_value = ?');
        $stmt->execute([$this->id, json_encode(true)]);
        $perms = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $permissions = [];
        foreach ($perms as $perm) {
            $permissions[] = $perm['permission_name'];
        }
        return $permissions;
    }

    public function can(FilePermission|NotePermission|UserPermission|VoucherPermission|string $permission, null|Note|UploadedFile|User|Voucher $subject=null) :bool {
        if(is_string($permission)) {
            if($permission === 'admin') {
                return $this->hasRole('admin');
            }
            if(null === $permission = $this->permissionFromString($permission)) {
                return false;
            };
        }
        if($permission === UserPermission::DELETE && $subject instanceof User && $subject->hasRole('admin')) {
            // cannot delete admin user
            return false;
        }
        $permissionChecker = PermissionChecker::forUser($this);
        return $permissionChecker->can($permission, $subject);
    }

    public function permissionFromString($permissionString) {
        switch(substr($permissionString, 0, strpos($permissionString, '_'))) {
            case 'file':
                return FilePermission::fromString($permissionString);
            case 'note':
                return NotePermission::fromString($permissionString);
            case 'user':
                return UserPermission::fromString($permissionString);
            case 'voucher':
                return VoucherPermission::fromString($permissionString);
        }
    }

}
