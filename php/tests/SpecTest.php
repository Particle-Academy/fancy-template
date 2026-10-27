<?php

declare(strict_types=1);

use FancyTemplate\Template;

/*
 * SPEC.md clauses no conformance row covers yet, found while building the
 * TypeScript and Python legs against the spec rather than against this leg.
 * The same assertions live in tests/spec.test.ts and python/tests/test_spec.py,
 * so each clause is held in three places rather than one.
 */

function specVars(): array
{
    return ['xs' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean'], 'n' => ['type' => 'string']]]]];
}

function specFp(array $blocks, ?array $variables = null): array
{
    return ['fancyTemplate' => 1, 'writer' => 'fine-print', 'variables' => $variables ?? specVars(), 'document' => ['blocks' => $blocks]];
}

it('section 8: $if sees the $each binding whatever the key order', function (): void {
    $t = specFp([['$if' => 'item.ok', '$each' => 'xs', 'type' => 'paragraph', 'runs' => [['text' => '{{ item.n }}']]]]);

    expect(Template::inspect($t)['issues'])->toBe([]);
    $doc = Template::render($t, ['xs' => [['ok' => true, 'n' => 'a'], ['ok' => false, 'n' => 'b'], ['ok' => true, 'n' => 'c']]]);
    expect(array_map(static fn (array $b): string => $b['runs'][0]['text'], $doc['blocks']))->toBe(['a', 'c']);
});

it('section 10: templateBytes counts every key, numeric ones too', function (): void {
    $t = json_decode('{"fancyTemplate":1,"writer":"fine-print","document":{"blocks":[{"type":"hr","1234567890":"x"}]}}', true);
    // Every string, keys included: the keys and values spelled out.
    $total = 0;
    foreach (['fancyTemplate', 'writer', 'fine-print', 'document', 'blocks', 'type', 'hr', '1234567890', 'x'] as $s) {
        $total += strlen($s);
    }

    expect(Template::inspect($t, ['limits' => ['templateBytes' => $total]])['issues'])->toBe([]);
    expect(Template::inspect($t, ['limits' => ['templateBytes' => $total - 1]])['issues'])->toBe([['code' => 'TEMPLATE_TOO_LARGE', 'path' => '']]);
});

it('section 3: maxLength and maxItems take an integral value written as 3.0', function (): void {
    // JSON Schema's integer, as section 3 defines it for data. JavaScript cannot
    // tell 3.0 from 3 at all, so any other reading splits the legs.
    $t = specFp([], ['s' => ['type' => 'string', 'maxLength' => 3.0], 'a' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 1.0]]);

    expect(Template::inspect($t)['issues'])->toBe([]);
    expect(Template::validateData($t, ['s' => 'abcd', 'a' => ['x', 'y']]))->toBe([
        ['path' => '/s', 'code' => 'DATA_MAX_LENGTH'],
        ['path' => '/a', 'code' => 'DATA_MAX_ITEMS'],
    ]);
});

it('section 2: the version is the integer 1, written 1 or 1.0, and never true', function (): void {
    $base = ['writer' => 'fine-print', 'document' => ['title' => 'x']];

    expect(Template::inspect(['fancyTemplate' => 1.0] + $base)['issues'])->toBe([]);
    expect(Template::inspect(['fancyTemplate' => true] + $base)['issues'])->toBe([['code' => 'TEMPLATE_VERSION', 'path' => '/fancyTemplate']]);
});

function hsTemplate(array $rows, array $variables): array
{
    return ['fancyTemplate' => 1, 'writer' => 'holy-sheet', 'variables' => $variables, 'document' => ['sheets' => [['name' => 'S', 'rows' => $rows]]]];
}

function firstCell(array $doc): mixed
{
    return $doc['sheets'][0]['rows'][0][0];
}

