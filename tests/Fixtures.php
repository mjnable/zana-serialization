<?php

declare(strict_types=1);

namespace Zana\Serialization\Tests;

use Zana\Serialization\Attribute\Ignore;
use Zana\Serialization\Attribute\ListOf;
use Zana\Serialization\Attribute\SerializedName;

enum Sex: string
{
    case Female = 'f';
    case Male = 'm';
}

enum Unit
{
    case Metric;
    case Imperial;
}

final readonly class Address
{
    public function __construct(public string $city, public ?string $postcode = null)
    {
    }
}

final readonly class Measurement
{
    public function __construct(
        #[SerializedName('height_cm')] public float $heightCm,
        public float $weightKg,
        public \DateTimeImmutable $takenAt,
    ) {
    }
}

final readonly class Patient
{
    /**
     * @param list<Measurement> $measurements
     * @param list<int> $scores
     */
    public function __construct(
        public string $name,
        public int $age,
        public Sex $sex,
        public Unit $unit = Unit::Metric,
        public bool $active = true,
        public ?Address $address = null,
        #[ListOf(Measurement::class)] public array $measurements = [],
        #[ListOf('int')] public array $scores = [],
        #[Ignore] public string $internalNote = '',
    ) {
    }
}

final readonly class Flexible
{
    public function __construct(public int|string $code, public mixed $anything = null, public ?float $ratio = null)
    {
    }
}

final class Point implements \JsonSerializable
{
    public function __construct(private readonly int $x, private readonly int $y)
    {
    }

    /**
     * @return array{x: int, y: int}
     */
    public function jsonSerialize(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}

final class WithPrivateState
{
    public string $visible = 'yes';

    private string $hidden = 'no';

    public static string $static = 'skip';

    public string $uninitialised;

    public function hidden(): string
    {
        return $this->hidden;
    }
}

final class Cyclic
{
    public ?Cyclic $other = null;
}

final class NoConstructor
{
}
