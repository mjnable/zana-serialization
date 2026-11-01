<?php

declare(strict_types=1);

namespace Zana\Serialization;

use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\ServiceProvider;
use Zana\Http\ExceptionMapperInterface;

/**
 * Registers the Serializer as a singleton. Needs mj-zana/core; the Serializer
 * itself works without it. With mj-zana/http, also the mapper that turns a
 * DenormalizationException into a 422 (ADR-025).
 */
final class SerializationServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services->singleton(Serializer::class);

        if (interface_exists(ExceptionMapperInterface::class)) {
            $services->singleton(DenormalizationExceptionMapper::class)->tag(ExceptionMapperInterface::TAG, DenormalizationExceptionMapper::class);
        }
    }

    public function origin(): string
    {
        return 'package:mj-zana/serialization';
    }
}
