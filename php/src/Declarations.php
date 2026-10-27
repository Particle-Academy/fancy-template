<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * Variable declarations: checking them (inspect) and checking data against
 * them (validateData). SPEC.md sections 3 and 4.
 */
final class Declarations
{
    public const TYPES = ['string', 'number', 'integer', 'boolean', 'date', 'image', 'array', 'object'];

    private const KEYS = ['type', 'required', 'default', 'example', 'description', 'enum', 'maxLength', 'maxItems', 'items', 'properties'];

    /**
     * Issues in one declaration, its own keys before its nested declarations.
     *
     * @param  list<array{code: string, path: string}>  $issues
     */
    public static function inspect(mixed $decl, string $path, array &$issues): void
    {
        if (! Json::isObject($decl) || $decl === []) {
            $issues[] = ['code' => 'DECL_INVALID', 'path' => $path];

            return;
        }
        if (! array_key_exists('type', $decl)) {
            $issues[] = ['code' => 'DECL_INVALID', 'path' => $path];

            return;
        }
        $type = $decl['type'];
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            $issues[] = ['code' => 'DECL_INVALID', 'path' => Json::pointer($path, 'type')];

            return;
        }

        $nested = [];
        foreach ($decl as $key => $value) {
            $at = Json::pointer($path, $key);
            $bad = match ($key) {
                'type' => false,
                'required' => ! is_bool($value),
                'description' => ! is_string($value),
                'enum' => ! in_array($type, ['string', 'number', 'integer'], true) || ! Json::isList($value) || $value === []
                    || array_filter($value, static fn ($v): bool => self::typeOk($type, $v)) !== $value,
                'maxLength' => ! in_array($type, ['string', 'date', 'image'], true) || ! self::isCount($value),
                'maxItems' => $type !== 'array' || ! self::isCount($value),
                'items' => $type !== 'array',
                'properties' => $type !== 'object' || ! Json::isObject($value),
                'default', 'example' => self::validate($value, $decl, '') !== [],
                default => true,
            };
            if ($bad) {
                $issues[] = ['code' => 'DECL_INVALID', 'path' => $at];

                continue;
            }
            if ($key === 'items') {
                $nested[] = [$value, $at];
            }
            if ($key === 'properties') {
                foreach ($value as $name => $sub) {
                    if (! Scanner::isName((string) $name)) {
                        $issues[] = ['code' => 'DECL_INVALID', 'path' => Json::pointer($at, $name)];

                        continue;
                    }
                    $nested[] = [$sub, Json::pointer($at, $name)];
                }
            }
        }

        if (($type === 'array' && ! array_key_exists('items', $decl)) || ($type === 'object' && ! array_key_exists('properties', $decl))) {
            $issues[] = ['code' => 'DECL_INVALID', 'path' => $path];
        }

