<?php

declare(strict_types=1);

namespace Zana\Serialization;

use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\ServiceProvider;

/**
 * Registers the Serializer as a singleton. Needs mj-zana/core; the Serializer
 * itself works without it.
 */
final class SerializationServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services->singleton(Serializer::class);
    }

    public function origin(): string
    {
        return 'package:mj-zana/serialization';
    }
}
