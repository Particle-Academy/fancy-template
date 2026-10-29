# fancy-template -- specification

**Normative.** One specification, three implementations (PHP first, then
TypeScript and Python), held together by the `template/*` suites in
`fancy-conformance`. Where an implementation and this file disagree, the
implementation is wrong. Where this file and a conformance row disagree, one of
them is a bug and it gets fixed in both places in one change.

Design and the owner's decisions: envelope `.ai/plans/document-writer-templates.md`
(accepted 2026-10-27).

## 1. What this is

A **document template** is a writer document (fine-print, last-word, dark-slide,
holy-sheet JSON) with `{{ tokens }}` in some string values and JSON directives on
some array items, plus a declaration of the data it needs. `render(template,
data)` returns a plain writer document, which the host then passes through that
writer's own `validate` and `write`. The writers do not depend on this package.

It is not a programming language. No code execution, no host-object access, no
includes, no calls. Templates and data both arrive from people and agents over the
wire.

## 2. Envelope

```json
{ "fancyTemplate": 1,
  "writer": "fine-print",
  "description": "optional, free text",
  "variables": { "recipientName": { "type": "string", "required": true, "maxLength": 200 } },
  "document": { "...": "a writer document" } }
```

| key | rule | issue code |
|---|---|---|
| `fancyTemplate` | exactly the integer `1` (an integral value, as section 3 defines integer: `1.0` is the same number; `true` and `"1"` are not) | `TEMPLATE_VERSION` |
| `writer` | a profile this implementation knows (section 9) | `TEMPLATE_WRITER` |
| `variables` | optional object; absent means `{}` | `TEMPLATE_SHAPE` |
| `document` | required object | `TEMPLATE_SHAPE` |
| anything else except `description` (a string) | not allowed | `TEMPLATE_SHAPE` |

The envelope itself must be an object (`TEMPLATE_SHAPE` at path `""`).

## 3. Variable declarations

`variables` maps a **name** (`^[A-Za-z_][A-Za-z0-9_]*$`) to a **declaration**.
A declaration is an object with these keys and no others:

| key | applies to | meaning |
|---|---|---|
| `type` | all, required | `string` `number` `integer` `boolean` `date` `image` `array` `object` |
| `required` | all | boolean, default `false` |
| `default` | all | used when the value is absent; must itself be valid |
| `example` | all | used by `preview()`; must itself be valid |
| `description` | all | string |
| `enum` | string, number, integer | non-empty list of allowed values of that type |
| `maxLength` | string, date, image | non-negative integer, counted in **Unicode code points** (JSON Schema's unit) |
| `maxItems` | array | non-negative integer |
| `items` | array, required | the declaration of every element (its `required` is ignored) |
| `properties` | object, required | map of name to declaration, same rules recursively |

Any other shape is `DECL_INVALID` at the offending key's path (or at the
declaration when `type` is missing or unknown). A bad name is `DECL_INVALID` at
`/variables/<name>`.

Types:

- `string`: a JSON string.
- `number`: a finite JSON number. `integer`: a number with an integral value
  (`3.0` is an integer, as in JSON Schema).
- `boolean`: `true` / `false`.
- `date`: a string, `YYYY-MM-DD` or `YYYY-MM-DDTHH:MM[:SS[.fraction]][Z|+HH:MM|-HH:MM]`,
  with a real calendar date (leap years honoured) and a real time.
- `image`: a string `data:image/png;base64,<b64>` or `data:image/jpeg;base64,<b64>`
  whose base64 decodes strictly and whose decoded bytes start with the format's
  magic number (PNG `89 50 4E 47 0D 0A 1A 0A`, JPEG `FF D8 FF`).
- `array`, `object`: JSON containers.

`null` is not a value of any type.

**Not JSON Schema, on purpose.** `required: true` on the declaration and the
`date`/`image` types are the plan's approved vocabulary and read better to the
people writing templates. They map mechanically to JSON Schema (`required`
hoisted to an array; `date` and `image` become `string` with a `format`), so a
form can still be generated from them; that mapping is not part of phase 1.

## 4. Data and `validateData`

`validateData(template, data) -> errors` where each error is `{ path, code }`,
`path` a JSON Pointer into `data`. Errors are listed in declaration order,
depth-first. An empty list means valid. Codes:

| code | when |
|---|---|
| `DATA_TYPE` | wrong type, including `null`; also when `data` itself is not an object (path `""`) |
| `DATA_REQUIRED` | a required name is absent and has no `default` |
| `DATA_MAX_LENGTH` | longer than `maxLength` code points |
| `DATA_MAX_ITEMS` | more than `maxItems` elements |
| `DATA_ENUM` | not one of `enum` (compared by type and value) |
| `DATA_FORMAT` | a `date` or `image` string that is not one |
| `DATA_TOO_DEEP` | data nested deeper than `limits.depth` (section 8) |

**Undeclared keys are ignored and unreachable**: `inspect` refuses any token or
directive that names one, so extra data a host passes along can never appear in a
document. Absent optional names are fine at this stage; a token that reads one
fails at render unless it has a fallback (section 6).

Before validating, data is reduced to plain JSON values. A host object that is
not plain JSON data is converted the way the host language's JSON encoder would
convert it, or refused (`DATA_TYPE`). No host method is called during render.

