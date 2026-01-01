<?php

namespace Phuppi;

use Flight;
use Phuppi\Permissions\FilePermission;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;

class PermissionChecker
{
    private $user;
    private $voucher;

    public function __construct(?User $user = null, ?Voucher $voucher = null)
    {
        $this->user = $user;
        $this->voucher = $voucher;
    }

    public static function forUser(User $user): self
    {
        return new self($user, null);
    }

    public static function forVoucher(Voucher $voucher): self
    {
        return new self(null, $voucher);
    }

    public function hasPermission(FilePermission|NotePermission|UserPermission|VoucherPermission $permission): bool
    {
        if($this->voucher) {
            $db = Flight::db();
            $stmt = $db->prepare('SELECT COUNT(*) FROM voucher_permissions WHERE voucher_id = ? AND permission_name = ? AND permission_value = ?');
            $stmt->execute([$this->voucher->id, $permission->value, json_encode(true)]);
            $count = $stmt->fetchColumn();
            return $count > 0;
        }

        if($this->user) {
            $db = Flight::db();
            $stmt = $db->prepare('SELECT COUNT(*) FROM user_permissions WHERE user_id = ? AND permission_name = ? AND permission_value = ?');
            $stmt->execute([$this->user->id, $permission->value, json_encode(true)]);
            $count = $stmt->fetchColumn();
            return $count > 0;
        }

        return false;

    }

    public function userCan(NotePermission|UserPermission|VoucherPermission|FilePermission $permission, null|UploadedFile|User|Voucher $subject = null): bool
    {
        if($this->voucher) {
            if($subject instanceof UploadedFile) {
                return $subject->voucher_id === $this->voucher->id && $this->hasPermission($permission);
            }
            return $this->hasPermission($permission);
        }
        if($this->user) {
            if($this->user->hasRole('admin')) {
                return true;
            }
            return $this->hasPermission($permission);
        }

        return false;
    }

    public function canListFiles(): bool
    {
        if ($this->voucher) {
            return $this->hasPermission(FilePermission::LIST);
        }
        if ($this->user) {
            return $this->hasPermission(FilePermission::LIST);
        }
        return false;
    }

    public function canViewFile(UploadedFile $file): bool
    {
        if ($this->voucher && $file->voucher_id == $this->voucher->id && $this->hasPermission(FilePermission::VIEW)) {
            return true;
        }
        if ($this->user && $file->user_id == $this->user->id && $this->hasPermission(FilePermission::VIEW)) {
            return true;
        }
        return false;
    }

    public function canGetFile(UploadedFile $file): bool
    {
        if ($this->voucher && $file->voucher_id == $this->voucher->id && $this->hasPermission(FilePermission::GET)) {
            return true;
        }
        if ($this->user && $file->user_id == $this->user->id && $this->hasPermission(FilePermission::GET)) {
            return true;
        }
        return false;
    }

    public function canPutFile(?UploadedFile $file = null): bool
    {
        if ($this->voucher && (!$file || $file->voucher_id == $this->voucher->id) && $this->hasPermission(FilePermission::PUT)) {
            return true;
        }
        if ($this->user && (!$file || $file->user_id == $this->user->id) && $this->hasPermission(FilePermission::PUT)) {
            return true;
        }
        return false;
    }

    public function canCreateFile(): bool
    {
        if ($this->voucher && $this->hasPermission(FilePermission::CREATE)) {
            return true;
        }
        if ($this->user && $this->hasPermission(FilePermission::CREATE)) {
            return true;
        }
        return false;
    }

    public function canUpdateFile(UploadedFile $file): bool
    {
        if ($this->voucher && $file->voucher_id == $this->voucher->id && $this->hasPermission(FilePermission::UPDATE)) {
            return true;
        }
        if ($this->user && $file->user_id == $this->user->id && $this->hasPermission(FilePermission::UPDATE)) {
            return true;
        }
        return false;
    }

    public function canDeleteFile(UploadedFile $file): bool
    {
        if ($this->voucher && $file->voucher_id == $this->voucher->id && $this->hasPermission(FilePermission::DELETE)) {
            return true;
        }
        if ($this->user && $file->user_id == $this->user->id && $this->hasPermission(FilePermission::DELETE)) {
            return true;
        }
        return false;
    }

    public function canListUsers(): bool
    {
        if ($this->voucher && $this->hasPermission(UserPermission::LIST)) {
            return true;
        }
        if ($this->user && $this->hasPermission(UserPermission::LIST)) {
            return true;
        }
        return false;
    }

    public function canViewUser(User $user): bool
    {
        return $this->user !== null;
    }

    public function canCreateUser(): bool
    {
        return $this->user !== null;
    }

    public function canEditUser(User $user): bool
    {
        return $this->user !== null;
    }

    public function canDeleteUser(User $user): bool
    {
        return $this->user !== null;
    }

    public function canManageVouchers(): bool
    {
        if ($this->user) {
            return true;
        }
        if ($this->voucher) {
            return $this->hasPermission(VoucherPermission::LIST);
        }
        return false;
    }

}