<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Exceptions;

use RuntimeException;

/**
 * The base for everything the catalog refuses.
 *
 * A shared base so that a caller can decide to handle "the masters are not
 * set up for this" as one condition, rather than listing subclasses and
 * missing the one added last month.
 */
abstract class CatalogException extends RuntimeException {}
