<?php

/**
 * UserMessage.php
 *
 * UserMessage class for user message objects in the Phuppi application.
 *
 * @package Phuppi\Messages\UserMessage
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

declare(strict_types=1);

namespace Phuppi\Messages;

use Phuppi\Messages\UserMessage\Enum\Attitude;
use Phuppi\Messages\UserMessage\Enum\Color;
use Phuppi\Messages\UserMessage\Enum\Icon;
use Phuppi\Messages\UserMessage\Enum\Type;

abstract class UserMessage
{
    /** @var ?string Unique identifier for the message */
    public ?string $id;

    /** @var ?string Title of the message */
    public ?string $title;

    /** @var string The message content */
    public string $message;

    /** @var Type The type of the message */
    public Type $type = Type::INFO;

    /** @var Icon The icon associated with the message */
    public Icon $icon = Icon::INFO;

    /** @var Color The color of the message */
    public Color $color = Color::INFO;

    /** @var Attitude The attitude of the message */
    public Attitude $attitude = Attitude::NEUTRAL;

    /** @var mixed Component ID for the message */
    protected $componentId;

    /**
     * Constructor for UserMessage.
     *
     * @param string $message The message content
     * @param ?string $title Optional title for the message
     */
    public function __construct(string $message, ?string $title = null)
    {
        $this->message = $message;
        $this->title = $title;
    }
}
