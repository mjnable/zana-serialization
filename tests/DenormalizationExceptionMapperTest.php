<?php

declare(strict_types=1);

namespace Zana\Serialization\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Zana\Core\Application;
use Zana\Http\ErrorMiddleware;
use Zana\Http\HttpServiceProvider;
use Zana\Serialization\SerializationServiceProvider;
use Zana\Serialization\Serializer;

/**
 * ADR-025: with mj-zana/http, a missing or mistyped field that reaches HTTP
 * is a 422 with the field errors.
 */
final class DenormalizationExceptionMapperTest extends TestCase
{
    public function testMissingAndMistypedFieldsAreA422(): void
    {
        $scope = Application::configure(sys_get_temp_dir() . '/zana-serialization-mapper')
            ->withEnvironment('testing')
            ->withProviders(HttpServiceProvider::class, SerializationServiceProvider::class)
            ->build()
            ->container()
            ->createScope();
        $errors = $scope->get(ErrorMiddleware::class);
        $serializer = $scope->get(Serializer::class);
        assert($errors instanceof ErrorMiddleware && $serializer instanceof Serializer);

        try {
            $serializer->denormalize(['quantity' => 'many'], Order::class);
            $this->fail('The input is invalid.');
        } catch (\Throwable $e) {
            $response = $errors->render($e, new ServerRequest('POST', '/orders', ['Accept' => 'application/json']));
        }

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['errors']);
        $this->assertEqualsCanonicalizing(['product', 'quantity'], array_keys($body['errors']));
    }
}

final readonly class Order
{
    public function __construct(
        public string $product,
        public int $quantity,
    ) {
    }
}
