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
 * Phase 6 built the dark-slide and holy-sheet profiles in this leg. The 0.35.0
 * fixtures still carry `skip.php` on those 11 rows (written before the leg
 * existed), and the pin does not move in this change, so the loader would keep
 * skipping them. They are run HERE instead, every one, and must pass: a skip
 * that outlives its reason is coverage the log claims and nobody has.
 *
 * When fancy-conformance drops the skips, runTable above runs them and this
 * test's floor (11) becomes the thing that fails, which is the prompt to
 * delete it.
 */
it('passes every row the pinned fixtures still skip for php (phase 6 built them)', function (): void {
    $ran = [];
    foreach (TEMPLATE_SUITES as $suite) {
        foreach (Conformance::cases($suite) as $case) {
            if (! isset($case['skip']['php'])) {
                continue;
            }
            $actual = runTemplateCase($case);
            expect(Conformance::equals($actual, $case['expected']))->toBeTrue("{$suite}/{$case['id']}: ".json_encode($actual));
            $ran[] = "{$suite}/{$case['id']}";
        }
    }
    echo "\nphp-skipped rows run and passed: ".count($ran)."\n";

    expect($ran)->toHaveCount(11);
});

it('can fail: a runner returning nothing fails every row it runs', function (string $suite): void {
    // The control for the comparison itself. If the loader's equality were
    // vacuous, the green above would mean nothing.
    $summary = Conformance::runTable($suite, static fn (): null => null);
    expect($summary['passed'])->toBe(0);
    expect($summary['failed'])->toBeGreaterThan(0);
})->with(TEMPLATE_SUITES);
