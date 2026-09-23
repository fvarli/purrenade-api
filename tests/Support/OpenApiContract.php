<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks a live response body against a schema in `docs/api/openapi.draft.yaml`.
 *
 * The document is the only wire contract the frontend generates its types
 * from, so a response that drifts from it is a frontend type error waiting to
 * happen — the tutorial envelope drifted exactly that way at M8. This pins the
 * implemented responses to the document.
 *
 * A deliberately small subset of JSON Schema, covering what the document
 * actually uses for these responses: `$ref`, `type` (including nullable type
 * lists), `required`, `properties`, `items`, `enum`, `minimum`, `maximum` and
 * `format: uuid`. One rule is **stricter** than JSON Schema: an object may not
 * carry a member its schema does not declare. An undeclared member is exactly
 * the kind of drift this exists to catch — and it is how an ANTI-6 field would
 * leak into a response without anybody noticing.
 */
final class OpenApiContract
{
    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    /**
     * @return list<string> Violations, empty when the value conforms.
     */
    public static function violations(mixed $value, string $schemaName): array
    {
        $errors = [];
        self::check($value, ['$ref' => '#/components/schemas/'.$schemaName], '$', $errors);

        return $errors;
    }

    /**
     * The schema of a response body, by path, method and status — resolved to
     * the component it points at, including an envelope's `data` member.
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(string $path, string $method, int $status): array
    {
        $document = self::document();
        $operation = $document['paths'][$path][strtolower($method)] ?? null;

        if (! is_array($operation)) {
            throw new RuntimeException("No operation {$method} {$path} in the contract.");
        }

        $schema = $operation['responses'][(string) $status]['content']['application/json']['schema'] ?? null;

        if (! is_array($schema)) {
            throw new RuntimeException("No JSON response {$status} for {$method} {$path} in the contract.");
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function violationsAgainst(mixed $value, array $schema): array
    {
        $errors = [];
        self::check($value, $schema, '$', $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private static function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['$ref'])) {
            $schema = self::resolve((string) $schema['$ref']);
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $part) {
                self::check($value, $part, $path, $errors);
            }
        }

        $types = isset($schema['type']) ? (array) $schema['type'] : [];

        if ($types !== [] && ! self::matchesAnyType($value, $types)) {
            $errors[] = "{$path}: expected ".implode('|', $types).', got '.get_debug_type($value);

            return;
        }

        if ($value === null) {
            return;
        }

        if (isset($schema['enum']) && ! in_array($value, (array) $schema['enum'], true)) {
            $errors[] = "{$path}: ".json_encode($value).' is not one of '.json_encode($schema['enum']);
        }

        if (is_int($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: {$value} is below minimum {$schema['minimum']}";
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: {$value} is above maximum {$schema['maximum']}";
            }
        }

        if (is_string($value) && ($schema['format'] ?? null) === 'uuid'
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
            $errors[] = "{$path}: '{$value}' is not a lower-case UUID";
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                self::check($item, $schema['items'], "{$path}[{$index}]", $errors);
            }

            return;
        }

        if (is_array($value) && (in_array('object', $types, true) || isset($schema['properties']))) {
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'] ?? [];

            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = "{$path}: missing required member '{$required}'";
                }
            }

            foreach ($value as $key => $member) {
                if (! isset($properties[$key])) {
                    $errors[] = "{$path}: undeclared member '{$key}'";

                    continue;
                }

                self::check($member, $properties[$key], "{$path}.{$key}", $errors);
            }
        }
    }

    /**
     * @param  list<string>  $types
     */
    private static function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'null' => $value === null,
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                // An empty PHP array decodes from `{}` or `[]` alike.
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolve(string $ref): array
    {
        $node = self::document();

        foreach (explode('/', substr($ref, 2)) as $segment) {
            if (! is_array($node) || ! isset($node[$segment])) {
                throw new RuntimeException("Unresolvable \$ref {$ref}");
            }

            $node = $node[$segment];
        }

        return (array) $node;
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        return self::$document ??= Yaml::parseFile(dirname(__DIR__, 2).'/docs/api/openapi.draft.yaml');
    }
}
