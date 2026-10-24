<?php

declare(strict_types=1);

namespace Zana\Serialization\Attribute;

use Attribute;

/**
 * Denormalize each element of an array parameter into this type.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class ListOf
{
    /**
     * @param class-string|'int'|'float'|'string'|'bool' $type
     */
    public function __construct(public readonly string $type)
    {
    }
}
