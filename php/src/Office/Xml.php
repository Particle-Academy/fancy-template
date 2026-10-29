<?php

declare(strict_types=1);

namespace FancyTemplate\Office;

use FancyTemplate\TemplateError;

/**
 * A forward-only XML lexer that reports every element WITH ITS BYTE OFFSETS
 * (SPEC.md section 12.7).
 *
 * Offsets are the point: a filled part is the original bytes with only the
 * text of the touched runs replaced, and no DOM can say where a node sits in
 * the source or write the rest back unchanged. So this reads the part once,
 * left to right, and the filler splices by offset.
 *
 * It is not a validating parser, and it refuses rather than guesses:
 *
 *   - a DOCTYPE, or any other `<!` declaration, is FILE_DOCTYPE / FILE_XML: no
 *     entity is ever defined, so no entity is ever expanded, and nothing is
 *     ever fetched (there is no DTD to fetch);
 *   - an encoding other than UTF-8 is FILE_XML (OPC allows UTF-16; no office
 *     suite writes it, and the offsets here are UTF-8 bytes);
 *   - mismatched or unclosed tags, an unquoted or `<`-holding attribute, an
 *     unbound prefix, an unknown entity, or a character reference to a
 *     character XML cannot hold are FILE_XML.
 *
 * Every index only moves forward and every search is a strpos/strcspn; there is
 * no regular expression over the part.
 *
 * @internal
 */
