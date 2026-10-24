# Zana Serialization

Convert objects to arrays and JSON, and hydrate typed objects from arrays and
JSON through their constructors. No dependencies. Requires PHP 8.5 or later.

```
composer require mj-zana/serialization
```

## Usage

```php
use Zana\Serialization\Serializer;

$serializer = new Serializer();

$json = $serializer->serialize($patient);                       // object → JSON
$patient = $serializer->deserialize($json, Patient::class);     // JSON → object
$array = $serializer->normalize($patient);                      // object → array
$dto = $serializer->denormalize($request->getParsedBody(), RegisterPatient::class);
```

**Normalizing** uses public properties, enum values, ATOM dates and
`JsonSerializable`. **Hydrating** fills constructor parameters by name:

- numeric strings become ints and floats, and `"true"` / `"false"` become booleans;
- enums are read from their value, or their name for unit enums;
- date strings become `DateTimeImmutable`;
- nested classes are hydrated recursively.

Attributes:

- `#[SerializedName('height_cm')]` renames a field;
- `#[Ignore]` leaves a property out;
- `#[ListOf(Item::class)]` hydrates each element of an array.

Every problem is reported at once:

```php
try {
    $serializer->denormalize($input, RegisterPatient::class);
} catch (DenormalizationException $e) {
    $e->errors();   // ['name' => 'This field is missing.', 'items[1].quantity' => 'This value should be of type int.']
}
```

## License

MIT — see [LICENSE](LICENSE).
