<?php

declare(strict_types=1);

/*
 * Builds the office-file fixtures in fixtures/files/ (SPEC.md section 12).
 *
 *   php scripts/files/build-fixtures.php
 *
 * Run from INSIDE the envelope: the writer-built fixtures come from the
 * sibling checkouts of last-word and dark-slide (through scripts/parity/write.php,
 * one writer per process). Everything else is hand-authored minimal OOXML,
 * written here, because a split run with chosen formatting, a text box, a
 * footnote and every hostile shape have to be exactly what the test says they are.
 * No third-party sample file is used.
 *
 * The zips are written by the small writer below rather than ZipArchive, for two
 * reasons: the output is byte-deterministic (fixed timestamps, fixed order), and
 * the hostile fixtures need names and flags ZipArchive refuses to write. Members
 * are DEFLATED (gzdeflate, this script only -- the library never deflates with
 * zlib), so "copied byte for byte" is tested against compressed records, which
 * is the case that matters.
 *
 * The fixtures are committed. CI does not run this script. Every fixture is
 * byte-identical from one build to the next except deck.pptx, whose
 * docProps carry dark-slide's creation time.
 */

$root = dirname(__DIR__, 2);
$repos = dirname($root);
$out = $root.'/fixtures/files';
if (! is_dir($out)) {
    mkdir($out, 0777, true);
}

const DOS_TIME = 0;                                   // 00:00:00
const DOS_DATE = ((2026 - 1980) << 9) | (10 << 5) | 29; // 2026-10-29

/**
 * @param  list<array{0: string, 1: string, 2?: array{method?: int, flags?: int}}>  $entries  [name, bytes, options]
 */
function zipBytes(array $entries): string
{
    $locals = '';
    $central = '';
    foreach ($entries as $entry) {
        [$name, $data] = $entry;
        $opt = $entry[2] ?? [];
        $method = $opt['method'] ?? 8;
        $flags = $opt['flags'] ?? 0;
        $stored = $method === 8 ? gzdeflate($data, 9) : $data;
        $crc = crc32($data);
        $offset = strlen($locals);
        $version = $method === 8 ? 20 : 10;
        $locals .= pack('VvvvvvVVVvv', 0x04034B50, $version, $flags, $method, DOS_TIME, DOS_DATE,
            $crc, strlen($stored), strlen($data), strlen($name), 0).$name.$stored;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, $version, $flags, $method, DOS_TIME, DOS_DATE,
            $crc, strlen($stored), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset).$name;
    }

    return $locals.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($central), strlen($locals), 0);
}

/** Every member of a zip, in central-directory order, decompressed. */
function unzipAll(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ftfx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive;
    if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('not a zip');
    }
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[] = [$zip->getNameIndex($i), $zip->getFromIndex($i)];
    }
    $zip->close();
    unlink($tmp);

    return $parts;
}

/** Replace exactly once, or stop: a fixture edit that silently did nothing would test nothing. */
function once(string $haystack, string $find, string $replace): string
{
    $n = substr_count($haystack, $find);
    if ($n !== 1) {
        throw new RuntimeException("expected exactly one occurrence of `{$find}`, found {$n}");
    }

    return str_replace($find, $replace, $haystack);
}

function writer(string $repos, string $root, string $writer, array $doc): string
{
    $dir = sys_get_temp_dir().'/ftfx-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/in.json", json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/parity/write.php').' write '.$writer.' '
        .escapeshellarg("{$dir}/in.json").' '.escapeshellarg("{$dir}/out");
    exec($cmd.' 2>&1', $lines, $code);
    $result = json_decode(implode("\n", $lines), true);
    if ($code !== 0 || ($result['errors'] ?? null) !== []) {
        throw new RuntimeException("{$writer} refused the fixture: ".implode("\n", $lines));
    }
    $bytes = (string) file_get_contents("{$dir}/out");
    unlink("{$dir}/in.json");
    unlink("{$dir}/out");
    rmdir($dir);

    return $bytes;
}

