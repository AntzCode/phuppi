<?php

declare(strict_types=1);

namespace Phuppi\Messages\UserMessage\Enum;

enum Attitude: string
{
    case POSITIVE = 'positive';
    case NEGATIVE = 'negative';
    case NEUTRAL = 'neutral';
}
