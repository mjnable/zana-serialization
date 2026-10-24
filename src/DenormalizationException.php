<?php

declare(strict_types=1);

namespace Zana\Serialization;

/**
 * Thrown when input cannot be hydrated into the target type. Lists every problem.
 */
final class DenormalizationException extends SerializationException
{
    /**
     * @param array<string, string> $errors path => message; "" is the input itself
     */
    public function __construct(public readonly string $type, private readonly array $errors)
    {
        $lines = [];
        foreach ($errors as $path => $message) {
            $lines[] = ($path === '' ? '(root)' : $path) . ': ' . $message;
        }

        parent::__construct(sprintf(
            'Could not denormalize %s (%d error%s): %s',
            $type,
            count($errors),
            count($errors) === 1 ? '' : 's',
            implode('; ', $lines)
        ));
    }

    /**
     * @return array<string, string> path => message
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