function put(string $out, string $name, string $bytes): void
{
    file_put_contents("{$out}/{$name}", $bytes);
    echo str_pad($name, 28).strlen($bytes)." bytes\n";
}

// ─── Shared OOXML pieces ─────────────────────────────────────────────────

const XML_DECL = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\r\n";
const W_NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
const CT_W = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';
const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

// A 1x1 transparent PNG: an untouched binary member.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

function docxContentTypes(string $main, bool $macro = false): string
{
    return XML_DECL.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Default Extension="png" ContentType="image/png"/>'
        .($macro ? '<Default Extension="bin" ContentType="application/vnd.ms-office.vbaProject"/>' : '')
        .'<Override PartName="/word/document.xml" ContentType="'.$main.'"/>'
        .'<Override PartName="/word/styles.xml" ContentType="'.CT_W.'styles+xml"/>'
        .'<Override PartName="/word/header1.xml" ContentType="'.CT_W.'header+xml"/>'
        .'<Override PartName="/word/footer1.xml" ContentType="'.CT_W.'footer+xml"/>'
        .'<Override PartName="/word/footnotes.xml" ContentType="'.CT_W.'footnotes+xml"/>'
        .'<Override PartName="/word/comments.xml" ContentType="'.CT_W.'comments+xml"/>'
        .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        .'</Types>';
}

$rootRels = XML_DECL.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    .'<Relationship Id="rId1" Type="'.REL.'/officeDocument" Target="word/document.xml"/>'
    .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
    .'</Relationships>';

$core = XML_DECL.'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/">'
    .'<dc:title>Proposal</dc:title><dc:creator>fancy-template fixtures</dc:creator></cp:coreProperties>';

$docRels = XML_DECL.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    .'<Relationship Id="rIdStyles" Type="'.REL.'/styles" Target="styles.xml"/>'
    .'<Relationship Id="rIdH1" Type="'.REL.'/header" Target="header1.xml"/>'
    .'<Relationship Id="rIdF1" Type="'.REL.'/footer" Target="footer1.xml"/>'
    .'<Relationship Id="rIdFn" Type="'.REL.'/footnotes" Target="footnotes.xml"/>'
    .'<Relationship Id="rIdCm" Type="'.REL.'/comments" Target="comments.xml"/>'
    .'<Relationship Id="rIdImg" Type="'.REL.'/image" Target="media/image1.png"/>'
    .'</Relationships>';

$styles = XML_DECL.'<w:styles '.W_NS.'><w:docDefaults><w:rPrDefault><w:rPr><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
    .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
    .'<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:sz w:val="48"/></w:rPr></w:style>'
    .'</w:styles>';

// The body. Paragraph indexes (the `#pN` in a location) are in the comments.
$textBox = '<w:r><w:drawing><wp:anchor distT="0" distB="0" distL="114300" distR="114300" simplePos="0" relativeHeight="251659264" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1">'
    .'<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="column"><wp:posOffset>0</wp:posOffset></wp:positionH>'
    .'<wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV><wp:extent cx="2743200" cy="457200"/>'
    .'<wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapSquare wrapText="bothSides"/><wp:docPr id="1" name="Text Box 1"/><wp:cNvGraphicFramePr/>'
    .'<a:graphic><a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"><wps:wsp><wps:cNvSpPr txBox="1"/>'
    .'<wps:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="2743200" cy="457200"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></wps:spPr>'
    .'<wps:txbx><w:txbxContent><w:p><w:r><w:t>Box: {{ project }}</w:t></w:r></w:p></w:txbxContent></wps:txbx><wps:bodyPr/></wps:wsp></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>';

