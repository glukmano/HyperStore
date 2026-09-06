<?php

declare(strict_types=1);

namespace Modules\POS\Exceptions;

use RuntimeException;

/**
 * Owner Delta §9: barcode/SKU has no DB uniqueness guarantee beyond
 * unique(tenant_id, sku) on products — never silently pick the first match.
 */
class AmbiguousBarcodeException extends RuntimeException {}
