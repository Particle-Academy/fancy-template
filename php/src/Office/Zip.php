<?php

declare(strict_types=1);

namespace FancyTemplate\Office;

use FancyTemplate\FileLimits;
use FancyTemplate\TemplateError;
use ZipArchive;

/**
 * A bounded, verified view of a zip package, and the rebuild that keeps every
 * untouched member as its ORIGINAL RECORD (SPEC.md section 12.8).
 *
 * The central directory is parsed here, by hand, before anything decompresses
 * a byte: entry count, declared sizes, the compression ratio and every name are
 * checked first, so a bomb or a traversal name costs a header read to refuse.
 * The checks are last-word's SourceArchive (0.13.0), with typed codes:
 *
 *   FILE_ARCHIVE           not a zip, corrupt, inconsistent, or a zip feature refused (zip64, multi-disk, other methods)
 *   FILE_ENCRYPTED         the zip encryption flag or an AES extra field
 *   FILE_PATH              an absolute, drive, backslash, control-character or `.`/`..` name; two names for one part
 *   FILE_TOO_LARGE         archiveBytes, partBytes, expandedBytes
 *   FILE_TOO_MANY_ENTRIES  entries
 *   FILE_ZIP_BOMB          compressionRatio
 *
 * Members are decompressed only when read, through ext-zip, from a temporary
 * copy that is deleted in a `finally`; the decompressed length and the CRC must
 * match the central directory, so a forged size is caught rather than trusted.
 *
 * @internal
 */
final class Zip
{
    /** @var array<string, string> name => decompressed bytes */
    private array $cache = [];

    /**
     * @param  list<array<string, int|string>>  $records  verified, central-directory order
     */
    private function __construct(
        private readonly string $bytes,
        public readonly array $records,
        private readonly string $comment,
    ) {}

    /** @throws TemplateError */
    public static function open(string $bytes, FileLimits $limits): self
    {
        if (strlen($bytes) > $limits->archiveBytes) {
            throw new TemplateError('FILE_TOO_LARGE', '');
        }
        [$records, $comment] = self::index($bytes, $limits);

        return new self($bytes, $records, $comment);
    }

    /** @return list<string> member names, central-directory order */
    public function names(): array
    {
        return array_map(static fn (array $r): string => (string) $r['name'], $this->records);
    }

    /**
     * Every listed member decompressed, verified against its CRC and size.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     *
     * @throws TemplateError
     */
    public function read(array $names): array
    {
        $want = array_values(array_diff($names, array_keys($this->cache)));
        if ($want !== []) {
            $this->decode($want);
        }
        $out = [];
        foreach ($names as $name) {
            $out[$name] = $this->cache[$name];
        }

        return $out;
    }

    /**
     * The package with `$replacements` written in place of those members and
     * every other member copied as its original local record, byte for byte.
     * Local order, central order and the archive comment are kept. A rewritten
     * member keeps its timestamp and attributes and is deflated through ext-zip.
     *
     * @param  array<string, string>  $replacements
     *
     * @throws TemplateError
     */
    public function rebuild(array $replacements): string
    {
        if ($replacements === []) {
            return $this->bytes;
        }
        $packed = self::deflate($replacements);
        $u16 = fn (int $at): int => unpack('v', substr($this->bytes, $at, 2))[1];
        $u32 = fn (int $at): int => unpack('V', substr($this->bytes, $at, 4))[1];

        $byLocal = $this->records;
        usort($byLocal, static fn (array $a, array $b): int => $a['local'] <=> $b['local']);
        $locals = '';
        $offsets = [];
        foreach ($byLocal as $r) {
            $name = (string) $r['name'];
            $offsets[$name] = strlen($locals);
            if (! array_key_exists($name, $packed)) {
                $locals .= substr($this->bytes, $r['local'], $r['finish'] - $r['local']);

                continue;
            }
            [$method, $crc, $data, $size] = $packed[$name];
            $locals .= pack('VvvvvvVVVvv', 0x04034B50, 20, $r['flags'] & 0x0800, $method,
                $u16($r['local'] + 10), $u16($r['local'] + 12), $crc, strlen($data), $size, strlen($name), 0).$name.$data;
        }

        $central = '';
        foreach ($this->records as $r) {
            $name = (string) $r['name'];
            if (! array_key_exists($name, $packed)) {
                $record = substr($this->bytes, $r['central'], $r['centralLength']);
                $central .= substr($record, 0, 42).pack('V', $offsets[$name]).substr($record, 46);

                continue;
            }
            [$method, $crc, $data, $size] = $packed[$name];
            $at = $r['central'];
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, $u16($at + 4), 20, $r['flags'] & 0x0800, $method,
                $u16($at + 12), $u16($at + 14), $crc, strlen($data), $size,
                strlen($name), 0, 0, 0, $u16($at + 36), $u32($at + 38), $offsets[$name]).$name;
        }