$document = XML_DECL.'<w:document '.W_NS.' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"><w:body>'
    // p0: one run
    .'<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>Proposal for {{ client }}</w:t></w:r></w:p>'
    // p1: a token split across a BOLD and an ITALIC run, with spell-check marks between
    .'<w:p><w:r><w:t xml:space="preserve">Dear </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{{ cli</w:t></w:r><w:proofErr w:type="spellStart"/>'
    .'<w:r><w:rPr><w:i/></w:rPr><w:t>ent }}</w:t></w:r><w:proofErr w:type="spellEnd"/><w:r><w:t>, thank you.</w:t></w:r></w:p>'
    // p2: a token over THREE runs, the last also carrying a second token and text after it
    .'<w:p><w:r><w:t xml:space="preserve">Total: {{ amo</w:t></w:r><w:r><w:rPr><w:color w:val="C00000"/></w:rPr><w:t>unt | mon</w:t></w:r>'
    .'<w:r><w:rPr><w:u w:val="single"/></w:rPr><w:t>ey:USD }} due {{ due | date:"d MMMM yyyy" }}</w:t></w:r></w:p>'
    // p3: a token written with character references, and an entity after it
    .'<w:p><w:r><w:t>Ref &#123;&#x7B; ref }} &amp; more</w:t></w:r></w:p>'
    // p4: nothing to fill
    .'<w:p><w:r><w:t>Nothing to fill here.</w:t></w:r></w:p>'
    // p5, p6: a table
    .'<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr><w:tblGrid><w:gridCol w:w="4000"/><w:gridCol w:w="4000"/></w:tblGrid>'
    .'<w:tr><w:tc><w:tcPr><w:tcW w:w="4000" w:type="dxa"/></w:tcPr><w:p><w:r><w:t>Project</w:t></w:r></w:p></w:tc>'
    .'<w:tc><w:tcPr><w:tcW w:w="4000" w:type="dxa"/></w:tcPr><w:p><w:r><w:t>{{ project }}</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
    // p7 holds a text box (p8, inside the drawing); the drawing is a barrier in p7
    .'<w:p><w:r><w:t xml:space="preserve">Before the box </w:t></w:r>'.$textBox.'<w:r><w:t xml:space="preserve"> after the box</w:t></w:r></w:p>'
    // p9: a footnote reference
    .'<w:p><w:r><w:t>See the note</w:t></w:r><w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:footnoteReference w:id="1"/></w:r></w:p>'
    // p10: a commented range
    .'<w:p><w:commentRangeStart w:id="0"/><w:r><w:t>Reviewed</w:t></w:r><w:commentRangeEnd w:id="0"/><w:r><w:commentReference w:id="0"/></w:r></w:p>'
    .'<w:sectPr><w:headerReference w:type="default" r:id="rIdH1"/><w:footerReference w:type="default" r:id="rIdF1"/>'
    .'<w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
    .'</w:body></w:document>';

$header = XML_DECL.'<w:hdr '.W_NS.'><w:p><w:r><w:t xml:space="preserve">{{ client | upper }} - CONFIDENTIAL</w:t></w:r></w:p></w:hdr>';
$footer = XML_DECL.'<w:ftr '.W_NS.'><w:p><w:r><w:t xml:space="preserve">Prepared {{ due | date:"yyyy-MM-dd" }}</w:t></w:r></w:p></w:ftr>';
$footnotes = XML_DECL.'<w:footnotes '.W_NS.'>'
    .'<w:footnote w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:footnote>'
    .'<w:footnote w:type="continuationSeparator" w:id="0"><w:p><w:r><w:continuationSeparator/></w:r></w:p></w:footnote>'
    .'<w:footnote w:id="1"><w:p><w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:footnoteRef/></w:r><w:r><w:t xml:space="preserve"> Prepared for {{ client }}.</w:t></w:r></w:p></w:footnote>'
    .'</w:footnotes>';
$comments = XML_DECL.'<w:comments '.W_NS.'><w:comment w:id="0" w:author="Reviewer" w:date="2026-10-29T00:00:00Z" w:initials="R">'
    .'<w:p><w:r><w:t>Check {{ project }} with legal</w:t></w:r></w:p></w:comment></w:comments>';

