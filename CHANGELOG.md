# Changelog

All notable changes to this package are documented here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

This package is pre-1.0: **breaking changes land in MINOR releases**, so the
version number is not a compatibility promise until 1.0.

## [Unreleased]

## [0.2.0] - 2026-10-27

Phase 6 of the plan: the TypeScript and Python legs, and the dark-slide and
holy-sheet profiles in all three. **Nothing to do for a PHP consumer** -- every
change to the PHP leg below either adds a profile or accepts a template the spec
always allowed; nothing that rendered before renders differently.

### Added

- **The `$value` splice** (owner ruling 2026-10-27, "Agent: add $value
  splice"), SPEC.md section 8: `{"$each": "<path>", "$as": "<name>", "$value":
  <any JSON>}` as an item of any array is replaced in place by one rendered copy
  of `$value` per element, so a holy-sheet ROW, a cell or a string in a list can
  repeat. `$value` renders as if written at the element's position (a spliced
  row's strings are still `{ "value": s }`), may contain directives under the
  same `directiveDepth`, and counts toward `iterations` and `outputNodes` like
  `$each`. `$value` without `$each` is `DIRECTIVE_INVALID`; a non-`$` key beside
  it is the new `DIRECTIVE_SPLICE_KEY`. Rows: fancy-conformance 0.36.0.
  Implemented in all three legs (Inspector + Renderer). Against the 0.36.0
  fixtures with the pre-splice code, all 24 new or changed rows failed in every
  leg; with it, all 203 pass in every leg, 211/211 byte-identical across legs
  (`parity.php`), 0% under `--control`, 60/60 through the real writers. The xlsx
  corpus report now repeats its quarter rows with a splice instead of
  addressing them by index. Nine sabotages per leg (`S33`-`S41`, `T33`-`T41`,
  `P33`-`P41`): `$value` without `$each`, a key beside it, `directiveDepth`
  inside it, the splice object counting no node, one iteration counter for every
  loop, the element's path for the profile, its top not being an element, a
  `null` `$value` still splicing, and plain notes in the profile data.

- **TypeScript leg**, `@particle-academy/fancy-template` (`src/`, `tests/`;
  ESM + CJS + types via tsup). Same API as PHP: `inspect`, `validateData`,
  `render`, `preview`, `tokenize`, `TemplateError { code, path, variable }`.
  Written against SPEC.md, not against the PHP source. Depends on
  `@particle-academy/fancy-expr` `>=0.2.0 <2.0` only. Scans UTF-8 bytes so the
  token-length bound means what it means in PHP; every key read and write goes
  through own-property helpers, so a template or data key spelled `__proto__`
  or `constructor` is only ever a key.
- **Python leg**, `fancy-template` (`python/`, Python >= 3.11, typed). API in
  snake_case (`validate_data`). Depends on `fancy-expr>=0.2.0,<2.0` only. Every
  walk of unknown depth is iterative; `bool` is never a number; names, digits
  and dates are ASCII-only; a host `str`/`int` subclass is read through its base
  type, so no host method runs.
- **dark-slide and holy-sheet profiles, in all three legs**: the `markup`,
  `markdown` and `cell` encodings. dark-slide values are CommonMark-escaped in
  `format: "markdown"` content and entity-escaped in `format: "html"`;
  every holy-sheet string result is written `{ "value": s }`, so user data can
  never become a formula. A whole-token number or boolean keeps its type, and so
  does a valid date whose path is DECLARED `date` (SPEC.md section 9, clarified).
- All 203 `template/*` rows of fancy-conformance 0.36.0 pass in every leg,
  none skipped. **The fixture pin moved 0.35.0 -> 0.36.0** together in
  composer.json (exact), package.json (`^0.36.0`) + package-lock.json, the
  three `PINNED_SUITE_VERSION` constants and both CI checkout refs.
- **Cross-leg parity** (`php php/tests/parity.php`): every fixture row and a
  corpus of realistic templates (`examples/`: certificate, invoice with `$each`
  line items, a deck with a repeated slide and repeated table rows, a regional
  xlsx report) run through all three legs and compared byte for byte -- 203/203
  against the expected column in each leg, 211/211 between the legs; `--control`
  reports 0% on every column. `--writers` renders the corpus through the real
  writers (fine-print; last-word, dark-slide and holy-sheet in PHP, Node and
  Python) and compares the extracted text: 60/60 files identical.
- `scripts/sabotage.py` covers all three legs and fancy-expr's bounds in all
  three of its languages: 134 sabotages (40 PHP, 42 TypeScript, 43 Python, 9
  fancy-expr), each turning a suite red. `S29`/`T29`/`P29` ("notes always
  markdown-escaped") were retired with that rule.
- CI: `node`, `python` and `parity` jobs. Publish: npm (Trusted Publishing,
  provenance) and PyPI (build and publish split so `id-token: write` never runs
  build code), both behind the read-only `guard` job and the changelog gate,
  which now BLOCKS them. Both names need the owner's one-time bootstrap.

### Changed

- **dark-slide speaker notes are plain text** (owner ruling 2026-10-27,
  "Agent: notes are plain"), SPEC.md section 9: the profile's `slides.*.notes`
  is `text`, not `markdown`, so a value is no longer backslash-escaped there.
  The PPTX writer writes notes as plain text and fancy-slides edits them in a
  plain textarea, so the escape printed its backslashes in both. Unreleased
  until now in every leg, so no consumer has the old behaviour. Row:
  fancy-conformance 0.36.0 `template/profiles` 0104.
- The PHP test that ran the 11 `skip.php` rows explicitly and asserted there
  were 11 is replaced by one asserting no `template/*` row is skipped for PHP.
  0.36.0 dropped the skips; the old test's own comment said to delete it then.

### Fixed

PHP leg disagreements with SPEC.md, found by building the other two legs against
the spec. Each landed with a test that failed against the old code.

- A `$if` written BEFORE its `$each` in the same object was refused as
  `DIRECTIVE_UNDECLARED`: the binding was only created when the inspector
  reached `$each` in key order. Section 8 says `$each` runs first.
- `templateBytes` skipped object keys spelled with digits (`"10"`), because PHP
  decodes them as integer keys. Section 10 counts every key.
- `maxLength` / `maxItems` written `3.0`, and `fancyTemplate` written `1.0`,
  were refused. Section 3 defines integer as an integral value, and JavaScript
  cannot even tell `3.0` from `3`.

## [0.1.0] - 2026-10-27

### Added

- The conformance test asserts the installed fixture set equals the pinned
  0.35.0 and that composer.json pins it exactly, so a moved fixture set fails
  instead of being printed and passed. `.gitattributes` keeps every file LF, so
  the in-source golden text matches on a Windows checkout too.

- `SPEC.md`, the normative specification of document templates: envelope,
  variable declarations, `{{ }}` tokens, resolution, the fixed filter list,
  `$each` / `$as` / `$if` directives, per-writer profiles and the bounds.
- `profiles/` for fine-print, last-word (implemented) and dark-slide,
  holy-sheet (data only; phase 6).
- The PHP leg: `Template::inspect`, `validateData`, `render`, `preview`,
  `tokenize`, throwing `TemplateError` with a code, a JSON Pointer and a
  variable path -- never a data value. Passes 169 of 169 runnable rows of the
  seven `template/*` suites (11 rows are dark-slide/holy-sheet, skipped for PHP
  in the fixtures with the reason stated).
- `examples/certificate.fine-print.json`: the default certificate, rendered
  through fine-print 0.3.0 and read back in a golden test.
- `scripts/sabotage.py`: every bound and guard broken in turn; all 28 turn a
  suite red.
