<?php

/**
 * Icon.php
 *
 * Icon enum for defining message icons in the Phuppi application.
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

enum Icon: string
{
    case SUCCESS = 'check';
    case ERROR = 'exclamation';
    case WARNING = 'warning';
    case INFO = 'info';
}
