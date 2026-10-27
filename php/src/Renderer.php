<?php

declare(strict_types=1);

namespace FancyTemplate;

use FancyExpr\Expr;

/**
 * Template + validated, defaulted data -> writer document (SPEC.md sections 6-10).
 *
 * Runs only on a template that passed inspect and data that passed
 * validateData, so it does not re-check what those guarantee; it checks what
 * only data can decide (absent paths, non-scalars, filter inputs, {page} in a
 * band value) and the output bounds, counted AS the output is produced so that
 * an oversized result is refused before it is built rather than after.
 */
final class Renderer
{
    private int $iterations = 0;

    private int $nodes = 0;

    private int $bytes = 0;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $variables  the declarations, for a cell's declared-date rule
     */
    private function __construct(
        private readonly Profile $profile,
        private readonly array $data,
        private readonly array $variables,
        private readonly Limits $limits,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function run(array $document, array $data, array $variables, Profile $profile, Limits $limits): array
    {
        $self = new self($profile, $data, $variables, $limits);
        $self->count(null);

        return $self->object($document, '/document', [], []);
    }

    /** CommonMark: a backslash before every ASCII punctuation character (SPEC.md section 9). */
    public static function escapeMarkdown(string $s): string
    {
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            $o = ord($c);
            $punct = ($o >= 33 && $o <= 47) || ($o >= 58 && $o <= 64) || ($o >= 91 && $o <= 96) || ($o >= 123 && $o <= 126);
            $out .= $punct ? '\\'.$c : $c;
        }

        return $out;
    }

