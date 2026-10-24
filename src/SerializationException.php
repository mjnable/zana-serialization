<?php

declare(strict_types=1);

namespace Zana\Serialization;

/**
 * Thrown when data cannot be normalized or encoded, or JSON cannot be decoded.
 */
class SerializationException extends \RuntimeException
{
}