$proposal = [
    ['[Content_Types].xml', docxContentTypes(CT_W.'document.main+xml')],
    ['_rels/.rels', $rootRels],
    ['docProps/core.xml', $core],
    ['word/document.xml', $document],
    ['word/_rels/document.xml.rels', $docRels],
    ['word/styles.xml', $styles],
    ['word/header1.xml', $header],
    ['word/footer1.xml', $footer],
    ['word/footnotes.xml', $footnotes],
    ['word/comments.xml', $comments],
    ['word/media/image1.png', $png],
];
put($out, 'proposal.docx', zipBytes($proposal));

// The same package declared as a TEMPLATE (.dotx): filled the same way, kind "template".
$dotx = $proposal;
$dotx[0] = ['[Content_Types].xml', docxContentTypes(CT_W.'template.main+xml')];
put($out, 'proposal.dotx', zipBytes($dotx));

// ─── A docx written by last-word: a split run it produced itself ────────

put($out, 'letter.last-word.docx', writer($repos, $root, 'last-word', [
    'title' => 'Letter',
    'page' => ['size' => 'a4', 'footer' => ['left' => '{{ client }} -- confidential', 'right' => 'Page {page} of {pages}']],
    'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Proposal for {{ client }}']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Dear {{ cli', 'bold' => true], ['text' => 'ent }}, welcome.', 'italic' => true]]],
        ['type' => 'table', 'rows' => [['cells' => [
            ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Project']]]]],
            ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '{{ project }}']]]]],
        ]]]],
    ],
]));

// ─── A pptx written by dark-slide, then given a layout, a master and a split run ─

$deck = writer($repos, $root, 'dark-slide', [
    'id' => 'proposal', 'title' => 'Proposal',
    'theme' => ['name' => 'default', 'colors' => ['background' => '#FFFFFF', 'text' => '#111827', 'muted' => '#6B7280', 'accent' => '#F97316', 'surface' => '#F3F4F6']],
    'slides' => [[
        'id' => 's1',
        'elements' => [
            ['id' => 't1', 'type' => 'text', 'x' => 0.05, 'y' => 0.05, 'w' => 0.9, 'h' => 0.15, 'content' => 'Proposal for {{ client }}'],
            ['id' => 't2', 'type' => 'text', 'x' => 0.05, 'y' => 0.3, 'w' => 0.9, 'h' => 0.15, 'content' => 'Total {{ amount | money:USD }}'],
            ['id' => 'tb', 'type' => 'table', 'x' => 0.05, 'y' => 0.5, 'w' => 0.9, 'h' => 0.3,
                'columns' => [['key' => 'k', 'label' => 'Field'], ['key' => 'v', 'label' => 'Value']],
                'rows' => [['k' => 'Project', 'v' => '{{ project }}'], ['k' => 'Client', 'v' => '{{ client }}']]],
        ],
        'notes' => 'Mention {{ client }} by name.',
    ]],
]);
// The master's box at the foot of the slide, the layout's just above it, so both are legible when rendered.
$shape = static fn (int $id, string $text, int $y): string => '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="Footer text '.$id.'"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
    .'<p:spPr><a:xfrm><a:off x="457200" y="'.$y.'"/><a:ext cx="8229600" cy="228600"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
    .'<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US" sz="900"/><a:t>'.$text.'</a:t></a:r></a:p></p:txBody></p:sp>';
