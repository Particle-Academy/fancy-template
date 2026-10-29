<?php

declare(strict_types=1);

use FancyTemplate\Template;
use FancyTemplate\TemplateError;

/*
 * Office files as templates (SPEC.md section 12): Template::inspectFile and
 * Template::renderFile over fixtures/files/.
 *
 * The instruments here are deliberately NOT the library's: members are read
 * with ZipArchive, raw zip records are cut out by the small central-directory
 * reader below, and visible text is extracted with DOMDocument. A check that
 * used the library's own scanner to read the library's own output would agree
 * with it whatever it did.
 */

const FILES_DIR = __DIR__.'/../../fixtures/files';

function fileFixture(string $name): string
{
    return (string) file_get_contents(FILES_DIR.'/'.$name);
}

function fileCases(): array
{
    return json_decode((string) file_get_contents(FILES_DIR.'/cases.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** A row's options, with "@name" declarations resolved from the table. */
function fileOptions(array $case): array
{
    $options = $case['input']['options'] ?? [];
    if (is_string($options['variables'] ?? null)) {
        $options['variables'] = fileCases()['declarations'][substr($options['variables'], 1)];
    }

    return $options;
}

/**
 * Every member's RAW local record (header + compressed data), by name, cut
 * out through the central directory. Independent of the library's reader.
 *
 * @return array<string, string>
 */
function rawRecords(string $zip): array
{
    $eocd = strrpos($zip, "PK\x05\x06");
    $count = unpack('v', substr($zip, $eocd + 10, 2))[1];
    $at = unpack('V', substr($zip, $eocd + 16, 4))[1];
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Vlocal', substr($zip, $at, 46));
        $name = substr($zip, $at + 46, $h['nlen']);
        $l = unpack('vnlen/velen', substr($zip, $h['local'] + 26, 4));
        $out[$name] = substr($zip, $h['local'], 30 + $l['nlen'] + $l['elen'] + $h['csize']);
        $at += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
    }

    return $out;
}

/** @return array<string, string> member name => decompressed bytes, via ZipArchive with consistency checks */
function members(string $zip): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ftt');
    file_put_contents($tmp, $zip);
    $z = new ZipArchive;
    expect($z->open($tmp, ZipArchive::RDONLY | ZipArchive::CHECKCONS))->toBeTrue();
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $out[$z->getNameIndex($i)] = $z->getFromIndex($i);
    }
    $z->close();
    unlink($tmp);

    return $out;
}

/**
 * Visible text of each text container in a part (SPEC.md 12.4), with DOM.
 *
 * @return list<string>
 */
function visibleText(string $xml): array
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml, LIBXML_NONET))->toBeTrue();
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $s = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $out = [];
    foreach ($dom->getElementsByTagName('*') as $el) {
        $container = ($el->namespaceURI === $w && $el->localName === 'p') || ($el->namespaceURI === $a && $el->localName === 'p')
            || ($el->namespaceURI === $s && in_array($el->localName, ['si', 'is'], true));
        if (! $container) {
            continue;
        }
        $text = '';
        foreach ($el->getElementsByTagNameNS($el->namespaceURI, 't') as $t) {
            // The nearest container ancestor must be THIS one (a text box's paragraph is its own).
            $up = $t->parentNode;
            while ($up !== null && ! ($up->namespaceURI === $el->namespaceURI && in_array($up->localName, ['p', 'si', 'is'], true))) {
                $up = $up->parentNode;
            }
            $parent = $t->parentNode;
            if ($up !== $el || ($el->namespaceURI === $s && $parent->localName === 'rPh')) {
                continue;
            }
            $text .= $t->textContent;
        }
        if ($el->namespaceURI === $s) {
            $text = preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', static fn (array $m): string => mb_chr(hexdec($m[1]), 'UTF-8'), $text);
        }
        $out[] = $text;
    }

    return $out;
}

