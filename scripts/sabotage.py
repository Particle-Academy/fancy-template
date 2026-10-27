"""Sabotage pass: break each security bound and guard ONE AT A TIME and
confirm a test goes red. A green control runs first, because a harness that
reports red on every run proves nothing either.

    python -I scripts/sabotage.py

Each sabotage is an exact text replacement with an expected occurrence count,
so a refactor that moves the guarded line makes the harness fail loudly rather
than silently sabotaging nothing. Files are read and restored as BYTES, never
through a shell, and restored in a `finally`.
"""

from __future__ import annotations

import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent
EXPR = HERE.parent.parent / "repos" / "fancy-expr"
SRC = HERE / "php" / "src"

PEST_TEMPLATE = (HERE, ["php", "vendor/bin/pest", "--test-directory=php/tests"])
PEST_EXPR = (EXPR, ["php", "vendor/bin/pest"])


@dataclass
class Sabotage:
    id: str
    what: str
    file: Path
    find: str
    replace: str
    count: int = 1
    nth: int | None = None  # replace only this occurrence (1-based)
    suites: tuple = (PEST_TEMPLATE,)


S = [
    Sabotage("S01", "token-length bound in the scanner (all three checks)", SRC / "Scanner.php",
             "if ($j - $start > $limit) {", "if (false) {", count=3),
    Sabotage("S02", "string literals skipped while finding `}}`", SRC / "Scanner.php",
             "if ($c === '\"') {", "if (false) {", count=2, nth=1),
    Sabotage("S03", "templateBytes bound", SRC / "Inspector.php",
             "if (Json::stringBytes($template) > $this->limits->templateBytes) {", "if (false) {"),
    Sabotage("S04", "tokens bound", SRC / "Inspector.php",
             "if ($this->countTokens($template['document'] ?? null) > $this->limits->tokens) {", "if (false) {"),
    Sabotage("S05", "template depth bound", SRC / "Inspector.php",
             "$tooDeep = Json::firstTooDeep($template, $this->limits->depth);", "$tooDeep = null;"),
    Sabotage("S06", "data depth bound", SRC / "Template.php",
             "$tooDeep = Json::firstTooDeep($data, $limits->depth);", "$tooDeep = null;"),
    Sabotage("S07", "directiveDepth bound", SRC / "Inspector.php",
             "if ($directiveDepth > $this->limits->directiveDepth) {", "if (false) {"),
    Sabotage("S08", "iterations bound", SRC / "Renderer.php",
             "if (++$this->iterations > $this->limits->iterations) {", "if (false) {"),
    Sabotage("S09", "outputNodes bound", SRC / "Renderer.php",
             "if (++$this->nodes > $this->limits->outputNodes) {", "if (false) {"),
    Sabotage("S10", "outputBytes bound", SRC / "Renderer.php",
             "if ($this->bytes > $this->limits->outputBytes) {", "if (false) {"),
    Sabotage("S11", "token-length bound on $if", SRC / "Inspector.php",
             "if (strlen($value) > $this->limits->tokenLength) {", "if (false) {", count=2, nth=2),
    Sabotage("S12", "profile allowlist (any field takes tokens)", SRC / "Inspector.php",
             "if ($field === null) {", "if (false) {"),
    Sabotage("S13", "{page} refused in band values", SRC / "Renderer.php",
             "if ($field['encode'] === 'band' && (", "if (false && ("),
    Sabotage("S14", "image field takes only a whole image-variable token", SRC / "Inspector.php",
             "if (! $isWhole || ! $found || ! is_array($decl) || ($decl['type'] ?? null) !== 'image') {", "if (false) {"),
    Sabotage("S15", "literal image src must be an allowed data URL", SRC / "Inspector.php",
             "if (($field['encode'] ?? null) === 'image' && ! in_array(Json::imageType($value), $field['mime'], true)) {", "if (false) {"),
    Sabotage("S16", "image magic-byte check", SRC / "Json.php",
             "return $raw !== false && str_starts_with($raw, $magic) ? $mime : null;", "return $raw !== false ? $mime : null;"),
    Sabotage("S17", "undeclared token paths refused", SRC / "Inspector.php",
             "$this->issue('TOKEN_UNDECLARED', $path", "null && $this->issue('TOKEN_UNDECLARED', $path"),
    Sabotage("S18", "missing data is loud (silent-empty instead)", SRC / "Renderer.php",
             "$value = isset($part['path']) ? $this->resolve($part['path'], $frames) : $part['literal'];",
             "$value = isset($part['path']) ? $this->resolve($part['path'], $frames) : $part['literal']; if ($value === Absent::Value) { $value = ''; }"),
    Sabotage("S19", "render validates data first", SRC / "Template.php",
             "if ($errors !== []) {", "if (false) {"),
    Sabotage("S20", "data reduced to plain JSON", SRC / "Template.php",
             "[$plainData, $data] = Json::plain($data);", "$plainData = true;"),
    Sabotage("S21", "$if refused when fancy-expr refuses it", SRC / "Inspector.php",
             "$this->issue('DIRECTIVE_EXPR', $at);", "$roots = [];"),
    Sabotage("S22", "errors never carry a data value", SRC / "Renderer.php",
             "throw new TemplateError('VALUE_PAGE_FIELD', $path, $variable);", "throw new TemplateError('VALUE_PAGE_FIELD', $path, $piece);"),
    Sabotage("S23", "directives only on array elements", SRC / "Inspector.php",
             "if (! $isElement) {", "if (false) {"),
    Sabotage("S24", "tokens refused in object keys", SRC / "Inspector.php",
             "if (str_contains((string) $key, '{{')) {", "if (false) {"),
    Sabotage("S25", "default replaces only absent, never a present empty string", SRC / "Filters.php",
             "return $value === Absent::Value || $value === null ? $arg : $value;",
             "return $value === Absent::Value || $value === null || $value === '' ? $arg : $value;"),
    Sabotage("E01", "fancy-expr: length bound", EXPR / "php" / "src" / "Expr.php",
             "if (strlen($source) > self::MAX_EXPRESSION_BYTES) {", "if (false) {", suites=(PEST_EXPR, PEST_TEMPLATE)),
    Sabotage("E02", "fancy-expr: depth guard on the way down", EXPR / "php" / "src" / "Expr.php",
             "if ($s['nest'] >= self::MAX_EXPRESSION_DEPTH) {", "if (false) {", suites=(PEST_EXPR, PEST_TEMPLATE)),
    Sabotage("E03", "fancy-expr: depth check on finished nodes", EXPR / "php" / "src" / "Expr.php",
             "if ($depth > self::MAX_EXPRESSION_DEPTH) {", "if (false) {", suites=(PEST_EXPR, PEST_TEMPLATE)),
]

