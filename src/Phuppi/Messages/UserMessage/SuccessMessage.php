<?php

/**
 * SuccessMessage.php
 *
 * SuccessMessage class for user success messages in the Phuppi application.
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

class SuccessMessage extends \Phuppi\Messages\UserMessage
{
    /**
     * Constructor for SuccessMessage.
     *
     * @param string $message The message text.
     * @param string $title The title (optional).
     */
    public function __construct(string $message, string $title = '')
    {
        $this->type = Type::SUCCESS;
        $this->icon = Icon::SUCCESS;
        $this->color = Color::SUCCESS;
        $this->attitude = Attitude::POSITIVE;
        parent::__construct($message, $title);
    }
}
