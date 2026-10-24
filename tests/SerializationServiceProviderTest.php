<?php

declare(strict_types=1);

namespace Zana\Serialization\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Core\Application;
use Zana\Serialization\SerializationServiceProvider;
use Zana\Serialization\Serializer;

final class SerializationServiceProviderTest extends TestCase
{
    public function testTheSerializerIsASingleton(): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-serialization-app')
            ->withProviders(SerializationServiceProvider::class)
            ->build()
            ->container();

        $serializer = $container->get(Serializer::class);
        $this->assertInstanceOf(Serializer::class, $serializer);
        $this->assertSame($serializer, $container->createScope()->get(Serializer::class));
    }
}