        foreach ($nested as [$sub, $at]) {
            self::inspect($sub, $at, $issues);
        }
    }

    /** Whether a declaration passed inspect, so that validation may lean on its shape. */
    public static function usable(mixed $decl): bool
    {
        $issues = [];
        self::inspect($decl, '', $issues);

        return $issues === [];
    }

    /**
     * Validate one PRESENT value against a declaration. Returns errors (data
     * pointers relative to `$path`); defaults are applied by applyDefaults().
     *
     * @param  array<string, mixed>  $decl
     * @return list<array{path: string, code: string}>
     */
    public static function validate(mixed $value, array $decl, string $path): array
    {
        $type = $decl['type'];
        if (! self::typeOk($type, $value)) {
            return [['path' => $path, 'code' => 'DATA_TYPE']];
        }
        if ($type === 'date' && Dates::parse($value) === null) {
            return [['path' => $path, 'code' => 'DATA_FORMAT']];
        }
        if ($type === 'image' && Json::imageType($value) === null) {
            return [['path' => $path, 'code' => 'DATA_FORMAT']];
        }

        $errors = [];
        if (isset($decl['enum']) && ! self::inEnum($value, $decl['enum'])) {
            $errors[] = ['path' => $path, 'code' => 'DATA_ENUM'];
        }
        if (isset($decl['maxLength']) && is_string($value) && Json::codePoints($value) > $decl['maxLength']) {
            $errors[] = ['path' => $path, 'code' => 'DATA_MAX_LENGTH'];
        }
        if ($type === 'array') {
            if (isset($decl['maxItems']) && count($value) > $decl['maxItems']) {
                $errors[] = ['path' => $path, 'code' => 'DATA_MAX_ITEMS'];
            }
            if (is_array($decl['items'] ?? null)) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...self::validate($item, $decl['items'], Json::pointer($path, $i)));
                }
            }
        }
        if ($type === 'object' && is_array($decl['properties'] ?? null)) {
            array_push($errors, ...self::validateMembers($value, $decl['properties'], $path));
        }

        return $errors;
    }

    /**
     * Validate an object's declared members, in declaration order.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $members
     * @return list<array{path: string, code: string}>
     */
    public static function validateMembers(array $object, array $members, string $path): array
    {
        $errors = [];
        foreach ($members as $name => $decl) {
            if (! is_array($decl) || ! self::usable($decl)) {
                continue;
            }
            $at = Json::pointer($path, $name);
            if (! array_key_exists($name, $object)) {
                if (! array_key_exists('default', $decl) && ($decl['required'] ?? false) === true) {
                    $errors[] = ['path' => $at, 'code' => 'DATA_REQUIRED'];
                }

                continue;
            }
            array_push($errors, ...self::validate($object[$name], $decl, $at));
        }

        return $errors;
    }

    /**
     * Fill absent members that declare a default, recursively (SPEC.md section 4).
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $members
     * @return array<string, mixed>
     */
    public static function applyDefaults(array $object, array $members): array
    {
        foreach ($members as $name => $decl) {
            if (! is_array($decl)) {
                continue;
            }
            if (! array_key_exists($name, $object) && array_key_exists('default', $decl)) {
                $object[$name] = $decl['default'];
            }
            if (! array_key_exists($name, $object)) {
                continue;
            }
            $type = $decl['type'] ?? null;
            if ($type === 'object' && is_array($decl['properties'] ?? null) && Json::isObject($object[$name])) {
                $object[$name] = self::applyDefaults($object[$name], $decl['properties']);
            }
            if ($type === 'array' && is_array($decl['items'] ?? null) && ($decl['items']['type'] ?? null) === 'object'
                && is_array($decl['items']['properties'] ?? null) && Json::isList($object[$name])) {
                foreach ($object[$name] as $i => $item) {
                    if (Json::isObject($item)) {
                        $object[$name][$i] = self::applyDefaults($item, $decl['items']['properties']);
                    }
                }
            }
        }

        return $object;
    }

    /**
     * A non-negative integer as SPEC.md section 3 defines integer: an integral
     * VALUE, so `3.0` counts. Until phase 6 this leg required a PHP int, which
     * JavaScript cannot even express the difference from.
     */
    private static function isCount(mixed $v): bool
    {
        return (is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v)) && $v >= 0;
    }

    public static function typeOk(string $type, mixed $v): bool
    {
        return match ($type) {
            'string', 'date', 'image' => is_string($v),
            'number' => is_int($v) || (is_float($v) && is_finite($v)),
            'integer' => is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v),
            'boolean' => is_bool($v),
            'array' => Json::isList($v),
            'object' => Json::isObject($v),
            default => false,
        };
    }

    /** @param  list<mixed>  $enum */
    private static function inEnum(mixed $value, array $enum): bool
    {
        foreach ($enum as $candidate) {
            if (is_string($value) && is_string($candidate) && $value === $candidate) {
                return true;
            }
            if ((is_int($value) || is_float($value)) && (is_int($candidate) || is_float($candidate)) && $value == $candidate) {
                return true;
            }
        }

        return false;
    }
}
