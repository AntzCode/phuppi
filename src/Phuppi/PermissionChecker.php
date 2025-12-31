<?php

namespace Phuppi;

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

    public function canListFiles(): bool
    {
        if ($this->user) {
            return true; // Users can list their files
        }
        if ($this->voucher) {
            return $this->voucher->hasPermission(Permissions::LIST);
        }
        return false;
    }

    public function canViewFile(UploadedFile $file): bool
    {
        if ($this->user && $file->user_id == $this->user->id) {
            return true;
        }
        if ($this->voucher && $file->voucher_id == $this->voucher->id && $this->voucher->hasPermission(Permissions::VIEW)) {
            return true;
        }
        return false;
    }

    public function canGetFile(UploadedFile $file): bool
    {
        if ($this->user && $file->user_id == $this->user->id) {
            return true;
        }
        if ($this->voucher && $file->voucher_id == $this->voucher->id && $this->voucher->hasPermission(Permissions::GET)) {
            return true;
        }
        return false;
    }

    public function canPutFile(): bool
    {
        if ($this->user) {
            return true;
        }
        if ($this->voucher && $this->voucher->hasPermission(Permissions::PUT)) {
            return true;
        }
        return false;
    }

    public function canCreateFile(): bool
    {
        if ($this->user) {
            return true;
        }
        if ($this->voucher && $this->voucher->hasPermission(Permissions::CREATE)) {
            return true;
        }
        return false;
    }

    public function canUpdateFile(UploadedFile $file): bool
    {
        if ($this->user && $file->user_id == $this->user->id) {
            return true;
        }
        if ($this->voucher && $file->voucher_id == $this->voucher->id && $this->voucher->hasPermission(Permissions::UPDATE)) {
            return true;
        }
        return false;
    }

    public function canDeleteFile(UploadedFile $file): bool
    {
        if ($this->user && $file->user_id == $this->user->id) {
            return true;
        }
        if ($this->voucher && $file->voucher_id == $this->voucher->id && $this->voucher->hasPermission(Permissions::DELETE)) {
            return true;
        }
        return false;
    }

    public function getAccessibleFiles(): array
    {
        if ($this->user) {
            return UploadedFile::findByUser($this->user->id);
        }
        if ($this->voucher) {
            return UploadedFile::findByVoucher($this->voucher->id);
        }
        return [];
    }
}