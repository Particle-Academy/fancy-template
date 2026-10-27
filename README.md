# fancy-template

Document templates for the Fancy document writers (fine-print, last-word, and
in phase 6 dark-slide and holy-sheet). A template is a writer document with
`{{ tokens }}` in some string values and JSON directives on some array items,
plus a declaration of the data it needs:

```json
{ "fancyTemplate": 1, "writer": "fine-print",
  "variables": { "recipientName": { "type": "string", "required": true, "maxLength": 200 } },
  "document": { "blocks": [ { "type": "paragraph", "runs": [ { "text": "{{ recipientName }}" } ] } ] } }
```

```php
use FancyTemplate\Template;

Template::inspect($template);               // at SAVE time: ['ok' => bool, 'issues' => [...]]
Template::validateData($template, $data);   // before render: [['path' => '/recipientName', 'code' => 'DATA_REQUIRED']]
$doc = Template::render($template, $data);  // plain writer JSON, or throws TemplateError
$pdf = \FinePrint\Agent::toBytes($doc);     // the writer validates it again
```

Missing data is loud, errors carry a code and a JSON Pointer and never a data
value, and every input is bounded. `SPEC.md` is normative; the conformance
corpus is `template/*` in `fancy-conformance`.

`examples/certificate.fine-print.json` is the default certificate of completion
(the laravel-courses migration target).

**Status: PHP leg only, local, unpublished.** See `CHANGELOG.md`.
