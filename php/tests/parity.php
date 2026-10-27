<?php

declare(strict_types=1);

/*
 * CROSS-LEG parity (the owner's bar: "tested with at least 90% parity on
 * template use"). Reported with numerators and denominators; exit 1 on any
 * mismatch.
 *
 * For every template/* fixture row AND every case in examples/corpus.json, run
 * all three legs -- PHP here, TypeScript through scripts/parity/node-driver.mjs
 * (the BUILT dist/), Python through scripts/parity/python_driver.py -- and
 * compare their results BYTE FOR BYTE with each other and, for fixture rows,
 * with the expected column.
 *
 * Every leg writes the same canonical JSON spelling (JSON.stringify's: numbers
 * as JavaScript writes them, strings with only the mandatory escapes, keys in
 * output order), so this compares DOCUMENTS, not encoders. Stricter than the
 * conformance loaders' comparison, which ignores key order and compares numbers
 * by value. Each leg decodes the fixtures with its own JSON decoder, as a
 * consumer would; the expected column is spelled by the Python driver, whose
 * decoder keeps key order and tells {} from [].
 *
 * Unlike the conformance tests, NO row is skipped for any leg here: the 11
 * phase-6 rows the 0.35.0 fixtures still skip for php run in all three.
 *
 * Usage (from inside the envelope, after `npm run build`):
 *   php php/tests/parity.php             the table
 *   php php/tests/parity.php --control   every leg replaced by `null`: must report 0%
 *   php php/tests/parity.php --writers   then render the corpus through the real writers
 *                                        (scripts/parity/writers.py) and compare the text
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/runner.php';

use FancyTemplate\Numbers;
use ParticleAcademy\Conformance\Conformance;

$root = dirname(__DIR__, 2);
$control = in_array('--control', $argv, true);
$writers = in_array('--writers', $argv, true);
$suites = ['template/tokenize', 'template/resolve', 'template/filters', 'template/directives', 'template/limits', 'template/profiles', 'template/inspect'];

function canon(mixed $v): string
{
    return match (true) {
        $v === null => 'null',
        is_bool($v) => $v ? 'true' : 'false',
        is_int($v), is_float($v) => Numbers::toJsString($v),
        is_string($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR),
        array_is_list($v) => '['.implode(',', array_map(canon(...), $v)).']',
        default => '{'.implode(',', array_map(
            static fn ($k, $x): string => json_encode((string) $k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR).':'.canon($x),
            array_keys($v), $v,
        )).'}',
    };
}

/** @return array<string, string> id => canonical line, in file order */
function readLines(string $file): array
{
    $out = [];
    foreach (explode("\n", rtrim((string) file_get_contents($file), "\n")) as $line) {
        [$id, $value] = explode("\t", $line, 2);
        $out[$id] = $value;
    }

    return $out;
}

/** Run a command with its output in a file and its exit code checked, never through a pipe. */
function runLeg(string $label, array $cmd, string $cwd, string $log): void
{
    $proc = proc_open($cmd, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $cwd);
    $code = proc_close($proc);
    if ($code !== 0) {
        fwrite(STDERR, "{$label} driver exited {$code}; see {$log}\n".file_get_contents($log));
        exit(2);
    }
}

if (! is_file("{$root}/dist/index.js")) {
    fwrite(STDERR, "dist/ is missing: run `npm run build` first. The TypeScript leg is measured as it ships.\n");
    exit(2);
}

$tmp = sys_get_temp_dir().'/fancy-template-parity-'.getmypid();
@mkdir($tmp, 0777, true);
$renderDir = "{$tmp}/rendered";
@mkdir($renderDir, 0777, true);

runLeg('node', ['node', 'scripts/parity/node-driver.mjs', '--out', "{$tmp}/node.txt", '--render', $renderDir], $root, "{$tmp}/node.log");
runLeg('python', ['python', '-I', 'scripts/parity/python_driver.py', '--out', "{$tmp}/python.txt", '--expected', "{$tmp}/expected.txt", '--render', $renderDir], $root, "{$tmp}/python.log");

$node = readLines("{$tmp}/node.txt");
$python = readLines("{$tmp}/python.txt");
$expected = readLines("{$tmp}/expected.txt");

