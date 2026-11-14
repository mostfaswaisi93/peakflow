<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

use RuntimeException;

/**
 * Base for every failure in the money layer.
 *
 * These are deliberately loud. A money operation that cannot be performed
 * correctly must not return an approximation.
 */
abstract class MoneyException extends RuntimeException {}
