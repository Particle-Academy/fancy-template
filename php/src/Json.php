<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * Small JSON-shaped helpers shared by the inspector, validator and renderer.
 */
final class Json
{
    public static function pointer(string $base, string|int $key): string
    {
        return $base.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
    }

    /** A JSON object, as PHP decodes one. `[]` is both, and counts as an empty object here. */
    public static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || ! array_is_list($v));
    }

    public static function isList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v);
    }

    /** Unicode code points, JSON Schema's unit for maxLength. Input is valid UTF-8 (it came from JSON). */
    public static function codePoints(string $s): int
    {
        return strlen($s) - preg_match_all('/[\x80-\xBF]/', $s);
    }

    /**
     * Reduce a host value to plain JSON data (SPEC.md section 4), or null when
     * it cannot be. Goes through the JSON encoder on purpose: that is the one
     * conversion every host already trusts, and after it nothing in the value
     * can call back into host code.
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function plain(mixed $value): array
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 4096);

            return [true, json_decode($json, true, 4096, JSON_THROW_ON_ERROR)];
        } catch (\JsonException) {
            return [false, null];
        }
    }

    /** Pointer of the first container, in document order, above `$limit` levels (the root is level 1). */
    public static function firstTooDeep(mixed $value, int $limit, string $path = '', int $level = 1): ?string
    {
        if (! is_array($value)) {
            return null;
        }
        if ($level > $limit) {
            return $path;
        }
        foreach ($value as $k => $v) {
            $found = self::firstTooDeep($v, $limit, self::pointer($path, $k), $level + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * UTF-8 bytes of every string, object keys included. An object key spelled
     * with digits ("10") is decoded by PHP as the INTEGER key 10, so it is
     * counted from its decimal spelling, which is the key's JSON text. Until
     * phase 6 it was skipped, and this leg allowed what the spec and the other
     * two legs refuse.
     */
    public static function stringBytes(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }
        if (! is_array($value)) {
            return 0;
        }
        $total = 0;
        $isList = array_is_list($value);
        foreach ($value as $k => $v) {
            $total += ($isList ? 0 : strlen((string) $k)) + self::stringBytes($v);
        }

        return $total;
    }

    /** The MIME type of a valid PNG or JPEG data URL (base64 strict, magic bytes checked), else null. */
    public static function imageType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        foreach (['image/png' => "\x89PNG\r\n\x1a\n", 'image/jpeg' => "\xFF\xD8\xFF"] as $mime => $magic) {
            $prefix = "data:{$mime};base64,";
            if (! str_starts_with($value, $prefix)) {
                continue;
            }
            $b64 = substr($value, strlen($prefix));
            if ($b64 === '' || strlen($b64) % 4 !== 0 || strspn($b64, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') !== strlen($b64)) {
                return null;
            }
            $raw = base64_decode($b64, true);

            return $raw !== false && str_starts_with($raw, $magic) ? $mime : null;
        }

        return null;
    }
}
