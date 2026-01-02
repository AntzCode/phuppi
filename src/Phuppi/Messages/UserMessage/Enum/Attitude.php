<?php

/**
 * Attitude.php
 *
 * Attitude enum for defining message attitudes in the Phuppi application.
 *
 * @package Phuppi\Messages\UserMessage\Enum
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

declare(strict_types=1);

namespace Phuppi\Messages\UserMessage\Enum;

enum Attitude: string
{
    case POSITIVE = 'positive';
    case NEGATIVE = 'negative';
    case NEUTRAL = 'neutral';
}
