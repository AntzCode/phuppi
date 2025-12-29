<?php

declare(strict_types=1);

namespace Phuppi\Messages\UserMessage;

use Phuppi\Messages\UserMessage\Enum\Attitude;
use Phuppi\Messages\UserMessage\Enum\Color;
use Phuppi\Messages\UserMessage\Enum\Icon;
use Phuppi\Messages\UserMessage\Enum\Type;

class SuccessMessage extends \Phuppi\Messages\UserMessage
{
    public function __construct(string $message, string $title = '')
    {
        $this->type = Type::SUCCESS;
        $this->icon = Icon::SUCCESS;
        $this->color = Color::SUCCESS;
        $this->attitude = Attitude::POSITIVE;
        parent::__construct($message, $title);
    }
}
