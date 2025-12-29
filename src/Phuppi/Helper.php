<?php

namespace Phuppi;

use Flight;

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
}
