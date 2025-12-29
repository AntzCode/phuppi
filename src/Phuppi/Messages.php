<?php

declare(strict_types=1);

namespace Phuppi;

use Flight;
use Phuppi\Messages\UserMessage;
use Phuppi\Messages\UserMessage\Enum\Type as Type;

class Messages
{
    public function getUserMessages(Type|string $type): ?array
    {
        if($messages = Flight::session()->get(get_class($this) . '::' . ($type->value ?? $type))) {
            $messages = json_decode($messages);
            // delete flash messages when read
            Flight::session()->delete(get_class($this) . '::' . ($type->value ?? $type));
            return $messages;
        }
        return null;
    }

    public function addUserMessage(UserMessage $message): void
    {
        Flight::session()->set(get_class($this) . '::' . $message->type->value, json_encode([$message]));
    }
}