function fileError(callable $fn): TemplateError
{
    try {
        $fn();
    } catch (TemplateError $e) {
        return $e;
    }
    throw new RuntimeException('expected a TemplateError');
}

// ─── The shared rows ─────────────────────────────────────────────────────

dataset('file rows', function (): array {
    $rows = [];
    foreach (fileCases()['cases'] as $case) {
        $rows[$case['id']] = [$case];
    }

    return $rows;
});

it('passes the shared template/files row', function (array $case): void {
    $bytes = fileFixture($case['input']['file']);
    $options = fileOptions($case);
    $expect = $case['expect'];

    if (isset($expect['error'])) {
        $e = fileError(fn () => Template::renderFile($bytes, $case['input']['data'] ?? [], $options));
        expect($e->toArray())->toBe($expect['error']);
        // inspectFile reports the same refusal as its single issue (render-time
        // data errors are not inspect's business, so those rows stop here).
        if (str_starts_with($expect['error']['code'], 'FILE_')) {
            expect(Template::inspectFile($bytes, $options))->toMatchArray(['ok' => false, 'issues' => [$expect['error']]]);
        }

        return;
    }

    if ($case['fn'] === 'inspectFile') {
        $result = Template::inspectFile($bytes, $options);
        foreach ($expect as $key => $value) {
            expect($result[$key])->toBe($value, "key {$key}");
        }

        return;
    }

    $output = Template::renderFile($bytes, $case['input']['data'], $options);
    $in = rawRecords($bytes);
    $outRecords = rawRecords($output);
    $outMembers = members($output);
    expect(array_keys($outRecords))->toBe(array_keys($in));

    if (isset($expect['changed'])) {
        $changed = [];
        foreach ($in as $name => $record) {
            if ($outRecords[$name] !== $record) {
                $changed[] = '/'.$name;
            }
        }
        sort($changed);
        $want = $expect['changed'];
        sort($want);
        expect($changed)->toBe($want);
    }
    foreach ($expect['text'] ?? [] as $part => $texts) {
        expect(visibleText($outMembers[substr($part, 1)]))->toBe($texts, $part);
    }
    foreach ($expect['contains'] ?? [] as $part => $needles) {
        foreach ($needles as $needle) {
            expect($outMembers[substr($part, 1)])->toContain($needle);
        }
    }
    foreach ($expect['absent'] ?? [] as $part => $needles) {
        foreach ($needles as $needle) {
            expect(str_contains($outMembers[substr($part, 1)], $needle))->toBeFalse("{$part} contains {$needle}");
        }
    }
})->with('file rows');

// ─── Byte preservation ───────────────────────────────────────────────────

const PROPOSAL_DATA = ['client' => 'Ada & <Lovelace> "Ltd"', 'amount' => 123450, 'due' => '2026-11-30', 'project' => 'Analytical Engine', 'ref' => 'R-42'];

it('copies every part without a token as its original zip record, byte for byte', function (string $file, array $data): void {
    $bytes = fileFixture($file);
    $out = Template::renderFile($bytes, $data);
    $before = members($bytes);
    $after = members($out);
    $raw = rawRecords($bytes);
    $rawOut = rawRecords($out);
    $untouched = 0;
    foreach ($before as $name => $content) {
        if (! str_contains($content, '{{') && ! str_contains($content, '&#123;')) {
            expect($rawOut[$name])->toBe($raw[$name], $name);
            $untouched++;
        }
        expect($after)->toHaveKey($name);
    }
    expect($untouched)->toBeGreaterThan(3);
})->with([
    'docx' => ['proposal.docx', PROPOSAL_DATA],
    'pptx' => ['deck.pptx', ['client' => 'A', 'amount' => 1, 'project' => 'P']],
    'xlsx' => ['quote.xlsx', ['client' => 'A', 'amount' => 1, 'project' => 'P', 'formula' => 'F', 'note' => 'N']],
]);