// The PHP leg, in-process, on the same rows (no skip honoured).
$php = [];
foreach ($suites as $suite) {
    foreach (Conformance::cases($suite) as $case) {
        try {
            $php["{$suite}/{$case['id']}"] = canon(runTemplateCase($case));
        } catch (Throwable $e) {
            $php["{$suite}/{$case['id']}"] = 'THREW '.str_replace("\n", ' ', $e->getMessage());
        }
    }
}
$corpus = json_decode((string) file_get_contents("{$root}/examples/corpus.json"), true, 512, JSON_THROW_ON_ERROR);
foreach ($corpus['cases'] as $c) {
    $template = json_decode((string) file_get_contents("{$root}/examples/{$c['template']}"), true, 512, JSON_THROW_ON_ERROR);
    $case = ($c['preview'] ?? false)
        ? ['fn' => 'preview', 'input' => ['template' => $template]]
        : ['fn' => 'render', 'input' => ['template' => $template, 'data' => $c['data']]];
    $result = runTemplateCase($case);
    $php["corpus/{$c['id']}"] = canon($result);
    if ($result['ok']) {
        file_put_contents("{$renderDir}/".str_replace('/', '__', $c['id']).'.php.json', json_encode($result['document'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}

if ($control) {
    // A DIFFERENT wrong answer per leg, so both comparators are shown to fail:
    // three legs all answering `null` would agree with each other and prove
    // nothing about the leg-vs-leg column.
    $php = array_map(static fn (): string => '"control-php"', $php);
    $node = array_map(static fn (): string => '"control-node"', $node);
    $python = array_map(static fn (): string => '"control-python"', $python);
}

// Every leg must have produced the same set of ids, or the denominators lie.
$ids = array_keys($php);
foreach (['node' => $node, 'python' => $python] as $label => $leg) {
    if (array_keys($leg) !== $ids) {
        fwrite(STDERR, "{$label} produced a different set or order of cases than php (".count($leg).' vs '.count($ids).")\n");
        exit(2);
    }
}

$groups = [];
foreach ($ids as $id) {
    $group = str_starts_with($id, 'corpus/') ? 'corpus/'.explode('/', $id)[1] : implode('/', array_slice(explode('/', $id), 0, 2));
    $groups[$group][] = $id;
}

$cols = ['php=exp', 'node=exp', 'py=exp', '3 legs equal'];
$totals = array_fill_keys($cols, [0, 0]);
$mismatches = [];
printf("%-22s %5s  %-12s %-12s %-12s %-12s\n", 'group', 'rows', ...$cols);
foreach ($groups as $group => $rows) {
    $counts = array_fill_keys($cols, [0, 0]);
    foreach ($rows as $id) {
        $hasExpected = isset($expected[$id]);
        $checks = [
            'php=exp' => $hasExpected ? $php[$id] === $expected[$id] : null,
            'node=exp' => $hasExpected ? $node[$id] === $expected[$id] : null,
            'py=exp' => $hasExpected ? $python[$id] === $expected[$id] : null,
            '3 legs equal' => $php[$id] === $node[$id] && $node[$id] === $python[$id],
        ];
        foreach ($checks as $col => $ok) {
            if ($ok === null) {
                continue;
            }
            $counts[$col][1]++;
            $totals[$col][1]++;
            if ($ok) {
                $counts[$col][0]++;
                $totals[$col][0]++;
            } else {
                $mismatches[] = "{$col} {$id}";
            }
        }
    }
    $cell = static fn (array $c): string => $c[1] === 0 ? 'n/a' : "{$c[0]}/{$c[1]}";
    printf("%-22s %5d  %-12s %-12s %-12s %-12s\n", $group, count($rows), ...array_map($cell, array_values($counts)));
}
$pct = static fn (array $c): string => $c[1] === 0 ? 'n/a' : sprintf('%d/%d = %.1f%%', $c[0], $c[1], 100 * $c[0] / $c[1]);
echo "\nTOTAL\n";
foreach ($totals as $col => $c) {
    printf("  %-14s %s\n", $col, $pct($c));
}
foreach ($mismatches as $m) {
    echo "  MISMATCH {$m}\n";
}
echo "\nrendered corpus documents: {$renderDir}\n";

$allEqual = $mismatches === [];
if ($writers && ! $control) {
    runLeg('writers', ['python', '-I', 'scripts/parity/writers.py', '--rendered', $renderDir], $root, "{$tmp}/writers.log");
    echo "\n".file_get_contents("{$tmp}/writers.log");
}

exit($allEqual ? 0 : 1);
