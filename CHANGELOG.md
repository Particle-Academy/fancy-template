# Changelog

All notable changes to this package are documented here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

This package is pre-1.0: **breaking changes land in MINOR releases**, so the
version number is not a compatibility promise until 1.0.

## [Unreleased]

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
