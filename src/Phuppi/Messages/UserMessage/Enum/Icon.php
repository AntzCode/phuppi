<?php

declare(strict_types=1);

namespace Phuppi\Messages\UserMessage\Enum;

enum Icon: string
{
    case SUCCESS = 'check';
    case ERROR = 'exclamation';
    case WARNING = 'warning';
    case INFO = 'info';
}