it('section 9: a declared date stays bare in a cell; a date-shaped string variable is wrapped', function (): void {
    expect(firstCell(Template::render(hsTemplate([['{{ d }}']], ['d' => ['type' => 'date']]), ['d' => '2026-10-27'])))->toBe('2026-10-27');
    expect(firstCell(Template::render(hsTemplate([['{{ s }}']], ['s' => ['type' => 'string']]), ['s' => '2026-10-27'])))->toBe(['value' => '2026-10-27']);
});

it('section 9: a filter that turns a date into text is wrapped', function (): void {
    expect(firstCell(Template::render(hsTemplate([['{{ d | date:"d MMMM yyyy" }}']], ['d' => ['type' => 'date']]), ['d' => '2026-10-27'])))->toBe(['value' => '27 October 2026']);
});

it('section 9: a whole-token number keeps its type through default; a formatted one is text', function (): void {
    expect(firstCell(Template::render(hsTemplate([['{{ n | default:"0" }}']], ['n' => ['type' => 'number']]), ['n' => 4.5])))->toBe(4.5);
    expect(firstCell(Template::render(hsTemplate([['{{ n | default:"=1+1" }}']], ['n' => ['type' => 'number']]), [])))->toBe(['value' => '=1+1']);
    expect(firstCell(Template::render(hsTemplate([['{{ n | number:2 }}']], ['n' => ['type' => 'number']]), ['n' => 4.5])))->toBe(['value' => '4.50']);
});

it('section 9: a literal cell is the author\'s, left as written', function (): void {
    expect(firstCell(Template::render(hsTemplate([['=SUM(A2:A9)']], []), [])))->toBe('=SUM(A2:A9)');
});

it('section 9: $index in a cell is a number', function (): void {
    $t = ['fancyTemplate' => 1, 'writer' => 'holy-sheet', 'variables' => ['xs' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'document' => ['sheets' => [['$each' => 'xs', '$as' => 'x', 'name' => '{{ x }}', 'rows' => [['{{ $index }}', '{{ x }}']]]]]];

    expect(Template::render($t, ['xs' => ['a', '=b']])['sheets'])->toBe([
        ['name' => 'a', 'rows' => [[0, ['value' => 'a']]]],
        ['name' => '=b', 'rows' => [[1, ['value' => '=b']]]],
    ]);
});

function dsTemplate(array $element): array
{
    return ['fancyTemplate' => 1, 'writer' => 'dark-slide', 'variables' => ['v' => ['type' => 'string']],
        'document' => ['slides' => [['elements' => [['type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1] + $element]]]]];
}

it('section 9: markup escapes only the value, never the template\'s own markup', function (): void {
    $doc = Template::render(dsTemplate(['content' => '**Dear** {{ v }}', 'format' => 'markdown']), ['v' => '**x**']);

    expect($doc['slides'][0]['elements'][0]['content'])->toBe('**Dear** \\*\\*x\\*\\*');
});

it('section 9: a literal token\'s text is a value too', function (): void {
    $doc = Template::render(dsTemplate(['content' => '{{ "{{" }}', 'format' => 'markdown']), []);

    expect($doc['slides'][0]['elements'][0]['content'])->toBe('\\{\\{');
});

it('section 9: non-ASCII punctuation is left alone', function (): void {
    $doc = Template::render(dsTemplate(['content' => '{{ v }}', 'format' => 'markdown']), ['v' => 'café — “q”']);

    expect($doc['slides'][0]['elements'][0]['content'])->toBe('café — “q”');
});

it('section 4: validateData in declaration order, depth-first', function (): void {
    $t = specFp([], ['a' => ['type' => 'object', 'properties' => ['b' => ['type' => 'integer'], 'c' => ['type' => 'string', 'required' => true]]], 'd' => ['type' => 'boolean']]);

    expect(Template::validateData($t, ['a' => ['b' => 1.5], 'd' => 'no']))->toBe([
        ['path' => '/a/b', 'code' => 'DATA_TYPE'],
        ['path' => '/a/c', 'code' => 'DATA_REQUIRED'],
        ['path' => '/d', 'code' => 'DATA_TYPE'],
    ]);
});
