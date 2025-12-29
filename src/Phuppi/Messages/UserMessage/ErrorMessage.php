<?php

declare(strict_types=1);

namespace Phuppi\Messages\UserMessage;

use Phuppi\Messages\UserMessage\Enum\Attitude;
use Phuppi\Messages\UserMessage\Enum\Color;
use Phuppi\Messages\UserMessage\Enum\Icon;
use Phuppi\Messages\UserMessage\Enum\Type;

class ErrorMessage extends \Phuppi\Messages\UserMessage
{
    public function __construct(string $message, string $title = '')
    {
        $this->type = Type::ERROR;
        $this->icon = Icon::ERROR;
        $this->color = Color::ERROR;
        $this->attitude = Attitude::NEGATIVE;
        parent::__construct($message, $title);
    }
}
