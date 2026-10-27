<?php

declare(strict_types=1);

use ParticleAcademy\Conformance\Conformance;

require_once __DIR__.'/runner.php';

/*
 * The template/* tables, run against the PHP leg.
 *
 * Written before this implementation, against SPEC.md. Every row runs; the
 * dark-slide and holy-sheet rows carry a stated PHP skip in the fixture
 * itself (phase 6), and the summary prints them, so "100% of what it
 * implements" is a number with a visible denominator rather than a claim.
 */

const TEMPLATE_SUITES = [
    'template/tokenize', 'template/resolve', 'template/filters', 'template/directives',
    'template/limits', 'template/profiles', 'template/inspect',
];

it('runs against the fixture set it was written against, and says which', function (): void {
    echo "\nfancy-conformance installed: ".Conformance::version()."\n";
    expect(Conformance::listSuites())->toContain(...TEMPLATE_SUITES);
});

it('passes every template/* row it does not skip, with the skips printed', function (string $suite): void {
    $summary = Conformance::runTable($suite, runTemplateCase(...));
    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_values(array_filter($summary['results'], static fn (array $r): bool => $r['status'] === 'fail'));
    expect($failures)->toBe([], "{$suite} fails: ".implode(', ', array_column($failures, 'id')));

    // The vacuity floor: a suite that loaded nothing also has no failures.
    expect($summary['passed'])->toBeGreaterThan(0);
})->with(TEMPLATE_SUITES);

it('can fail: a runner returning nothing fails every row it runs', function (string $suite): void {
    // The control for the comparison itself. If the loader's equality were
    // vacuous, the green above would mean nothing.
    $summary = Conformance::runTable($suite, static fn (): null => null);
    expect($summary['passed'])->toBe(0);
    expect($summary['failed'])->toBeGreaterThan(0);
})->with(TEMPLATE_SUITES);
