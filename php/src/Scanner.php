<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * Splits one string into text and `{{ }}` tokens (SPEC.md section 5).
 *
 * Hand-written and single-pass: every index only moves forward, and no regular
 * expression ever runs over template text (plan section 5). Template text is
 * authored by admins and agents and arrives over the wire, and a backtracking
 * regex over it is a denial of service waiting for the right string.
 *
 * The search for a token's closing `}}` is BOUNDED by the token-length limit
 * (template/tokenize 0027): a `{{` followed by a megabyte of anything costs
 * `tokenLength` bytes of work to refuse, not a megabyte.
 *
 * Errors are thrown as TemplateError with an empty path; the caller knows which
 * field it was scanning and re-throws with the pointer.
 */
final class Scanner
{
    public const LOOP_HEADS = ['$index', '$first', '$last'];

    /**
     * @return list<array<string, mixed>> parts: {text} | {path, filters} | {literal, filters}
     */
    public static function tokenize(string $text, int $tokenLength): array
    {
        $parts = [];
        $buffer = '';
        $n = strlen($text);
        $i = 0;

        while ($i < $n) {
            $open = strpos($text, '{{', $i);
            if ($open === false) {
                $buffer .= substr($text, $i);
                break;
            }
            $buffer .= substr($text, $i, $open - $i);

            $start = $open + 2;
            $close = self::findClose($text, $start, $n, $tokenLength);
            $token = self::parseToken(substr($text, $start, $close - $start));

            if ($buffer !== '') {
                $parts[] = ['text' => $buffer];
                $buffer = '';
            }
            $parts[] = $token;
            $i = $close + 2;
        }

        if ($buffer !== '') {
            $parts[] = ['text' => $buffer];
        }

        return $parts;
    }

    /** How many tokens a string opens, stopping quietly at the first error (that is reported elsewhere). */
    public static function count(string $text, int $tokenLength): int
    {
        if (! str_contains($text, '{{')) {
            return 0;
        }
        $count = 0;
        $n = strlen($text);
        $i = 0;
        while (($open = strpos($text, '{{', $i)) !== false) {
            $count++;
            try {
                $i = self::findClose($text, $open + 2, $n, $tokenLength) + 2;
            } catch (TemplateError) {
                break;
            }
        }

        return $count;
    }

    /** Index of the `}}` that closes a token whose inner text starts at $start. */
    private static function findClose(string $text, int $start, int $n, int $limit): int
    {
        $j = $start;
        for (;;) {
            if ($j - $start > $limit) {
                throw new TemplateError('TOKEN_TOO_LONG', '');
            }
            if ($j >= $n) {
                throw new TemplateError('TOKEN_UNCLOSED', '');
            }
            $c = $text[$j];
            if ($c === '"') {
                // A string literal: `}}` inside it does not close the token
                // (template/tokenize 0013). Escapes skip one byte.
                $j++;
                while ($j < $n && $text[$j] !== '"') {
                    if ($j - $start > $limit) {
                        throw new TemplateError('TOKEN_TOO_LONG', '');
                    }
                    $j += $text[$j] === '\\' ? 2 : 1;
                }
                if ($j >= $n) {
                    if ($j - $start > $limit) {
                        throw new TemplateError('TOKEN_TOO_LONG', '');
                    }
                    throw new TemplateError('TOKEN_UNCLOSED', '');
                }
                $j++;

                continue;
            }
            if ($c === '}' && $j + 1 < $n && $text[$j + 1] === '}') {
                return $j;
            }
            $j++;
        }
    }

    /** @return array<string, mixed> */
    private static function parseToken(string $inner): array
    {
        if (trim($inner, " \t") === '') {
            throw new TemplateError('TOKEN_EMPTY', '');
        }

        $p = new Cursor($inner);
        $p->skipWs();

        if ($p->peek() === '"') {
            $token = ['literal' => self::string($p)];
        } else {
            $token = ['path' => self::path($p)];
        }

        $filters = [];
        for (;;) {
            $p->skipWs();
            if ($p->done()) {
                break;
            }
            if ($p->peek() !== '|') {
                throw new TemplateError('TOKEN_SYNTAX', '');
            }
            $p->advance();
            $p->skipWs();
            $name = $p->takeWhile(static fn (string $c): bool => $c >= 'a' && $c <= 'z');
            if ($name === '') {
                throw new TemplateError('TOKEN_SYNTAX', '');
            }
            $filter = ['name' => $name];
            $p->skipWs();
            if ($p->peek() === ':') {
                $p->advance();
                $p->skipWs();
                if ($p->peek() === '"') {
                    $filter['arg'] = self::string($p);
                } else {
                    $arg = $p->takeWhile(static fn (string $c): bool => ctype_alnum($c) || $c === '_' || $c === '.' || $c === '+' || $c === '-');
                    if ($arg === '') {
                        throw new TemplateError('TOKEN_SYNTAX', '');
                    }
                    $filter['arg'] = $arg;
                }
            }
            $filters[] = $filter;
        }

        $token['filters'] = $filters;

        return $token;
    }

    /**
     * A path for a token or for `$each` (SPEC.md section 5).
     *
     * @return list<string>
     */
    public static function path(Cursor $p): array
    {
        $segments = [];
        if ($p->peek() === '$') {
            $p->advance();
            $head = '$'.$p->takeWhile(self::isNameChar(...));
            if (! in_array($head, self::LOOP_HEADS, true)) {
                throw new TemplateError('TOKEN_SYNTAX', '');
            }
            $segments[] = $head;
        } else {
            $head = self::name($p);
            if ($head === null) {
                throw new TemplateError('TOKEN_SYNTAX', '');
            }
            $segments[] = $head;
        }

        while ($p->peek() === '.') {
            $p->advance();
            $digits = $p->takeWhile(static fn (string $c): bool => $c >= '0' && $c <= '9');
            if ($digits !== '') {
                $segments[] = $digits;

                continue;
            }
            $name = self::name($p);
            if ($name === null) {
                throw new TemplateError('TOKEN_SYNTAX', '');
            }
            $segments[] = $name;
        }

        return $segments;
    }

    /** Parse a whole string as a bare path, as `$each` takes one. Null if it is not one. */
    public static function pathOnly(string $text): ?array
    {
        $p = new Cursor($text);
        $p->skipWs();
        try {
            $path = self::path($p);
        } catch (TemplateError) {
            return null;
        }
        $p->skipWs();

        return $p->done() ? $path : null;
    }

    public static function isName(string $s): bool
    {
        if ($s === '' || ! (ctype_alpha($s[0]) || $s[0] === '_')) {
            return false;
        }
        $n = strlen($s);
        for ($i = 1; $i < $n; $i++) {
            if (! self::isNameChar($s[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function name(Cursor $p): ?string
    {
        $c = $p->peek();
        if ($c === null || ! (ctype_alpha($c) || $c === '_')) {
            return null;
        }

        return $p->takeWhile(self::isNameChar(...));
    }

    private static function isNameChar(string $c): bool
    {
        return ctype_alnum($c) || $c === '_';
    }

    private static function string(Cursor $p): string
    {
        $p->advance(); // opening quote
        $out = '';
        for (;;) {
            $c = $p->peek();
            if ($c === null) {
                throw new TemplateError('TOKEN_SYNTAX', '');
            }
            $p->advance();
            if ($c === '"') {
                return $out;
            }
            if ($c === '\\') {
                $next = $p->peek();
                if ($next !== '"' && $next !== '\\') {
                    throw new TemplateError('TOKEN_SYNTAX', '');
                }
                $p->advance();
                $out .= $next;

                continue;
            }
            $out .= $c;
        }
    }
}
