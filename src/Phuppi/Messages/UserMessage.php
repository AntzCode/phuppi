<?php

declare(strict_types=1);

namespace Phuppi\Messages;

use Phuppi\Messages\UserMessage\Enum\Attitude;
use Phuppi\Messages\UserMessage\Enum\Color;
use Phuppi\Messages\UserMessage\Enum\Icon;
use Phuppi\Messages\UserMessage\Enum\Type;

abstract class UserMessage
{
    public ?string $id;
    public ?string $title;
    public string $message;

    public Type $type = Type::INFO;
    public Icon $icon = Icon::INFO;
    public Color $color = Color::INFO;
    public Attitude $attitude = Attitude::NEUTRAL;

    protected $componentId;

    public function __construct(string $message, ?string $title = null)
    {
        $this->message = $message;
        $this->title = $title;
    }
}
