# Using fancy-template — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-template     # PHP ^8.4, ext-zip
npm install @particle-academy/fancy-template          # Node >=22
pip install fancy-template                            # Python >=3.11
```

**Status:** the PHP leg is on Packagist. The TypeScript and Python legs are
built but not yet published; the npm and PyPI names need their first publish.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). The registry entry is slug `fancy-template`. Find it
with `search-backend-packages` (stacks `php`, `node`, `python`) and get the
exact commands with `install-instructions`.

## API essentials

The same three calls in every leg:

```php
use FancyTemplate\Template;

Template::inspect($template);                 // at SAVE time: ['ok' => bool, 'issues' => [...]]
Template::validateData($template, $data);     // before render
$doc = Template::render($template, $data);    // plain writer JSON, or throws TemplateError
```

```ts
import { inspect, validateData, render } from "@particle-academy/fancy-template";
```

```python
from fancy_template import inspect, validate_data, render
```

- A template is `{ "fancyTemplate": 1, "writer": "fine-print" | "last-word" | "dark-slide" | "holy-sheet", "variables": {...}, "document": {...} }`.
  The full format is `SPEC.md`, which ships in the npm package.
- `render` returns a plain writer document. Pass it through that writer's own
  `validate` / `write` (for example `\FinePrint\Agent::toBytes($doc)`).
- `TemplateError` carries a `code`, a JSON Pointer `path` and the `variable`,
  never a data value.
- **Office files as templates (PHP only):** `Template::inspectFile($bytes)` and
  `Template::renderFile($bytes, $data, ['variables' => [...]])` fill
  `{{ tokens }}` typed into a `.docx` / `.dotx`, `.pptx` / `.potx` / `.ppsx` or
  `.xlsx` / `.xltx`.
- Worked examples (certificate, invoice, deck, report) are in `examples/` in the
  repo.

### Repeating things: `$each`, the `$value` splice, `$spread`

| you want to repeat | write |
|---|---|
| one object (a paragraph, a table row) | `{ "$each": "lines", "$as": "line", ...the object }` |
| one non-object (a holy-sheet row, a string) | `{ "$each": "lines", "$value": [...] }` |
| a GROUP of siblings, in order and flat | `{ "$each": "models", "$spread": true, "$value": [ ... ] }` |

A per-model quote: a page per model (no break before the first), a heading, a
lines table whose rows are a nested `$each`, and a subtotal. Negation in
fancy-expr is `!` (there is no `not`), so "not the first item" is `"!$first"`:

```json
{ "$each": "models", "$as": "model", "$spread": true, "$value": [
  { "$if": "!$first", "type": "pageBreak" },
  { "type": "heading", "level": 2, "runs": [{ "text": "{{ model.name }}" }] },
  { "type": "table", "widths": [60, 15, 25], "rows": [
    { "header": true, "cells": [
      { "blocks": [{ "type": "paragraph", "runs": [{ "text": "Item" }] }] },
      { "blocks": [{ "type": "paragraph", "runs": [{ "text": "Qty" }] }] },
      { "blocks": [{ "type": "paragraph", "runs": [{ "text": "Amount" }] }] } ] },
    { "$each": "model.lines", "$as": "line", "cells": [
      { "blocks": [{ "type": "paragraph", "runs": [{ "text": "{{ line.desc }}" }] }] },
      { "blocks": [{ "type": "paragraph", "runs": [{ "text": "{{ line.qty }}" }] }] },
      { "blocks": [{ "type": "paragraph", "runs": [{ "text": "{{ line.amount | money:USD }}" }] }] } ] } ] },
  { "type": "paragraph", "runs": [{ "text": "Subtotal: {{ model.subtotal | money:USD }}" }] }
] }
```

The complete template, with its declarations and data, is fancy-conformance row
`template/directives` 0033, which the PHP leg also writes through fine-print.

### `editor`: a builder's source, carried

An optional top-level `editor` object holds a visual builder's own source. It is
never read (no token in it is scanned or rendered), never output, and never
changed, and it is bounded on its own by `limits.editorBytes` (default 262144,
`TEMPLATE_EDITOR_TOO_LARGE` at `/editor`) rather than `templateBytes`.
`document` is still what renders.

## Traps

- **Call `inspect()` when a template is saved**, not only at render time; it
  reports static issues before any data exists.
- **Missing data is an error**, not an empty string.
- **Only profile-listed fields take tokens** (`profiles/<writer>.json`); a token
  anywhere else is refused.
- **A substituted value is never re-scanned**, so data containing `{{` appears
  literally.
- **holy-sheet strings are written `{ "value": s }`** so data can never become a
  formula, but holy-sheet still coerces `"+1"` / `"-1"` to numbers; declare the
  column `string` to keep text.
- **An array `$value` without `$spread` NESTS**: per item it is ONE element
  that happens to be an array, so `blocks` gets `[[...], [...]]` and fine-print
  refuses it. A group of blocks per item is `$spread: true`.
- **Inside a spread element, `$first` is the spread's**; inside an element's own
  `$each` (a table row) it is that loop's.
- **Avoid digit-only object keys** in templates: PHP decodes them as integer
  keys and JavaScript reorders them.
- **Integers past 2^53** are rounded in the TypeScript leg; `money` takes only
  safe integers there.
- **The TypeScript and Python legs throw `FileTemplatesNotImplementedError`**
  for `inspectFile` / `renderFile`. Repeating sections (a row per line item) in
  office files are not covered yet.
- **Office files refuse** DOCTYPEs, zip bombs, traversal or duplicate entry
  names, macro-enabled and encrypted files, with a typed `FILE_` code.
- **Pre-1.0:** breaking changes land in MINOR releases.
