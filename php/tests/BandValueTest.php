<?php

declare(strict_types=1);

use FancyTemplate\Template;
use FancyTemplate\TemplateError;

/*
 * User data must not become a live field. A value holding a writer's band token
 * would otherwise print a page number, a title or a date in place of what the
 * person typed, and neither writer has an escape for it. Refused, never rewritten.
 */

function fpBandTemplate(string $writer, array $document): array
{
    return ['fancyTemplate' => 1, 'writer' => $writer, 'variables' => ['v' => ['type' => 'string']], 'document' => $document];
}

function fpBandCode(array $template, string $value): ?string
{
    try {
        Template::render($template, ['v' => $value]);
    } catch (TemplateError $e) {
        return $e->errorCode;
    }

    return null;
}

it('refuses a band value holding any field token', function (string $value) {
    foreach (['fine-print', 'last-word'] as $writer) {
        expect(fpBandCode(fpBandTemplate($writer, ['blocks' => [], 'page' => ['footer' => ['left' => '{{ v }}']]]), $value))->toBe('VALUE_PAGE_FIELD');
    }
})->with([
    '{page}', '{pages}', '{section}', '{sectionpages}', '{author}', '{title}', '{filename}', '{date}', '{time}',
    'see {section} here', '{date:d MMMM yyyy}', '{time:HH:mm}',
]);

it('keeps a value that is only brace-shaped', function (string $value) {
    expect(Template::render(fpBandTemplate('fine-print', ['blocks' => [], 'page' => ['footer' => ['left' => '{{ v }}']]]), ['v' => $value])['page']['footer']['left'])->toBe($value);
})->with(['{Page}', '{pagex}', 'x{ page }', '{date:}', '{date:a{b}', 'no braces']);

it('takes tokens in every fine-print band slot, and refuses a token value there', function (string $key, string $slot) {
    $template = fpBandTemplate('fine-print', ['blocks' => [], 'page' => [$key => [$slot => '{{ v }}']]]);

    expect(Template::render($template, ['v' => 'Ada'])['page'][$key][$slot])->toBe('Ada')
        ->and(fpBandCode($template, '{section}'))->toBe('VALUE_PAGE_FIELD');
})->with(function () {
    foreach (['header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter'] as $key) {
        foreach (['left', 'center', 'right'] as $slot) {
            yield [$key, $slot];
        }
    }
});

it('takes tokens in a band on a fine-print break, and refuses a token value there', function (string $key) {
    $template = fpBandTemplate('fine-print', ['blocks' => [['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', $key => ['left' => '{{ v }}']]]]]);

    expect(Template::render($template, ['v' => 'Ada'])['blocks'][0]['page'][$key]['left'])->toBe('Ada')
        ->and(fpBandCode($template, '{pages}'))->toBe('VALUE_PAGE_FIELD');
})->with(['header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter']);

it('refuses a token value in a blocks band, in a table cell too, for both writers', function (string $writer) {
    $para = ['type' => 'paragraph', 'runs' => [['text' => '{{ v }}']]];
    $cell = ['type' => 'table', 'rows' => [['cells' => [['blocks' => [$para]]]]]];

    foreach ([$para, $cell] as $block) {
        expect(fpBandCode(fpBandTemplate($writer, ['blocks' => [], 'page' => ['footer' => ['blocks' => [$block]]]]), 'see {page}'))->toBe('VALUE_PAGE_FIELD');
    }
})->with(['fine-print', 'last-word']);

it('keeps a body run with a token-shaped value literal: only a band is live', function () {
    $template = fpBandTemplate('fine-print', ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '{{ v }}']]]]]);

    expect(Template::render($template, ['v' => '{page}'])['blocks'][0]['runs'][0]['text'])->toBe('{page}');
});

it('keeps a band\'s page geometry closed to tokens on a break', function () {
    $errors = fpBandTemplate('fine-print', ['blocks' => [['type' => 'pageBreak', 'page' => ['orientation' => '{{ v }}']]]]);

    expect(fn () => Template::render($errors, ['v' => 'landscape']))->toThrow(TemplateError::class);
});
