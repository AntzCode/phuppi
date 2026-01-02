<?php

/**
 * Type.php
 *
 * Type enum for defining message types in the Phuppi application.
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

enum Type: string
{
    case SUCCESS = 'success';
    case ERROR = 'error';
    case WARNING = 'warning';
    case INFO = 'info';
}
