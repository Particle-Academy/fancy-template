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

## Layout

```
SPEC.md                    the specification
profiles/<writer>.json     which fields take tokens and how values are encoded -- DATA, shared by every leg
examples/                  real templates (the default certificate)
php/src/                   PHP leg: Template (API), Inspector, Renderer, Scanner, Declarations, Filters, Numbers, Dates, Profile
php/tests/                 Pest: conformance, golden (through fine-print), security
scripts/sabotage.py        breaks each bound and guard in turn; every one must turn a suite red
composer.json              AT THE ROOT
```

## Invariants

- **Writers never depend on this package.** It emits their plain JSON; they
  stay zero-dependency.
- **No regex over template text.** `Scanner` and `Dates::compile` are
  forward-only. The token search is bounded by `tokenLength`.
- **Profiles are allowlists.** A field not listed refuses tokens.
- **Errors carry codes and template pointers, never data values.**
  `SecurityTest` and sabotage S22 hold this.
- **Absent is `Absent::Value`, an enum case**, because any string sentinel can
  be forged by data.
- **Data is reduced to plain JSON first** (`Json::plain`), so no host method
  runs during render.
- **A value is never re-scanned.** Substituted text containing `{{` reaches the
  page literally (golden test).

## Testing

```bash
composer install
php vendor/bin/pest --test-directory=php/tests
php php/tests/parity.php            # byte-for-byte parity vs every fixture (reported, not a gate)
php php/tests/parity.php --control  # must report 0%
python -I scripts/sabotage.py       # every bound and guard, one at a time
```

Every dependency resolves from Packagist; there are no path repositories.
`fancy-expr` takes the sibling range `>=0.2.0 <2.0`. `fancy-conformance` and
`fine-print` are EXACT `require-dev` pins, because `composer.lock` is not
committed and a range would resolve whatever Packagist serves on the day CI
runs. Moving the fixture set is a deliberate commit here. The conformance test
prints the installed fixture version.

`scripts/sabotage.py` also breaks bounds in the envelope's `repos/fancy-expr`
checkout, so it runs from inside the envelope only.

## Traps

- `[]` is both an empty object and an empty list in PHP. `Json::isObject`
  treats it as an object; a template using `{}` meaningfully round-trips as `[]`.
- PHP's float-to-string depends on ini settings. `Numbers` never uses it.
- Pest needs `--test-directory=php/tests` (no root `tests/` here).
