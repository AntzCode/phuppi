<?php

/**
 * InfoMessage.php
 *
 * InfoMessage class for user info messages in the Phuppi application.
 *
 * @package Phuppi\Messages\UserMessage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

declare(strict_types=1);

namespace Phuppi\Messages\UserMessage;

use Phuppi\Messages\UserMessage\Enum\Attitude;
use Phuppi\Messages\UserMessage\Enum\Color;
use Phuppi\Messages\UserMessage\Enum\Icon;
use Phuppi\Messages\UserMessage\Enum\Type;

class InfoMessage extends \Phuppi\Messages\UserMessage
{
    /**
     * Constructor for InfoMessage.
     *
     * @param string $message The message text.
     * @param string $title The title (optional).
     */
    public function __construct(string $message, string $title = '')
    {
        $this->type = Type::INFO;
        $this->icon = Icon::INFO;
        $this->color = Color::INFO;
        $this->attitude = Attitude::NEUTRAL;
        parent::__construct($message, $title);
    }
}
