<?php

declare(strict_types=1);

namespace Phuppi;

use Flight;
use Phuppi\Messages\UserMessage;

class Messages
{
    public function getUserMessages(string $type): ?array
    {
        if($messages = Flight::session()->get(get_class($this) . '::' . $type)) {
            $messages = json_decode($messages);
            // delete flash messages when read
            Flight::session()->delete(get_class($this) . '::' . $type);
            return $messages;
        }
        return null;
    }

    public function addUserMessage(UserMessage $message): void
    {
        Flight::session()->set(get_class($this) . '::' . $message->type->value, json_encode([$message]));
    }

    public function addInfo($message, $header='') {
        $this->addUserMessage(new UserMessage\InfoMessage($message, $header));
    }

    public function addSuccess($message, $header='') {
        $this->addUserMessage(new UserMessage\SuccessMessage($message, $header));
    }

    public function addError($message, $header='') {
        $this->addUserMessage(new UserMessage\ErrorMessage($message, $header));
    }


}