it('changes nothing in a filled part but the text of the runs a token touched', function (): void {
    $before = members(fileFixture('proposal.docx'))['word/document.xml'];
    $after = members(Template::renderFile(fileFixture('proposal.docx'), PROPOSAL_DATA))['word/document.xml'];
    // Blank every w:t (start tag included, since xml:space may be added) on both sides.
    $blank = static fn (string $xml): string => preg_replace('#<w:t(?:\s[^>]*)?>[^<]*</w:t>#', '<w:t/>', $xml);
    expect($blank($after))->toBe($blank($before));
    expect(substr_count($after, '<w:r>') + substr_count($after, '<w:r '))->toBe(substr_count($before, '<w:r>') + substr_count($before, '<w:r '));
});

it('keeps the zip member order, local and central', function (): void {
    $bytes = fileFixture('deck.pptx');
    expect(array_keys(members(Template::renderFile($bytes, ['client' => 'A', 'amount' => 1, 'project' => 'P']))))->toBe(array_keys(members($bytes)));
});

it('produces a file whose filled text is fully rendered: inspecting the output finds no placeholder', function (): void {
    $out = Template::renderFile(fileFixture('proposal.docx'), PROPOSAL_DATA);
    expect(Template::inspectFile($out))->toMatchArray(['ok' => true, 'placeholders' => [], 'variables' => []]);
});

// ─── The run-split rule ──────────────────────────────────────────────────

it('puts the value in the run where the token STARTS and keeps that run\'s formatting', function (): void {
    $xml = members(Template::renderFile(fileFixture('proposal.docx'), PROPOSAL_DATA))['word/document.xml'];
    // The bold run holds the value; the italic run that held "ent }}" is kept, empty.
    expect($xml)->toContain('<w:r><w:rPr><w:b/></w:rPr><w:t>Ada &amp; &lt;Lovelace&gt; "Ltd"</w:t></w:r>');
    expect($xml)->toContain('<w:r><w:rPr><w:i/></w:rPr><w:t></w:t></w:r>');
});

it('never deletes a run, even one the rule empties', function (): void {
    $bytes = fileFixture('letter.last-word.docx');
    $before = members($bytes)['word/document.xml'];
    $after = members(Template::renderFile($bytes, ['client' => 'Ada', 'project' => 'P']))['word/document.xml'];
    expect(substr_count($after, '<w:r>'))->toBe(substr_count($before, '<w:r>'));
});

// ─── Escaping ────────────────────────────────────────────────────────────

it('XML-escapes every value, so a value can never inject markup', function (string $file, array $data, string $part): void {
    $hostile = '</w:t></w:r><w:r><w:t>INJECTED</w:t></a:t></t></si><!-- <![CDATA[ & &amp; ]]>';
    foreach ($data as $k => $v) {
        if (is_string($v) && $k !== 'due') {  // `due` goes through the date filter
            $data[$k] = $hostile;
        }
    }
    $before = members(fileFixture($file))[$part];
    $after = members(Template::renderFile(fileFixture($file), $data))[$part];
    expect(implode("\n", visibleText($after)))->toContain($hostile);
    // The element structure is unchanged: same number of elements of every name.
    $count = static function (string $xml): array {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $names = [];
        foreach ($dom->getElementsByTagName('*') as $el) {
            $names[$el->nodeName] = ($names[$el->nodeName] ?? 0) + 1;
        }
        ksort($names);

        return $names;
    };
    expect($count($after))->toBe($count($before));
})->with([
    'docx' => ['proposal.docx', PROPOSAL_DATA, 'word/footnotes.xml'],
    'pptx' => ['deck.pptx', ['client' => 'x', 'amount' => 1, 'project' => 'x'], 'ppt/slideMasters/slideMaster1.xml'],
    'xlsx' => ['quote.xlsx', ['client' => 'x', 'amount' => 1, 'project' => 'x', 'formula' => 'x', 'note' => 'x'], 'xl/sharedStrings.xml'],
]);

