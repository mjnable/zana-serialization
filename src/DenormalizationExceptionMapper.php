<?php

declare(strict_types=1);

namespace Zana\Serialization;

use Zana\Http\Exception\HttpException;
use Zana\Http\Exception\UnprocessableEntityHttpException;
use Zana\Http\ExceptionMapperInterface;

/**
 * A DenormalizationException that reaches HTTP (a missing or mistyped field)
 * is a 422 with "errors": {path: [message]} (ADR-025). Needs mj-zana/http;
 * SerializationServiceProvider registers it only then.
 */
final readonly class DenormalizationExceptionMapper implements ExceptionMapperInterface
{
    public function map(\Throwable $exception): ?HttpException
    {
        return $exception instanceof DenormalizationException
            ? new UnprocessableEntityHttpException($exception->errors(), previous: $exception)
            : null;
    }
}
