<?php

declare(strict_types=1);

use ParticleAcademy\Conformance\Conformance;

require_once __DIR__.'/runner.php';

/*
 * The template/* tables, run against the PHP leg.
 *
 * Written before this implementation, against SPEC.md. Every row runs and
 * none is skipped (asserted below); the summary prints each suite's count, so
 * "100%" is a number with a visible denominator rather than a claim.
 */

const TEMPLATE_SUITES = [
    'template/tokenize', 'template/resolve', 'template/filters', 'template/directives',
    'template/limits', 'template/profiles', 'template/inspect',
];

/*
 * The fixture set this leg was written and verified against. Moving it is one
 * deliberate commit: re-run every table against the new version first, then
 * move this constant and composer.json's exact require-dev pin together. The
 * two tests below fail until they agree with what is installed.
 */
const PINNED_SUITE_VERSION = '0.35.0';

it('runs against the pinned fixture set, and says which', function (): void {
    echo "\nfancy-conformance installed: ".Conformance::version().', pinned: '.PINNED_SUITE_VERSION."\n";

    expect(Conformance::version())->toBe(PINNED_SUITE_VERSION);
    expect(Conformance::listSuites())->toContain(...TEMPLATE_SUITES);
});

it('pins the fixture set exactly in composer.json', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require-dev']['particle-academy/fancy-conformance'] ?? null)->toBe(PINNED_SUITE_VERSION);
});

it('passes every template/* row it does not skip, with the skips printed', function (string $suite): void {
    $summary = Conformance::runTable($suite, runTemplateCase(...));
    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_values(array_filter($summary['results'], static fn (array $r): bool => $r['status'] === 'fail'));
    expect($failures)->toBe([], "{$suite} fails: ".implode(', ', array_column($failures, 'id')));

    // The vacuity floor: a suite that loaded nothing also has no failures.
    expect($summary['passed'])->toBeGreaterThan(0);
})->with(TEMPLATE_SUITES);

/*
 * No template/* row is skipped for PHP since fancy-conformance 0.36.0 dropped
 * the 11 phase-6 skips. Asserted, so a skip that comes back is seen rather
 * than printed and passed.
 */
it('skips no template/* row for php', function (string $suite): void {
    $skipped = array_column(array_filter(Conformance::cases($suite), static fn (array $c): bool => isset($c['skip']['php'])), 'id');

    expect($skipped)->toBe([]);
})->with(TEMPLATE_SUITES);

it('can fail: a runner returning nothing fails every row it runs', function (string $suite): void {
    // The control for the comparison itself. If the loader's equality were
    // vacuous, the green above would mean nothing.
    $summary = Conformance::runTable($suite, static fn (): null => null);
    expect($summary['passed'])->toBe(0);
    expect($summary['failed'])->toBeGreaterThan(0);
})->with(TEMPLATE_SUITES);
