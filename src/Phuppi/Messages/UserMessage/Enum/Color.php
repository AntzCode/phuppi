<?php

declare (strict_types=1);

namespace Phuppi\Messages\UserMessage\Enum;

enum Color: string
{
    case SUCCESS = 'green';
    case ERROR = 'red';
    case WARNING = 'yellow';
    case INFO = 'blue';
}
