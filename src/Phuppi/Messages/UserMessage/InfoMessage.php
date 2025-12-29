<?php

declare(strict_types=1);

namespace Phuppi\Messages\UserMessage;

use Phuppi\Messages\UserMessage\Enum\Attitude;
use Phuppi\Messages\UserMessage\Enum\Color;
use Phuppi\Messages\UserMessage\Enum\Icon;
use Phuppi\Messages\UserMessage\Enum\Type;

class InfoMessage extends \Phuppi\Messages\UserMessage
{
    public function __construct(string $message, string $title = '')
    {
        $this->type = Type::INFO;
        $this->icon = Icon::INFO;
        $this->color = Color::INFO;
        $this->attitude = Attitude::NEUTRAL;
        parent::__construct($message, $title);
    }
}