Never plain JSON, in every language, so the three legs refuse the same input
(`DATA_TYPE` at `""` for data, `TEMPLATE_SHAPE` at `""` for a template): a
non-finite number, a string that is not well-formed Unicode (a lone surrogate),
a cycle, and nesting deeper than 4096 levels (PHP's JSON encoder limit). These
are refused WHOLE, before validation, rather than reported at a path, because
JavaScript's encoder would otherwise rewrite `Infinity` to `null` and move the
error.

**Defaults** apply at every level: an absent name with a `default` takes the
default (recursively for object properties) before anything reads it.

## 5. Tokens

Tokens appear only inside string VALUES of `document`, and only in fields the
writer's profile allows (section 9).

```
text     := ( char | "{{" token "}}" )*
token    := ws ( string | path ) ( ws "|" ws filter )* ws
path     := head ( "." segment )*
head     := name | "$index" | "$first" | "$last"
segment  := name | digits
name     := [A-Za-z_][A-Za-z0-9_]*
digits   := [0-9]+
filter   := fname ( ws ":" ws arg )?
fname    := [a-z]+
arg      := string | bare
bare     := [A-Za-z0-9_.+-]+
string   := '"' ( any char except '"' and '\' | '\"' | '\\' )* '"'
ws       := ( " " | TAB )*
```

- `{{` always opens a token. There is no other escape: a literal `{{` is written
  `{{ "{{" }}`. A `}}` outside a token, or a single `{` or `}`, is literal text.
- Inside a string literal `}}` does not close the token.
- Scanning is a single left-to-right pass with no backtracking.
- Failures, in scan order. The scan for the closing `}}` (skipping string
  literals) fails with `TOKEN_TOO_LONG` as soon as it has passed
  `limits.tokenLength` UTF-8 bytes after the `{{` without finding one, and with
  `TOKEN_UNCLOSED` if the text ends first. A token is therefore never scanned
  past its limit. Then the inner text is parsed: `TOKEN_EMPTY` if it is only
  whitespace, `TOKEN_SYNTAX` for anything else that does not match, including a
  newline or an unknown `$` head.

`tokenize(text)` returns the parts in order: `{ "text": s }` for literal text
(adjacent text merged, empty text omitted), `{ "path": [segments], "filters": [...] }`
or `{ "literal": s, "filters": [...] }` for a token. A filter is `{ "name": n }` or
`{ "name": n, "arg": s }`; an arg is always a string, whether written bare or quoted.
`""` tokenizes to `[]`.

A string that is **exactly one token** (nothing before `{{` or after `}}`, not even
whitespace) is a **whole token**. Whether that keeps the value's type depends on
the field's encoding (section 9); in a text field it is stringified like any
other token.

## 6. Resolution

The **scope** is: loop bindings of the enclosing `$each` items (innermost first:
its `$as` name, then `$index`, `$first`, `$last`), then the declared variables.
`inspect` refuses a token whose head is neither (`TOKEN_UNDECLARED`) and a
`$index`/`$first`/`$last` outside any `$each` (`TOKEN_UNDECLARED`).

`inspect` also walks the rest of the path through the DECLARATIONS: a name must be
a declared property of an `object`, digits must step into an `array`, and nothing
steps into a scalar; otherwise `TOKEN_UNDECLARED`. A loop binding's declaration is
its array's `items`.

At render the rest of the path walks the value: a name selects an object key, digits select
an array element. Anything that does not resolve -- an absent key, an index out of
range, a step into a scalar -- is **absent**. Absent is never an empty string.

| resolved value | text field result |
|---|---|
| string | itself |
| number | shortest round-trip decimal, as JavaScript's `Number.prototype.toString` writes it (`3`, `0.1`, `1e+21`) |
| boolean | `true` / `false` |
| array, object | `TEMPLATE_NOT_SCALAR` |
| absent | `TEMPLATE_MISSING`, unless a `default` filter supplies a value |

Every render error carries `code` and `path`, where `path` is a JSON Pointer into
the TEMPLATE (`/document/blocks/3/runs/0/text`), never an expanded copy and never
a data value. Errors name variables by path (`recipientName`, `item.name`), never
by value.

## 7. Filters

Applied left to right. A filter receives the current value, which may be absent.
Every filter except `default` given an absent value fails with `TEMPLATE_MISSING`.
Unknown filter names are `FILTER_UNKNOWN` and a bad or missing argument is
`FILTER_ARG`, both reported by `inspect`. A value of the wrong kind at render is
`FILTER_INPUT`.

| filter | argument | input | output |
|---|---|---|---|
| `default` | required | anything | the input unchanged if present, else the argument (a string) |
| `upper` / `lower` | none | scalar (stringified first) | ASCII letters mapped; every other character unchanged |
| `number` | required, integer 0-10 | number | fixed decimals, comma thousands groups |
| `money` | required, ISO 4217 code from the table | integer minor units | symbol or code, comma groups, the currency's minor digits |
| `date` | required, a pattern | a `date` string | formatted |
| `join` | optional, default `", "` | array of scalars | elements stringified and joined |

**`number`**: the input's shortest round-trip decimal representation is rounded to
the given number of decimals, half away from zero, in decimal arithmetic (never by
binary floating point: `2.675 | number:2` is `2.68`). Thousands are grouped with
`,`. Negative values take a leading `-`; a value that rounds to zero is written
without a sign.

**`money`**: the input must be an integer (minor units: `123450 | money:USD` is
`$1,234.50`). The exponent comes from the table; the amount is never converted
through a float. Prefix is the symbol (`USD $`, `EUR €`, `GBP £`, `JPY ¥`,
`INR ₹`, `KRW ₩`) or the code and a space (`CHF 1,234.50`); a negative amount is
`-$1,234.50`. Exponents: 0 for `JPY KRW CLP VND ISK`, 3 for `KWD BHD JOD OMR TND`,
2 for `USD EUR GBP CAD AUD NZD CHF CNY INR BRL MXN SEK NOK DKK PLN SGD HKD ZAR`.
Any other code is `FILTER_ARG`.

**`date`**: input is a `date` string (section 3); a time-of-day defaults to
`00:00:00`, an offset is ignored (the wall-clock time written is the one given).
Pattern letters, each run matched in full:

| run | output |
|---|---|
| `yyyy` / `yy` | year, 4 digits / last 2 |
| `MMMM` `MMM` `MM` `M` | January / Jan / 01 / 1 |
| `dd` `d` | day, 2 digits / no padding |
| `EEEE` `EEE` | Monday / Mon |
| `HH` `H` | hour 00-23 / 0-23 |
| `hh` `h` | hour 01-12 / 1-12 |
| `mm` `ss` | minute, second, 2 digits |
| `a` | `AM` / `PM` |

Text in single quotes is literal (`''` is one quote). Any other ASCII letter, or a
run length not listed, is `FILTER_ARG`. Every other character is literal. English
names only (owner decision 4).

## 8. Directives

On an object that is an ELEMENT OF AN ARRAY anywhere in `document`:

| key | value |
|---|---|
| `$each` | a path (section 5 grammar, no filters) to an array |
| `$as` | a name for the element; default `item`; only with `$each` |
| `$if` | a fancy-expr expression, kept or dropped by fancy-expr truthiness |
| `$value` | any JSON value; makes the element a **splice** (below); only with `$each` |

Directive keys are removed from the output. `$each` repeats the object once per
element, spliced into the parent array in order, with bindings `<$as>`, `$index`
(0-based), `$first`, `$last`. When both are present, `$each` runs first and `$if`
is evaluated once per element. `$if` sees every declared variable (after
defaults) and the loop bindings -- including the element's own `$each` binding,
**whatever order the two keys are written in** (JSON key order is not something
an author should have to get right); an absent path is `null` (fancy-expr's
rule), so a condition on an optional variable is not an error.

### The `$value` splice

A directive sits on an object, so an element that is itself an array (a
holy-sheet row), a string (a list of strings) or any other value cannot carry
one. The **splice** repeats any value:

```json
{ "$each": "lines", "$as": "line", "$value": ["{{ line.desc }}", "{{ line.qty }}"] }
```

An object element of an array that has a `$value` key is a splice. It is
replaced, in place, by one rendered copy of its `$value` per element of `$each`,
in order; an empty array produces nothing. Everything else is `$each`'s rules:
`$as`, the bindings, `$if` evaluated once per element (in any key order), an
absent array at render is `TEMPLATE_MISSING` at `$each`, and the same bounds
(below).

- **`$value` is the element.** Its content renders exactly as if it had been
  written at the splice's position in the array: the profile matches it by the
  ELEMENT's path (`sheets.*.rows.*` for a row, so its strings are `cell`s and a
  string result is still `{ "value": s }`), and `$value` is not a path segment.
  Error paths point into the template: `/document/sheets/0/rows/1/$value/0`.
