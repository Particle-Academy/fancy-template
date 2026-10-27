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
| `fancyTemplate` | exactly the integer `1` | `TEMPLATE_VERSION` |
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

Directive keys are removed from the output. `$each` repeats the object once per
element, spliced into the parent array in order, with bindings `<$as>`, `$index`
(0-based), `$first`, `$last`. When both are present, `$each` runs first and `$if`
is evaluated once per element. `$if` sees every declared variable (after
defaults) and the loop bindings; an absent path is `null` (fancy-expr's rule),
so a condition on an optional variable is not an error.

Static issues (`inspect`):

| code | when |
|---|---|
| `DIRECTIVE_MISPLACED` | a `$`-key on an object that is not an array element, or on `document` itself |
| `DIRECTIVE_UNKNOWN` | any other `$`-key |
| `DIRECTIVE_INVALID` | `$as` without `$each`; a non-string value; a bad path; an `$as` that is not a name or shadows a variable or an enclosing `$as` |
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
| `cell` | a whole token keeps a number, boolean or date as-is; every other result is written `{ "value": s }`, so user data can never become a formula |
| `image` | must be a whole token, no filters, naming an `image` variable; the value's type must be in the profile's `mime` list (`VALUE_IMAGE_TYPE`). A literal (token-free) value must be a valid image data URL of an allowed type (`TEMPLATE_IMAGE_INVALID`) |

Wrong use of an image field is `TOKEN_IMAGE_FIELD`.

**`{page}` is refused rather than escaped** because neither fine-print nor
last-word has an escape for it: both replace the literal text in a band. A refusal
is loud; any lookalike substitution would print a different character. When a
writer gains an escape, the band encoding uses it.

**The markdown escape is CommonMark's.** dark-slide's PPTX writer
(`MarkdownInline`) does not honour backslash escapes yet and writes `html` as
plain text, so an escaped value prints its backslashes there. Recorded as a
dark-slide finding; the escape is still the correct encoding for every
CommonMark consumer, including fancy-slides.

Profiles in phase 1: `fine-print` and `last-word` (implemented in PHP);
`dark-slide` and `holy-sheet` (rows written, implementation in phase 6).

## 10. Bounds

Host-overridable through `options.limits`; each failure has a stable code.

| limit | default | measured as | code |
|---|---|---|---|
| `templateBytes` | 262144 | UTF-8 bytes of every string in the envelope, keys included | `TEMPLATE_TOO_LARGE` |
| `tokens` | 10000 | tokens in the template | `TEMPLATE_TOO_MANY_TOKENS` |
| `tokenLength` | 512 | UTF-8 bytes between `{{` and `}}`; also the length of a `$if` or `$each` value | `TOKEN_TOO_LONG` |
| `directiveDepth` | 8 | directive objects nested inside directive objects | `DIRECTIVE_TOO_DEEP` |
| `iterations` | 10000 | `$each` elements expanded in one render, all loops together | `RENDER_TOO_MANY_ITERATIONS` |
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

Identical in every language (names adapted to the language's case convention):

- `inspect(template, options?) -> { ok, issues: [{ code, path, variable? }] }` -- no
  data. Issues in document order: envelope keys, then variables (in written
  order, a declaration's own keys before its nested declarations), then
  `document` depth-first. Within an object its `$` keys come first, in written
  order, then its other entries in written order. Within a string, tokens in
  order; within a token, its path before its filters.
- `validateData(template, data, options?) -> errors` (section 4).
- `render(template, data, options?) -> document`, or throws `TemplateError { code, path, variable? }`.
  Order: `inspect` (first issue), then `validateData` (first error), then render.
- `preview(template, options?) -> document` -- `render` with each variable's
  `example` (else `default`).
- `tokenize(text, options?) -> parts` (section 5).

Output key order is the template's, minus directive keys.