it('refuses a value holding a character XML 1.0 cannot carry, naming the variable and never the value', function (): void {
    $e = fileError(fn () => Template::renderFile(fileFixture('proposal.docx'), ['client' => "S3CRET\x01"] + PROPOSAL_DATA));
    expect($e->toArray())->toBe(['code' => 'VALUE_XML_CHAR', 'path' => '/word/document.xml#p0', 'variable' => 'client']);
    expect($e->getMessage())->not->toContain('S3CRET');
});

it('never re-scans a value: a value holding {{ }} reaches the file literally', function (): void {
    $out = members(Template::renderFile(fileFixture('proposal.docx'), ['client' => '{{ project }}'] + PROPOSAL_DATA));
    expect(visibleText($out['word/header1.xml']))->toBe(['{{ PROJECT }} - CONFIDENTIAL']);
});

// ─── xlsx: never a formula ───────────────────────────────────────────────

it('never writes a filled xlsx value as a formula, whatever it starts with', function (string $value): void {
    $data = ['client' => $value, 'amount' => 1, 'project' => 'P', 'formula' => $value, 'note' => 'N'];
    $out = members(Template::renderFile(fileFixture('quote.xlsx'), $data));
    $sheet = $out['xl/worksheets/sheet1.xml'];
    expect(substr_count($sheet, '<f>'))->toBe(1);  // the author's own formula, A5
    expect($sheet)->toContain('<c r="B4" t="inlineStr"><is><t>');
    expect(visibleText($out['xl/sharedStrings.xml'])[1])->toBe($value);
    expect(visibleText($sheet)[0])->toBe($value);
})->with(['=1+1', '+1', '-1', '@SUM(A1)', '=HYPERLINK("http://example.com","x")']);

it('keeps a whole-token number in a cell as TEXT, in the string the cell already was', function (): void {
    $out = members(Template::renderFile(fileFixture('quote.xlsx'), ['client' => 'A', 'amount' => 1500, 'project' => 'P', 'formula' => 'F', 'note' => 'N']));
    expect($out['xl/worksheets/sheet1.xml'])->toContain('<c r="B2" t="s"><v>3</v></c>');
    expect($out['xl/sharedStrings.xml'])->toContain('<si><t>1500</t></si>');
});

// ─── Declarations ────────────────────────────────────────────────────────

it('checks a declarations sidecar the way inspect checks variables', function (): void {
    $result = Template::inspectFile(fileFixture('proposal.docx'), ['variables' => ['client' => ['type' => 'text']]]);
    // Exactly what inspect() reports for the same declaration in an envelope.
    expect($result['issues'][0])->toBe(['code' => 'DECL_INVALID', 'path' => '/variables/client/type']);
});

it('refuses a token naming an image variable: an image cannot be text', function (): void {
    $vars = fileCases()['declarations']['proposal'];
    $vars['project'] = ['type' => 'image'];
    $issues = Template::inspectFile(fileFixture('proposal.docx'), ['variables' => $vars])['issues'];
    expect($issues[0])->toBe(['code' => 'TOKEN_IMAGE_FIELD', 'path' => '/word/document.xml#p6']);
});

it('bounds the tokens in a file with limits.tokens, as inspect does', function (): void {
    $vars = fileCases()['declarations']['proposal'];
    $issues = Template::inspectFile(fileFixture('proposal.docx'), ['variables' => $vars, 'limits' => ['tokens' => 2]])['issues'];
    expect($issues)->toBe([['code' => 'TEMPLATE_TOO_MANY_TOKENS', 'path' => '']]);
});

it('bounds the bytes it writes with limits.outputBytes', function (): void {
    $e = fileError(fn () => Template::renderFile(fileFixture('proposal.docx'), PROPOSAL_DATA, ['limits' => ['outputBytes' => 40]]));
    expect($e->errorCode)->toBe('RENDER_TOO_LARGE');
});