final class Xml
{
    public const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * Walk a part. `$start(uri, local, attrs, tagStart, tagEnd, depth, empty)`
     * where attrs maps the attribute's qualified name to its decoded value and
     * tagEnd is just past `>`; `$end(uri, local, tagStart, tagEnd, depth)` for
     * the closing tag (for an empty element, both offsets are the start tag's end).
     *
     * @throws TemplateError FILE_XML, FILE_DOCTYPE
     */
    public static function walk(string $xml, string $part, callable $start, callable $end): void
    {
        $n = strlen($xml);
        $i = str_starts_with($xml, "\xEF\xBB\xBF") ? 3 : 0;
        if (str_starts_with($xml, "\xFE\xFF") || str_starts_with($xml, "\xFF\xFE")) {
            throw new TemplateError('FILE_XML', $part);
        }
        /** @var list<array{0: string, 1: string, 2: string, 3: array<string, string>}> $stack qname, uri, local, ns map */
        $stack = [];
        $ns = ['xml' => self::XML_NS, '' => ''];
        $sawRoot = false;

        while ($i < $n) {
            $lt = strpos($xml, '<', $i);
            if ($lt === false) {
                break;
            }
            $next = $xml[$lt + 1] ?? '';
            if ($next === '?') {
                $close = strpos($xml, '?>', $lt + 2);
                if ($close === false) {
                    throw new TemplateError('FILE_XML', $part);
                }
                if (substr($xml, $lt, 6) === '<?xml ' && ! $sawRoot) {
                    self::declaration(substr($xml, $lt + 6, $close - $lt - 6), $part);
                }
                $i = $close + 2;

                continue;
            }
            if ($next === '!') {
                if (substr($xml, $lt, 4) === '<!--') {
                    $close = strpos($xml, '-->', $lt + 4);
                    if ($close === false) {
                        throw new TemplateError('FILE_XML', $part);
                    }
                    $i = $close + 3;

                    continue;
                }
                if (substr($xml, $lt, 9) === '<![CDATA[' && $stack !== []) {
                    $close = strpos($xml, ']]>', $lt + 9);
                    if ($close === false) {
                        throw new TemplateError('FILE_XML', $part);
                    }
                    $i = $close + 3;

                    continue;
                }
                throw new TemplateError(strcasecmp(substr($xml, $lt, 9), '<!DOCTYPE') === 0 ? 'FILE_DOCTYPE' : 'FILE_XML', $part);
            }
            if ($next === '/') {
                $gt = strpos($xml, '>', $lt + 2);
                if ($gt === false || $stack === []) {
                    throw new TemplateError('FILE_XML', $part);
                }
                $qname = rtrim(substr($xml, $lt + 2, $gt - $lt - 2), " \t\r\n");
                [$open, $uri, $local, $outer] = array_pop($stack);
                if ($qname !== $open) {
                    throw new TemplateError('FILE_XML', $part);
                }
                $end($uri, $local, $lt, $gt + 1, count($stack));
                $ns = $outer;
                $i = $gt + 1;

                continue;
            }

            // A start tag.
            if ($sawRoot && $stack === []) {
                throw new TemplateError('FILE_XML', $part);
            }
            $p = $lt + 1;
            $len = strcspn($xml, " \t\r\n/>", $p);
            $qname = substr($xml, $p, $len);
            if ($qname === '' || strcspn($qname, "<=\"'&") !== strlen($qname)) {
                throw new TemplateError('FILE_XML', $part);
            }
            $p += $len;
            $attrs = [];
            $empty = false;
            for (;;) {
                $p += strspn($xml, " \t\r\n", $p);
                $c = $xml[$p] ?? '';
                if ($c === '>') {
                    $p++;
                    break;
                }
                if ($c === '/' && ($xml[$p + 1] ?? '') === '>') {
                    $p += 2;
                    $empty = true;
                    break;
                }
                $alen = strcspn($xml, " \t\r\n=/><\"'", $p);
                $aname = substr($xml, $p, $alen);
                $p += $alen;
                $p += strspn($xml, " \t\r\n", $p);
                if ($aname === '' || ($xml[$p] ?? '') !== '=') {
                    throw new TemplateError('FILE_XML', $part);
                }
                $p++;
                $p += strspn($xml, " \t\r\n", $p);
                $quote = $xml[$p] ?? '';
                if ($quote !== '"' && $quote !== "'") {
                    throw new TemplateError('FILE_XML', $part);
                }
                $close = strpos($xml, $quote, $p + 1);
                if ($close === false || array_key_exists($aname, $attrs)) {
                    throw new TemplateError('FILE_XML', $part);
                }
                $raw = substr($xml, $p + 1, $close - $p - 1);
                if (str_contains($raw, '<')) {
                    throw new TemplateError('FILE_XML', $part);
                }
                $attrs[$aname] = self::decode($xml, $p + 1, $close, $part)[0];
                $p = $close + 1;
            }

            $outer = $ns;
            foreach ($attrs as $aname => $value) {
                if ($aname === 'xmlns') {
                    $ns[''] = $value;
                } elseif (str_starts_with($aname, 'xmlns:')) {
                    $ns[substr($aname, 6)] = $value;
                }
            }
            $colon = strpos($qname, ':');
            $prefix = $colon === false ? '' : substr($qname, 0, $colon);
            $local = $colon === false ? $qname : substr($qname, $colon + 1);
            if (! array_key_exists($prefix, $ns) || $local === '') {
                throw new TemplateError('FILE_XML', $part);
            }
            $depth = count($stack);
            $sawRoot = true;
            $start($ns[$prefix], $local, $attrs, $lt, $p, $depth, $empty);
            if ($empty) {
                $end($ns[$prefix], $local, $p, $p, $depth);
                $ns = $outer;
            } else {
                $stack[] = [$qname, $ns[$prefix], $local, $outer];
            }
            $i = $p;
        }

        if ($stack !== [] || ! $sawRoot) {
            throw new TemplateError('FILE_XML', $part);
        }
    }

    /**
     * The character data between `$from` and `$to`, decoded, with a map from
     * every decoded byte to the raw offset it came from (`map[strlen(text)]`
     * is `$to`). Entities and character references decode; CDATA is literal;
     * comments and processing instructions contribute nothing. Any element
     * inside is FILE_XML: a text node holds text.
     *
     * @return array{0: string, 1: list<int>}
     *
     * @throws TemplateError
     */
    public static function decode(string $xml, int $from, int $to, string $part): array
    {
        $text = '';
        $map = [];
        $i = $from;
        while ($i < $to) {
            $stop = $i + strcspn($xml, '&<', $i, $to - $i);
            if ($stop > $i) {
                $text .= substr($xml, $i, $stop - $i);
                for ($k = $i; $k < $stop; $k++) {
                    $map[] = $k;
                }
                $i = $stop;
            }
            if ($i >= $to) {
                break;
            }
            if ($xml[$i] === '&') {
                $semi = strpos($xml, ';', $i);
                if ($semi === false || $semi >= $to || $semi - $i > 12) {
                    throw new TemplateError('FILE_XML', $part);
                }
                $char = self::entity(substr($xml, $i + 1, $semi - $i - 1), $part);
                $text .= $char;
                for ($k = 0; $k < strlen($char); $k++) {
                    $map[] = $i;
                }
                $i = $semi + 1;

                continue;
            }
            if (substr($xml, $i, 9) === '<![CDATA[') {
                $close = strpos($xml, ']]>', $i + 9);
                if ($close === false || $close + 3 > $to) {
                    throw new TemplateError('FILE_XML', $part);
                }
                $text .= substr($xml, $i + 9, $close - $i - 9);
                for ($k = $i + 9; $k < $close; $k++) {
                    $map[] = $k;
                }
                $i = $close + 3;

                continue;
            }
            [$open, $closeWith] = substr($xml, $i, 4) === '<!--' ? ['<!--', '-->'] : (($xml[$i + 1] ?? '') === '?' ? ['<?', '?>'] : [null, null]);
            if ($open === null) {
                throw new TemplateError('FILE_XML', $part);
            }
            $close = strpos($xml, $closeWith, $i + strlen($open));
            if ($close === false || $close + strlen($closeWith) > $to) {
                throw new TemplateError('FILE_XML', $part);
            }
            $i = $close + strlen($closeWith);
        }
        $map[] = $to;

        return [$text, $map];
    }

    /** XML-escape a value for character data; with `$xlsx`, also neutralise a `_xHHHH_` escape (ECMA-376 ST_Xstring). */
    public static function escape(string $s, bool $xlsx): string
    {
        $s = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
        if (! $xlsx || ! str_contains($s, '_x')) {
            return $s;
        }
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            if ($s[$i] === '_' && ($s[$i + 1] ?? '') === 'x' && $i + 6 < $n && $s[$i + 6] === '_'
                && strspn($s, '0123456789abcdefABCDEF', $i + 2, 4) === 4) {
                $out .= '_x005F_';

                continue;
            }
            $out .= $s[$i];
        }

        return $out;
    }