    /** `& < > " '` as entities (SPEC.md section 9). */
    public static function escapeHtml(string $s): string
    {
        return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<string>  $segments
     * @param  list<array<string, mixed>>  $frames
     * @return array<string, mixed>
     */
    private function object(array $object, string $path, array $segments, array $frames): array
    {
        $out = [];
        foreach ($object as $key => $value) {
            if (is_string($key) && str_starts_with($key, '$')) {
                continue;
            }
            $out[$key] = $this->value($value, Json::pointer($path, $key), [...$segments, (string) $key], $frames, $object);
        }

        return $out;
    }

    /**
     * @param  list<string>  $segments
     * @param  list<array<string, mixed>>  $frames
     * @param  array<string, mixed>|null  $parent  the object holding this value, for a markup field's `format`
     */
    private function value(mixed $value, string $path, array $segments, array $frames, ?array $parent = null): mixed
    {
        if (is_string($value)) {
            $out = $this->string($value, $path, $segments, $frames, $parent);
            if (is_array($out)) {
                // A cell wrapper: one object and one string (SPEC.md section 10).
                $this->count(null);
                $this->count($out['value']);
            } else {
                $this->count($out);
            }

            return $out;
        }
        if (! is_array($value)) {
            $this->count($value);

            return $value;
        }
        $this->count(null);
        if (! array_is_list($value)) {
            return $this->object($value, $path, $segments, $frames);
        }

        $out = [];
        foreach ($value as $i => $item) {
            $at = Json::pointer($path, $i);
            $itemSegments = [...$segments, (string) $i];
            if (! Json::isObject($item) || $item === [] || ! $this->hasDirectives($item)) {
                $out[] = $this->value($item, $at, $itemSegments, $frames);

                continue;
            }
            foreach ($this->expand($item, $at, $frames) as $innerFrames) {
                $this->count(null);
                $out[] = $this->object($item, $at, $itemSegments, $innerFrames);
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $item */
    private function hasDirectives(array $item): bool
    {
        foreach ($item as $key => $_) {
            if (is_string($key) && str_starts_with($key, '$')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The frames each copy of a directive element renders with: one per kept
     * `$each` element, or one (or none) for a lone `$if`.
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $frames
     * @return iterable<list<array<string, mixed>>>
     */
    private function expand(array $item, string $path, array $frames): iterable
    {
        if (! array_key_exists('$each', $item)) {
            if ($this->condition($item['$if'], $frames)) {
                yield $frames;
            }

            return;
        }

        $at = Json::pointer($path, '$each');
        $steps = Scanner::pathOnly($item['$each']);
        $list = $this->resolve($steps, $frames);
        if ($list === Absent::Value) {
            throw new TemplateError('TEMPLATE_MISSING', $at, implode('.', $steps));
        }
        if (! Json::isList($list)) {
            throw new TemplateError('DIRECTIVE_NOT_ARRAY', $at, implode('.', $steps));
        }

        $as = $item['$as'] ?? 'item';
        [, $decl] = Inspector::declAt($steps, $frames, $this->variables);
        $itemsDecl = is_array($decl) ? ($decl['items'] ?? null) : null;
        $last = count($list) - 1;
        foreach ($list as $index => $element) {
            if (++$this->iterations > $this->limits->iterations) {
                throw new TemplateError('RENDER_TOO_MANY_ITERATIONS', $at);
            }
            $inner = [...$frames, ['name' => $as, 'decl' => $itemsDecl, 'value' => $element, '$index' => $index, '$first' => $index === 0, '$last' => $index === $last]];
            if (array_key_exists('$if', $item) && ! $this->condition($item['$if'], $inner)) {
                continue;
            }
            yield $inner;
        }
    }

    /** @param  list<array<string, mixed>>  $frames */
    private function condition(string $expression, array $frames): bool
    {
        $context = $this->data;
        foreach ($frames as $frame) {
            $context[$frame['name']] = $frame['value'];
        }
        if ($frames !== []) {
            $innermost = $frames[count($frames) - 1];
            foreach (Scanner::LOOP_HEADS as $head) {
                $context[$head] = $innermost[$head];
            }
        }

        // Parsed and bounded at inspect; fancy-expr never reaches the host.
        return Expr::truthy(Expr::evaluate($expression, $context));
    }

    /**
     * @param  list<string>  $steps
     * @param  list<array<string, mixed>>  $frames
     */
    private function resolve(array $steps, array $frames): mixed
    {
        $head = $steps[0];
        if (in_array($head, Scanner::LOOP_HEADS, true)) {
            return $frames === [] ? Absent::Value : $frames[count($frames) - 1][$head];
        }

        $value = Absent::Value;
        for ($f = count($frames) - 1; $f >= 0; $f--) {
            if ($frames[$f]['name'] === $head) {
                $value = $frames[$f]['value'];
                break;
            }
        }
        if ($value === Absent::Value) {
            if (! array_key_exists($head, $this->data)) {
                return Absent::Value;
            }
            $value = $this->data[$head];
        }

        foreach (array_slice($steps, 1) as $step) {
            if (! is_array($value)) {
                return Absent::Value;
            }
            if (array_is_list($value)) {
                if (! ctype_digit($step) || ! array_key_exists((int) $step, $value)) {
                    return Absent::Value;
                }
                $value = $value[(int) $step];

                continue;
            }
            if (! array_key_exists($step, $value)) {
                return Absent::Value;
            }
            $value = $value[$step];
        }

        return $value;
    }

    /**
     * A string's rendered value: a string, or for a `cell` field a typed scalar
     * or the `{value: s}` wrapper.
     *
     * @param  list<string>  $segments
     * @param  list<array<string, mixed>>  $frames
     * @param  array<string, mixed>|null  $parent
     */
    private function string(string $text, string $path, array $segments, array $frames, ?array $parent): mixed
    {
        if (! str_contains($text, '{{')) {
            return $text;
        }

        $field = $this->profile->field($segments);
        $parts = Scanner::tokenize($text, $this->limits->tokenLength);
        $format = $field['encode'] === 'markdown' ? 'markdown' : ($field['encode'] === 'markup' ? ($parent['format'] ?? null) : null);
        $out = '';

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $out .= $part['text'];

                continue;
            }
            $variable = isset($part['path']) ? implode('.', $part['path']) : null;
            $value = isset($part['path']) ? $this->resolve($part['path'], $frames) : $part['literal'];

            try {
                foreach ($part['filters'] as $filter) {
                    $value = Filters::apply($filter, $value);
                }
                if ($value === Absent::Value) {
                    throw new TemplateError('TEMPLATE_MISSING', '');
                }
                if ($field['encode'] === 'image') {
                    $type = Json::imageType($value);
                    if (! in_array($type, $field['mime'], true)) {
                        throw new TemplateError('VALUE_IMAGE_TYPE', '');
                    }

                    return $value;
                }
                if ($field['encode'] === 'cell' && count($parts) === 1) {
                    $kept = $this->typedCell($value, $part['path'] ?? null, $frames);
                    if ($kept !== Absent::Value) {
                        return $kept;
                    }
                }
                $piece = Filters::scalarText($value);
            } catch (TemplateError $e) {
                throw new TemplateError($e->errorCode, $path, $variable);
            }

            if ($field['encode'] === 'band' && (str_contains($piece, '{page}') || str_contains($piece, '{pages}'))) {
                throw new TemplateError('VALUE_PAGE_FIELD', $path, $variable);
            }
            $out .= match ($format) {
                'markdown' => self::escapeMarkdown($piece),
                'html' => self::escapeHtml($piece),
                default => $piece,
            };
        }

        // Every other cell result is wrapped, so user data can never become a
        // formula: holy-sheet promotes a BARE string starting with "=" and takes
        // {value: s} as literal text (template/profiles 0201).
        return $field['encode'] === 'cell' ? ['value' => $out] : $out;
    }

    /**
     * A whole token in a cell keeps a number or a boolean, and a string that is
     * a valid `date` (SPEC.md section 3) when its path is DECLARED `date`.
     * Absent::Value when the result is to be wrapped. A valid date cannot begin
     * with "=", so nothing kept bare here can become a formula.
     *
     * @param  list<string>|null  $steps
     * @param  list<array<string, mixed>>  $frames
     */
    private function typedCell(mixed $value, ?array $steps, array $frames): mixed
    {
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        if (! is_string($value) || $steps === null || Dates::parse($value) === null) {
            return Absent::Value;
        }
        [$found, $decl] = Inspector::declAt($steps, $frames, $this->variables);

        return $found && is_array($decl) && ($decl['type'] ?? null) === 'date' ? $value : Absent::Value;
    }

    /** One output value; a string also adds its bytes (SPEC.md section 10). */
    private function count(mixed $value): void
    {
        if (++$this->nodes > $this->limits->outputNodes) {
            throw new TemplateError('RENDER_TOO_MANY_NODES', '');
        }
        if (is_string($value)) {
            $this->bytes += strlen($value);
            if ($this->bytes > $this->limits->outputBytes) {
                throw new TemplateError('RENDER_TOO_LARGE', '');
            }
        }
    }
}
