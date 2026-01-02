<?php

/**
 * PermissionChecker.php
 *
 * PermissionChecker class for verifying user and voucher permissions in the Phuppi application.
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
use Phuppi\Note;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;

class PermissionChecker
{
    /** @var ?User */
    private $user;

    /** @var ?Voucher */
    private $voucher;

    /**
     * Constructor for PermissionChecker.
     *
     * @param ?User $user The user to check permissions for.
     * @param ?Voucher $voucher The voucher to check permissions for.
     */
    public function __construct(?User $user = null, ?Voucher $voucher = null)
    {
        $this->user = $user;
        $this->voucher = $voucher;
    }

    /**
     * Creates a PermissionChecker for a user.
     *
     * @param User $user The user.
     * @return self The PermissionChecker instance.
     */
    public static function forUser(User $user): self
    {
        return new self($user, null);
    }

    /**
     * Creates a PermissionChecker for a voucher.
     *
     * @param Voucher $voucher The voucher.
     * @return self The PermissionChecker instance.
     */
    public static function forVoucher(Voucher $voucher): self
    {
        return new self(null, $voucher);
    }

    /**
     * Checks if the user or voucher has the specified permission.
     *
     * @param FilePermission|NotePermission|UserPermission|VoucherPermission $permission The permission to check.
     * @return bool True if the permission is granted, false otherwise.
     */
    public function hasPermission(FilePermission|NotePermission|UserPermission|VoucherPermission $permission): bool
    {
        if ($this->voucher) {
            $db = Flight::db();
            $stmt = $db->prepare('SELECT COUNT(*) FROM voucher_permissions WHERE voucher_id = ? AND permission_name = ? AND permission_value = ?');
            $stmt->execute([$this->voucher->id, $permission->value, json_encode(true)]);
            $count = $stmt->fetchColumn();
            return $count > 0;
        }

        if ($this->user) {
            $db = Flight::db();
            $stmt = $db->prepare('SELECT COUNT(*) FROM user_permissions WHERE user_id = ? AND permission_name = ? AND permission_value = ?');
            $stmt->execute([$this->user->id, $permission->value, json_encode(true)]);
            $count = $stmt->fetchColumn();
            return $count > 0;
        }

        return false;
    }

    /**
     * Checks if the user or voucher can perform the action on the subject.
     *
     * @param NotePermission|UserPermission|VoucherPermission|FilePermission $permission The permission to check.
     * @param null|Note|UploadedFile|User|Voucher $subject The subject of the action.
     * @return bool True if the action is allowed, false otherwise.
     */
    public function can(NotePermission|UserPermission|VoucherPermission|FilePermission $permission, null|Note|UploadedFile|User|Voucher $subject = null): bool
    {
        if (!$this->voucher && $this->user->hasRole('admin')) {
            // admin can do anything
            return true;
        }

        $hasPermission = $this->hasPermission($permission);

        if ($this->voucher) {
            if ($subject instanceof User || $subject instanceof Voucher) {
                // vouchers cannot interact with users or vouchers
                return false;
            }

            // vouchers can only interact with their own notes
            if ($hasPermission && $subject instanceof Note) {
                return $subject->voucher_id === $this->voucher->id;
            }
            // vouchers can only interact with their own files
            if ($hasPermission && $subject instanceof UploadedFile) {
                return $subject->voucher_id === $this->voucher->id;
            }

            return $hasPermission;
        } else if ($this->user) {
            // users can interact with all of their notes
            if ($hasPermission && $subject instanceof Note) {
                return $subject->user_id === $this->user->id;
            }
            // users can interact with all of their files
            if ($hasPermission && $subject instanceof UploadedFile) {
                return $subject->user_id === $this->user->id;
            }

            if ($hasPermission && $subject instanceof User) {
                return $subject->id === $this->user->id;
            }

            if ($hasPermission && $subject instanceof Voucher) {
                return $subject->user_id === $this->user->id;
            }

            // permitted if the subject doesn't require an owner permission check, eg: create or list
            return $hasPermission;
        }

        return false;
    }
}
