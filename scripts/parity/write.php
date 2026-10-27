<?php

declare(strict_types=1);

/*
 * The PHP writers, for scripts/parity/writers.py. One writer per process, from
 * its own checkout in the envelope (repos/<writer>/vendor), so two writers'
 * autoloaders never meet.
 *
 *   php write.php write <writer> <in.json> <out>   validate, then write the bytes;
 *                                                  prints {"errors": [...]} (the writer's own validation)
 *   php write.php pdftext <in.pdf>                 fine-print's own reader, unredacted: the text of every page
 */

$repos = dirname(__DIR__, 3);
[$self, $mode] = [$argv[0], $argv[1] ?? ''];

if ($mode === 'pdftext') {
    require "{$repos}/fine-print/vendor/autoload.php";
    $read = \FinePrint\Agent::readUnredacted((string) file_get_contents($argv[2]));
    echo json_encode(array_map(static fn (array $p): string => $p['text'], $read['pages'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}

[, , $writer, $in, $out] = $argv;
$agent = match ($writer) {
    'fine-print' => '\FinePrint\Agent',
    'last-word' => '\LastWord\Agent',
    'dark-slide' => '\DarkSlide\Agent',
    'holy-sheet' => '\HolySheet\Agent',
};
require "{$repos}/{$writer}/vendor/autoload.php";

$doc = json_decode((string) file_get_contents($in), true, 512, JSON_THROW_ON_ERROR);
$errors = $agent::validate($doc);
file_put_contents($out, $agent::toBytes($doc));
echo json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
