<?php

/**
 * Messages.php
 *
 * Messages class for managing user flash messages in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

declare(strict_types=1);

namespace Phuppi;

use Flight;
use Phuppi\Messages\UserMessage;

class Messages
{
    /**
     * Retrieves user messages of a specific type from session.
     *
     * @param string $type The type of messages to retrieve
     * @return ?array Array of messages or null if none
     */
    public function getUserMessages(string $type): ?array
    {
        if ($messages = Flight::session()->get(get_class($this) . '::' . $type)) {
            $messages = json_decode($messages);
            // delete flash messages when read
            Flight::session()->delete(get_class($this) . '::' . $type);
            return $messages;
        }
        return null;
    }

    /**
     * Adds a user message to the session.
     *
     * @param UserMessage $message The message object to add
     * @return void
     */
    public function addUserMessage(UserMessage $message): void
    {
        Flight::session()->set(get_class($this) . '::' . $message->type->value, json_encode([$message]));
    }

    /**
     * Adds an info message.
     *
     * @param mixed $message The message content
     * @param string $header Optional header for the message
     * @return void
     */
    public function addInfo($message, $header = ''): void
    {
        $this->addUserMessage(new UserMessage\InfoMessage($message, $header));
    }

    /**
     * Adds a success message.
     *
     * @param mixed $message The message content
     * @param string $header Optional header for the message
     * @return void
     */
    public function addSuccess($message, $header = ''): void
    {
        $this->addUserMessage(new UserMessage\SuccessMessage($message, $header));
    }

    /**
     * Adds an error message.
     *
     * @param mixed $message The message content
     * @param string $header Optional header for the message
     * @return void
     */
    public function addError($message, $header = ''): void
    {
        $this->addUserMessage(new UserMessage\ErrorMessage($message, $header));
    }
}