$members = [];
foreach (unzipAll($deck) as [$name, $data]) {
    if ($name === 'ppt/slideMasters/slideMaster1.xml') {
        $data = once($data, '</p:spTree>', $shape(90, '{{ client }} - confidential', 4800600).'</p:spTree>');
    }
    if ($name === 'ppt/slideLayouts/slideLayout1.xml') {
        $data = once($data, '</p:spTree>', $shape(91, 'Prepared for {{ client }}', 4457700).'</p:spTree>');
    }
    if ($name === 'ppt/slides/slide1.xml') {
        // Split "{{ amount | money:USD }}" over two runs, the second one bold.
        $data = once($data, '<a:t>Total {{ amount | money:USD }}</a:t></a:r>',
            '<a:t>Total {{ amo</a:t></a:r><a:r><a:rPr lang="en-US" sz="1050" b="1"/><a:t>unt | money:USD }}</a:t></a:r>');
    }
    $members[] = [$name, $data];
}
put($out, 'deck.pptx', zipBytes($members));

// ─── A hand-authored xlsx: shared strings, a rich-text split, inline strings ─

const S_NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
const CT_S = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';
$xlsx = [
    ['[Content_Types].xml', XML_DECL.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="'.CT_S.'sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="'.CT_S.'worksheet+xml"/>'
        .'<Override PartName="/xl/sharedStrings.xml" ContentType="'.CT_S.'sharedStrings+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="'.CT_S.'styles+xml"/>'
        .'</Types>'],
    ['_rels/.rels', XML_DECL.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="'.REL.'/officeDocument" Target="xl/workbook.xml"/></Relationships>'],
    ['xl/workbook.xml', XML_DECL.'<workbook '.S_NS.'><sheets><sheet name="Quote" sheetId="1" r:id="rId1"/></sheets></workbook>'],
    ['xl/_rels/workbook.xml.rels', XML_DECL.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="'.REL.'/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="'.REL.'/sharedStrings" Target="sharedStrings.xml"/>'
        .'<Relationship Id="rId3" Type="'.REL.'/styles" Target="styles.xml"/></Relationships>'],
    ['xl/styles.xml', XML_DECL.'<styleSheet '.S_NS.'><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>'],
    // si0 Client, si1 {{ client }}, si2 Amount, si3 {{ amount }}, si4 rich text split, si5 Formula
    ['xl/sharedStrings.xml', XML_DECL.'<sst '.S_NS.' count="6" uniqueCount="6">'
        .'<si><t>Client</t></si><si><t>{{ client }}</t></si><si><t>Amount</t></si><si><t>{{ amount }}</t></si>'
        .'<si><r><rPr><b/><sz val="11"/></rPr><t>{{ pro</t></r><r><rPr><i/><sz val="11"/></rPr><t xml:space="preserve">ject }} total</t></r></si>'
        .'<si><t>Formula</t></si></sst>'],
    ['xl/worksheets/sheet1.xml', XML_DECL.'<worksheet '.S_NS.'><sheetData>'
        .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>'
        .'<row r="3"><c r="A3" t="s"><v>4</v></c><c r="B3"><v>42</v></c></row>'
        .'<row r="4"><c r="A4" t="s"><v>5</v></c><c r="B4" t="inlineStr"><is><t>{{ formula }}</t></is></c></row>'
        .'<row r="5"><c r="A5"><f>B3*2</f><v>84</v></c><c r="B5" t="inlineStr"><is><t>Note: {{ note }}</t></is></c></row>'
        .'</sheetData></worksheet>'],
];
put($out, 'quote.xlsx', zipBytes($xlsx));

// ─── Hostile and refused files ───────────────────────────────────────────

// A DOCTYPE with an internal subset in the main part.
$doctype = $proposal;
$doctype[3] = ['word/document.xml', once($document, XML_DECL, XML_DECL.'<!DOCTYPE w:document [<!ENTITY x "expanded">]>')];
put($out, 'hostile-doctype.docx', zipBytes($doctype));

// A DOCTYPE in a part that is never scanned for tokens, only read.
$doctypeStyles = $proposal;
$doctypeStyles[5] = ['word/styles.xml', once($styles, XML_DECL, XML_DECL.'<!DOCTYPE w:styles SYSTEM "http://example.com/x.dtd">')];
put($out, 'hostile-doctype-styles.docx', zipBytes($doctypeStyles));

// An external image and an external hyperlink: must survive untouched, never fetched.
$external = $proposal;
$external[4] = ['word/_rels/document.xml.rels', once($docRels, '</Relationships>',
    '<Relationship Id="rIdExt" Type="'.REL.'/image" Target="http://example.com/track.png" TargetMode="External"/>'
    .'<Relationship Id="rIdLink" Type="'.REL.'/hyperlink" Target="file:///etc/passwd" TargetMode="External"/></Relationships>')];
put($out, 'hostile-external.docx', zipBytes($external));

// Path traversal in an entry name.
put($out, 'hostile-traversal.docx', zipBytes([...$proposal, ['../../evil.txt', 'owned']]));

// Two names that are the same OPC part (part names are case-insensitive).
put($out, 'hostile-case-duplicate.docx', zipBytes([...$proposal, ['WORD/Document.xml', $document]]));

// A zip bomb: 4 MiB of zeros deflates past the 1000:1 ratio bound.
$zeros = str_repeat("\0", 4 * 1024 * 1024);
if (strlen($zeros) / strlen(gzdeflate($zeros, 9)) <= 1000) {
    throw new RuntimeException('the bomb fixture does not exceed the ratio bound');
}
put($out, 'hostile-bomb.docx', zipBytes([...$proposal, ['word/media/zeros.bin', $zeros]]));

// An entry with the zip encryption flag set (stored, so the bytes are otherwise valid).
put($out, 'hostile-zip-encrypted.docx', zipBytes([...$proposal, ['word/media/secret.bin', 'secret', ['method' => 0, 'flags' => 1]]]));

// An encrypted OOXML file is an OLE compound file, not a zip. A minimal header
// plus the UTF-16LE stream name every encrypted package carries.
$cfb = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504)
    .mb_convert_encoding('EncryptionInfo', 'UTF-16LE', 'UTF-8').str_repeat("\0", 50)
    .mb_convert_encoding('EncryptedPackage', 'UTF-16LE', 'UTF-8').str_repeat("\0", 400);
