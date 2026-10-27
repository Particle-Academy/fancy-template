<?php

declare(strict_types=1);

namespace FancyTemplate;

use InvalidArgumentException;

/**
 * The bounds from SPEC.md section 10 (plan section 5). Every one has a stable
 * error code and every one is host-overridable through `options.limits`.
 */
final class Limits
{
    public const DEFAULTS = [
        'templateBytes' => 262144,
        'tokens' => 10000,
        'tokenLength' => 512,
        'directiveDepth' => 8,
        'iterations' => 10000,
        'outputNodes' => 50000,
        'outputBytes' => 8388608,
        'depth' => 64,
    ];

    private function __construct(
        public readonly int $templateBytes,
        public readonly int $tokens,
        public readonly int $tokenLength,
        public readonly int $directiveDepth,
        public readonly int $iterations,
        public readonly int $outputNodes,
        public readonly int $outputBytes,
        public readonly int $depth,
    ) {}

    /** @param  array<string, mixed>  $options */
    public static function from(array $options): self
    {
        $given = $options['limits'] ?? [];
        if (! is_array($given)) {
            throw new InvalidArgumentException('options.limits must be an object');
        }

        $values = self::DEFAULTS;
        foreach ($given as $name => $value) {
            if (! array_key_exists($name, self::DEFAULTS)) {
                throw new InvalidArgumentException("Unknown limit `{$name}`");
            }
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException("Limit `{$name}` must be a non-negative integer");
            }
            $values[$name] = $value;
        }

        return new self(...$values);
    }
}