// ─── Hardening beyond the rows ───────────────────────────────────────────

it('refuses what is not a zip', function (string $bytes): void {
    expect(fileError(fn () => Template::renderFile($bytes, []))->toArray())->toBe(['code' => 'FILE_ARCHIVE', 'path' => '']);
})->with(['empty' => [''], 'text' => ['not a zip at all'], 'truncated' => [substr(fileFixture('proposal.docx'), 0, 2000)]]);

it('refuses a member whose bytes do not match its CRC', function (): void {
    $bytes = fileFixture('proposal.docx');
    $raw = rawRecords($bytes)['word/styles.xml'];
    $at = strpos($bytes, $raw) + strlen($raw) - 3;
    $bytes[$at] = chr(ord($bytes[$at]) ^ 0xFF);
    expect(fileError(fn () => Template::renderFile($bytes, PROPOSAL_DATA))->errorCode)->toBe('FILE_ARCHIVE');

    // A host that turns warnings into exceptions (Laravel does) still gets the
    // typed error: ext-zip's CRC warning must not escape as an ErrorException.
    set_error_handler(static function (int $no, string $message): never {
        throw new ErrorException($message, 0, $no);
    });
    try {
        expect(fileError(fn () => Template::renderFile($bytes, PROPOSAL_DATA))->errorCode)->toBe('FILE_ARCHIVE');
    } finally {
        restore_error_handler();
    }
});

it('refuses entry names that escape or are not plain', function (string $name): void {
    $members = members(fileFixture('proposal.docx'));
    // Re-zip with ZipArchive, then patch the extra member's name in both headers.
    $tmp = tempnam(sys_get_temp_dir(), 'ftn');
    $z = new ZipArchive;
    $z->open($tmp, ZipArchive::OVERWRITE);
    foreach ($members as $n => $c) {
        $z->addFromString($n, $c);
    }
    $placeholder = str_repeat('Q', strlen($name));
    $z->addFromString($placeholder, 'x');
    $z->close();
    $bytes = str_replace($placeholder, $name, (string) file_get_contents($tmp));
    unlink($tmp);
    expect(fileError(fn () => Template::renderFile($bytes, PROPOSAL_DATA))->errorCode)->toBe('FILE_PATH');
})->with(['/etc/passwd', 'word\\..\\x', 'C:x', 'a/../../b', './x', "a\x01b"]);

it('never fetches anything: an external target survives verbatim and nothing resolves it', function (): void {
    $bytes = fileFixture('hostile-external.docx');
    $out = Template::renderFile($bytes, PROPOSAL_DATA);
    expect(rawRecords($out)['word/_rels/document.xml.rels'])->toBe(rawRecords($bytes)['word/_rels/document.xml.rels']);
    expect(members($out)['word/_rels/document.xml.rels'])->toContain('Target="http://example.com/track.png" TargetMode="External"');
});

it('reports a FILE_ refusal from inspectFile as its single issue, without throwing', function (): void {
    expect(Template::inspectFile(fileFixture('macro.docm')))->toMatchArray(['ok' => false, 'issues' => [['code' => 'FILE_MACRO_ENABLED', 'path' => '']]]);
});

it('throws TemplateError, never a raw exception, for a hostile file', function (string $file): void {
    expect(fileError(fn () => Template::renderFile(fileFixture($file), []))->errorCode)->toStartWith('FILE_');
})->with(['hostile-doctype.docx', 'hostile-bomb.docx', 'hostile-traversal.docx', 'encrypted.docx', 'macro.docm']);

it('validates fileLimits like limits: an unknown or negative one is refused', function (array $fileLimits): void {
    expect(fn () => Template::inspectFile(fileFixture('proposal.docx'), ['fileLimits' => $fileLimits]))->toThrow(InvalidArgumentException::class);
})->with([[['nope' => 1]], [['entries' => -1]], [['entries' => '5']]]);
