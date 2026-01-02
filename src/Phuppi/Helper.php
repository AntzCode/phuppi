<?php

namespace Phuppi;

use Flight;

use Phuppi\Note;
use Phuppi\UploadedFile;
use Phuppi\User;
use Phuppi\Voucher;
use Phuppi\Permissions\NotePermission;
use Phuppi\Permissions\UserPermission;
use Phuppi\Permissions\VoucherPermission;
use Phuppi\Permissions\FilePermission;

class Helper {

    public static function getViewPath($template) {

    }
    
    public static function getPhuppiVersion(): string {
        return '2.0.0';
    }

    public static function getUserMessages($type): ?string {
        
        if($messages = Flight::messages()->getUserMessages($type)) {

            $latte = Flight::latte();

            $attitude ??= null;
            $color ??= null;
            $componentId ??= '';
            $header ??= null;
            $icon ??= null;
            $type ??= null;

            foreach ($messages as $message) {
                $attitude = $attitude ?? $message->attitude;
                $color = $color ?? $message->color;
                $componentId .= $message->componentId ?? $message->id ?? 'msg_' . rand();
                $header = $header ?? !empty($message->title) ? $message->title : null;
                $icon = $icon ?? $message->icon;
                $type = $type ?? $message->type;
            }

            return $latte->renderToString('messages.latte', [
                'attitude' => $attitude,
                'color' => $color,
                'componentId' => $componentId,
                'header' => $header,
                'icon' => $icon,
                'messages' => $messages,
                'type' => $type, 
            ]);
        } else {
            return null;
        }
    }

    public static function getUserId() {
        return Flight::user()?->id ?? null;
    }
    
    public static function getVoucherId() {
        return Flight::voucher()?->id ?? null;
    }

    public static function isAuthenticated() {
        return Flight::session()->get('id') || Flight::session()->get('voucher_id');
    }

    public static function userCan(NotePermission|UserPermission|VoucherPermission|FilePermission|string $permission, null|Note|UploadedFile|User|Voucher $subject = null) {
        $user = Flight::user();
        $voucher = Flight::voucher();
        if ($voucher && $voucher->id) {
            return $voucher->can($permission, $subject);
        } else if ($user && $user->id) {
            return $user->can($permission, $subject);
        } else {
            return false;
        }
    }


}