ANSI = re.compile(r"\x1b\[[0-9;]*m")


def run(suite: tuple) -> tuple[int, str, list[str]]:
    cwd, cmd = suite
    log = HERE / "scripts" / ".sabotage.log"
    with open(log, "wb") as out:
        code = subprocess.run(cmd, cwd=cwd, stdout=out, stderr=subprocess.STDOUT).returncode
    text = ANSI.sub("", log.read_text(encoding="utf-8", errors="replace"))
    log.unlink()
    summary = next((line.strip() for line in text.splitlines() if line.strip().startswith("Tests:")), "no summary line")
    failed = sorted({m.group(1).strip() for m in re.finditer(r"FAILED\s+\S+\s+>\s+(.+?)(?:\s{2,}|$)", text, re.M)})
    return code, summary, failed


def apply(s: Sabotage) -> bytes:
    original = s.file.read_bytes()
    text = original.decode("utf-8")
    found = text.count(s.find)
    if found != s.count:
        raise SystemExit(f"{s.id}: expected {s.count} occurrence(s) of the guarded text in {s.file.name}, found {found}")
    if s.nth is None:
        text = text.replace(s.find, s.replace)
    else:
        parts = text.split(s.find)
        text = s.find.join(parts[: s.nth]) + s.replace + s.find.join(parts[s.nth:])
    s.file.write_bytes(text.encode("utf-8"))
    return original


def main() -> int:
    print("CONTROL (nothing sabotaged)")
    for suite in (PEST_TEMPLATE, PEST_EXPR):
        code, summary, _ = run(suite)
        print(f"  {suite[0].name}: exit={code} {summary}")
        if code != 0:
            print("  the control is not green; a sabotage result would mean nothing")
            return 2

    rows = []
    for s in S:
        original = apply(s)
        try:
            results = [run(suite) for suite in s.suites]
        finally:
            s.file.write_bytes(original)
        assert s.file.read_bytes() == original
        red = any(code != 0 for code, _, _ in results)
        detail = " | ".join(f"{suite[0].name}: exit={code} {summary}" for suite, (code, summary, _) in zip(s.suites, results))
        names = [n for _, _, failed in results for n in failed]
        rows.append((s.id, s.what, red, detail, names))
        print(f"{s.id} {'RED  ' if red else 'GREEN'} {s.what}\n      {detail}")
        for n in names[:6]:
            print(f"        - {n}")

    caught = sum(1 for r in rows if r[2])
    print(f"\n{caught} / {len(rows)} sabotages turned a suite red")
    return 0 if caught == len(rows) else 1


if __name__ == "__main__":
    sys.exit(main())