- **Any JSON value**: an array, an object, a string, a number, a boolean or
  `null`. `null`, `0` and `false` are values to copy, not "no value".
- **It may contain directives, under the same nesting bound.** Arrays inside
  `$value` take directive elements and splices like any other array, and each
  counts toward `limits.directiveDepth` on its path, the splice itself
  included. The TOP of `$value` is not an array element, so a `$`-key on it is
  `DIRECTIVE_MISPLACED` -- a condition belongs on the splice.
- **A splice holds only directives.** `$value` without `$each` is
  `DIRECTIVE_INVALID` at `$value` (as `$as` without `$each` is), and its content
  is not inspected. A key not starting with `$` beside `$value` is
  `DIRECTIVE_SPLICE_KEY` at that key, and its value is not inspected: there is
  no object left to put it on.
- **Bounds**: each element counts one toward `limits.iterations`, shared with
  every `$each`; each rendered copy's values count toward `limits.outputNodes`
  and `outputBytes`; the splice object itself is not output and counts nothing.

`inspect` order for a splice: its `$` keys in written order, then its other
keys in written order, then the content of `$value` (when `$each` is present).

Static issues (`inspect`):

| code | when |
|---|---|
| `DIRECTIVE_MISPLACED` | a `$`-key on an object that is not an array element, or on `document` itself, or on the top of a `$value` |
| `DIRECTIVE_UNKNOWN` | any other `$`-key |
| `DIRECTIVE_INVALID` | `$as` or `$value` without `$each`; a non-string value; a bad path; an `$as` that is not a name or shadows a variable or an enclosing `$as` |
| `DIRECTIVE_SPLICE_KEY` | a key not starting with `$` on a splice (an object with `$value`) |
| `DIRECTIVE_EXPR` | `$if` that fancy-expr refuses (any fancy-expr code) |
| `DIRECTIVE_UNDECLARED` | `$each` head or a `$if` reference that is not in scope |
| `DIRECTIVE_NOT_ARRAY` | `$each` path whose declaration is not `array` |
| `DIRECTIVE_TOO_DEEP` | more than `limits.directiveDepth` directive objects nested |

