<?php

declare(strict_types=1);

namespace Zana\Serialization;

use Zana\Serialization\Attribute\ErrorMessage;
use Zana\Serialization\Attribute\Ignore;
use Zana\Serialization\Attribute\ListOf;
use Zana\Serialization\Attribute\SerializedName;

/**
 * Converts objects to arrays and JSON, and hydrates typed objects from arrays
 * and JSON through their constructors.
 */
final class Serializer
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** Marks a value that failed to denormalize; its error is already recorded. */
    private readonly object $invalid;

    /** @var array<class-string, list<array{\ReflectionProperty, string}>> */
    private array $properties = [];

    public function __construct()
    {
        $this->invalid = new \stdClass();
    }

    // ── Normalization ──────────────────────────────────────────────────────

    /**
     * @throws SerializationException For resources and circular references
     */
    public function normalize(mixed $data): mixed
    {
        return $this->normalizeValue($data, []);
    }

    /**
     * @throws SerializationException
     */
    public function serialize(mixed $data): string
    {
        try {
            return json_encode($this->normalize($data), self::JSON_FLAGS);
        } catch (\JsonException $e) {
            throw new SerializationException("Cannot encode JSON: {$e->getMessage()}", 0, $e);
        }
    }

    // ── Denormalization ────────────────────────────────────────────────────

    /**
     * @template T of object
     * @param class-string<T>|string $type A class name or int, float, string, bool, array, mixed
     * @return ($type is class-string<T> ? T : mixed)
     * @throws DenormalizationException With every problem found
     */
    public function denormalize(mixed $data, string $type): mixed
    {
        $errors = [];
        $result = $this->denormalizeNamed($data, $type, '', $errors);

        if ($errors !== []) {
            throw new DenormalizationException($type, $errors);
        }

        return $result;
    }

    /**
     * @template T of object
     * @param class-string<T>|string $type
     * @return ($type is class-string<T> ? T : mixed)
     * @throws SerializationException For invalid JSON
     * @throws DenormalizationException
     */
    public function deserialize(string $json, string $type): mixed
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SerializationException("Invalid JSON: {$e->getMessage()}", 0, $e);
        }

        return $this->denormalize($data, $type);
    }

    /**
     * @param array<int, true> $visiting
     */
    private function normalizeValue(mixed $data, array $visiting): mixed
    {
        if ($data === null || is_scalar($data)) {
            return $data;
        }

        if ($data instanceof \BackedEnum) {
            return $data->value;
        }

        if ($data instanceof \UnitEnum) {
            return $data->name;
        }

        if ($data instanceof \DateTimeInterface) {
            return $data->format(\DateTimeInterface::ATOM);
        }

        if (is_resource($data)) {
            throw new SerializationException('Resources cannot be normalized.');
        }

        if (is_object($data)) {
            $id = spl_object_id($data);
            if (isset($visiting[$id])) {
                throw new SerializationException('Circular reference detected while normalizing ' . $data::class . '.');
            }
            $visiting[$id] = true;

            if ($data instanceof \JsonSerializable) {
                return $this->normalizeValue($data->jsonSerialize(), $visiting);
            }

            if ($data instanceof \Traversable) {
                return $this->normalizeIterable($data, $visiting);
            }

            $normalized = [];
            foreach ($this->propertiesOf($data) as [$property, $name]) {
                if ($property->isInitialized($data)) {
                    $normalized[$name] = $this->normalizeValue($property->getValue($data), $visiting);
                }
            }

            return $normalized;
        }

        /** @var array<mixed> $data */
        return $this->normalizeIterable($data, $visiting);
    }

    /**
     * @param iterable<mixed> $items
     * @param array<int, true> $visiting
     * @return array<mixed>
     */
    private function normalizeIterable(iterable $items, array $visiting): array
    {
        $normalized = [];
        foreach ($items as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value, $visiting);
        }

        return $normalized;
    }

    /**
     * Public, non-static properties without #[Ignore], with their serialized names.
     *
     * @return list<array{\ReflectionProperty, string}>
     */
    private function propertiesOf(object $object): array
    {
        return $this->properties[$object::class] ??= array_values(array_map(
            static fn (\ReflectionProperty $p): array => [$p, self::nameOf($p)],
            array_filter(
                (new \ReflectionClass($object))->getProperties(\ReflectionProperty::IS_PUBLIC),
                static fn (\ReflectionProperty $p): bool => !$p->isStatic() && $p->getAttributes(Ignore::class) === []
            )
        ));
    }

    /**
     * @param array<string, string> $errors
     */
    private function denormalizeNamed(mixed $value, string $type, string $path, array &$errors): mixed
    {
        switch (strtolower($type)) {
            case 'mixed':
                return $value;
            case 'int':
                return $this->toInt($value, $path, $errors);
            case 'float':
                return $this->toFloat($value, $path, $errors);
            case 'string':
                return $this->toString($value, $path, $errors);
            case 'bool':
                return $this->toBool($value, $path, $errors);
            case 'array':
            case 'iterable':
                return is_array($value) ? $value : $this->error($path, 'This value should be of type array.', $errors);
            case 'null':
                return $value === null ? null : $this->error($path, 'This value should be null.', $errors);
            case 'true':
            case 'false':
                return $value === ($type === 'true') ? $value : $this->error($path, "This value should be {$type}.", $errors);
            case 'object':
                return is_object($value) ? $value : $this->error($path, 'This value should be of type object.', $errors);
        }

        if (enum_exists($type)) {
            return $this->toEnum($value, $type, $path, $errors);
        }

        if (in_array($type, [\DateTimeInterface::class, \DateTimeImmutable::class, \DateTime::class], true)) {
            return $this->toDateTime($value, $type, $path, $errors);
        }

        if (!class_exists($type)) {
            throw new SerializationException("Cannot denormalize into unknown type {$type}.");
        }

        return $this->toObject($value, $type, $path, $errors);
    }

    /**
     * @param class-string $class
     * @param array<string, string> $errors
     */
    private function toObject(mixed $value, string $class, string $path, array &$errors): mixed
    {
        if (!is_array($value)) {
            return $this->error($path, 'This value should be an object.', $errors);
        }

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null || !$reflection->isInstantiable()) {
            throw new SerializationException("{$class} cannot be denormalized: it needs a public constructor.");
        }

        $arguments = [];
        $failed = false;
        foreach ($constructor->getParameters() as $parameter) {
            $name = self::nameOf($parameter);
            $childPath = $path === '' ? $name : "{$path}.{$name}";

            $messages = ($parameter->getAttributes(ErrorMessage::class)[0] ?? null)?->newInstance();

            if (!array_key_exists($name, $value)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                if ($parameter->allowsNull() && !$parameter->isVariadic()) {
                    $arguments[$parameter->getName()] = null;
                    continue;
                }
                $this->error($childPath, $messages->missing ?? 'This field is missing.', $errors);
                $failed = true;
                continue;
            }

            $converted = $this->denormalizeTyped($value[$name], $parameter, $childPath, $errors);
            if ($converted === $this->invalid) {
                // Only the field's own error is replaced; nested paths keep theirs.
                if ($messages?->invalid !== null && array_key_exists($childPath, $errors)) {
                    $errors[$childPath] = $messages->invalid;
                }
                $failed = true;
                continue;
            }
            $arguments[$parameter->getName()] = $converted;
        }

        return $failed ? $this->invalid : $reflection->newInstanceArgs($arguments);
    }

    /**
     * @param array<string, string> $errors
     */
    private function denormalizeTyped(mixed $value, \ReflectionParameter $parameter, string $path, array &$errors): mixed
    {
        $type = $parameter->getType();
        if ($type === null) {
            return $value;
        }

        if ($value === null) {
            return $type->allowsNull() ? null : $this->error($path, 'This value should not be null.', $errors);
        }

        if ($type instanceof \ReflectionNamedType) {
            $listOf = $parameter->getAttributes(ListOf::class)[0] ?? null;
            if ($listOf !== null && in_array($type->getName(), ['array', 'iterable'], true)) {
                return $this->toList($value, $listOf->newInstance()->type, $path, $errors);
            }

            return $this->denormalizeNamed($value, $type->getName(), $path, $errors);
        }

        if ($type instanceof \ReflectionUnionType) {
            // A value that already has one of the member types is kept as is: PHP
            // reports builtin members in a canonical order, not the declared one.
            foreach ($type->getTypes() as $member) {
                if ($member instanceof \ReflectionNamedType && self::hasType($value, $member->getName())) {
                    return $value;
                }
            }

            foreach ($type->getTypes() as $member) {
                if (!$member instanceof \ReflectionNamedType || $member->getName() === 'null') {
                    continue;
                }

                $attempt = [];
                $result = $this->denormalizeNamed($value, $member->getName(), $path, $attempt);
                if ($attempt === []) {
                    return $result;
                }
            }

            return $this->error($path, "This value does not match {$type}.", $errors);
        }

        return $this->error($path, "Intersection types like {$type} cannot be denormalized.", $errors);
    }

    /**
     * @param array<string, string> $errors
     */
    private function toList(mixed $value, string $type, string $path, array &$errors): mixed
    {
        if (!is_array($value)) {
            return $this->error($path, 'This value should be a list.', $errors);
        }

        $items = [];
        $failed = false;
        foreach ($value as $key => $item) {
            $converted = $this->denormalizeNamed($item, $type, "{$path}[{$key}]", $errors);
            if ($converted === $this->invalid) {
                $failed = true;
            }
            $items[$key] = $converted;
        }

        return $failed ? $this->invalid : $items;
    }

    /**
     * @param array<string, string> $errors
     */
    private function toInt(mixed $value, string $path, array &$errors): mixed
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => $this->error($path, 'This value should be of type int.', $errors),
        };
    }

    /**
     * @param array<string, string> $errors
     */
    private function toFloat(mixed $value, string $path, array &$errors): mixed
    {
        return match (true) {
            is_float($value) => $value,
            is_int($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => $this->error($path, 'This value should be of type float.', $errors),
        };
    }

    /**
     * @param array<string, string> $errors
     */
    private function toString(mixed $value, string $path, array &$errors): mixed
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => $this->error($path, 'This value should be of type string.', $errors),
        };
    }

    /**
     * @param array<string, string> $errors
     */
    private function toBool(mixed $value, string $path, array &$errors): mixed
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default => $this->error($path, 'This value should be of type bool.', $errors),
        };
    }

    /**
     * @param class-string<\UnitEnum> $enum
     * @param array<string, string> $errors
     */
    private function toEnum(mixed $value, string $enum, string $path, array &$errors): mixed
    {
        if (is_subclass_of($enum, \BackedEnum::class)) {
            $case = is_int($value) || is_string($value) ? $enum::tryFrom($value) : null;
        } else {
            $case = is_string($value) && defined("{$enum}::{$value}") ? constant("{$enum}::{$value}") : null;
        }

        if ($case === null) {
            $allowed = array_map(
                static fn (\UnitEnum $c): string => (string) ($c instanceof \BackedEnum ? $c->value : $c->name),
                $enum::cases()
            );

            return $this->error($path, 'The value should be one of: ' . implode(', ', $allowed) . '.', $errors);
        }

        return $case;
    }

    /**
     * @param array<string, string> $errors
     */
    private function toDateTime(mixed $value, string $type, string $path, array &$errors): mixed
    {
        if (!is_string($value) || $value === '') {
            return $this->error($path, 'This value should be a date string.', $errors);
        }

        try {
            return $type === \DateTime::class ? new \DateTime($value) : new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $this->error($path, 'This value is not a valid date.', $errors);
        }
    }

    /**
     * Record an error and return the invalid marker.
     *
     * @param array<string, string> $errors
     */
    private function error(string $path, string $message, array &$errors): object
    {
        $errors[$path] = $message;

        return $this->invalid;
    }

    private static function hasType(mixed $value, string $type): bool
    {
        return match (strtolower($type)) {
            'int' => is_int($value),
            'float' => is_float($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array', 'iterable' => is_array($value),
            'mixed' => true,
            default => is_object($value) && $value instanceof $type,
        };
    }

    private static function nameOf(\ReflectionProperty|\ReflectionParameter $target): string
    {
        $attribute = $target->getAttributes(SerializedName::class)[0] ?? null;

        return $attribute === null ? $target->getName() : $attribute->newInstance()->name;
    }
}
