<?php

declare(strict_types=1);

namespace FancyTemplate;

use FancyExpr\Expr;
use FancyExpr\ExprSyntaxError;

/**
 * Everything that can be known about a template without data (SPEC.md
 * sections 2, 3, 5, 8, 9, 10 and 11). The answer an admin gets at SAVE time.
 *
 * Issues come in document order so that three runtimes produce comparable
 * lists: envelope, declarations, then the document depth-first with an
 * object's `$` keys before its other entries.
 */
final class Inspector
{
    private const ENVELOPE_KEYS = ['fancyTemplate', 'writer', 'description', 'variables', 'document'];

    /** @var list<array{code: string, path: string, variable?: string}> */
    private array $issues = [];

    /** @var array<string, mixed> */
    private array $variables = [];

    private ?Profile $profile = null;

    private function __construct(private readonly Limits $limits) {}

    /** @return list<array{code: string, path: string, variable?: string}> */
    public static function run(mixed $template, Limits $limits): array
    {
        $self = new self($limits);
        $self->inspect($template);

        return $self->issues;
    }

    /** The declared variables of a template that passed inspect. */
    public static function variables(array $template): array
    {
        return Json::isObject($template['variables'] ?? null) ? $template['variables'] : [];
    }

    private function inspect(mixed $template): void
    {
        if (! Json::isObject($template)) {
            $this->issue('TEMPLATE_SHAPE', '');

            return;
        }

        // The three bounds that STOP inspect come first, in this order, so that
        // an oversized template costs nothing more to refuse (template/inspect 0020).
        $tooDeep = Json::firstTooDeep($template, $this->limits->depth);
        if ($tooDeep !== null) {
            $this->issue('TEMPLATE_TOO_DEEP', $tooDeep);

            return;
        }
        if (Json::stringBytes($template) > $this->limits->templateBytes) {
            $this->issue('TEMPLATE_TOO_LARGE', '');

            return;
        }
        if ($this->countTokens($template['document'] ?? null) > $this->limits->tokens) {
            $this->issue('TEMPLATE_TOO_MANY_TOKENS', '');

            return;
        }

        foreach ($template as $key => $value) {
            $at = Json::pointer('', $key);
            match ($key) {
                'fancyTemplate' => $value === 1 ? null : $this->issue('TEMPLATE_VERSION', $at),
                'writer' => ($this->profile = Profile::for($value)) !== null ? null : $this->issue('TEMPLATE_WRITER', $at),
                'description' => is_string($value) ? null : $this->issue('TEMPLATE_SHAPE', $at),
                'variables' => Json::isObject($value) ? null : $this->issue('TEMPLATE_SHAPE', $at),
                'document' => Json::isObject($value) ? null : $this->issue('TEMPLATE_SHAPE', $at),
                default => $this->issue('TEMPLATE_SHAPE', $at),
            };
        }
        foreach (['fancyTemplate' => 'TEMPLATE_VERSION', 'writer' => 'TEMPLATE_WRITER', 'document' => 'TEMPLATE_SHAPE'] as $key => $code) {
            if (! array_key_exists($key, $template)) {
                $this->issue($code, Json::pointer('', $key));
            }
        }

        if (Json::isObject($template['variables'] ?? [])) {
            foreach ($template['variables'] ?? [] as $name => $decl) {
                $at = Json::pointer('/variables', $name);
                if (! Scanner::isName((string) $name)) {
                    $this->issue('DECL_INVALID', $at);

                    continue;
                }
                Declarations::inspect($decl, $at, $this->issues);
                $this->variables[(string) $name] = $decl;
            }
        }

        if ($this->profile !== null && Json::isObject($template['document'] ?? null)) {
            $this->walk($template['document'], '/document', [], [], 0, false);
        }
    }

    private function countTokens(mixed $value): int
    {
        if (is_string($value)) {
            return Scanner::count($value, $this->limits->tokenLength);
        }
        if (! is_array($value)) {
            return 0;
        }
        $n = 0;
        foreach ($value as $v) {
            $n += $this->countTokens($v);
            if ($n > $this->limits->tokens) {
                break;
            }
        }

        return $n;
    }