put($out, 'encrypted.docx', $cfb);

// A macro-enabled document (.docm): main type is macroEnabled and it carries a VBA project.
$docm = $proposal;
$docm[0] = ['[Content_Types].xml', docxContentTypes('application/vnd.ms-word.document.macroEnabled.main+xml', true)];
put($out, 'macro.docm', zipBytes([...$docm, ['word/vbaProject.bin', "\xCC\x61 not really VBA"]]));

// A .docx main part that still carries VBA: declared by content type under another name...
$vbaDeclared = $proposal;
$vbaDeclared[0] = ['[Content_Types].xml', docxContentTypes(CT_W.'document.main+xml', true)];
put($out, 'hostile-vba-declared.docx', zipBytes([...$vbaDeclared, ['word/macros.bin', "\xCC\x61 not really VBA"]]));

// ...and present by name with no declaration at all.
put($out, 'hostile-vba-named.docx', zipBytes([...$proposal, ['word/vbaProject.bin', "\xCC\x61 not really VBA"]]));

// A zip with no [Content_Types].xml: not an OOXML package.
put($out, 'not-ooxml.zip', zipBytes([['readme.txt', 'just a zip']]));

// A token where tokens cannot be filled: in the core properties (title).
$titled = $proposal;
$titled[2] = ['docProps/core.xml', once($core, '<dc:title>Proposal</dc:title>', '<dc:title>Proposal for {{ client }}</dc:title>')];
put($out, 'token-in-properties.docx', zipBytes($titled));

// A token broken by a tab: it cannot be joined across, so it is unclosed.
$tabbed = $proposal;
$tabbed[3] = ['word/document.xml', once($document, '<w:r><w:t>Nothing to fill here.</w:t></w:r>',
    '<w:r><w:t>{{ client</w:t><w:tab/><w:t>}}</w:t></w:r>')];
put($out, 'token-across-tab.docx', zipBytes($tabbed));

echo "done\n";
