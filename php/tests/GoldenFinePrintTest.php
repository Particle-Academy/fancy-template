<?php

declare(strict_types=1);

use FancyTemplate\Template;
use FinePrint\Agent;

/*
 * Golden: a template rendered through the REAL writer and read back.
 *
 * The conformance rows prove the document JSON. This proves the JSON is a
 * document fine-print accepts, draws and returns: rendered, validated by
 * fine-print's own validator and linter, written to PDF bytes, and read back
 * with fine-print's own reader, asserting the substituted text survived the
 * whole trip.
 *
 * The template is sandbox/courses-cert/prototype.php tokenised -- the
 * certificate laravel-courses is moving to. GOLDEN_TEXT is that prototype's own
 * read-back, captured from running it on 2026-10-27 with the same values, so the
 * template is held to the document a person built by hand.
 */

const GOLDEN_TEXT = <<<'TXT'
CERTIFICATE OF COMPLETION
Certificate of Completion
This is to certify that
Ada Lovelace
has successfully completed Introduction to Analytical Engines, course.

Issued Issued by Verification
October 27, 2026 Particle Academy A1B2C3D4E5F60718
TXT;

function certificateTemplate(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/examples/certificate.fine-print.json'), true, 64, JSON_THROW_ON_ERROR);
}

function certificateData(array $overrides = []): array
{
    // $overrides FIRST: PHP's array union keeps the left operand's keys.
    return $overrides + [
        'recipientName' => 'Ada Lovelace',
        'programName' => 'Introduction to Analytical Engines',
        'issuedAt' => '2026-10-27',
        'verificationCode' => 'A1B2C3D4E5F60718',
    ];
}

/** Render, write and read back through fine-print. @return array{doc: array, text: string} */
function throughFinePrint(array $data): array
{
    $doc = Template::render(certificateTemplate(), $data);
    expect(Agent::validate($doc))->toBe([]);
    expect(Agent::lint($doc))->toBe([]);

    $read = Agent::read(Agent::toBytes($doc));
    expect($read['pageCount'])->toBe(1);

    return ['doc' => $doc, 'text' => $read['pages'][0]['text']];
}

it('inspects clean', function (): void {
    expect(Template::inspect(certificateTemplate()))->toBe(['ok' => true, 'issues' => []]);
});

it('reads back exactly what the hand-built prototype reads back', function (): void {
    $text = throughFinePrint(certificateData())['text'];

    expect(str_replace("\r\n", "\n", trim($text)))->toBe(GOLDEN_TEXT);
});

it('carries different data through to the page', function (): void {
    $text = throughFinePrint(certificateData([
        'recipientName' => 'Grace Hopper',
        'programName' => 'Compilers',
        'programDetail' => 'workshop',
        'issuedAt' => '2027-01-05',
        'issuer' => 'Harvard Computation Lab',
        'verificationCode' => 'ZZ99',
    ]))['text'];

    foreach (['Grace Hopper', 'Compilers', ', workshop.', 'January 5, 2027', 'Harvard Computation Lab', 'ZZ99'] as $expected) {
        expect(str_contains($text, $expected))->toBeTrue("missing from the PDF: {$expected}");
    }
    expect(str_contains($text, 'Ada Lovelace'))->toBeFalse();
});

it('keeps or drops the $if paragraph with the data', function (): void {
    $with = throughFinePrint(certificateData(['certificateNumber' => 'NO-0042']));
    $without = throughFinePrint(certificateData());

    expect(str_contains($with['text'], 'Certificate No. NO-0042'))->toBeTrue();
    expect(str_contains($without['text'], 'Certificate No.'))->toBeFalse();
    expect(count($with['doc']['blocks']))->toBe(count($without['doc']['blocks']) + 1);
    expect(json_encode($with['doc']))->not->toContain('$if');
});

it('never re-reads a value as a template: braces in data reach the page literally', function (): void {
    $text = throughFinePrint(certificateData(['recipientName' => '{{ issuer }} {page}']))['text'];

    expect(str_contains($text, '{{ issuer }} {page}'))->toBeTrue();
});

it('previews from the declared examples through the writer', function (): void {
    $doc = Template::preview(certificateTemplate());
    $text = Agent::read(Agent::toBytes($doc))['pages'][0]['text'];

    expect(str_replace("\r\n", "\n", trim($text)))->toBe(GOLDEN_TEXT);
});
