# AGENTS.md -- fancy-template

Document templates: a writer document plus `{{ tokens }}`, JSON directives and
variable declarations, rendered to plain writer JSON. `CLAUDE.md` symlinks
here. This file describes THIS REPO'S CODE; process rules live in the
envelope's `RULES.md`.

## Read SPEC.md first

`SPEC.md` is normative and the `template/*` suites in `fancy-conformance` are
its rows. A disagreement between an implementation and the spec is a bug in the
implementation; a disagreement between the spec and a row is fixed in both, in
one change. The design and the owner's decisions:
envelope `.ai/plans/document-writer-templates.md`.

Three legs, one spec. Each leg was written against SPEC.md, not against another
leg's source -- that is how phase 6 found four PHP disagreements (CHANGELOG).
Keep it that way: when a leg needs a change, read the spec clause first.

## Layout

```
SPEC.md                    the specification
profiles/<writer>.json     which fields take tokens and how values are encoded -- DATA, read by every leg
examples/                  realistic templates + corpus.json (the parity corpus, with hostile data sets)
php/src/  php/tests/       PHP leg (composer.json AT THE ROOT): Pest -- conformance, spec, security, golden
src/  tests/               TypeScript leg (package.json AT THE ROOT): vitest -- conformance, spec, security
python/                    Python leg, its own pyproject (`fancy-template`): pytest -- conformance, spec, security
scripts/parity/            the per-leg drivers and the writer read-back for php/tests/parity.php
scripts/sabotage.py        breaks each bound and guard in turn, in every leg; every one must turn a suite red
```

The same module split in each leg: Scanner (tokens), Inspector (static issues),
Declarations (types, validateData, defaults), Renderer (directives, encodings,
output bounds), Filters / Numbers / Dates, Profile, Json (plain-JSON reduction,
pointers, image check), Limits, TemplateError.

## Invariants

- **Writers never depend on this package.** It emits their plain JSON; they
  stay zero-dependency.
- **No regex over template text.** The scanners and the date-pattern compilers
  are forward-only. The token search is bounded by `tokenLength`, measured in
  UTF-8 BYTES in every leg (the TS and Python scanners work on encoded bytes).
- **Profiles are allowlists.** A field not listed refuses tokens.
- **Errors carry codes and template pointers, never data values.** The security
  tests and sabotage S22/T22/P22 hold this.
- **Absent is a value nothing in data can be**: `Absent::Value` (PHP enum case),
  a unique `Symbol` (TS), a private singleton (Python).
- **Data is reduced to plain JSON first**, with the same refusals in every leg
  (non-finite, lone surrogate, cycle, deeper than 4096): SPEC.md section 4.
- **A value is never re-scanned.** Substituted text containing `{{` reaches the
  page literally.
- **holy-sheet cells**: every string result is `{ "value": s }`; only numbers,
  booleans and declared-`date` values stay bare.
- **Iterations are counted lazily** (a generator in every leg), so the same
  template fails with the same code whichever bound it hits first.

## Testing

```bash
# PHP
composer install
php vendor/bin/pest --test-directory=php/tests

# TypeScript
npm install
npm run lint && npm test && npm run build

# Python (from python/; the loader and fancy-expr come from the envelope's
# sibling checkouts through pytest's `pythonpath`)
cd python && python -m pytest -q
python -m ruff check python && python -m ruff format --check python
cd python && MYPYPATH="src;../../fancy-expr/python/src" python -m mypy --config-file pyproject.toml   # ':' on POSIX

# Cross-leg (needs `npm run build` first; the TS leg is measured as it ships)
php php/tests/parity.php              # three legs x every row + corpus, byte for byte; exit 1 on any mismatch
php php/tests/parity.php --control    # every leg replaced by a different wrong answer: must report 0%
php php/tests/parity.php --writers    # + the corpus through the real writers, text read back and compared

python -I scripts/sabotage.py [php|ts|py|expr]
```

Never read a suite's verdict through a pipe; the harnesses write to files and
check exit codes.

**Pins.** fancy-conformance is 0.35.0 in all three legs and every one asserts
it: composer.json's EXACT require-dev, package.json's `^0.35.0` plus the
committed package-lock.json, `PINNED_SUITE_VERSION` in each conformance test,
and the CI checkout `ref: v0.35.0` (a Python test reads ci.yml). fancy-expr takes
`>=0.2.0 <2.0` everywhere; CI's Python job installs it from the `v0.2.0` tag
because it is not on PyPI yet. `fine-print` is an exact PHP require-dev pin.

**From 0.36.0 no `template/*` row is skipped for any language**, and the PHP
conformance test asserts it per suite. (0.35.0 marked 11 dark-slide/holy-sheet
rows `skip.php`; the test that ran them explicitly was deleted with the skips.)

**Running against an untagged fixture set.** Python reads the sibling
`repos/fancy-conformance` checkout (pytest `pythonpath`), so it sees the working
tree at once. PHP and TypeScript read `vendor/` and `node_modules/`; to run them
against fixtures that are not published yet, copy that checkout's `suites/` and
`VERSION` into `vendor/particle-academy/fancy-conformance/` and
`node_modules/@particle-academy/fancy-conformance/`. `composer install` /
`npm ci` put the pinned release back.

`scripts/sabotage.py` and `parity.php --writers` read the envelope's sibling
checkouts (`repos/fancy-expr`, the nine writer repos), so they run from inside
the envelope only. CI runs `parity.php` without `--writers`.

## Traps

- `[]` is both an empty object and an empty list in PHP. `Json::isObject`
  treats it as an object; a template using `{}` meaningfully round-trips as `[]`
  in the PHP leg only.
- PHP decodes an object key spelled with digits as an INTEGER key, and a
  `{"0":..,"1":..}` object as a list. JavaScript reorders integer-like keys to
  the front of an object. Avoid digit keys in templates.
- PHP's float-to-string depends on ini settings. `Numbers` never uses it; TS
  uses `Number#toString` itself; Python starts from `repr()`.
- An integer past 2^53 is exact in PHP and Python and rounded in JavaScript
  (JSON.parse). `money` takes only safe integers in TS for that reason.
- Pest needs `--test-directory=php/tests` (no root `tests/` here -- that is the
  TypeScript leg's).
- The Python wheel force-includes `../profiles`; build with `--sdist --wheel`,
  never the wheel from the sdist.

## Known gaps (phase 6 findings, open)

- **dark-slide `items` (kpiBand, metadataGrid) are objects**, but the profile's
  `slides.*.elements.*.items.*` matches only a string item, so a KPI value or
  caption cannot take a token. Slide and element `id` are not templatable
  either, so a repeated slide repeats its literal id.
- **dark-slide's PPTX `MarkdownInline` has no backslash escapes** (all three
  languages): escaped values print their backslashes AND `\*` / `` \` `` still
  open italics / code. SPEC.md section 9 records the measurement and why the
  profile does not force `plain`. Fix belongs in dark-slide.
- **holy-sheet coerces `{ "value": "+1" }` to the number 1** (and `"-1"`):
  the wrapper stops formulas but not auto-typing. A template has no per-cell way
  to say "text" other than a `string` column type.
