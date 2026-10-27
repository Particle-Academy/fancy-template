<?php

declare(strict_types=1);

use FancyTemplate\Template;
use FancyTemplate\TemplateError;

/*
 * One case in, the shape its suite's expected column uses out. Shared by
 * ConformanceTest.php and parity.php so the two measure the same thing.
 */

if (function_exists('runTemplateCase')) {
    return;
}

/**
 * @param  array<string, mixed>  $case
 * @return array<string, mixed>
 */
function runTemplateCase(array $case): array
{
    $in = $case['input'];
    $options = $in['options'] ?? [];

    return match ($case['fn']) {
        'tokenize' => (static function () use ($in, $options): array {
            try {
                return ['ok' => true, 'parts' => Template::tokenize($in['text'], $options)];
            } catch (TemplateError $e) {
                return ['ok' => false, 'code' => $e->errorCode];
            }
        })(),
        'render', 'preview' => (static function () use ($case, $in, $options): array {
            try {
                $doc = $case['fn'] === 'render'
                    ? Template::render($in['template'], $in['data'], $options)
                    : Template::preview($in['template'], $options);

                return ['ok' => true, 'document' => $doc];
            } catch (TemplateError $e) {
                return ['ok' => false, 'code' => $e->errorCode, 'path' => $e->path];
            }
        })(),
        'validateData' => ['errors' => Template::validateData($in['template'], $in['data'], $options)],
        'inspect' => ['issues' => array_map(
            static fn (array $i): array => ['code' => $i['code'], 'path' => $i['path']],
            Template::inspect($in['template'], $options)['issues'],
        )],
    };
}