At render, a `$each` path that is absent is `TEMPLATE_MISSING` and one that is not
an array is `DIRECTIVE_NOT_ARRAY`.

## 9. Profiles

A profile is data (`profiles/<writer>.json`): which string fields may hold tokens
and how a value is ENCODED there. Field patterns are dot-separated over the path
of the string inside `document`: a literal key, `*` for exactly one key or
index, `**` for zero or more. **Anything not listed refuses tokens**
(`TOKEN_FORBIDDEN_FIELD`), and a token inside an object KEY is `TOKEN_IN_KEY`.

| encoding | result |
|---|---|
| `text` | the string, every token stringified |
| `band` | as `text`, but a VALUE containing `{page}` or `{pages}` is `VALUE_PAGE_FIELD` |
| `markup` | as `text`, each value escaped for the element's `format`: `markdown` backslash-escapes every ASCII punctuation character (CommonMark), `html` escapes `& < > " '` as entities, `plain` or absent leaves it |
| `markdown` | as `markup` with format fixed to markdown |
| `cell` | a whole token keeps a number, boolean or date as-is; every other result is written `{ "value": s }`, so user data can never become a formula. "Date" means: the token's path is DECLARED `date` and the result (after filters) is a valid `date` string (section 3) -- a date-shaped value of a `string` variable is wrapped, and `{{ d \| date:"yyyy" }}` is text. A valid date cannot begin with `=`. A literal (token-free) cell is the author's and is left as written |
| `image` | must be a whole token, no filters, naming an `image` variable; the value's type must be in the profile's `mime` list (`VALUE_IMAGE_TYPE`). A literal (token-free) value must be a valid image data URL of an allowed type (`TEMPLATE_IMAGE_INVALID`) |

Wrong use of an image field is `TOKEN_IMAGE_FIELD`.

**`{page}` is refused rather than escaped** because neither fine-print nor
last-word has an escape for it: both replace the literal text in a band. A refusal
is loud; any lookalike substitution would print a different character. When a
writer gains an escape, the band encoding uses it.

**The markdown escape is CommonMark's, and the profile does not force `plain`.**
dark-slide's PPTX writer (`MarkdownInline`, in all three of its languages) does
not honour backslash escapes and writes `html` as plain text. Measured in phase 6
by writing the hostile corpus deck through it: an escaped value prints its
backslashes, AND the escape fails to neutralise markup -- `\*bold\*` still opens
italics and `` \`c\` `` still opens a code span, because the tokenizer reads the
`*` and the backtick as markers regardless of the backslash before them.

Forcing `format: plain` on a substituted element is not the answer: `format` is
per ELEMENT, not per run, so it would print the template author's own `**`, `#`
and `-` literally on every slide that uses a token, and it contradicts
template/profiles 0101 and 0102. The escape is the correct encoding for every
CommonMark consumer, including fancy-slides; the fix belongs in dark-slide
(`MarkdownInline`: a backslash before ASCII punctuation yields that character,
consumed before marker detection). Until then a dark-slide template that must
show user text verbatim in a `.pptx` uses an element without `format`.

**dark-slide speaker notes are `text`, not `markdown`** (owner ruling,
2026-10-27). The PPTX writer writes notes as plain text in all three of its
languages, and fancy-slides edits them in a plain textarea, so a markdown
escape would print its backslashes in both. A value reaches the notes exactly
as given (template/profiles 0104).

Profiles: `fine-print`, `last-word`, `dark-slide` and `holy-sheet`, implemented
in all three legs (phase 6).

## 10. Bounds

Host-overridable through `options.limits`; each failure has a stable code.

| limit | default | measured as | code |
|---|---|---|---|
| `templateBytes` | 262144 | UTF-8 bytes of every string in the envelope, keys included | `TEMPLATE_TOO_LARGE` |
| `tokens` | 10000 | tokens in the template | `TEMPLATE_TOO_MANY_TOKENS` |
| `tokenLength` | 512 | UTF-8 bytes between `{{` and `}}`; also the length of a `$if` or `$each` value | `TOKEN_TOO_LONG` |
| `directiveDepth` | 8 | directive objects nested inside directive objects | `DIRECTIVE_TOO_DEEP` |
| `iterations` | 10000 | `$each` elements expanded in one render, all loops and splices together | `RENDER_TOO_MANY_ITERATIONS` |
| `outputNodes` | 50000 | values in the output (each object, array and scalar is one) | `RENDER_TOO_MANY_NODES` |
| `outputBytes` | 8388608 | UTF-8 bytes of every string value in the output | `RENDER_TOO_LARGE` |
| `depth` | 64 | nesting of the template and of the data | `TEMPLATE_TOO_DEEP` / `DATA_TOO_DEEP` |
| per value | declarations | `maxLength`, `maxItems` | `DATA_MAX_LENGTH` / `DATA_MAX_ITEMS` |

`depth` counts containers: the envelope (or the data object) is level 1 and each
nested object or array one more; a container at a level above the limit fails.
`directiveDepth` counts directive-bearing objects on one path from `document`
down, the object itself included. Iterations are counted as they happen, so a
nested `$each` counts every inner element of every outer one.

