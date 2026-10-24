<?php

declare(strict_types=1);

namespace Zana\Serialization\Attribute;

use Attribute;

/**
 * Use another name for the property in normalized data, e.g. "height_cm".
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class SerializedName
{
    public function __construct(public readonly string $name)
    {
    }
}