        return $locals.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($this->records), count($this->records),
            strlen($central), strlen($locals), strlen($this->comment)).$this->comment;
    }

    // ─── Decoding ────────────────────────────────────────────────────────

    /** @param  list<string>  $names */
    private function decode(array $names): void
    {
        $index = [];
        foreach ($this->records as $i => $r) {
            $index[$r['name']] = $i;
        }
        self::withZip($this->bytes, function (ZipArchive $zip) use ($names, $index): void {
            if ($zip->numFiles !== count($this->records)) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            foreach ($names as $name) {
                $r = $this->records[$index[$name]];
                // The extra byte detects a forged size without an unbounded allocation.
                $part = @$zip->getFromIndex($index[$name], $r['size'] + 1);
                if (! is_string($part) || strlen($part) !== $r['size'] || crc32($part) !== $r['crc']
                    || $zip->getNameIndex($index[$name]) !== $name) {
                    throw new TemplateError('FILE_ARCHIVE', '');
                }
                $this->cache[$name] = $part;
            }
        });
    }

    /**
     * Deflate members through ext-zip: write them to a scratch archive and
     * lift each compressed stream back out of it, verified by our own index.
     *
     * @param  array<string, string>  $parts
     * @return array<string, array{0: int, 1: int, 2: string, 3: int}> name => [method, crc, compressed, size]
     */
    private static function deflate(array $parts): array
    {
        $path = self::scratch();
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new TemplateError('FILE_IO', '');
            }
            $i = 0;
            $names = [];
            foreach ($parts as $name => $data) {
                // Neutral names: ours, never one from the file.
                $names["p{$i}"] = $name;
                $zip->addFromString("p{$i}", $data);
                $zip->setCompressionName("p{$i}", ZipArchive::CM_DEFLATE);
                $i++;
            }
            if (! $zip->close()) {
                throw new TemplateError('FILE_IO', '');
            }
            $bytes = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
        $loose = FileLimits::from(['fileLimits' => ['archiveBytes' => PHP_INT_MAX, 'partBytes' => PHP_INT_MAX,
            'expandedBytes' => PHP_INT_MAX, 'compressionRatio' => PHP_INT_MAX]]);
        [$records] = self::index($bytes, $loose);
        $out = [];
        foreach ($records as $r) {
            $name = $names[$r['name']];
            if ($r['crc'] !== crc32($parts[$name]) || $r['size'] !== strlen($parts[$name])) {
                throw new TemplateError('FILE_IO', '');
            }
            $out[$name] = [$r['method'], $r['crc'], substr($bytes, $r['data'], $r['compressed']), $r['size']];
        }

        return $out;
    }

    private static function withZip(string $bytes, callable $fn): void
    {
        $path = self::scratch();
        $zip = new ZipArchive;
        $opened = false;
        // ext-zip reports a bad CRC as a PHP warning as well as a failed read.
        // A host that turns warnings into exceptions (Laravel does) would see
        // a raw ErrorException instead of FILE_ARCHIVE, so the warning is
        // swallowed here and the failed read is what decides.
        set_error_handler(static fn (): bool => true);
        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new TemplateError('FILE_IO', '');
            }
            if (@$zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            $opened = true;
            $fn($zip);
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
            restore_error_handler();
        }
    }

    private static function scratch(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fancy_template_');
        if ($path === false) {
            throw new TemplateError('FILE_IO', '');
        }

        return $path;
    }

    // ─── The central directory, verified (last-word SourceArchive) ───────

    /** @return array{0: list<array<string, int|string>>, 1: string} */
    private static function index(string $bytes, FileLimits $limits): array
    {
        $length = strlen($bytes);
        $end = null;
        for ($at = $length - 22; $at >= max(0, $length - 65557); $at--) {
            if (substr($bytes, $at, 4) === "PK\x05\x06" && $at + 22 + self::u16($bytes, $at + 20) === $length
                && self::u32($bytes, $at + 16) + self::u32($bytes, $at + 12) === $at) {
                $end = $at;
                break;
            }
        }
        if ($end === null) {
            throw new TemplateError('FILE_ARCHIVE', '');
        }
        $count = self::u16($bytes, $end + 10);
        if (self::u16($bytes, $end + 4) !== 0 || self::u16($bytes, $end + 6) !== 0
            || self::u16($bytes, $end + 8) !== $count || $count === 65535) {
            throw new TemplateError('FILE_ARCHIVE', '');
        }
        if ($count > $limits->entries) {
            throw new TemplateError('FILE_TOO_MANY_ENTRIES', '');
        }
        $cdStart = self::u32($bytes, $end + 16);
        $at = $cdStart;
        if ($end - $at < $count * 46) {
            throw new TemplateError('FILE_ARCHIVE', '');
        }
        $records = $seen = $regions = [];
        $expanded = 0;
        for ($i = 0; $i < $count; $i++) {
            self::bounds($bytes, $at, 46, $end);
            if (self::u32($bytes, $at) !== 0x02014B50) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            $flags = self::u16($bytes, $at + 8);
            $method = self::u16($bytes, $at + 10);
            $crc = self::u32($bytes, $at + 16);
            $compressed = self::u32($bytes, $at + 20);
            $size = self::u32($bytes, $at + 24);
            $nameLength = self::u16($bytes, $at + 28);
            $extraLength = self::u16($bytes, $at + 30);
            $commentLength = self::u16($bytes, $at + 32);
            $local = self::u32($bytes, $at + 42);
            if (($flags & 0x0001) !== 0 || ($flags & 0x0040) !== 0 || $method === 99) {
                throw new TemplateError('FILE_ENCRYPTED', '');
            }
            if (($flags & ~0x080E) !== 0 || ! in_array($method, [0, 8], true)
                || ($method === 0 && ($flags & 6) !== 0) || self::u16($bytes, $at + 34) !== 0
                || in_array(0xFFFFFFFF, [$compressed, $size, $local], true) || self::u16($bytes, $at + 6) > 20) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            self::bounds($bytes, $at + 46, $nameLength + $extraLength + $commentLength, $end);
            $name = substr($bytes, $at + 46, $nameLength);
            self::name($name, $flags);
            // OPC part names are ASCII case-insensitive: two spellings of one
            // part would let the scan read one and an office suite the other.
            $key = strtolower($name);
            if (isset($seen[$key])) {
                throw new TemplateError('FILE_PATH', '');
            }
            $seen[$key] = true;
            $expanded += $size;
            if ($size > $limits->partBytes || $expanded > $limits->expandedBytes) {
                throw new TemplateError('FILE_TOO_LARGE', '');
            }
            if ($size > max(1, $compressed) * $limits->compressionRatio) {
                throw new TemplateError('FILE_ZIP_BOMB', '');
            }
            if ($method === 0 && $compressed !== $size) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            self::extra($bytes, $at + 46 + $nameLength, $extraLength);
            self::bounds($bytes, $local, 30, $cdStart);
            if (self::u32($bytes, $local) !== 0x04034B50 || self::u16($bytes, $local + 6) !== $flags
                || self::u16($bytes, $local + 8) !== $method) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            $localName = self::u16($bytes, $local + 26);
            $localExtra = self::u16($bytes, $local + 28);
            $start = $local + 30 + $localName + $localExtra;
            self::bounds($bytes, $local + 30, $localName + $localExtra + $compressed, $cdStart);
            if (substr($bytes, $local + 30, $localName) !== $name) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            self::extra($bytes, $local + 30 + $localName, $localExtra);
            foreach ([14 => $crc, 18 => $compressed, 22 => $size] as $offset => $expected) {
                $value = self::u32($bytes, $local + $offset);
                if ($value !== $expected && ! (($flags & 8) !== 0 && $value === 0)) {
                    throw new TemplateError('FILE_ARCHIVE', '');
                }
            }
            $finish = $start + $compressed;
            if (($flags & 8) !== 0) {
                $finish = self::descriptor($bytes, $finish, $cdStart, $crc, $compressed, $size);
            }
            $records[] = ['name' => $name, 'size' => $size, 'compressed' => $compressed, 'crc' => $crc, 'method' => $method,
                'flags' => $flags, 'local' => $local, 'data' => $start, 'finish' => $finish,
                'central' => $at, 'centralLength' => 46 + $nameLength + $extraLength + $commentLength];
            $regions[] = [$local, $finish];
            $at += 46 + $nameLength + $extraLength + $commentLength;
        }
        if ($at !== $end) {
            throw new TemplateError('FILE_ARCHIVE', '');
        }
        usort($regions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $previous = 0;
        foreach ($regions as [$start, $finish]) {
            if ($start < $previous) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            $previous = $finish;
        }

        return [$records, substr($bytes, $end + 22, self::u16($bytes, $end + 20))];
    }

    private static function name(string $name, int $flags): void
    {
        if ($name === '' || $name[0] === '/' || str_contains($name, chr(92)) || str_contains($name, ':')) {
            throw new TemplateError('FILE_PATH', '');
        }
        $n = strlen($name);
        for ($i = 0; $i < $n; $i++) {
            $byte = ord($name[$i]);
            if ($byte < 32 || $byte === 127) {
                throw new TemplateError('FILE_PATH', '');
            }
            if ($byte > 127 && ($flags & 0x0800) === 0) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
        }
        if (preg_match('//u', $name) !== 1) {
            throw new TemplateError('FILE_PATH', '');
        }
        foreach (explode('/', str_ends_with($name, '/') ? substr($name, 0, -1) : $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new TemplateError('FILE_PATH', '');
            }
        }
    }

    private static function extra(string $bytes, int $start, int $length): void
    {
        $end = $start + $length;
        while ($start < $end) {
            self::bounds($bytes, $start, 4, $end);
            $id = self::u16($bytes, $start);
            $size = self::u16($bytes, $start + 2);
            self::bounds($bytes, $start + 4, $size, $end);
            if ($id === 0x9901) {
                throw new TemplateError('FILE_ENCRYPTED', '');
            }
            if ($id === 1) {
                throw new TemplateError('FILE_ARCHIVE', '');
            }
            $start += 4 + $size;
        }
    }

    private static function descriptor(string $bytes, int $at, int $end, int $crc, int $compressed, int $size): int
    {
        foreach ([0, 4] as $prefix) {
            if ($at + $prefix + 12 <= $end && ($prefix === 0 || self::u32($bytes, $at) === 0x08074B50)
                && self::u32($bytes, $at + $prefix) === $crc && self::u32($bytes, $at + $prefix + 4) === $compressed
                && self::u32($bytes, $at + $prefix + 8) === $size) {
                return $at + $prefix + 12;
            }
        }
        throw new TemplateError('FILE_ARCHIVE', '');
    }

    private static function bounds(string $bytes, int $at, int $size, ?int $end = null): void
    {
        if ($at < 0 || $size < 0 || $at + $size > ($end ?? strlen($bytes)) || $at + $size > strlen($bytes)) {
            throw new TemplateError('FILE_ARCHIVE', '');
        }
    }

    private static function u16(string $bytes, int $at): int
    {
        self::bounds($bytes, $at, 2);

        return unpack('v', substr($bytes, $at, 2))[1];
    }

    private static function u32(string $bytes, int $at): int
    {
        self::bounds($bytes, $at, 4);

        return unpack('V', substr($bytes, $at, 4))[1];
    }
}
