<?php

/**
 * Color.php
 *
 * Color enum for defining message colors in the Phuppi application.
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

enum Color: string
{
    case SUCCESS = 'green';
    case ERROR = 'red';
    case WARNING = 'yellow';
    case INFO = 'blue';
}