    /** Whether every character of a (valid UTF-8) string is one XML 1.0 can carry. */
    public static function canCarry(string $s): bool
    {
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $o = ord($s[$i]);
            if ($o < 0x20 && $o !== 0x09 && $o !== 0x0A && $o !== 0x0D) {
                return false;
            }
            if ($o === 0xEF && ($s[$i + 1] ?? '') === "\xBF" && (($s[$i + 2] ?? '') === "\xBE" || ($s[$i + 2] ?? '') === "\xBF")) {
                return false;
            }
        }

        return true;
    }

    private static function entity(string $name, string $part): string
    {
        $named = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'"];
        if (isset($named[$name])) {
            return $named[$name];
        }
        if (strlen($name) < 2 || $name[0] !== '#') {
            throw new TemplateError('FILE_XML', $part);
        }
        $hex = $name[1] === 'x';
        $digits = substr($name, $hex ? 2 : 1);
        if ($digits === '' || strspn($digits, $hex ? '0123456789abcdefABCDEF' : '0123456789') !== strlen($digits)) {
            throw new TemplateError('FILE_XML', $part);
        }
        $cp = $hex ? hexdec($digits) : (int) $digits;
        $ok = $cp === 0x9 || $cp === 0xA || $cp === 0xD || ($cp >= 0x20 && $cp <= 0xD7FF)
            || ($cp >= 0xE000 && $cp <= 0xFFFD) || ($cp >= 0x10000 && $cp <= 0x10FFFF);
        if (! $ok) {
            throw new TemplateError('FILE_XML', $part);
        }

        return self::utf8((int) $cp);
    }

    private static function utf8(int $cp): string
    {
        return match (true) {
            $cp < 0x80 => chr($cp),
            $cp < 0x800 => chr(0xC0 | ($cp >> 6)).chr(0x80 | ($cp & 0x3F)),
            $cp < 0x10000 => chr(0xE0 | ($cp >> 12)).chr(0x80 | (($cp >> 6) & 0x3F)).chr(0x80 | ($cp & 0x3F)),
            default => chr(0xF0 | ($cp >> 18)).chr(0x80 | (($cp >> 12) & 0x3F)).chr(0x80 | (($cp >> 6) & 0x3F)).chr(0x80 | ($cp & 0x3F)),
        };
    }

    /** `<?xml ... ?>`: only UTF-8 is read. */
    private static function declaration(string $body, string $part): void
    {
        $at = strpos($body, 'encoding');
        if ($at === false) {
            return;
        }
        $p = $at + 8;
        $p += strspn($body, " \t\r\n", $p);
        if (($body[$p] ?? '') !== '=') {
            throw new TemplateError('FILE_XML', $part);
        }
        $p++;
        $p += strspn($body, " \t\r\n", $p);
        $quote = $body[$p] ?? '';
        $close = $quote === '' ? false : strpos($body, $quote, $p + 1);
        if (($quote !== '"' && $quote !== "'") || $close === false) {
            throw new TemplateError('FILE_XML', $part);
        }
        if (strcasecmp(substr($body, $p + 1, $close - $p - 1), 'UTF-8') !== 0) {
            throw new TemplateError('FILE_XML', $part);
        }
    }
}
