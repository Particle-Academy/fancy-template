<?php

declare(strict_types=1);

namespace FancyTemplate\Office;

use FancyTemplate\Declarations;
use FancyTemplate\FileLimits;
use FancyTemplate\Filters;
use FancyTemplate\Inspector;
use FancyTemplate\Json;
use FancyTemplate\Limits;
use FancyTemplate\Renderer;
use FancyTemplate\Scanner;
use FancyTemplate\TemplateError;
use LogicException;

/**
 * An office file (.docx/.dotx, .pptx/.potx/.ppsx, .xlsx/.xltx) as a template:
 * its `{{ tokens }}` found in the file's own text and filled in place, every
 * other byte kept (SPEC.md section 12).
 *
 * @internal Reached through Template::inspectFile / Template::renderFile.
 */
final class Package
{
    private const W = ['http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main'];

    private const A = ['http://schemas.openxmlformats.org/drawingml/2006/main', 'http://purl.oclc.org/ooxml/drawingml/main'];

    private const S = ['http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'http://purl.oclc.org/ooxml/spreadsheetml/main'];

    private const MATH = ['http://schemas.openxmlformats.org/officeDocument/2006/math', 'http://purl.oclc.org/ooxml/officeDocument/math'];

    private const CONTENT_TYPES_NS = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const WML = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';

    private const PML = 'application/vnd.openxmlformats-officedocument.presentationml.';

    private const SML = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';

    /** Main-part content type => [format, kind]. */
    private const MAIN = [
        self::WML.'document.main+xml' => ['docx', 'document'],
        self::WML.'template.main+xml' => ['docx', 'template'],
        self::PML.'presentation.main+xml' => ['pptx', 'document'],
        self::PML.'slideshow.main+xml' => ['pptx', 'document'],
        self::PML.'template.main+xml' => ['pptx', 'template'],
        self::SML.'sheet.main+xml' => ['xlsx', 'document'],
        self::SML.'template.main+xml' => ['xlsx', 'template'],
    ];

    /** Macro-enabled (and binary) main parts: refused, never passed through. */
    private const MACRO_MAIN = [
        'application/vnd.ms-word.document.macroenabled.main+xml',
        'application/vnd.ms-word.template.macroenabledtemplate.main+xml',
        'application/vnd.ms-powerpoint.presentation.macroenabled.main+xml',
        'application/vnd.ms-powerpoint.slideshow.macroenabled.main+xml',
        'application/vnd.ms-powerpoint.template.macroenabled.main+xml',
        'application/vnd.ms-powerpoint.addin.macroenabled.main+xml',
        'application/vnd.ms-excel.sheet.macroenabled.main+xml',
        'application/vnd.ms-excel.template.macroenabled.main+xml',
        'application/vnd.ms-excel.addin.macroenabled.main+xml',
        'application/vnd.ms-excel.sheet.binary.macroenabled.main',
    ];

    private const VBA = 'application/vnd.ms-office.vbaproject';

    /** The parts whose text is read and filled, by content type (SPEC.md 12.3). */
    private const SCANNED = [
        'docx' => [
            self::WML.'document.main+xml', self::WML.'template.main+xml', self::WML.'header+xml', self::WML.'footer+xml',
            self::WML.'footnotes+xml', self::WML.'endnotes+xml', self::WML.'comments+xml',
        ],
        'pptx' => [
            self::PML.'slide+xml', self::PML.'slidelayout+xml', self::PML.'slidemaster+xml',
            self::PML.'notesslide+xml', self::PML.'notesmaster+xml',
        ],
        'xlsx' => [self::SML.'sharedstrings+xml', self::SML.'worksheet+xml'],
    ];

    /**
     * How text is laid out in each format (SPEC.md 12.4). `containers` hold
     * text; a `run` holds `t`; run children in `transparent` neither add text
     * nor break it; every other run child is a BARRIER, and so is a container
     * child listed in `barriers` (namespace, local name).
     */
    private const DIALECTS = [
        'docx' => ['ns' => self::W, 'containers' => ['p'], 'transparent' => ['rPr', 'delText', 'lastRenderedPageBreak'],
            'barriers' => [], 'directText' => false, 'preserve' => true, 'xlsx' => false],
        'pptx' => ['ns' => self::A, 'containers' => ['p'], 'transparent' => ['rPr'],
            'barriers' => ['br', 'fld'], 'directText' => false, 'preserve' => false, 'xlsx' => false],
        'xlsx' => ['ns' => self::S, 'containers' => ['si', 'is'], 'transparent' => ['rPr'],
            'barriers' => [], 'directText' => true, 'preserve' => true, 'xlsx' => true],
    ];

    /** @var array<string, string> scanned part name => its XML */
    private array $xml = [];

    /** @var array<string, list<array<string, mixed>>> part name => spans holding tokens */
    private array $spans = [];

    private int $tokenCount = 0;

    private function __construct(
        private readonly Zip $zip,
        public readonly string $format,
        public readonly string $kind,
        /** @var list<string> */
        private readonly array $scanned,
        /** @var list<string> */
        private readonly array $sniffed,
        private readonly Limits $limits,
    ) {}

    /**
     * Open and classify a package. Every refusal is a TemplateError with a
     * FILE_ code (SPEC.md 12.8).
     *
     * @throws TemplateError
     */
    public static function open(string $bytes, FileLimits $fileLimits, Limits $limits): self
    {
        if (str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            // An OLE compound file. A password-protected OOXML file is one, with
            // an EncryptedPackage stream (its name is UTF-16LE in the directory);
            // so is a legacy .doc/.xls/.ppt, which is simply not OOXML.
            $encrypted = str_contains($bytes, "E\0n\0c\0r\0y\0p\0t\0e\0d\0P\0a\0c\0k\0a\0g\0e\0");
            throw new TemplateError($encrypted ? 'FILE_ENCRYPTED' : 'FILE_UNSUPPORTED', '');
        }
        $zip = Zip::open($bytes, $fileLimits);
        $names = $zip->names();
        if (! in_array('[Content_Types].xml', $names, true)) {
            throw new TemplateError('FILE_UNSUPPORTED', '');
        }
        $types = self::checked('[Content_Types].xml', $zip->read(['[Content_Types].xml'])['[Content_Types].xml']);
        [$defaults, $overrides, $declared] = self::contentTypes($types);
        $typeOf = static function (string $name) use ($defaults, $overrides): ?string {
            $key = '/'.strtolower($name);
            if (isset($overrides[$key])) {
                return $overrides[$key];
            }
            $dot = strrpos($name, '.');
            $slash = strrpos($name, '/');

            return $dot !== false && ($slash === false || $dot > $slash) ? ($defaults[strtolower(substr($name, $dot + 1))] ?? null) : null;
        };

        $mains = [];
        foreach ($names as $name) {
            $type = $typeOf($name);
            if ($type !== null && in_array($type, self::MACRO_MAIN, true)) {
                throw new TemplateError('FILE_MACRO_ENABLED', '');
            }
            foreach (self::MAIN as $main => $what) {
                if ($type === strtolower($main)) {
                    $mains[] = $what;
                }
            }
        }
        if (count($mains) !== 1) {
            throw new TemplateError('FILE_UNSUPPORTED', '');
        }
        // A VBA project anywhere, declared or merely present, is macro content.
        foreach ($declared as $type) {
            if ($type === self::VBA) {
                throw new TemplateError('FILE_MACRO_ENABLED', '');
            }
        }
        foreach ($names as $name) {
            if (str_ends_with(strtolower($name), 'vbaproject.bin')) {
                throw new TemplateError('FILE_MACRO_ENABLED', '');
            }
        }
        [$format, $kind] = $mains[0];

        $scanned = $sniffed = [];
        $roles = array_map('strtolower', self::SCANNED[$format]);
        foreach ($names as $name) {
            $type = $typeOf($name);
            if ($type !== null && in_array($type, $roles, true)) {
                $scanned[] = $name;
            } elseif (str_ends_with(strtolower($name), '.xml') || str_ends_with(strtolower($name), '.rels')) {
                $sniffed[] = $name;
            }
        }

        return new self($zip, $format, $kind, $scanned, $sniffed, $limits);
    }

    /**
     * Inspect every part, in central-directory order (SPEC.md 12.5).
     *
     * @param  array<string, mixed>|null  $declared  the sidecar declarations, or null to infer
     * @return array{issues: list<array<string, string>>, placeholders: list<array<string, string>>, variables: list<string>}
     *
     * @throws TemplateError FILE_ codes from a part
     */
    public function scan(?array $declared): array
    {
        $issues = $placeholders = $heads = [];
        $parts = $this->zip->read([...$this->scanned, ...$this->sniffed]);
        foreach ($this->zip->names() as $name) {
            if (! array_key_exists($name, $parts)) {
                continue;
            }
            $xml = self::checked($name, $parts[$name]);
            if (! in_array($name, $this->scanned, true)) {
                if (str_contains($xml, '{{')) {
                    $issues[] = ['code' => 'TOKEN_FORBIDDEN_FIELD', 'path' => '/'.$name];
                }

                continue;
            }
            $this->xml[$name] = $xml;
            foreach ($this->containers($name, $xml) as $container) {
                foreach ($container['spans'] as $nodes) {
                    $joined = implode('', array_map(static fn (array $n): string => $n['text'], $nodes));
                    if (! str_contains($joined, '{{')) {
                        continue;
                    }
                    try {
                        $tokens = Scanner::locate($joined, $this->limits->tokenLength);
                    } catch (TemplateError $e) {
                        $issues[] = ['code' => $e->errorCode, 'path' => $container['path']];

                        continue;
                    }
                    $this->tokenCount += count($tokens);
                    foreach ($tokens as [$start, $end, $token]) {
                        $placeholder = ['path' => $container['path'], 'token' => substr($joined, $start, $end - $start)];
                        if (isset($token['path'])) {
                            $placeholder['variable'] = implode('.', $token['path']);
                            if (! in_array($token['path'][0], $heads, true) && ! in_array($token['path'][0], Scanner::LOOP_HEADS, true)) {
                                $heads[] = $token['path'][0];
                            }
                        }
                        $placeholders[] = $placeholder;
                        array_push($issues, ...self::check($token, $container['path'], $declared));
                    }
                    $this->spans[$name][] = ['path' => $container['path'], 'nodes' => $nodes, 'tokens' => $tokens];
                }
            }
        }

        return ['issues' => $issues, 'placeholders' => $placeholders, 'variables' => $heads];
    }

    public function tokenCount(): int
    {
        return $this->tokenCount;
    }

    /**
     * Fill every token and rebuild the package (SPEC.md 12.5, 12.6). Runs
     * only after scan() found no issue and the data validated.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TemplateError
     */
    public function fill(array $data): string
    {
        $xlsx = self::DIALECTS[$this->format]['xlsx'];
        $preserve = self::DIALECTS[$this->format]['preserve'];
        $written = 0;
        $replacements = [];
        foreach ($this->spans as $name => $spans) {
            $xml = $this->xml[$name];
            $edits = [];
            foreach ($spans as $span) {
                $values = [];
                foreach ($span['tokens'] as [, , $token]) {
                    $variable = isset($token['path']) ? implode('.', $token['path']) : null;
                    try {
                        $value = Renderer::tokenText($token, $data);
                    } catch (TemplateError $e) {
                        throw new TemplateError($e->errorCode, $span['path'], $variable);
                    }
                    if (! Xml::canCarry($value)) {
                        throw new TemplateError('VALUE_XML_CHAR', $span['path'], $variable);
                    }
                    $written += strlen($value);
                    if ($written > $this->limits->outputBytes) {
                        throw new TemplateError('RENDER_TOO_LARGE', '');
                    }
                    $values[] = $value;
                }
                array_push($edits, ...self::spanEdits($xml, $span, $values, $xlsx, $preserve));
            }
            usort($edits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $out = '';
            $at = 0;
            foreach ($edits as [$from, $to, $with]) {
                $out .= substr($xml, $at, $from - $at).$with;
                $at = $to;
            }
            $out .= substr($xml, $at);
            if ($out !== $xml) {
                // The filled part must still be one the lexer accepts: an edit that
                // broke the structure is a bug here, never a file to hand back.
                try {
                    Xml::walk($out, '/'.$name, static fn () => null, static fn () => null);
                } catch (TemplateError) {
                    throw new LogicException("Filling /{$name} produced a part that is not well-formed.");
                }
                $replacements[$name] = $out;
            }
        }

        return $this->zip->rebuild($replacements);
    }

    // ─── The run-split rule (SPEC.md 12.5) ───────────────────────────────

    /**
     * Edits for one span: each token's value goes into the node where the
     * token STARTS, at the token's position, in that node's run (so with its
     * formatting); the rest of the token is cut from the nodes it spans. Text
     * around a token is copied as its original bytes. No node and no run is
     * ever removed, even when it ends up empty.
     *
     * @param  array<string, mixed>  $span
     * @param  list<string>  $values
     * @return list<array{0: int, 1: int, 2: string}> [from, to, replacement] over the part
     */
    private static function spanEdits(string $xml, array $span, array $values, bool $xlsx, bool $preserve): array
    {
        $edits = [];
        $a = 0;
        foreach ($span['nodes'] as $node) {
            $b = $a + strlen($node['text']);
            $raw = static fn (int $x) => $x === $a ? $node['tagEnd'] : $node['map'][$x - $a];
            $c = $a;
            $content = '';
            $text = '';
            $touched = false;
            foreach ($span['tokens'] as $i => [$start, $end]) {
                if ($end <= $a || $start >= $b) {
                    continue;
                }
                $touched = true;
                if ($start >= $a) {
                    $content .= substr($xml, $raw($c), $node['map'][$start - $a] - $raw($c));
                    $text .= substr($node['text'], $c - $a, $start - $c);
                    $content .= Xml::escape($values[$i], $xlsx);
                    $text .= $values[$i];
                }
                $c = max($c, min($end, $b));
            }
            if ($touched) {
                $content .= substr($xml, $raw($c), $node['contentEnd'] - $raw($c));
                $text .= substr($node['text'], $c - $a);
                if ($preserve && ! $node['preserve'] && $text !== ''
                    && (strspn($text, " \t\r\n", 0, 1) === 1 || strspn($text, " \t\r\n", strlen($text) - 1) === 1)) {
                    // Word and Excel drop leading and trailing white space from
                    // a text node unless it says to keep it.
                    $edits[] = [$node['tagEnd'] - 1, $node['tagEnd'] - 1, ' xml:space="preserve"'];
                }
                $edits[] = [$node['tagEnd'], $node['contentEnd'], $content];
            }
            $a = $b;
        }

        return $edits;
    }

    // ─── Reading the text (SPEC.md 12.4) ─────────────────────────────────

    /**
     * Every text container of a part, in start-tag order, with its spans: the
     * runs of text nodes between barriers.
     *
     * @return list<array{path: string, spans: list<list<array<string, mixed>>>}>
     */
    private function containers(string $name, string $xml): array
    {
        $d = self::DIALECTS[$this->format];
        $ns = $d['ns'];
        $out = [];
        $frames = [];
        $count = 0;
        $cell = null;
        $part = '/'.$name;

        $barrier = static function (array &$frame): void {
            $frame['items'][] = null;
        };
        $start = function (string $uri, string $local, array $attrs, int $ts, int $te, int $depth, bool $empty) use (&$frames, &$count, &$cell, $d, $ns, $part, $barrier): void {
            $mine = in_array($uri, $ns, true);
            if ($mine && $local === 'c' && $d['xlsx']) {
                $cell = $attrs['r'] ?? null;
            }
            if ($mine && in_array($local, $d['containers'], true)) {
                $label = match (true) {
                    $local === 'is' && is_string($cell) && $cell !== '' && strspn($cell, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') === strlen($cell) => $cell,
                    default => $local.$count,
                };
                $frame = ['seq' => $count++, 'depth' => $depth, 'path' => $part.'#'.$label, 'run' => null, 'items' => [], 'pending' => null];
                if ($empty) {
                    return;
                }
                $frames[] = $frame;

                return;
            }
            if ($frames === []) {
                return;
            }
            $top = &$frames[count($frames) - 1];
            if ($top['run'] === null) {
                if ($mine && $local === 'r') {
                    $top['run'] = $depth;
                } elseif ($mine && $local === 't' && $d['directText'] && $depth === $top['depth'] + 1) {
                    $top['pending'] = $empty ? null : ['depth' => $depth, 'tagStart' => $ts, 'tagEnd' => $te,
                        'preserve' => ($attrs['xml:space'] ?? null) === 'preserve'];
                } elseif (($mine && in_array($local, $d['barriers'], true))
                    || (in_array($uri, self::MATH, true) && in_array($local, ['oMath', 'oMathPara'], true))) {
                    $barrier($top);
                }

                return;
            }
            if ($depth !== $top['run'] + 1) {
                return;
            }
            if ($mine && $local === 't') {
                $top['pending'] = $empty ? null : ['depth' => $depth, 'tagStart' => $ts, 'tagEnd' => $te,
                    'preserve' => ($attrs['xml:space'] ?? null) === 'preserve'];
            } elseif (! ($mine && in_array($local, $d['transparent'], true))) {
                $barrier($top);
            }
        };
        $end = function (string $uri, string $local, int $ts, int $te, int $depth) use (&$frames, &$out, $xml, $part): void {
            if ($frames === []) {
                return;
            }
            $top = &$frames[count($frames) - 1];
            if ($top['pending'] !== null && $depth === $top['pending']['depth']) {
                [$text, $map] = Xml::decode($xml, $top['pending']['tagEnd'], $ts, $part);
                $top['items'][] = $top['pending'] + ['contentEnd' => $ts, 'text' => $text, 'map' => $map];
                $top['pending'] = null;

                return;
            }
            if ($top['run'] === $depth) {
                $top['run'] = null;

                return;
            }
            if ($top['depth'] === $depth) {
                $spans = [[]];
                foreach ($top['items'] as $item) {
                    if ($item === null) {
                        $spans[] = [];
                    } elseif ($item['text'] !== '') {
                        $spans[count($spans) - 1][] = $item;
                    }
                }
                $out[] = ['seq' => $top['seq'], 'path' => $top['path'], 'spans' => array_values(array_filter($spans, static fn (array $s): bool => $s !== []))];
                unset($top);
                array_pop($frames);
            }
        };
        Xml::walk($xml, $part, $start, $end);

        // Containers close inner-first; report them in START order, so a text
        // box's paragraph comes after the paragraph that holds it.
        usort($out, static fn (array $x, array $y): int => $x['seq'] <=> $y['seq']);

        return $out;
    }

    // ─── Checks ──────────────────────────────────────────────────────────

    /**
     * One token's static issues (the same codes as inspect, SPEC.md 12.5).
     *
     * @param  array<string, mixed>  $token
     * @param  array<string, mixed>|null  $declared
     * @return list<array<string, string>>
     */
    private static function check(array $token, string $path, ?array $declared): array
    {
        $issues = [];
        if (isset($token['path'])) {
            $variable = implode('.', $token['path']);
            if (in_array($token['path'][0], Scanner::LOOP_HEADS, true)) {
                $issues[] = ['code' => 'TOKEN_UNDECLARED', 'path' => $path, 'variable' => $variable];
            } elseif ($declared !== null) {
                [$found, $decl] = Inspector::declAt($token['path'], [], $declared);
                if (! $found) {
                    $issues[] = ['code' => 'TOKEN_UNDECLARED', 'path' => $path, 'variable' => $variable];
                } elseif (is_array($decl) && ($decl['type'] ?? null) === 'image') {
                    $issues[] = ['code' => 'TOKEN_IMAGE_FIELD', 'path' => $path];
                }
            }
        }
        foreach ($token['filters'] as $filter) {
            $code = Filters::check($filter);
            if ($code !== null) {
                $issues[] = ['code' => $code, 'path' => $path];
            }
        }

        return $issues;
    }

    /**
     * A sidecar's declarations, checked as inspect checks an envelope's
     * `variables` (SPEC.md 12.2). Null when there is no sidecar.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: array<string, mixed>|null, 1: list<array<string, string>>}
     */
    public static function declarations(array $options): array
    {
        if (! array_key_exists('variables', $options)) {
            return [null, []];
        }
        [$plain, $variables] = Json::plain($options['variables']);
        if (! $plain || ! Json::isObject($variables)) {
            return [[], [['code' => 'TEMPLATE_SHAPE', 'path' => '/variables']]];
        }
        $issues = [];
        $declared = [];
        foreach ($variables as $name => $decl) {
            $at = Json::pointer('/variables', $name);
            if (! Scanner::isName((string) $name)) {
                $issues[] = ['code' => 'DECL_INVALID', 'path' => $at];

                continue;
            }
            Declarations::inspect($decl, $at, $issues);
            $declared[(string) $name] = $decl;
        }

        return [$declared, $issues];
    }

    /** A part's bytes, refused when it declares a DOCTYPE (SPEC.md 12.8). */
    private static function checked(string $name, string $xml): string
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new TemplateError('FILE_DOCTYPE', '/'.$name);
        }

        return $xml;
    }

    /**
     * `[Content_Types].xml`: Default by extension and Override by part name,
     * both lower-cased (OPC compares them case-insensitively).
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: list<string>}
     */
    private static function contentTypes(string $xml): array
    {
        $defaults = $overrides = $all = [];
        Xml::walk($xml, '/[Content_Types].xml', static function (string $uri, string $local, array $attrs) use (&$defaults, &$overrides, &$all): void {
            if ($uri !== self::CONTENT_TYPES_NS) {
                return;
            }
            $type = strtolower($attrs['ContentType'] ?? '');
            if ($local === 'Default' && isset($attrs['Extension'])) {
                $defaults[strtolower($attrs['Extension'])] = $type;
                $all[] = $type;
            } elseif ($local === 'Override' && isset($attrs['PartName'])) {
                $overrides[strtolower($attrs['PartName'])] = $type;
                $all[] = $type;
            }
        }, static fn () => null);

        return [$defaults, $overrides, $all];
    }
}
