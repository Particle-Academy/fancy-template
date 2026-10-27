<?php

declare(strict_types=1);

use FancyTemplate\Template;
use FancyTemplate\TemplateError;

/*
 * The plan's section 5 properties that a conformance row cannot express,
 * because they are about what an error CONTAINS and what the engine TOUCHES
 * rather than what it returns.
 */

function fpTemplate(array $blocks, array $variables, array $extra = []): array
{
    return ['fancyTemplate' => 1, 'writer' => 'fine-print', 'variables' => $variables, 'document' => $extra + ['blocks' => $blocks]];
}

function failureOf(callable $fn): TemplateError
{
    try {
        $fn();
    } catch (TemplateError $e) {
        return $e;
    }
    throw new RuntimeException('expected a TemplateError');
}

const SECRET = 'S3CRET-recipient-9f2c';

it('never puts a data value in an error, for every render-time failure', function (array $template, array $data, string $code): void {
    $e = failureOf(fn () => Template::render($template, $data));

    expect($e->errorCode)->toBe($code);
    expect(str_contains($e->getMessage(), SECRET))->toBeFalse();
    expect(str_contains(json_encode($e->toArray()), SECRET))->toBeFalse();
})->with([
    'enum' => [fpTemplate([], ['v' => ['type' => 'string', 'enum' => ['a']]]), ['v' => SECRET], 'DATA_ENUM'],
    'max length' => [fpTemplate([], ['v' => ['type' => 'string', 'maxLength' => 3]]), ['v' => SECRET], 'DATA_MAX_LENGTH'],
    'date format' => [fpTemplate([], ['v' => ['type' => 'date']]), ['v' => SECRET], 'DATA_FORMAT'],
    'page field' => [fpTemplate([], ['v' => ['type' => 'string']], ['page' => ['footer' => ['left' => '{{ v }}']]]), ['v' => SECRET.'{page}'], 'VALUE_PAGE_FIELD'],
    'filter input' => [fpTemplate([['type' => 'paragraph', 'runs' => [['text' => '{{ v | date:"d" }}']]]], ['v' => ['type' => 'string']]), ['v' => SECRET], 'FILTER_INPUT'],
    'not scalar' => [fpTemplate([['type' => 'paragraph', 'runs' => [['text' => '{{ v }}']]]], ['v' => ['type' => 'array', 'items' => ['type' => 'string']]]), ['v' => [SECRET]], 'TEMPLATE_NOT_SCALAR'],
]);

it('names the variable by path, never by value', function (): void {
    $e = failureOf(fn () => Template::render(fpTemplate([['type' => 'paragraph', 'runs' => [['text' => '{{ person.middle }}']]]],
        ['person' => ['type' => 'object', 'properties' => ['middle' => ['type' => 'string']]]]), ['person' => ['first' => SECRET]]));

    expect($e->toArray())->toBe(['code' => 'TEMPLATE_MISSING', 'path' => '/document/blocks/0/runs/0/text', 'variable' => 'person.middle']);
});

it('reduces host objects to plain JSON before anything reads them', function (): void {
    $object = new class implements JsonSerializable
    {
        public string $hidden = 'never';

        public function jsonSerialize(): mixed
        {
            return 'Ada';
        }
    };
    $doc = Template::render(fpTemplate([['type' => 'paragraph', 'runs' => [['text' => '{{ v }}']]]], ['v' => ['type' => 'string']]), ['v' => $object]);

    expect($doc['blocks'][0]['runs'][0]['text'])->toBe('Ada');
});

it('refuses data that is not JSON at all', function (): void {
    expect(Template::validateData(fpTemplate([], ['v' => ['type' => 'number']]), ['v' => INF]))->toBe([['path' => '', 'code' => 'DATA_TYPE']]);
    $resource = fopen('php://memory', 'r');
    expect(Template::validateData(fpTemplate([], []), ['v' => $resource]))->toBe([['path' => '', 'code' => 'DATA_TYPE']]);
});

it('cannot call anything from a condition', function (string $expression): void {
    $issues = Template::inspect(fpTemplate([['$if' => $expression, 'type' => 'hr']], ['v' => ['type' => 'string']]))['issues'];

    expect($issues)->toBe([['code' => 'DIRECTIVE_EXPR', 'path' => '/document/blocks/0/$if']]);
})->with(['phpinfo()', 'v.constructor("x")', 'system("id")']);

it('refuses a megabyte after an open brace in token-length time', function (): void {
    $text = 'x{{'.str_repeat('"', 1).str_repeat('a', 1_000_000);
    $start = hrtime(true);
    $e = failureOf(fn () => Template::tokenize($text));
    $ms = (hrtime(true) - $start) / 1e6;

    expect($e->errorCode)->toBe('TOKEN_TOO_LONG');
    // Generous: it is about scanning 512 bytes, not about this machine.
    expect($ms)->toBeLessThan(50.0);
});

it('scans many tokens and many lone braces linearly', function (): void {
    // Units chosen so no two adjacent copies form `{{`.
    $text = str_repeat('{ } }} x', 50_000).str_repeat('{{a}}', 2_000);
    $start = hrtime(true);
    $parts = Template::tokenize($text);
    $ms = (hrtime(true) - $start) / 1e6;

    expect(count($parts))->toBe(2_001);
    expect($ms)->toBeLessThan(500.0);
});
