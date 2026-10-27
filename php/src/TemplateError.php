<?php

declare(strict_types=1);

namespace FancyTemplate;

use RuntimeException;

/**
 * Every refusal this package makes: a stable code, a JSON Pointer, and
 * optionally the variable PATH a token named.
 *
 * Never a data value, in the message or anywhere else (plan section 5). A
 * certificate's recipient name is personal data, and an error is the thing that
 * gets logged, shown to an admin, and pasted into an issue.
 */
final class TemplateError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $path,
        public readonly ?string $variable = null,
    ) {
        $where = $path === '' ? 'the root' : $path;
        $what = $variable === null ? '' : " (variable `{$variable}`)";
        parent::__construct("{$errorCode} at {$where}{$what}");
    }

    /** @return array{code: string, path: string, variable?: string} */
    public function toArray(): array
    {
        $out = ['code' => $this->errorCode, 'path' => $this->path];
        if ($this->variable !== null) {
            $out['variable'] = $this->variable;
        }

        return $out;
    }
}
