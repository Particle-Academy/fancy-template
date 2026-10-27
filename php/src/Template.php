<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * Document templates for the Fancy writers. SPEC.md is normative.
 *
 * ```php
 * $issues = Template::inspect($template)['issues'];   // at SAVE time, no data
 * $errors = Template::validateData($template, $data); // before rendering
 * $doc    = Template::render($template, $data);       // throws TemplateError
 * $pdf    = \FinePrint\Agent::toBytes($doc);          // the writer validates again
 * ```
 *
 * The output is plain writer JSON, so the writers never depend on this package
 * and stay zero-dependency (plan section 4).
 */
final class Template
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, issues: list<array{code: string, path: string, variable?: string}>}
     */
    public static function inspect(mixed $template, array $options = []): array
    {
        $limits = Limits::from($options);
        [$plain, $template] = Json::plain($template);
        $issues = $plain ? Inspector::run($template, $limits) : [['code' => 'TEMPLATE_SHAPE', 'path' => '']];

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Data errors in declaration order; empty when valid. Assumes a template
     * that passes inspect; declarations that do not are skipped, not guessed at.
     *
     * @param  array<string, mixed>  $options
     * @return list<array{path: string, code: string}>
     */
    public static function validateData(mixed $template, mixed $data, array $options = []): array
    {
        return self::prepare($template, $data, Limits::from($options))[1];
    }

    /**
     * Render a template with data. Inspect first, then validate the data, then
     * render; the first failure is thrown (SPEC.md section 11).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> the writer document
     *
     * @throws TemplateError
     */
    public static function render(mixed $template, mixed $data, array $options = []): array
    {
        $limits = Limits::from($options);
        [$plain, $template] = Json::plain($template);
        if (! $plain) {
            throw new TemplateError('TEMPLATE_SHAPE', '');
        }
        $issues = Inspector::run($template, $limits);
        if ($issues !== []) {
            $first = $issues[0];
            throw new TemplateError($first['code'], $first['path'], $first['variable'] ?? null);
        }

        [$data, $errors] = self::prepare($template, $data, $limits);
        if ($errors !== []) {
            throw new TemplateError($errors[0]['code'], $errors[0]['path']);
        }

        return Renderer::run($template['document'], $data, Inspector::variables($template), Profile::for($template['writer']), $limits);
    }

    /**
     * Render with each variable's `example`, falling back to its `default`.
     * A required variable with neither fails, rather than inventing a value
     * the real data could not produce (template/resolve 0202).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws TemplateError
     */
    public static function preview(mixed $template, array $options = []): array
    {
        $data = [];
        foreach ((is_array($template) && Json::isObject($template['variables'] ?? null)) ? $template['variables'] : [] as $name => $decl) {
            if (is_array($decl) && array_key_exists('example', $decl)) {
                $data[$name] = $decl['example'];
            }
        }

        return self::render($template, $data, $options);
    }

    /**
     * Split one string into text and token parts (SPEC.md section 5).
     *
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     *
     * @throws TemplateError
     */
    public static function tokenize(string $text, array $options = []): array
    {
        return Scanner::tokenize($text, Limits::from($options)->tokenLength);
    }

    /**
     * Reduce data to plain JSON, bound its depth, apply defaults, validate.
     *
     * @return array{0: array<string, mixed>, 1: list<array{path: string, code: string}>}
     */
    private static function prepare(mixed $template, mixed $data, Limits $limits): array
    {
        [$plainTemplate, $template] = Json::plain($template);
        [$plainData, $data] = Json::plain($data);
        if (! $plainData) {
            return [[], [['path' => '', 'code' => 'DATA_TYPE']]];
        }
        $tooDeep = Json::firstTooDeep($data, $limits->depth);
        if ($tooDeep !== null) {
            return [[], [['path' => $tooDeep, 'code' => 'DATA_TOO_DEEP']]];
        }
        if (! Json::isObject($data)) {
            return [[], [['path' => '', 'code' => 'DATA_TYPE']]];
        }

        $variables = $plainTemplate && Json::isObject($template) ? Inspector::variables($template) : [];
        $errors = Declarations::validateMembers($data, $variables, '');

        return [Declarations::applyDefaults($data, $variables), $errors];
    }
}
