<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * A forward-only position in a byte string. The scanner's and the date
 * pattern's only way to read text: nothing here can move backwards.
 */
final class Cursor
{
    private int $i = 0;

    private readonly int $n;

    public function __construct(private readonly string $text)
    {
        $this->n = strlen($text);
    }

    public function peek(): ?string
    {
        return $this->i < $this->n ? $this->text[$this->i] : null;
    }

    public function advance(): void
    {
        $this->i++;
    }

    public function done(): bool
    {
        return $this->i >= $this->n;
    }

    /** Spaces and tabs only. A newline is never whitespace inside a token (template/tokenize 0020). */
    public function skipWs(): void
    {
        while ($this->i < $this->n && ($this->text[$this->i] === ' ' || $this->text[$this->i] === "\t")) {
            $this->i++;
        }
    }

    /** @param  callable(string): bool  $accept */
    public function takeWhile(callable $accept): string
    {
        $start = $this->i;
        while ($this->i < $this->n && $accept($this->text[$this->i])) {
            $this->i++;
        }

        return substr($this->text, $start, $this->i - $start);
    }
}
