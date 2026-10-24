<?php

declare(strict_types=1);

namespace Zana\Serialization\Attribute;

use Attribute;

/**
 * Leave the property out of normalized data.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Ignore
{
}
