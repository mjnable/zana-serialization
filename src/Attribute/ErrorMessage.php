<?php

declare(strict_types=1);

namespace Zana\Serialization\Attribute;

use Attribute;

/**
 * Replaces the default denormalization messages for one field:
 *
 *     #[ErrorMessage(invalid: 'Must be a number.', missing: 'Height is required.')]
 *
 * invalid covers every conversion failure of the field itself (wrong type,
 * null, unknown enum case, bad date); errors inside a nested object keep
 * their own messages. missing is used when a required field is absent.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class ErrorMessage
{
    public function __construct(
        public ?string $invalid = null,
        public ?string $missing = null,
    ) {
        if ($invalid === null && $missing === null) {
            throw new \InvalidArgumentException('ErrorMessage needs an invalid or a missing message.');
        }
    }
}
