<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * The fixed filter list (SPEC.md section 7). Not extensible from a template,
 * and not from a host either in v1: every filter is a conformance-pinned
 * behaviour, and a host-registered one would be a fourth dialect.
 */
final class Filters
{
    public const NAMES = ['default', 'upper', 'lower', 'number', 'money', 'date', 'join'];

    /** Static check of one filter. Null when fine, else the issue code. */
    public static function check(array $filter): ?string
    {
        $name = $filter['name'];
        if (! in_array($name, self::NAMES, true)) {
            return 'FILTER_UNKNOWN';
        }
        $arg = $filter['arg'] ?? null;

        $ok = match ($name) {
            'default' => $arg !== null,
            'upper', 'lower' => $arg === null,
            'number' => $arg !== null && ctype_digit($arg) && strlen($arg) <= 2 && (int) $arg <= 10,
            'money' => $arg !== null && isset(Numbers::CURRENCIES[$arg]),
            'date' => $arg !== null && Dates::compile($arg) !== null,
            'join' => true,
        };

        return $ok ? null : 'FILTER_ARG';
    }

    /**
     * Apply one filter. `$value` may be Absent::Value. Throws TemplateError with an
     * empty path; the renderer adds the field's pointer.
     */
    public static function apply(array $filter, mixed $value): mixed
    {
        $name = $filter['name'];
        $arg = $filter['arg'] ?? null;

        if ($name === 'default') {
            return $value === Absent::Value || $value === null ? $arg : $value;
        }
        if ($value === Absent::Value) {
            throw new TemplateError('TEMPLATE_MISSING', '');
        }

        return match ($name) {
            'upper' => strtoupper(self::scalarText($value)),
            'lower' => strtolower(self::scalarText($value)),
            'number' => (is_int($value) || is_float($value)) ? Numbers::fixed($value, (int) $arg) : self::input(),
            'money' => self::money($value, (string) $arg),
            'date' => self::date($value, (string) $arg),
            'join' => self::join($value, $arg ?? ', '),
        };
    }

    /** A scalar as text (SPEC.md section 6). Arrays and objects are not scalars. */
    public static function scalarText(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => Numbers::toJsString($value),
            default => throw new TemplateError('TEMPLATE_NOT_SCALAR', ''),
        };
    }

    private static function money(mixed $value, string $currency): string
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            $value = (int) $value;
        }
        if (! is_int($value)) {
            self::input();
        }

        return Numbers::money($value, $currency);
    }

    private static function date(mixed $value, string $pattern): string
    {
        $date = Dates::parse($value);
        if ($date === null) {
            self::input();
        }

        return Dates::format($date, Dates::compile($pattern));
    }

    private static function join(mixed $value, string $separator): string
    {
        if (! Json::isList($value)) {
            self::input();
        }
        $parts = [];
        foreach ($value as $item) {
            if (is_array($item) || $item === null) {
                self::input();
            }
            $parts[] = self::scalarText($item);
        }

        return implode($separator, $parts);
    }

    private static function input(): never
    {
        throw new TemplateError('FILTER_INPUT', '');
    }
}
