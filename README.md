# fancy-template

Document templates for the Fancy document writers -- fine-print (PDF), last-word
(docx), dark-slide (pptx) and holy-sheet (xlsx). A template is a writer document
with `{{ tokens }}` in some string values and JSON directives on some array
items, plus a declaration of the data it needs:

```json
{ "fancyTemplate": 1, "writer": "fine-print",
  "variables": { "recipientName": { "type": "string", "required": true, "maxLength": 200 } },
  "document": { "blocks": [ { "type": "paragraph", "runs": [ { "text": "{{ recipientName }}" } ] } ] } }
```

One specification (`SPEC.md`), three implementations with the same API:

```php
use FancyTemplate\Template;                       // composer require particle-academy/fancy-template

Template::inspect($template);                      // at SAVE time: ['ok' => bool, 'issues' => [...]]
Template::validateData($template, $data);          // before render
$doc = Template::render($template, $data);         // plain writer JSON, or throws TemplateError
$pdf = \FinePrint\Agent::toBytes($doc);            // the writer validates it again
```

```ts
import { inspect, validateData, render } from "@particle-academy/fancy-template";
const doc = render(template, data);                // throws TemplateError { code, path, variable }
```

```python
from fancy_template import inspect, validate_data, render
doc = render(template, data)                       # raises TemplateError
```

Missing data is loud, errors carry a code and a JSON Pointer and never a data
value, and every input is bounded. Values are encoded for where they land:
holy-sheet strings are written `{ "value": s }` so data can never become a
formula; dark-slide values are escaped for the element's markdown or html.

The three legs pass the same 203 `template/*` conformance rows
(fancy-conformance 0.36.0) and produce byte-identical output on every row and on
the realistic templates in `examples/` (`php php/tests/parity.php`).

## A customer's own file as the template

Any `.docx` / `.dotx`, `.pptx` / `.potx` / `.ppsx` or `.xlsx` / `.xltx` with
`{{ tokens }}` typed into it can be filled as it is (PHP; SPEC.md section 12):

```php
$found = Template::inspectFile($bytes);            // ['ok', 'format', 'kind', 'issues', 'placeholders', 'variables']
// placeholders: [['path' => '/word/document.xml#p1', 'token' => '{{ client }}', 'variable' => 'client'], ...]

$filled = Template::renderFile($bytes, ['client' => 'Ada & Co'], [
    'variables' => ['client' => ['type' => 'string', 'required' => true]],   // optional; without it names are inferred
]);
```

- Each token is replaced in the file's own text, including one Word has split
  across runs: the value goes into the run holding `{{` and takes its
  formatting; the rest of the token is cut from the runs after it; no run is
  removed.
- Every member without a token is copied as its original zip record, byte for
  byte. Values are XML-escaped; an xlsx value is always text, never a formula.
- Read and filled: docx body, tables, text boxes, headers, footers, footnotes,
  endnotes, comments; pptx slides, layouts, masters, notes; xlsx shared and
  inline strings. A token anywhere else is refused, not left behind.
- Refused with a typed `FILE_` code: a DOCTYPE in any part read, zip bombs,
  size and entry caps, traversal or duplicate entry names, macro-enabled and
  encrypted files. Nothing a file points at is ever fetched.

The TypeScript and Python legs throw `FileTemplatesNotImplementedError` for now.
Repeating sections (a row per line item) are not covered yet.

`examples/` holds the default certificate (fine-print), an invoice (last-word),
a quarterly deck (dark-slide) and a regional report (holy-sheet).

**Status:** PHP 0.1.0 on Packagist. The TypeScript and Python legs are built and
unpublished: the npm and PyPI names need their first publish. See `CHANGELOG.md`.
