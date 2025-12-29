<?php

declare(strict_types=1);

namespace Phuppi\Messages\UserMessage\Enum;

enum Type: string
{
    case SUCCESS = 'success';
    case ERROR = 'error';
    case WARNING = 'warning';
    case INFO = 'info';
}
