<?php

declare(strict_types=1);

/*
 * Parity measure (the owner's 90% bar), REPORTED, not a gate.
 *
 * For every template/* row: run the PHP leg, encode its result as JSON, and
 * compare BYTE FOR BYTE with the fixture's expected value encoded the same way.
 * Stricter than the conformance loader's comparison, which is
 * key-order-insensitive and compares numbers by value: here key order, number
 * spelling (3 vs 3.0) and every string byte must match.
 *
 * Usage: php php/tests/parity.php [--control]
 *   --control replaces the implementation with one that returns null for every
 *   row, to prove the comparator can fail (it must report 0%).
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/runner.php';

use ParticleAcademy\Conformance\Conformance;

$control = in_array('--control', $argv, true);
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
$suites = ['template/tokenize', 'template/resolve', 'template/filters', 'template/directives', 'template/limits', 'template/profiles', 'template/inspect'];

$total = $same = $skipped = 0;
$mismatches = [];
foreach ($suites as $suite) {
    $suiteTotal = $suiteSame = 0;
    foreach (Conformance::cases($suite) as $case) {
        if (isset($case['skip']['php'])) {
            $skipped++;

            continue;
        }
        $actual = $control ? null : runTemplateCase($case);
        $a = json_encode($actual, $flags);
        $e = json_encode($case['expected'], $flags);
        $suiteTotal++;
        if ($a === $e) {
            $suiteSame++;
        } else {
            $mismatches[] = "{$suite}/{$case['id']}";
        }
    }
    printf("%-22s %3d / %3d byte-identical\n", $suite, $suiteSame, $suiteTotal);
    $total += $suiteTotal;
    $same += $suiteSame;
}

printf("\nPHP leg: %d / %d byte-identical = %.1f%%  (%d rows not run: skipped for php in the fixture)\n", $same, $total, $total ? 100 * $same / $total : 0, $skipped);
foreach ($mismatches as $m) {
    echo "  MISMATCH {$m}\n";
}
exit($same === $total ? 0 : 1);
