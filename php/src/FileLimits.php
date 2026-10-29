<?php

declare(strict_types=1);

namespace FancyTemplate;

use InvalidArgumentException;

/**
 * The archive bounds for office files (SPEC.md section 12.8), host-overridable
 * through `options.fileLimits`. Separate from `options.limits` on purpose: the
 * template limits are one table shared by three languages, and a key added there
 * would be accepted by this leg and refused by the others.
 *
 * The defaults are last-word's SourceArchive bounds, so a file one Fancy package
 * accepts is not refused by the next one.
 */
final class FileLimits
{
    public const DEFAULTS = [
        'archiveBytes' => 67108864,      // the file itself
        'entries' => 16384,              // members in the central directory
        'partBytes' => 67108864,         // any one member, uncompressed (declared)
        'expandedBytes' => 268435456,    // every member, uncompressed (declared), together
        'compressionRatio' => 1000,      // uncompressed / compressed, any one member
    ];

    private function __construct(
        public readonly int $archiveBytes,
        public readonly int $entries,
        public readonly int $partBytes,
        public readonly int $expandedBytes,
        public readonly int $compressionRatio,
    ) {}

    /** @param  array<string, mixed>  $options */
    public static function from(array $options): self
    {
        $given = $options['fileLimits'] ?? [];
        if (! is_array($given)) {
            throw new InvalidArgumentException('options.fileLimits must be an object');
        }

        $values = self::DEFAULTS;
        foreach ($given as $name => $value) {
            if (! array_key_exists($name, self::DEFAULTS)) {
                throw new InvalidArgumentException("Unknown file limit `{$name}`");
            }
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException("File limit `{$name}` must be a positive integer");
            }
            $values[$name] = $value;
        }

        return new self(...$values);
    }
}
