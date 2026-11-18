<?php

declare(strict_types=1);

use FancyTemplate\Template;
use FinePrint\Agent;
use ParticleAcademy\Conformance\Conformance;

/*
 * End to end: the `$spread` quote, rendered and then written by fine-print.
 *
 * The template and data are the conformance row itself
 * (template/directives 0033-spread-quote-end-to-end), not a copy, so the row
 * that every leg renders byte for byte is also the one proven to be a
 * document the real writer accepts, lints clean, writes and reads back.
 *
 * The defect this exists for: a group of blocks per pricing model written as
 * an array `$value` rendered as nested arrays, which fine-print refuses with a
 * SchemaException. Row 0026 pins that nesting; this pins that the spread form
 * is a document.
 */

const QUOTE_ROW = '0033-spread-quote-end-to-end';

function quoteRow(): array
{
    foreach (Conformance::cases('template/directives') as $case) {
        if ($case['id'] === QUOTE_ROW) {
            return $case;
        }
    }
    throw new RuntimeException('row '.QUOTE_ROW.' is not in the installed fixture set');
}

it('renders the row to exactly its expected document', function (): void {
    $row = quoteRow();

    expect(Template::render($row['input']['template'], $row['input']['data']))->toBe($row['expected']['document']);
});

it('writes through fine-print: a page per model, every line and subtotal on its page', function (): void {
    $row = quoteRow();
    $doc = Template::render($row['input']['template'], $row['input']['data']);

    expect(Agent::validate($doc))->toBe([]);
    // Lint is layout advice. With two short sample models the second page is
    // sparse by design (one page per model), which is a warning, never an error.
    $errors = array_filter(Agent::lint($doc), static fn (array $f): bool => ($f['severity'] ?? null) === 'error');
    expect($errors)->toBe([]);
    expect(array_column(Agent::lint($doc), 'rule'))->not->toContain('no-page-numbers');

    $read = Agent::read(Agent::toBytes($doc));
    expect($read['pageCount'])->toBe(2);

    $first = $read['pages'][0]['text'];
    $second = $read['pages'][1]['text'];
    foreach (['Quote for Acme', 'Starter', 'Seats', 'Onboarding', '$500.00', '$1,200.00', 'Subtotal: $1,700.00'] as $text) {
        expect(str_contains($first, $text))->toBeTrue("page 1 lacks {$text}");
    }
    foreach (['Growth', 'Seats', '$1,800.00', 'Subtotal: $1,800.00'] as $text) {
        expect(str_contains($second, $text))->toBeTrue("page 2 lacks {$text}");
    }
    expect(str_contains($first, 'Growth'))->toBeFalse('the second model leaked onto the first page');
});

it('is the document fine-print refuses when written without $spread', function (): void {
    $row = quoteRow();
    $template = $row['input']['template'];
    unset($template['document']['blocks'][1]['$spread']);
    $nested = Template::render($template, $row['input']['data']);

    // The consumer's report: [[4 blocks], [4 blocks]] where blocks belong.
    expect($nested['blocks'][1])->toBeArray()->and(array_is_list($nested['blocks'][1]))->toBeTrue();
    expect(Agent::validate($nested))->not->toBe([]);
});
