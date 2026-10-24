<?php

declare(strict_types=1);

namespace Zana\Serialization\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Serialization\DenormalizationException;
use Zana\Serialization\SerializationException;
use Zana\Serialization\Serializer;

final class SerializerTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Serializer();
    }

    // ── normalize ──────────────────────────────────────────────────────────

    public function testObjectsBecomeArraysOfPublicProperties(): void
    {
        $patient = new Patient(
            'Ada',
            36,
            Sex::Female,
            Unit::Imperial,
            address: new Address('London'),
            measurements: [new Measurement(165.0, 60.5, new \DateTimeImmutable('2026-10-24T10:00:00+00:00'))],
            internalNote: 'secret',
        );

        $this->assertSame([
            'name' => 'Ada',
            'age' => 36,
            'sex' => 'f',
            'unit' => 'Imperial',
            'active' => true,
            'address' => ['city' => 'London', 'postcode' => null],
            'measurements' => [['height_cm' => 165.0, 'weightKg' => 60.5, 'takenAt' => '2026-10-24T10:00:00+00:00']],
            'scores' => [],
        ], $this->serializer->normalize($patient));
    }

    public function testJsonSerializableIterablesAndScalars(): void
    {
        $this->assertSame(['x' => 1, 'y' => 2], $this->serializer->normalize(new Point(1, 2)));
        $this->assertSame(['a' => ['x' => 1, 'y' => 2]], $this->serializer->normalize(new \ArrayIterator(['a' => new Point(1, 2)])));
        $this->assertSame([3 => 'three'], $this->serializer->normalize([3 => 'three']));
        $this->assertSame(1.5, $this->serializer->normalize(1.5));
        $this->assertNull($this->serializer->normalize(null));
    }

    public function testOnlyInitialisedPublicInstancePropertiesAreNormalized(): void
    {
        $this->assertSame(['visible' => 'yes'], $this->serializer->normalize(new WithPrivateState()));
    }

    public function testResourcesAndCyclesAreRejected(): void
    {
        $a = new Cyclic();
        $a->other = new Cyclic();
        $a->other->other = $a;

        try {
            $this->serializer->normalize($a);
            $this->fail('A cycle must be rejected.');
        } catch (SerializationException $e) {
            $this->assertStringContainsString('Circular reference', $e->getMessage());
        }

        $stream = fopen('php://memory', 'r');
        $this->expectException(SerializationException::class);
        $this->serializer->normalize(['stream' => $stream]);
    }

    // ── denormalize ────────────────────────────────────────────────────────

    public function testObjectsAreHydratedThroughTheirConstructor(): void
    {
        $patient = $this->serializer->denormalize([
            'name' => 'Ada',
            'age' => '36',
            'sex' => 'f',
            'unit' => 'Imperial',
            'active' => 'false',
            'address' => ['city' => 'London'],
            'measurements' => [['height_cm' => '165', 'weightKg' => 60, 'takenAt' => '2026-10-24 10:00']],
            'scores' => ['1', 2],
            'unknown' => 'ignored',
        ], Patient::class);

        $this->assertSame(['Ada', 36, Sex::Female, Unit::Imperial, false], [$patient->name, $patient->age, $patient->sex, $patient->unit, $patient->active]);
        $this->assertNotNull($patient->address);
        $this->assertSame('London', $patient->address->city);
        $this->assertNull($patient->address->postcode);
        $this->assertSame(165.0, $patient->measurements[0]->heightCm);
        $this->assertSame(60.0, $patient->measurements[0]->weightKg, 'ints are accepted for floats');
        $this->assertSame('2026-10-24 10:00', $patient->measurements[0]->takenAt->format('Y-m-d H:i'));
        $this->assertSame([1, 2], $patient->scores);
    }

    public function testDefaultsAndNullablesFillMissingFields(): void
    {
        $patient = $this->serializer->denormalize(['name' => 'Ada', 'age' => 36, 'sex' => 'm'], Patient::class);

        $this->assertSame([Unit::Metric, true, null, []], [$patient->unit, $patient->active, $patient->address, $patient->measurements]);
        $this->assertNull($this->serializer->denormalize(['code' => 'A1'], Flexible::class)->ratio);
    }

    public function testEveryErrorIsCollectedWithItsPath(): void
    {
        try {
            $this->serializer->denormalize([
                'age' => 'thirty',
                'sex' => 'z',
                'active' => 'maybe',
                'address' => ['postcode' => 12],
                'measurements' => [['height_cm' => 1, 'weightKg' => 2, 'takenAt' => '2026-01-01'], ['height_cm' => 'tall', 'takenAt' => 'not a date']],
                'scores' => [1, 'x'],
            ], Patient::class);
            $this->fail('Denormalization should fail.');
        } catch (DenormalizationException $e) {
            $this->assertSame([
                'name' => 'This field is missing.',
                'age' => 'This value should be of type int.',
                'sex' => 'The value should be one of: f, m.',
                'active' => 'This value should be of type bool.',
                'address.city' => 'This field is missing.',
                'measurements[1].height_cm' => 'This value should be of type float.',
                'measurements[1].weightKg' => 'This field is missing.',
                'measurements[1].takenAt' => 'This value is not a valid date.',
                'scores[1]' => 'This value should be of type int.',
            ], $e->errors());
            $this->assertSame(Patient::class, $e->type);
            $this->assertStringStartsWith('Could not denormalize ' . Patient::class . ' (9 errors): name: This field is missing.', $e->getMessage());
        }
    }

    public function testUnionTypesKeepMatchingValuesThenTryConversions(): void
    {
        $this->assertSame(42, $this->serializer->denormalize(['code' => 42], Flexible::class)->code);
        $this->assertSame('42', $this->serializer->denormalize(['code' => '42'], Flexible::class)->code, 'a string stays a string');
        $this->assertSame('1.5', $this->serializer->denormalize(['code' => 1.5], Flexible::class)->code, 'converted to the first member that accepts it');
        $this->assertSame(['nested' => true], $this->serializer->denormalize(['code' => 1, 'anything' => ['nested' => true]], Flexible::class)->anything);

        $this->expectException(DenormalizationException::class);
        $this->expectExceptionMessage('code: This value does not match string|int.');
        $this->serializer->denormalize(['code' => [1]], Flexible::class);
    }

    public function testNullIsOnlyAcceptedForNullableTypes(): void
    {
        $this->expectException(DenormalizationException::class);
        $this->expectExceptionMessage('name: This value should not be null.');

        $this->serializer->denormalize(['name' => null, 'age' => 1, 'sex' => 'f'], Patient::class);
    }

    public function testBuiltinTargetsAndConversions(): void
    {
        $this->assertSame(5, $this->serializer->denormalize('5', 'int'));
        $this->assertSame(2.5, $this->serializer->denormalize('2.5', 'float'));
        $this->assertSame('7', $this->serializer->denormalize(7, 'string'));
        $this->assertSame('true', $this->serializer->denormalize(true, 'string'));
        $this->assertTrue($this->serializer->denormalize(1, 'bool'));
        $this->assertSame([1], $this->serializer->denormalize([1], 'array'));
        $this->assertSame(Sex::Male, $this->serializer->denormalize('m', Sex::class));
        $this->assertSame(Unit::Metric, $this->serializer->denormalize('Metric', Unit::class));

        try {
            $this->serializer->denormalize(1.5, 'int');
            $this->fail('Floats are not ints.');
        } catch (DenormalizationException $e) {
            $this->assertSame(['' => 'This value should be of type int.'], $e->errors());
            $this->assertStringContainsString('(root): This value should be of type int.', $e->getMessage());
        }
    }

    public function testClassesMustBeHydratableFromArrays(): void
    {
        try {
            $this->serializer->denormalize('Ada', Patient::class);
            $this->fail('A string is not an object.');
        } catch (DenormalizationException $e) {
            $this->assertSame(['' => 'This value should be an object.'], $e->errors());
        }

        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('needs a public constructor');
        $this->serializer->denormalize([], NoConstructor::class);
    }

    public function testUnknownTypesAreRejected(): void
    {
        $this->expectException(SerializationException::class);

        $this->serializer->denormalize([], 'App\\Missing');
    }

    // ── JSON ───────────────────────────────────────────────────────────────

    public function testJsonRoundTrip(): void
    {
        $patient = new Patient('Zoë', 36, Sex::Female, address: new Address('Bruxelles/Brussel'));

        $json = $this->serializer->serialize($patient);

        $this->assertStringContainsString('"name":"Zoë"', $json, 'unicode is not escaped');
        $this->assertStringContainsString('Bruxelles/Brussel', $json, 'slashes are not escaped');
        $this->assertEquals($patient, $this->serializer->deserialize($json, Patient::class));
    }

    public function testInvalidJsonIsReported(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->serializer->deserialize('{"name":', Patient::class);
    }

    public function testUnencodableDataIsReported(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Cannot encode JSON');

        $this->serializer->serialize(['bad' => "\xB1\x31"]);
    }
}
