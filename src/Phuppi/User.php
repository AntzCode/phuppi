<?php

/**
 * User.php
 *
 * User class for managing user accounts, authentication, and permissions in the Phuppi application.
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

class User
{
    /** @var int|null The unique identifier for the user. */
    public $id;

    /** @var string The username of the user. */
    public $username;

    /** @var string The hashed password of the user. */
    public $password;

    /** @var string|null The creation timestamp of the user. */
    public $created_at;

    /** @var string|null The last update timestamp of the user. */
    public $updated_at;

    /** @var string|null The disabled timestamp of the user. */
    public $disabled_at;

    /** @var string|null The session expiration timestamp of the user. */
    public $session_expires_at;

    /** @var string Additional notes for the user. */
    public $notes;

    /**
     * Constructor for the User class.
     *
     * Initializes a User object with the provided data array.
     *
     * @param array $data An associative array of user data to initialize the object with.
     */
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

    /**
     * Loads user data from the database by ID.
     *
     * @param int $id The user ID to load.
     * @return bool True if the user was found and loaded, false otherwise.
     */
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

    /**
     * Finds a user by username.
     *
     * @param string $username The username to search for.
     * @return self|null The User object if found, null otherwise.
     */
    public static function findByUsername(string $username): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Finds a user by ID.
     *
     * @param int $id The user ID to search for.
     * @return self|null The User object if found, null otherwise.
     */
    public static function findById(int $id): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Retrieves all users from the database, ordered by username.
     *
     * @return array An array of User objects.
     */
    public static function findAll(): array
    {
        $db = Flight::db();
        $stmt = $db->query('SELECT * FROM users ORDER BY username');
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($row) => new self($row), $data);
    }

    /**
     * Authenticates the user with the provided password.
     *
     * @param string $password The password to verify.
     * @return bool True if the password is correct, false otherwise.
     */
    public function authenticate(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * Checks if the user is disabled.
     *
     * @return bool True if the user is disabled, false otherwise.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Saves the user to the database.
     *
     * If the user has an ID, it updates the existing record; otherwise, it inserts a new one.
     * Hashes the password if it's not already hashed.
     *
     * @return bool True if the save was successful, false otherwise.
     */
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

    /**
     * Deletes the user from the database.
     *
     * Also deletes associated user permissions and roles.
     *
     * @return bool True if the deletion was successful, false otherwise.
     */
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

    /**
     * Retrieves the roles assigned to the user.
     *
     * @return array An array of role names.
     */
    public function getRoles(): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?');
        $stmt->execute([$this->id]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Checks if the user has a specific role.
     *
     * @param string $role The role name to check.
     * @return bool True if the user has the role, false otherwise.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles());
    }

    /**
     * Checks if the user has a specific permission.
     *
     * @param FilePermission|NotePermission|UserPermission|VoucherPermission $permission The permission to check.
     * @return bool True if the user has the permission, false otherwise.
     */
    public function hasPermission(FilePermission|NotePermission|UserPermission|VoucherPermission $permission): bool
    {
        $permissionChecker = PermissionChecker::forUser($this);
        return $permissionChecker->hasPermission($permission);
    }

    /**
     * Retrieves file statistics for the user.
     *
     * @return object An object with 'file_count' and 'total_size' properties.
     */
    public function getFileStats(): object
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT COUNT(*) as file_count, SUM(filesize) as total_size FROM uploaded_files WHERE user_id = ?');
        $stmt->execute([$this->id]);
        $stats = $stmt->fetch(\PDO::FETCH_OBJ);
        return (object) [
            'file_count' => (int) $stats->file_count,
            'total_size' => (int) $stats->total_size
        ];
    }

    /**
     * Retrieves the allowed permissions for the user.
     *
     * @return array An array of permission names that are allowed.
     */
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

    /**
     * Checks if the user can perform a specific action on a subject.
     *
     * @param FilePermission|NotePermission|UserPermission|VoucherPermission|string $permission The permission or permission string to check.
     * @param null|Note|UploadedFile|User|Voucher $subject The subject to check the permission against.
     * @return bool True if the user can perform the action, false otherwise.
     */
    public function can(FilePermission|NotePermission|UserPermission|VoucherPermission|string $permission, null|Note|UploadedFile|User|Voucher $subject = null): bool
    {
        if (is_string($permission)) {
            if ($permission === 'admin') {
                return $this->hasRole('admin');
            }
            if (null === $permission = $this->permissionFromString($permission)) {
                return false;
            };
        }
        if ($permission === UserPermission::DELETE && $subject instanceof User && $subject->hasRole('admin')) {
            // cannot delete admin user
            return false;
        }
        $permissionChecker = PermissionChecker::forUser($this);
        return $permissionChecker->can($permission, $subject);
    }

    /**
     * Converts a permission string to a permission object.
     *
     * @param string $permissionString The permission string to convert.
     * @return FilePermission|NotePermission|UserPermission|VoucherPermission|null The permission object or null if invalid.
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
}
