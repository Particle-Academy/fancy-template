<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * A writer profile (SPEC.md section 9): which string fields may hold tokens and
 * how a value is encoded there. The profiles are DATA in `profiles/`, shared by
 * every implementation, and pinned to a writer schema version.
 *
 * An allowlist. A field that matches no pattern refuses tokens, so a writer
 * gaining a new field (a link, a font, a URL) is closed to data by default.
 */
final class Profile
{
    /** The encodings this implementation has built. A profile needing any other is not offered. */
    public const IMPLEMENTED_ENCODINGS = ['text', 'band', 'markup', 'markdown', 'cell', 'image'];

    /** @var array<string, self|null> */
    private static array $cache = [];

    /**
     * @param  list<array{pattern: list<string>, encode: string, mime?: list<string>}>  $fields
     */
    private function __construct(public readonly string $writer, private readonly array $fields) {}

    /** The profile for a writer, or null when unknown or not implemented here. */
    public static function for(mixed $writer): ?self
    {
        if (! is_string($writer) || ! preg_match('/^[a-z][a-z-]*$/D', $writer)) {
            return null;
        }
        if (array_key_exists($writer, self::$cache)) {
            return self::$cache[$writer];
        }

        $file = dirname(__DIR__, 2).'/profiles/'.$writer.'.json';
        $profile = null;
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $fields = [];
            $usable = true;
            foreach ($data['fields'] as $field) {
                if (! in_array($field['encode'], self::IMPLEMENTED_ENCODINGS, true)) {
                    $usable = false;
                }
                $fields[] = ['pattern' => explode('.', $field['path'])] + $field;
            }
            $profile = $usable ? new self($writer, $fields) : null;
        }

        return self::$cache[$writer] = $profile;
    }

    /**
     * The field rule for a string at `$segments` inside `document`, or null.
     * First match wins, in the profile's written order.
     *
     * @param  list<string>  $segments
     * @return array{encode: string, mime?: list<string>}|null
     */
    public function field(array $segments): ?array
    {
        foreach ($this->fields as $field) {
            if (self::matches($field['pattern'], 0, $segments, 0)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Glob match: `*` is one segment, `**` zero or more. Patterns are a few
     * segments long and come from this package, never from a template.
     *
     * @param  list<string>  $pattern
     * @param  list<string>  $path
     */
    private static function matches(array $pattern, int $p, array $path, int $s): bool
    {
        if ($p === count($pattern)) {
            return $s === count($path);
        }
        if ($pattern[$p] === '**') {
            for ($k = $s; $k <= count($path); $k++) {
                if (self::matches($pattern, $p + 1, $path, $k)) {
                    return true;
                }
            }

            return false;
        }
        if ($s === count($path)) {
            return false;
        }

        return ($pattern[$p] === '*' || $pattern[$p] === $path[$s]) && self::matches($pattern, $p + 1, $path, $s + 1);
    }
}