    /**
     * @param  list<string>  $segments  path inside `document`, for the profile
     * @param  list<array{name: string, decl: mixed}>  $frames  loop bindings, innermost last
     */
    private function walk(mixed $value, string $path, array $segments, array $frames, int $directiveDepth, bool $isElement): void
    {
        if (is_string($value)) {
            $this->string($value, $path, $segments, $frames);

            return;
        }
        if (! is_array($value)) {
            return;
        }
        if (array_is_list($value)) {
            foreach ($value as $i => $item) {
                $this->walk($item, Json::pointer($path, $i), [...$segments, (string) $i], $frames, $directiveDepth, true);
            }

            return;
        }

        $directives = array_filter(array_keys($value), static fn ($k): bool => is_string($k) && str_starts_with($k, '$'));
        if ($directives !== []) {
            if (! $isElement) {
                foreach ($directives as $key) {
                    $this->issue('DIRECTIVE_MISPLACED', Json::pointer($path, $key));
                }
            } else {
                $directiveDepth++;
                if ($directiveDepth > $this->limits->directiveDepth) {
                    $this->issue('DIRECTIVE_TOO_DEEP', $path);

                    return;
                }
                $frames = $this->directives($value, $path, $frames);
            }
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && str_starts_with($key, '$')) {
                continue;
            }
            $at = Json::pointer($path, $key);
            if (str_contains((string) $key, '{{')) {
                $this->issue('TOKEN_IN_KEY', $at);
            }
            $this->walk($child, $at, [...$segments, (string) $key], $frames, $directiveDepth, false);
        }
    }

    /**
     * Check an element's directives in written order; return the frames its
     * contents see (with the new loop binding, when `$each` is valid).
     *
     * @param  array<string, mixed>  $object
     * @param  list<array{name: string, decl: mixed}>  $frames
     * @return list<array{name: string, decl: mixed}>
     */
    private function directives(array $object, string $path, array $frames): array
    {
        $inner = $frames;
        $hasEach = array_key_exists('$each', $object);
        $as = $object['$as'] ?? 'item';

        foreach ($object as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, '$')) {
                continue;
            }
            $at = Json::pointer($path, $key);

            if ($key === '$each') {
                if (! is_string($value)) {
                    $this->issue('DIRECTIVE_INVALID', $at);

                    continue;
                }
                if (strlen($value) > $this->limits->tokenLength) {
                    $this->issue('TOKEN_TOO_LONG', $at);

                    continue;
                }
                $steps = Scanner::pathOnly($value);
                if ($steps === null) {
                    $this->issue('DIRECTIVE_INVALID', $at);

                    continue;
                }
                [$found, $decl] = $this->resolveDecl($steps, $frames);
                if (! $found) {
                    $this->issue('DIRECTIVE_UNDECLARED', $at, implode('.', $steps));

                    continue;
                }
                if (is_array($decl) && ($decl['type'] ?? null) !== 'array') {
                    $this->issue('DIRECTIVE_NOT_ARRAY', $at, implode('.', $steps));

                    continue;
                }
                if (is_string($as) && Scanner::isName($as)) {
                    $inner = [...$frames, ['name' => $as, 'decl' => is_array($decl) ? ($decl['items'] ?? null) : null]];
                }

                continue;
            }

            if ($key === '$as') {
                $shadows = is_string($value) && (array_key_exists($value, $this->variables)
                    || in_array($value, array_column($frames, 'name'), true));
                if (! $hasEach || ! is_string($value) || ! Scanner::isName($value) || $shadows) {
                    $this->issue('DIRECTIVE_INVALID', $at);
                }

                continue;
            }

            if ($key === '$if') {
                if (! is_string($value)) {
                    $this->issue('DIRECTIVE_INVALID', $at);

                    continue;
                }
                if (strlen($value) > $this->limits->tokenLength) {
                    $this->issue('TOKEN_TOO_LONG', $at);

                    continue;
                }
                try {
                    $roots = Expr::references($value);
                } catch (ExprSyntaxError) {
                    $this->issue('DIRECTIVE_EXPR', $at);

                    continue;
                }
                // `$if` sees this element's own loop binding (SPEC.md section 8).
                $scope = $hasEach ? $inner : $frames;
                foreach ($roots as $root) {
                    if (! $this->inScope($root, $scope)) {
                        $this->issue('DIRECTIVE_UNDECLARED', $at, $root);
                        break;
                    }
                }

                continue;
            }

            $this->issue('DIRECTIVE_UNKNOWN', $at);
        }

        return $inner;
    }

    /** @param  list<array{name: string, decl: mixed}>  $frames */
    private function inScope(string $root, array $frames): bool
    {
        if (in_array($root, Scanner::LOOP_HEADS, true)) {
            return $frames !== [];
        }

        return in_array($root, array_column($frames, 'name'), true) || array_key_exists($root, $this->variables);
    }

    /**
     * Walk a path through the declarations (SPEC.md section 6). [found, decl];
     * decl is null when the shape below is unknown (a broken declaration has
     * already been reported, and is not reported twice).
     *
     * @param  list<string>  $steps
     * @param  list<array{name: string, decl: mixed}>  $frames
     * @return array{0: bool, 1: mixed}
     */
    private function resolveDecl(array $steps, array $frames): array
    {
        $head = $steps[0];
        if (in_array($head, Scanner::LOOP_HEADS, true)) {
            if ($frames === [] || count($steps) > 1) {
                return [false, null];
            }

            return [true, ['type' => $head === '$index' ? 'integer' : 'boolean']];
        }

        $decl = null;
        $found = false;
        foreach (array_reverse($frames) as $frame) {
            if ($frame['name'] === $head) {
                [$found, $decl] = [true, $frame['decl']];
                break;
            }
        }
        if (! $found) {
            if (! array_key_exists($head, $this->variables)) {
                return [false, null];
            }
            $decl = $this->variables[$head];
        }

        foreach (array_slice($steps, 1) as $step) {
            if (! is_array($decl) || ! isset($decl['type'])) {
                return [true, null];
            }
            if ($decl['type'] === 'object' && is_array($decl['properties'] ?? null) && array_key_exists($step, $decl['properties'])) {
                $decl = $decl['properties'][$step];

                continue;
            }
            if ($decl['type'] === 'array' && ctype_digit($step)) {
                $decl = $decl['items'] ?? null;

                continue;
            }

            return [false, null];
        }

        return [true, $decl];
    }

    /**
     * @param  list<string>  $segments
     * @param  list<array{name: string, decl: mixed}>  $frames
     */
    private function string(string $value, string $path, array $segments, array $frames): void
    {
        try {
            $parts = Scanner::tokenize($value, $this->limits->tokenLength);
        } catch (TemplateError $e) {
            $this->issue($e->errorCode, $path);

            return;
        }

        $tokens = array_values(array_filter($parts, static fn (array $p): bool => ! isset($p['text'])));
        $field = $this->profile->field($segments);

        if ($tokens === []) {
            if (($field['encode'] ?? null) === 'image' && ! in_array(Json::imageType($value), $field['mime'], true)) {
                $this->issue('TEMPLATE_IMAGE_INVALID', $path);
            }

            return;
        }
        if ($field === null) {
            $this->issue('TOKEN_FORBIDDEN_FIELD', $path);

            return;
        }

        if ($field['encode'] === 'image') {
            $token = $tokens[0];
            $isWhole = count($parts) === 1 && isset($token['path']) && $token['filters'] === [];
            [$found, $decl] = $isWhole ? $this->resolveDecl($token['path'], $frames) : [false, null];
            if (! $isWhole || ! $found || ! is_array($decl) || ($decl['type'] ?? null) !== 'image') {
                $this->issue('TOKEN_IMAGE_FIELD', $path);
            }

            return;
        }

        foreach ($tokens as $token) {
            if (isset($token['path'])) {
                [$found] = $this->resolveDecl($token['path'], $frames);
                if (! $found) {
                    $this->issue('TOKEN_UNDECLARED', $path, implode('.', $token['path']));
                }
            }
            foreach ($token['filters'] as $filter) {
                $code = Filters::check($filter);
                if ($code !== null) {
                    $this->issue($code, $path);
                }
            }
        }
    }

    private function issue(string $code, string $path, ?string $variable = null): null
    {
        $issue = ['code' => $code, 'path' => $path];
        if ($variable !== null) {
            $issue['variable'] = $variable;
        }
        $this->issues[] = $issue;

        return null;
    }
}