`depth`, then `templateBytes`, then `tokens` are checked first and stop
`inspect`: a template over one of them is reported with that one issue. fancy-expr's own limits
(8192 bytes, depth 64) apply inside `$if` as well.

## 11. API

Identical in every language (names adapted to the language's case convention:
PHP `Template::validateData`, TypeScript `validateData`, Python `validate_data`):

- `inspect(template, options?) -> { ok, issues: [{ code, path, variable? }] }` -- no
  data. Issues in document order: envelope keys, then variables (in written
  order, a declaration's own keys before its nested declarations), then
  `document` depth-first. Within an object its `$` keys come first, in written
  order, then its other entries in written order (a splice then walks its
  `$value`, section 8). Within a string, tokens in
  order; within a token, its path before its filters.
- `validateData(template, data, options?) -> errors` (section 4).
- `render(template, data, options?) -> document`, or throws `TemplateError { code, path, variable? }`.
  Order: `inspect` (first issue), then `validateData` (first error), then render.
- `preview(template, options?) -> document` -- `render` with each variable's
  `example` (else `default`).
- `tokenize(text, options?) -> parts` (section 5).
- `inspectFile(bytes, options?)` and `renderFile(bytes, data, options?)` (section 12).

Output key order is the template's, minus directive keys.

## 12. Office files as templates

Owner's rule: **any file can be a template.** A customer's own `.docx`, `.pptx`
or `.xlsx` with `{{ tokens }}` typed into it is filled AS IT IS, in its own
text, with every other byte of the package kept. Nothing is read into a writer
model and written back, because a model drops what it does not carry (custom
styles, embedded objects, fields, comments, layout).

**Status.** PHP implements this section. TypeScript and Python expose the two
functions and throw `FileTemplatesNotImplementedError` (`code`
`FILE_NOT_IMPLEMENTED`, `operation` the function's name), never a silent
no-op. Shared rows and fixtures: `fixtures/files/` (section 12.10).

### 12.1 API

| | PHP | TypeScript | Python |
|---|---|---|---|
| inspect | `Template::inspectFile(string $bytes, array $options = [])` | `inspectFile(bytes: Uint8Array, options?)` | `inspect_file(data: bytes, options=None)` |
| render | `Template::renderFile(string $bytes, mixed $data, array $options = []) : string` | `renderFile(bytes, data, options?) : Uint8Array` | `render_file(data, values, options=None) -> bytes` |

`inspectFile` returns
`{ ok, format, kind, issues: [{code, path, variable?}], placeholders: [{path, token, variable?}], variables: [name] }`:

- `format`: `docx`, `pptx` or `xlsx`; `kind`: `document` or `template` (from the
  main part's content type, 12.3). Both `null` when the file is refused.
- `placeholders`: every token, in order (12.5): `path` is its location, `token`
  the token's source text from `{{` to `}}` as it reads in the joined text,
  `variable` the dotted path (absent for a string-literal token).
- `variables`: the HEAD of every token path, distinct, in first-use order: the
  names the data must supply.
- A refused file (any `FILE_` code) is reported as the single issue, as an
  oversized template is (section 10), and `inspectFile` does not throw for it.

`renderFile` returns the filled file's bytes, or throws `TemplateError`. Order:
open and classify the package (`FILE_` codes), inspect (first issue), validate
the data as `validateData` does (first error, path into the data), fill.

Options: `variables` (12.2), `limits` (`tokens`, `tokenLength`, `outputBytes`,
and `depth` for the data, apply; the rest have nothing to bound in a file) and
`fileLimits` (12.8). An
unknown `fileLimits` key, or a value that is not a positive integer, is refused
as an unknown `limits` key is.

### 12.2 Declarations: a sidecar, not document properties

`options.variables` is a declaration map exactly as an envelope's `variables`
(section 3), checked by the same rules with the same codes and paths
(`DECL_INVALID` at `/variables/<name>/...`; a non-object is `TEMPLATE_SHAPE` at
`/variables`). With it, the file is inspected as a template is: a token whose
path is not declared is `TOKEN_UNDECLARED`, a token naming an `image` variable
is `TOKEN_IMAGE_FIELD` (an image cannot be text), data is validated with
`DATA_*` codes and defaults apply.

**Why a sidecar.** Custom document properties would put the declarations inside
the customer's file, where:

- anyone who opens it in Word sees and can edit them (File > Properties), so the
  customer can break the template without knowing it is one;
- writing or updating them rewrites `docProps/custom.xml`, which is exactly the
  kind of change to a customer's file this feature exists to avoid;
- they are flat strings, so a declaration would be JSON inside a property, a
  second format to parse in three languages;
- some editors drop unknown custom properties on save.

The host already stores a record for every file it accepts as a template; the
declarations belong in that record, beside the file, where they can be versioned
and reused across new uploads of the same file.

**Without declarations** the names are INFERRED from the tokens, the way
`inspect` reports them: no token is undeclared, nothing is typed, and
`validateData` has no member to check (data must still be a plain JSON object:
`DATA_TYPE`, `DATA_TOO_DEEP`). Every token must still resolve at render: absent
is `TEMPLATE_MISSING`, a container is `TEMPLATE_NOT_SCALAR`, a wrong filter input
is `FILTER_INPUT`. A file has no `$each`, so `$index`, `$first` and `$last` are
`TOKEN_UNDECLARED` in both modes.

### 12.3 Recognising the package

In this order; the first failure is the answer.

1. An OLE compound file (first 8 bytes `D0 CF 11 E0 A1 B1 1A E1`) is
   `FILE_ENCRYPTED` when its bytes contain the UTF-16LE stream name
   `EncryptedPackage` (a password-protected OOXML file), else `FILE_UNSUPPORTED`
   (a legacy binary `.doc`/`.xls`/`.ppt`).
2. The zip is indexed and checked (12.8).
3. `[Content_Types].xml` must be a member (exact name), else `FILE_UNSUPPORTED`.
   It is read like every part (12.7). Each member's content type is its
   `Override` (by part name `/` + member name, ASCII case-insensitive), else the
   `Default` for its extension (case-insensitive), else none. Content types
   compare case-insensitively.
4. A member whose type is a macro-enabled or binary main type is
   `FILE_MACRO_ENABLED`: `application/vnd.ms-word.document.macroEnabled.main+xml`,
   `...ms-word.template.macroEnabledTemplate.main+xml`,
   `...ms-powerpoint.{presentation,slideshow,template,addin}.macroEnabled.main+xml`,
   `...ms-excel.{sheet,template,addin}.macroEnabled.main+xml`,
   `...ms-excel.sheet.binary.macroEnabled.main`.
5. Exactly one member must have one of these main types, else `FILE_UNSUPPORTED`:

   | content type (`application/vnd.openxmlformats-officedocument.` +) | format | kind |
   |---|---|---|
   | `wordprocessingml.document.main+xml` | docx | document |
   | `wordprocessingml.template.main+xml` | docx | template |
   | `presentationml.presentation.main+xml`, `presentationml.slideshow.main+xml` | pptx | document |
   | `presentationml.template.main+xml` | pptx | template |
   | `spreadsheetml.sheet.main+xml` | xlsx | document |
   | `spreadsheetml.template.main+xml` | xlsx | template |

6. A VBA project anywhere is `FILE_MACRO_ENABLED`: any declared type (`Default`
   or `Override`) equal to `application/vnd.ms-office.vbaProject`, or any member
   whose name ends with `vbaProject.bin` (case-insensitive). A `.docx` may not
   smuggle what a `.docm` declares.

**Macro-enabled files are refused, not passed through.** Passing one through
would hand back, from this system and filled by an agent, a file carrying code
nothing here inspected; macro documents are the most common malware carrier,
and last-word's template intake already refuses them. A customer who needs the
macros keeps them in their own copy. **Encrypted files are refused** because
there is nothing to fill: the text is ciphertext without the password, and
"passing it through" would return the input unchanged with every placeholder
still in it.

**The parts that are filled**, by content type (prefix as above):

| format | parts |
|---|---|
| docx | the main document (`document.main` or `template.main`), `header`, `footer`, `footnotes`, `endnotes`, `comments` (`wordprocessingml.*+xml`) |
| pptx | `slide`, `slideLayout`, `slideMaster`, `notesSlide`, `notesMaster` (`presentationml.*+xml`) |
| xlsx | `sharedStrings`, `worksheet` (`spreadsheetml.*+xml`) |

**Layouts and masters are filled.** A token on a master or layout (a footer's
`{{ client }}`, a confidentiality line) is drawn on every slide that uses it,
so leaving it unfilled would print `{{ client }}` on every slide; that is the
common reason to put text there at all. A placeholder's prompt text on a layout
("Click to add title") is never shown in a slide show, so filling a token in it
is harmless. **Comments are filled** because they ride along with the file and
an unfilled token in one leaks the template into the customer's review pane.

**Every other `.xml` or `.rels` member is still read** (12.7, so a DOCTYPE in
it is refused) and searched for `{{`: one found is `TOKEN_FORBIDDEN_FIELD` at
`/<member name>`. A token in a place that is not filled is refused, not left
in the output, exactly as a profile refuses a field it does not list (section
9): document properties, charts, SmartArt, a glossary, custom XML.

### 12.4 Where text is: containers, runs, text nodes, barriers

Elements are matched by namespace URI and local name, never by prefix. Each
format accepts its transitional and strict namespace:

| format | namespace (transitional; strict) |
|---|---|
| docx | `http://schemas.openxmlformats.org/wordprocessingml/2006/main`; `http://purl.oclc.org/ooxml/wordprocessingml/main` |
| pptx | `http://schemas.openxmlformats.org/drawingml/2006/main`; `http://purl.oclc.org/ooxml/drawingml/main` |
| xlsx | `http://schemas.openxmlformats.org/spreadsheetml/2006/main`; `http://purl.oclc.org/ooxml/spreadsheetml/main` |

| format | container | run | text node | transparent run children | barriers |
|---|---|---|---|---|---|
| docx | `p` | `r` | `t` child of a run | `rPr`, `delText`, `lastRenderedPageBreak` | every other run child (`tab`, `br`, `cr`, `drawing`, `pict`, `fldChar`, `instrText`, `sym`, `footnoteReference`, ...); `m:oMath`/`m:oMathPara` (OMML namespace) inside the container |
| pptx | `p` | `r` | `t` child of a run | `rPr` | every other run child; `br` and `fld` inside the container (outside a run) |
| xlsx | `si`, `is` | `r` | `t` child of a run, or `t` child of the container itself | `rPr` | every other run child |

- A **container** is a paragraph (docx/pptx), a shared-string item `si` or an
  inline string `is` (xlsx). A container inside another (a text box's paragraph
  inside a run's drawing) is its own container; the run child holding it
  (`drawing`) is a barrier in the outer one.
- A **run** is a run element that is a descendant of the innermost open
  container and not inside another run. Containers' other children (`proofErr`,
  `bookmarkStart`, `ins`, `hyperlink`, `fldSimple`, `sdt`, `commentRangeStart`,
  `rPh`, ...) are neither text nor barriers; runs inside them are runs.
  `delText` is not text: deleted text is not shown and is not joined.
- The text nodes of a container, in document order, split at barriers, form its
  **spans**. A span's **joined text** is its nodes' decoded text (12.7)
  concatenated. An empty text node (`<w:t/>`, `<w:t></w:t>`) is skipped.
- A container's **location** is `/<member name>#<label>`, the label being the
  container's local name and its 0-based index among the part's containers in
  START-tag order (`#p0`, `#si3`), except an xlsx `is` inside a `c` whose `r`
  attribute is a cell reference (`[A-Z0-9]+`): then the label is that reference
  (`#B4`). A text box's paragraph is numbered after the paragraph holding it.
- The **visible text** of a container, used by the shared rows, is its text
  nodes' decoded text concatenated (barriers add nothing); in xlsx, a
  `_xHHHH_` escape is then decoded to its character.

### 12.5 Tokens, inspection, and the run-split rule

Each span whose joined text contains `{{` is scanned with section 5's grammar
and bounds. A scan failure (`TOKEN_UNCLOSED`, `TOKEN_TOO_LONG`, `TOKEN_EMPTY`,
`TOKEN_SYNTAX`) is one issue at the container's location, and that span
contributes no tokens. A token cannot cross a barrier or a container boundary,
so a `{{` before a tab and its `}}` after it is `TOKEN_UNCLOSED`.

Per token, in order: its path (`TOKEN_UNDECLARED` with `variable`, or
`TOKEN_IMAGE_FIELD`, 12.2), then each filter (`FILTER_UNKNOWN`, `FILTER_ARG`),
all at the container's location.

Issue order: sidecar declarations (12.2), then members in CENTRAL-DIRECTORY
order; within a filled part, containers in location order, spans in order,
tokens in order. Placeholders are in the same order. When the file's tokens
outnumber `limits.tokens`, the issues are exactly `[TEMPLATE_TOO_MANY_TOKENS at ""]`.

**The run-split rule.** Word splits text into runs for spell-check marks,
revision ids and formatting, so `{{ client }}` may be `{{ cli` in one run and
`ent }}` in the next. A token is found in the span's joined text, then:

1. The value is written into the text node where the token STARTS, at the
   token's position, replacing the token's characters in that node. That node's
   run keeps its properties, so **the value takes the formatting of the run
   holding `{{`**: `{{ cli` bold + `ent }}` italic fills bold.
2. The rest of the token is cut from each following node it covers. Text in
   those nodes outside the token stays, in its own run with its own formatting.
3. **No node and no run is ever removed**, even one left empty: an empty run is
   invisible, and a run may be what a bookmark, a comment range or a revision
   is anchored to.
4. Text around a token in a touched node is copied as its ORIGINAL bytes (its
   entities, character references, comments and CDATA as written); only the
   token's own bytes change. A node no token touches is not changed at all.

### 12.6 Values

A token's value is computed as a `text` field's (sections 6 and 7): resolved
against the (defaulted) data, filtered, stringified. Then:

- It must hold only characters XML 1.0 can carry: a control character other
  than TAB, LF and CR, or U+FFFE / U+FFFF, is `VALUE_XML_CHAR` at the location,
  with `variable`. There is no escape for these in XML 1.0.
- It is **XML-escaped**: `&` `<` `>` as `&amp;` `&lt;` `&gt;`. Nothing in a
  value can become markup.
- **xlsx**: a value is written as text in the shared string or inline string
  that held the token, and the cell around it is never touched, so it stays a
  text cell: a value starting with `=`, `+`, `-` or `@` is text, never a
  formula. A `_xHHHH_` sequence in a value has its `_` written as `_x005F_`
  (ECMA-376 ST_Xstring), so Excel shows the value as given instead of decoding it.
- **A whole-token number in an xlsx cell stays a string.** The token sits in a
  shared string, which may be used by several cells, so turning one cell into a
  number means editing the worksheet's cells and possibly splitting the string;
  the cell's number format was authored for text; and the value has already been
  stringified by section 6's rules, which is what the customer typed the token
  into. A future option may write declared `number` values as number cells;
  until then the rule is: text in, text out.
- **docx and xlsx white space**: when a touched text node's new text begins or
  ends with white space and its start tag has no `xml:space` attribute,
  ` xml:space="preserve"` is inserted before the tag's `>`, because Word and
  Excel otherwise drop it. pptx text keeps its white space and needs none.
- A value is never re-scanned: `{{` in a value reaches the file literally.
- `limits.outputBytes` bounds the UTF-8 bytes of all values written
  (`RENDER_TOO_LARGE` at `""`).

### 12.7 Reading XML

Every member that is read (`[Content_Types].xml`, the filled parts and the
searched `.xml`/`.rels` members) is refused with `FILE_DOCTYPE` at
`/<member name>` when it contains `<!DOCTYPE` (ASCII case-insensitive),
before it is parsed. Nothing defines an entity, so nothing expands one, and
there is no DTD to fetch.

The filled parts and `[Content_Types].xml` are read by a forward-only lexer that
reports each element with its byte offsets. `FILE_XML` at `/<member name>` for:
a UTF-16 byte-order mark, an XML declaration naming an encoding other than
UTF-8, any `<!` other than a comment or CDATA, an unterminated comment, CDATA or
processing instruction, a mismatched or unclosed tag, more than one root, an
attribute that is unquoted, repeated or holds `<`, an unbound prefix, an entity
other than the five predefined ones, a character reference to a character XML
cannot hold, or an element inside a text node. Text-node content decodes the
five entities and character references, takes CDATA literally and skips
comments and processing instructions.

A filled part is re-read by the same lexer before it is written; failing that is
a bug in the implementation, not a file problem.

### 12.8 The archive

The central directory is parsed and checked before any member is decompressed.
Bounds, through `options.fileLimits` (the defaults are last-word's):

| limit | default | measured as | code |
|---|---|---|---|
| `archiveBytes` | 67108864 | the file | `FILE_TOO_LARGE` |
| `entries` | 16384 | members in the central directory | `FILE_TOO_MANY_ENTRIES` |
| `partBytes` | 67108864 | any member's declared uncompressed size | `FILE_TOO_LARGE` |
| `expandedBytes` | 268435456 | all declared uncompressed sizes together | `FILE_TOO_LARGE` |
| `compressionRatio` | 1000 | a member's uncompressed / max(1, compressed) | `FILE_ZIP_BOMB` |

All with path `""`. Also refused, path `""`:

| code | when |
|---|---|
| `FILE_ARCHIVE` | no end-of-central-directory record that accounts for the whole file; multi-disk; zip64 (an entry count of 65535, a 0xFFFFFFFF size or offset, a zip64 extra field); a method other than stored (0) or deflate (8); a version needed above 2.0; a flag other than bits 1, 2, 3 and 11; a stored member whose sizes differ; a local header that disagrees with the central one (signature, flags, method, name, CRC and sizes unless bit 3 defers them to a matching data descriptor); members that overlap; bytes between the last central record and the end record; a non-ASCII name without the UTF-8 flag; a member whose decompressed length or CRC differs from the directory |
| `FILE_ENCRYPTED` | the encryption flag (bit 0) or the strong-encryption flag (bit 6), method 99, or an AES extra field (0x9901) |
| `FILE_PATH` | a name that is empty, starts with `/`, contains `\` or `:` or a control character, is not UTF-8, or has an empty, `.` or `..` segment; two names equal under ASCII case folding (two spellings of one OPC part let a scan read one and an office suite the other) |

**Nothing is fetched.** Relationships are never resolved: an external target
(`TargetMode="External"`, a remote image, a `file://` link) is bytes in a
`.rels` member, copied with it.

**Output.** The output has the input's members in the same local and central
order with the same archive comment. Every member not filled is its ORIGINAL
LOCAL RECORD, byte for byte (header, extra field, compressed data, data
descriptor), and its central record is the original with only the local-header
offset changed. A filled member is written deflated (or stored, if the
compressor chooses) with its original timestamp, UTF-8 flag and attributes, no
extra field, no comment and no data descriptor.

### 12.9 Not covered

- **Repeating sections** (a table row per line item). They would need: a
  marker that delimits the repeated block in the file's own text (for example
  a row whose first cell holds `{{ #each lines as line }}` and a closing marker),
  the block located at the XML-element level (a `w:tr`, a run of `w:p`, an
  `a:tr`, a worksheet `row`) rather than in text, copies of that element with
  fresh ids where the format demands unique ones (`w14:paraId`, drawing
  `docPr/@id`, bookmark ids, xlsx row numbers and every cell reference, shared
  formula ranges, merged cells and defined names below it), the same
  `iterations` bound, and a rule for what happens to a split marker. That is a
  structural edit, not a text substitution, which is why it is a separate step.
- Text that is not in a container's text nodes: attribute values (alt text,
  hyperlink targets), field codes (`instrText`), chart and SmartArt text, xlsx
  header/footer strings (`oddHeader`), cell comments, formulas. A token in a
  member that is not filled is refused (12.3); one in a filled member outside
  its text nodes is not seen.
- A newline in a value is written as a newline character, which Word and
  PowerPoint show as a space; turning it into a break element is not done.
- ODF (`.odt`, `.odp`, `.ods`), legacy binary formats, `.xlsb`.

### 12.10 Shared rows

`fixtures/files/cases.json` holds the rows (hand-written from this section)
and `fixtures/files/*` the files, built by `scripts/files/build-fixtures.php`:
hand-authored minimal OOXML, plus a docx written by last-word and a pptx written
by dark-slide (then given a layout, a master and a split run). The rows are in
fancy-conformance's case-table shape, ready to become its `template/files`
suite; until then every leg reads them from here. A port is done when it passes
every row: `expect.text` by visible text (12.4), `expect.changed` as the exact
set of members whose bytes differ (all others byte-identical records),
`expect.contains` / `expect.absent` as raw substrings, `expect.error` as the
thrown error and as `inspectFile`'s single issue.
