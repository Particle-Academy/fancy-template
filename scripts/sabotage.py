"""Sabotage pass: break each security bound and guard ONE AT A TIME, in every
leg, and confirm a test goes red. A green control runs first, because a harness
that reports red on every run proves nothing either.

    python -I scripts/sabotage.py            every leg
    python -I scripts/sabotage.py php        one leg (php | ts | py | expr)

Each sabotage is an exact text replacement with an expected occurrence count,
so a refactor that moves the guarded line makes the harness fail loudly rather
than silently sabotaging nothing. Files are read and restored as BYTES, never
through a shell, and restored in a `finally`.

S = PHP leg, T = TypeScript leg, P = Python leg, with the same number for the
same guard; J and Y are guards only one language needs (JavaScript's prototype
chain, Python's bool-is-an-int and Unicode classes); E breaks fancy-expr's own
bounds in the envelope's checkout. The TypeScript template leg imports
fancy-expr from node_modules, so a T-leg E-row turns fancy-expr's own suite red;
the Python template leg runs on the checkout itself, so its E-rows run both.
"""

from __future__ import annotations

import re
import subprocess
import sys
from dataclasses import dataclass, field
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent
EXPR = HERE.parent.parent / "repos" / "fancy-expr"
PHP = HERE / "php" / "src"
TS = HERE / "src"
PY = HERE / "python" / "src" / "fancy_template"

PEST_TEMPLATE = ("pest fancy-template", HERE, ["php", "vendor/bin/pest", "--test-directory=php/tests"])
PEST_EXPR = ("pest fancy-expr", EXPR, ["php", "vendor/bin/pest"])
VITEST_TEMPLATE = ("vitest fancy-template", HERE, ["node", "node_modules/vitest/vitest.mjs", "run"])
VITEST_EXPR = ("vitest fancy-expr", EXPR, ["node", "node_modules/vitest/vitest.mjs", "run"])
PYTEST_TEMPLATE = ("pytest fancy-template", HERE / "python", [sys.executable, "-m", "pytest", "-q", "-p", "no:cacheprovider"])
PYTEST_EXPR = ("pytest fancy-expr", EXPR / "python", [sys.executable, "-m", "pytest", "-q", "-p", "no:cacheprovider"])

LEG_SUITES = {
    "php": (PEST_TEMPLATE, PEST_EXPR),
    "ts": (VITEST_TEMPLATE, VITEST_EXPR),
    "py": (PYTEST_TEMPLATE, PYTEST_EXPR),
}


@dataclass
class Sabotage:
    id: str
    what: str
    file: Path
    # One or more (find, replace, expected count, nth-or-None) edits to ONE file.
    edits: list[tuple[str, str, int, int | None]]
    suites: tuple = field(default=())

    @property
    def leg(self) -> str:
        return {"S": "php", "T": "ts", "J": "ts", "P": "py", "Y": "py"}.get(self.id[0], "expr")


def sab(id_: str, what: str, file: Path, find: str, replace: str, count: int = 1, nth: int | None = None,
        suites: tuple = ()) -> Sabotage:
    return Sabotage(id_, what, file, [(find, replace, count, nth)], suites)


S: list[Sabotage] = [
    # ---------------------------------------------------------------- PHP leg
    sab("S01", "token-length bound in the scanner (all three checks)", PHP / "Scanner.php",
        "if ($j - $start > $limit) {", "if (false) {", count=3),
    sab("S02", "string literals skipped while finding `}}`", PHP / "Scanner.php",
        "if ($c === '\"') {", "if (false) {", count=2, nth=1),
    sab("S03", "templateBytes bound", PHP / "Inspector.php",
        "if (Json::stringBytes($template) > $this->limits->templateBytes) {", "if (false) {"),
    sab("S04", "tokens bound", PHP / "Inspector.php",
        "if ($this->countTokens($template['document'] ?? null) > $this->limits->tokens) {", "if (false) {"),
    sab("S05", "template depth bound", PHP / "Inspector.php",
        "$tooDeep = Json::firstTooDeep($template, $this->limits->depth);", "$tooDeep = null;"),
    sab("S06", "data depth bound", PHP / "Template.php",
        "$tooDeep = Json::firstTooDeep($data, $limits->depth);", "$tooDeep = null;"),
    sab("S07", "directiveDepth bound", PHP / "Inspector.php",
        "if ($directiveDepth > $this->limits->directiveDepth) {", "if (false) {"),
    sab("S08", "iterations bound", PHP / "Renderer.php",
        "if (++$this->iterations > $this->limits->iterations) {", "if (false) {"),
    sab("S09", "outputNodes bound", PHP / "Renderer.php",
        "if (++$this->nodes > $this->limits->outputNodes) {", "if (false) {"),
    sab("S10", "outputBytes bound", PHP / "Renderer.php",
        "if ($this->bytes > $this->limits->outputBytes) {", "if (false) {"),
    sab("S11", "token-length bound on $if", PHP / "Inspector.php",
        "if (strlen($value) > $this->limits->tokenLength) {", "if (false) {", count=2, nth=2),
    sab("S12", "profile allowlist (any field takes tokens)", PHP / "Inspector.php",
        "if ($field === null) {", "if (false) {"),
    sab("S13", "{page} refused in band values", PHP / "Renderer.php",
        "if ($field['encode'] === 'band' && (", "if (false && ("),
    sab("S14", "image field takes only a whole image-variable token", PHP / "Inspector.php",
        "if (! $isWhole || ! $found || ! is_array($decl) || ($decl['type'] ?? null) !== 'image') {", "if (false) {"),
    sab("S15", "literal image src must be an allowed data URL", PHP / "Inspector.php",
        "if (($field['encode'] ?? null) === 'image' && ! in_array(Json::imageType($value), $field['mime'], true)) {", "if (false) {"),
    sab("S16", "image magic-byte check", PHP / "Json.php",
        "return $raw !== false && str_starts_with($raw, $magic) ? $mime : null;", "return $raw !== false ? $mime : null;"),
    sab("S17", "undeclared token paths refused", PHP / "Inspector.php",
        "$this->issue('TOKEN_UNDECLARED', $path", "null && $this->issue('TOKEN_UNDECLARED', $path"),
    sab("S18", "missing data is loud (silent-empty instead)", PHP / "Renderer.php",
        "$value = isset($part['path']) ? $this->resolve($part['path'], $frames) : $part['literal'];",
        "$value = isset($part['path']) ? $this->resolve($part['path'], $frames) : $part['literal']; if ($value === Absent::Value) { $value = ''; }"),
    sab("S19", "render validates data first", PHP / "Template.php",
        "if ($errors !== []) {", "if (false) {", count=2, nth=1),
    sab("S20", "data reduced to plain JSON", PHP / "Template.php",
        "[$plainData, $data] = Json::plain($data);", "$plainData = true;"),
    sab("S21", "$if refused when fancy-expr refuses it", PHP / "Inspector.php",
        "$this->issue('DIRECTIVE_EXPR', $at);", "$roots = [];"),
    sab("S22", "errors never carry a data value", PHP / "Renderer.php",
        "throw new TemplateError('VALUE_PAGE_FIELD', $path, $variable);", "throw new TemplateError('VALUE_PAGE_FIELD', $path, $piece);"),
    sab("S23", "directives only on array elements", PHP / "Inspector.php",
        "if (! $isElement) {", "if (false) {"),
    sab("S24", "tokens refused in object keys", PHP / "Inspector.php",
        "if (str_contains((string) $key, '{{')) {", "if (false) {"),
    sab("S25", "default replaces only absent, never a present empty string", PHP / "Filters.php",
        "return $value === Absent::Value || $value === null ? $arg : $value;",
        "return $value === Absent::Value || $value === null || $value === '' ? $arg : $value;"),
    sab("S26", "holy-sheet: every string result wrapped {value: s}", PHP / "Renderer.php",
        "return $field['encode'] === 'cell' ? ['value' => $out] : $out;", "return $out;"),
    sab("S27", "dark-slide: markdown values escaped", PHP / "Renderer.php",
        "'markdown' => self::escapeMarkdown($piece),", "'markdown' => $piece,"),
    sab("S28", "dark-slide: html values entity-escaped", PHP / "Renderer.php",
        "'html' => self::escapeHtml($piece),", "'html' => $piece,"),
    # S29/T29/P29 ("notes always markdown-escaped") were retired with that rule:
    # notes are plain text since fancy-conformance 0.36.0 (owner ruling
    # 2026-10-27), so no profile uses the `markdown` encoding any more and those
    # sabotages went GREEN. S41/T41/P41 guard the replacement rule.
    sab("S30", "holy-sheet: a bare date only when DECLARED date", PHP / "Renderer.php",
        "return $found && is_array($decl) && ($decl['type'] ?? null) === 'date' ? $value : Absent::Value;", "return $value;"),
    sab("S31", "templateBytes counts numeric object keys", PHP / "Json.php",
        "$total += ($isList ? 0 : strlen((string) $k)) + self::stringBytes($v);",
        "$total += (is_string($k) ? strlen($k) : 0) + self::stringBytes($v);"),
    sab("S32", "$if sees the $each binding whatever the key order", PHP / "Inspector.php",
        "$scope = $hasEach ? $inner : $frames;",
        "$scope = $hasEach && array_search('$each', array_keys($object), true) < array_search('$if', array_keys($object), true) ? $inner : $frames;"),
    sab("S33", "$value: refused without $each", PHP / "Inspector.php",
        "if (! $hasEach) {", "if (false) {"),
    sab("S34", "$value: a non-$ key beside it is refused", PHP / "Inspector.php",
        "$this->issue('DIRECTIVE_SPLICE_KEY'", "null && $this->issue('DIRECTIVE_SPLICE_KEY'"),
    sab("S35", "$value: directiveDepth bound applies inside it", PHP / "Inspector.php",
        "Json::pointer($path, '$value'), $segments, $frames, $directiveDepth, false);",
        "Json::pointer($path, '$value'), $segments, $frames, 0, false);"),
    sab("S36", "$value: the splice object itself counts no output node", PHP / "Renderer.php",
        "if ($isSplice) {", "if ($isSplice) { $this->count(null);"),
    sab("S37", "$value: iterations shared by every loop and splice", PHP / "Renderer.php",
        "$last = count($list) - 1;", "$last = count($list) - 1; $this->iterations = 0;"),
    sab("S38", "$value: matched at the element's path, never as a segment", PHP / "Inspector.php",
        "Json::pointer($path, '$value'), $segments, $frames, $directiveDepth, false);",
        "Json::pointer($path, '$value'), [...$segments, '$value'], $frames, $directiveDepth, false);"),
    sab("S39", "$value: its top is not an array element", PHP / "Inspector.php",
        "Json::pointer($path, '$value'), $segments, $frames, $directiveDepth, false);",
        "Json::pointer($path, '$value'), $segments, $frames, $directiveDepth, true);"),
    sab("S40", "$value: null is a value to copy, not 'no splice'", PHP / "Renderer.php",
        "$isSplice = array_key_exists('$value', $item);", "$isSplice = isset($item['$value']);"),
    sab("S41", "dark-slide: notes are plain text (profile data)", HERE / "profiles" / "dark-slide.json",
        '{ "path": "slides.*.notes", "encode": "text" }', '{ "path": "slides.*.notes", "encode": "markdown" }'),
    # Office files (SPEC.md section 12), PHP only so far.
    sab("S42", "files: split runs joined (each text node scanned alone instead)", PHP / "Office" / "Package.php",
        "$spans[count($spans) - 1][] = $item;", "$spans[] = [$item];"),
    sab("S43", "files: values XML-escaped", PHP / "Office" / "Package.php",
        "$content .= Xml::escape($values[$i], $xlsx);", "$content .= $values[$i];"),
    sab("S44", "files: an xlsx value never becomes a formula (sabotage writes `=...` as an <f>)", PHP / "Office" / "Package.php",
        "$content .= Xml::escape($values[$i], $xlsx);",
        "$content .= $xlsx && str_starts_with($values[$i], '=') ? '</t></is><f>'.Xml::escape(substr($values[$i], 1), false).'</f><is><t>' : Xml::escape($values[$i], $xlsx);"),
    sab("S45", "files: untouched members copied as their original records", PHP / "Office" / "Zip.php",
        "if ($replacements === []) {", "$replacements += $this->read($this->names()); if ($replacements === []) {"),
    sab("S46", "files: a DOCTYPE in any part read is refused", PHP / "Office" / "Package.php",
        "if (stripos($xml, '<!DOCTYPE') !== false) {", "if (false) {"),
    sab("S47", "files: xml:space=\"preserve\" added when a filled node gains edge white space", PHP / "Office" / "Package.php",
        "$edits[] = [$node['tagEnd'] - 1, $node['tagEnd'] - 1, ' xml:space=\"preserve\"'];", "$edits = $edits;"),
    sab("S48", "files: compression-ratio (zip bomb) bound", PHP / "Office" / "Zip.php",
        "if ($size > max(1, $compressed) * $limits->compressionRatio) {", "if (false) {"),
    sab("S49", "files: `..` and `.` segments refused in entry names", PHP / "Office" / "Zip.php",
        "if ($part === '' || $part === '.' || $part === '..') {", "if ($part === '') {"),
    sab("S50", "files: two spellings of one part name refused (case-insensitive)", PHP / "Office" / "Zip.php",
        "$key = strtolower($name);", "$key = $name;"),
    sab("S51", "files: a VBA project anywhere refuses the file", PHP / "Office" / "Package.php",
        "if ($type === self::VBA) {", "if (false) {"),
    sab("S52", "files: a value XML 1.0 cannot carry is refused", PHP / "Office" / "Package.php",
        "if (! Xml::canCarry($value)) {", "if (false) {"),
    sab("S53", "files: a token in a part never filled is refused", PHP / "Office" / "Package.php",
        "if (str_contains($xml, '{{')) {", "if (false) {"),
    sab("S54", "files: xlsx _xHHHH_ in a value neutralised", PHP / "Office" / "Xml.php",
        "if (! $xlsx || ! str_contains($s, '_x')) {", "if (true) {"),
    sab("S55", "files: entry-count bound", PHP / "Office" / "Zip.php",
        "if ($count > $limits->entries) {", "if (false) {"),
    Sabotage("S56", "files: a host warning handler never turns a bad CRC into a raw exception", PHP / "Office" / "Zip.php", [
        ("set_error_handler(static fn (): bool => true);", "null;", 1, None),
        ("restore_error_handler();", "null;", 1, None),
    ]),
    sab("S58", "files: a vbaProject.bin member refuses the file, declared or not", PHP / "Office" / "Package.php",
        "if (str_ends_with(strtolower($name), 'vbaproject.bin')) {", "if (false) {"),
    sab("S57", "files: renderFile validates data first", PHP / "Template.php",
        "if ($errors !== []) {", "if (false) {", count=2, nth=2),
    # ---------------------------------------------------------- TypeScript leg
    sab("T01", "token-length bound in the scanner (all three checks)", TS / "scanner.ts",
        'if (j - start > limit) throw new TemplateError("TOKEN_TOO_LONG", "");', 'if (false) throw new TemplateError("TOKEN_TOO_LONG", "");', count=3),
    sab("T02", "string literals skipped while finding `}}`", TS / "scanner.ts",
        "if (c === QUOTE) {", "if (false) {"),
    sab("T03", "templateBytes bound", TS / "inspector.ts",
        "if (stringBytes(template) > this.limits.templateBytes) {", "if (false) {"),
    sab("T04", "tokens bound", TS / "inspector.ts",
        'if (this.countTokens(own(template, "document")) > this.limits.tokens) {', "if (false) {"),
    sab("T05", "template depth bound", TS / "inspector.ts",
        "const tooDeep = firstTooDeep(template, this.limits.depth);", "const tooDeep = null as string | null;"),
    sab("T06", "data depth bound", TS / "index.ts",
        "const tooDeep = firstTooDeep(d as Json, limits.depth);", "const tooDeep = null as string | null;"),
    sab("T07", "directiveDepth bound", TS / "inspector.ts",
        "if (directiveDepth > this.limits.directiveDepth) {", "if (false) {"),
    sab("T08", "iterations bound", TS / "renderer.ts",
        "if (++this.iterations > this.limits.iterations) {", "if (false) {"),
    sab("T09", "outputNodes bound", TS / "renderer.ts",
        "if (++this.nodes > this.limits.outputNodes) throw", "if (false) throw"),
    sab("T10", "outputBytes bound", TS / "renderer.ts",
        "if (this.bytes > this.limits.outputBytes) throw", "if (false) throw"),
    sab("T11", "token-length bound on $if", TS / "inspector.ts",
        "if (new TextEncoder().encode(value).length > this.limits.tokenLength) {", "if (false) {", count=2, nth=2),
    sab("T12", "profile allowlist (any field takes tokens)", TS / "inspector.ts",
        "if (field === null) {", "if (false) {"),
    sab("T13", "{page} refused in band values", TS / "renderer.ts",
        'if (field.encode === "band" && (piece.includes', "if (false && (piece.includes"),
    sab("T14", "image field takes only a whole image-variable token", TS / "inspector.ts",
        'if (!isWhole || !found || !isObject(decl) || decl.type !== "image") {', "if (false) {"),
    sab("T15", "literal image src must be an allowed data URL", TS / "inspector.ts",
        'if (field?.encode === "image" && !(field.mime ?? []).includes(imageType(value) ?? "")) {', "if (false) {"),
    sab("T16", "image magic-byte check", TS / "json.ts",
        "if (raw.charCodeAt(i) !== magic[i]) return null;", "if (false) return null;"),
    sab("T17", "undeclared token paths refused", TS / "inspector.ts",
        'this.issue("TOKEN_UNDECLARED"', 'false && this.issue("TOKEN_UNDECLARED"'),
    sab("T18", "missing data is loud (silent-empty instead)", TS / "renderer.ts",
        'let value: Value = "path" in part ? this.resolve(part.path, frames) : part.literal;',
        'let value: Value = "path" in part ? this.resolve(part.path, frames) : part.literal; if (value === ABSENT) value = "";'),
    sab("T19", "render validates data first", TS / "index.ts",
        "if (errors.length > 0) throw", "if (false) throw"),
    sab("T20", "data reduced to plain JSON", TS / "index.ts",
        "const [plainData, d] = plain(data);", "const [plainData, d] = [true, data as Json] as const;"),
    sab("T21", "$if refused when fancy-expr refuses it", TS / "inspector.ts",
        'this.issue("DIRECTIVE_EXPR", at);', "roots = [];"),
    sab("T22", "errors never carry a data value", TS / "renderer.ts",
        'throw new TemplateError("VALUE_PAGE_FIELD", path, variable);', 'throw new TemplateError("VALUE_PAGE_FIELD", path, piece);'),
    sab("T23", "directives only on array elements", TS / "inspector.ts",
        "if (!isElement) {", "if (false) {"),
    sab("T24", "tokens refused in object keys", TS / "inspector.ts",
        'if (key.includes("{{"))', "if (false)"),
    sab("T25", "default replaces only absent, never a present empty string", TS / "filters.ts",
        "return value === ABSENT || value === null ? arg! : value;",
        'return value === ABSENT || value === null || value === "" ? arg! : value;'),
    sab("T26", "holy-sheet: every string result wrapped {value: s}", TS / "renderer.ts",
        'return field.encode === "cell" ? { value: out } : out;', "return out;"),
    sab("T27", "dark-slide: markdown values escaped", TS / "renderer.ts",
        'if (format === "markdown") return escapeMarkdown;', "if (false) return escapeMarkdown;"),
    sab("T28", "dark-slide: html values entity-escaped", TS / "renderer.ts",
        'if (format === "html") return escapeHtml;', "if (false) return escapeHtml;"),
    sab("T30", "holy-sheet: a bare date only when DECLARED date", TS / "renderer.ts",
        'return found && isObject(decl) && decl.type === "date" ? value : null;', "return value;"),
    sab("T32", "$if sees the $each binding whatever the key order", TS / "inspector.ts",
        "const scope = hasEach ? inner : frames;",
        'const keys = Object.keys(object); const scope = hasEach && keys.indexOf("$each") < keys.indexOf("$if") ? inner : frames;'),
    sab("T33", "$value: refused without $each", TS / "inspector.ts",
        'if (!hasEach) this.issue("DIRECTIVE_INVALID", at);', 'if (false) this.issue("DIRECTIVE_INVALID", at);'),
    sab("T34", "$value: a non-$ key beside it is refused", TS / "inspector.ts",
        'if (!key.startsWith("$")) this.issue("DIRECTIVE_SPLICE_KEY"', 'if (false) this.issue("DIRECTIVE_SPLICE_KEY"'),
    sab("T35", "$value: directiveDepth bound applies inside it", TS / "inspector.ts",
        'pointer(path, "$value"), segments, frames, directiveDepth, false);',
        'pointer(path, "$value"), segments, frames, 0, false);'),
    sab("T36", "$value: the splice object itself counts no output node", TS / "renderer.ts",
        "if (isSplice) {", "if (isSplice) { this.count(null);"),
    sab("T37", "$value: iterations shared by every loop and splice", TS / "renderer.ts",
        "const last = list.length - 1;", "const last = list.length - 1; this.iterations = 0;"),
    sab("T38", "$value: matched at the element's path, never as a segment", TS / "inspector.ts",
        'pointer(path, "$value"), segments, frames, directiveDepth, false);',
        'pointer(path, "$value"), [...segments, "$value"], frames, directiveDepth, false);'),
    sab("T39", "$value: its top is not an array element", TS / "inspector.ts",
        'pointer(path, "$value"), segments, frames, directiveDepth, false);',
        'pointer(path, "$value"), segments, frames, directiveDepth, true);'),
    sab("T40", "$value: null is a value to copy, not 'no splice'", TS / "renderer.ts",
        'const isSplice = Object.hasOwn(item, "$value");', 'const isSplice = own(item, "$value") != null;'),
    sab("T41", "dark-slide: notes are plain text (profile data)", HERE / "profiles" / "dark-slide.json",
        '{ "path": "slides.*.notes", "encode": "text" }', '{ "path": "slides.*.notes", "encode": "markdown" }'),
    sab("J01", "output keys are own properties (`__proto__` never sets a prototype)", TS / "renderer.ts",
        "setOwn(out, key, this.value(value, pointer(path, key), [...segments, key], frames, object));",
        "(out as Record<string, unknown>)[key] = this.value(value, pointer(path, key), [...segments, key], frames, object);"),
    sab("J02", "paths read own properties only (`constructor` is absent)", TS / "renderer.ts",
        "if (!hasOwn(value, step)) return ABSENT;", "if (!(step in value)) return ABSENT;"),
    sab("J03", "non-finite numbers are not plain JSON", TS / "json.ts",
        'if (typeof v === "number" && !Number.isFinite(v)) throw new NotPlain();', "if (false) throw new NotPlain();"),
    # -------------------------------------------------------------- Python leg
    sab("P01", "token-length bound in the scanner (all three checks)", PY / "_scanner.py",
        "if j - start > limit:", "if False:", count=3),
    sab("P02", "string literals skipped while finding `}}`", PY / "_scanner.py",
        "if c == _QUOTE:", "if False:", count=2, nth=1),
    sab("P03", "templateBytes bound", PY / "_inspector.py",
        "if string_bytes(template) > self.limits.template_bytes:", "if False:"),
    sab("P04", "tokens bound", PY / "_inspector.py",
        'if self._count_tokens(template.get("document")) > self.limits.tokens:', "if False:"),
    sab("P05", "template depth bound", PY / "_inspector.py",
        "too_deep = first_too_deep(template, self.limits.depth)", "too_deep = None"),
    sab("P06", "data depth bound", PY / "__init__.py",
        "too_deep = first_too_deep(d, limits.depth)", "too_deep = None"),
    sab("P07", "directiveDepth bound", PY / "_inspector.py",
        "if directive_depth > self.limits.directive_depth:", "if False:"),
    sab("P08", "iterations bound", PY / "_renderer.py",
        "if self.iterations > self.limits.iterations:", "if False:"),
    sab("P09", "outputNodes bound", PY / "_renderer.py",
        "if self.nodes > self.limits.output_nodes:", "if False:"),
    sab("P10", "outputBytes bound", PY / "_renderer.py",
        "if self.bytes > self.limits.output_bytes:", "if False:"),
    sab("P11", "token-length bound on $if", PY / "_inspector.py",
        "if utf8_length(value) > self.limits.token_length:", "if False:", count=2, nth=2),
    sab("P12", "profile allowlist (any field takes tokens)", PY / "_inspector.py",
        "if field is None:", "if False:"),
    sab("P13", "{page} refused in band values", PY / "_renderer.py",
        'if field["encode"] == "band" and ("{page}" in piece', 'if False and ("{page}" in piece'),
    sab("P14", "image field takes only a whole image-variable token", PY / "_inspector.py",
        'if not is_whole or not found or not isinstance(decl, dict) or decl.get("type") != "image":', "if False:"),
    sab("P15", "literal image src must be an allowed data URL", PY / "_inspector.py",
        'if field is not None and field["encode"] == "image" and image_type(value) not in field["mime"]:', "if False:"),
    sab("P16", "image magic-byte check", PY / "_json.py",
        "return mime if raw.startswith(magic) else None", "return mime"),
    sab("P17", "undeclared token paths refused", PY / "_inspector.py",
        'self._issue("TOKEN_UNDECLARED", path, ".".join(token["path"]))', "pass"),
    sab("P18", "missing data is loud (silent-empty instead)", PY / "_renderer.py",
        'value = self._resolve(part["path"], frames) if "path" in part else part["literal"]',
        'value = self._resolve(part["path"], frames) if "path" in part else part["literal"]; value = "" if value is ABSENT else value'),
    sab("P19", "render validates data first", PY / "__init__.py",
        "    if errors:\n", "    if False:\n"),
    sab("P20", "data reduced to plain JSON", PY / "__init__.py",
        "plain_data, d = plain(data)", "plain_data, d = True, data"),
    sab("P21", "$if refused when fancy-expr refuses it", PY / "_inspector.py",
        'self._issue("DIRECTIVE_EXPR", at)', "pass"),
    sab("P22", "errors never carry a data value", PY / "_renderer.py",
        'raise TemplateError("VALUE_PAGE_FIELD", path, variable)', 'raise TemplateError("VALUE_PAGE_FIELD", path, piece)'),
    sab("P23", "directives only on array elements", PY / "_inspector.py",
        "if not is_element:", "if False:"),
    sab("P24", "tokens refused in object keys", PY / "_inspector.py",
        'if "{{" in key:', "if False:"),
    sab("P25", "default replaces only absent, never a present empty string", PY / "_filters.py",
        "return arg if value is ABSENT or value is None else value",
        'return arg if value is ABSENT or value is None or value == "" else value'),
    sab("P26", "holy-sheet: every string result wrapped {value: s}", PY / "_renderer.py",
        'return {"value": out} if field["encode"] == "cell" else out', "return out"),
    sab("P27", "dark-slide: markdown values escaped", PY / "_renderer.py",
        "return escape_markdown", "return _identity", count=2, nth=2),
    sab("P28", "dark-slide: html values entity-escaped", PY / "_renderer.py",
        "return escape_html", "return _identity"),
    sab("P30", "holy-sheet: a bare date only when DECLARED date", PY / "_renderer.py",
        'return value if found and isinstance(decl, dict) and decl.get("type") == "date" else ABSENT', "return value"),
    sab("P32", "$if sees the $each binding whatever the key order", PY / "_inspector.py",
        "scope = inner if has_each else frames",
        'scope = inner if has_each and list(obj).index("$each") < list(obj).index("$if") else frames'),
    sab("P33", "$value: refused without $each", PY / "_inspector.py",
        "if not has_each:", "if False:"),
    sab("P34", "$value: a non-$ key beside it is refused", PY / "_inspector.py",
        'self._issue("DIRECTIVE_SPLICE_KEY", pointer(path, key))', "pass"),
    sab("P35", "$value: directiveDepth bound applies inside it", PY / "_inspector.py",
        'pointer(path, "$value"), segments, frames, directive_depth, False)',
        'pointer(path, "$value"), segments, frames, 0, False)'),
    sab("P36", "$value: the splice object itself counts no output node", PY / "_renderer.py",
        'result.append(self._value(item["$value"]', 'self._count(None) or result.append(self._value(item["$value"]'),
    sab("P37", "$value: iterations shared by every loop and splice", PY / "_renderer.py",
        "last = len(lst) - 1", "last = len(lst) - 1; self.iterations = 0"),
    sab("P38", "$value: matched at the element's path, never as a segment", PY / "_inspector.py",
        'pointer(path, "$value"), segments, frames, directive_depth, False)',
        'pointer(path, "$value"), [*segments, "$value"], frames, directive_depth, False)'),
    sab("P39", "$value: its top is not an array element", PY / "_inspector.py",
        'pointer(path, "$value"), segments, frames, directive_depth, False)',
        'pointer(path, "$value"), segments, frames, directive_depth, True)'),
    sab("P40", "$value: null is a value to copy, not 'no splice'", PY / "_renderer.py",
        'is_splice = "$value" in item', 'is_splice = item.get("$value") is not None'),
    sab("P41", "dark-slide: notes are plain text (profile data)", HERE / "profiles" / "dark-slide.json",
        '{ "path": "slides.*.notes", "encode": "text" }', '{ "path": "slides.*.notes", "encode": "markdown" }'),
    sab("Y01", "a bool is never a number (`True == 1` in Python)", PY / "_json.py",
        "return isinstance(v, (int, float)) and not isinstance(v, bool)", "return isinstance(v, (int, float))"),
    sab("Y02", "non-finite numbers are not plain JSON", PY / "_json.py",
        "        if not math.isfinite(v):\n            raise _NotPlainError\n        return float.__float__(v)",
        "        return float.__float__(v)"),
    sab("Y03", "host strings read through `str`, never their own methods", PY / "_json.py",
        'str.encode(s, "utf-8")', 's.encode("utf-8")'),
    Sabotage("Y04", "dates match ASCII digits only", PY / "_dates.py", [
        (r'r"([0-9]{4})-([0-9]{2})-([0-9]{2})"', r'r"(\d{4})-(\d{2})-(\d{2})"', 1, None),
        ("    re.ASCII,\n)", "    0,\n)", 1, None),
    ]),
    # ------------------------------------------------- fancy-expr's own bounds
    sab("E01", "fancy-expr PHP: length bound", EXPR / "php" / "src" / "Expr.php",
        "if (strlen($source) > self::MAX_EXPRESSION_BYTES) {", "if (false) {", suites=(PEST_EXPR, PEST_TEMPLATE)),
    sab("E02", "fancy-expr PHP: depth guard on the way down", EXPR / "php" / "src" / "Expr.php",
        "if ($s['nest'] >= self::MAX_EXPRESSION_DEPTH) {", "if (false) {", suites=(PEST_EXPR, PEST_TEMPLATE)),
    sab("E03", "fancy-expr PHP: depth check on finished nodes", EXPR / "php" / "src" / "Expr.php",
        "if ($depth > self::MAX_EXPRESSION_DEPTH) {", "if (false) {", suites=(PEST_EXPR, PEST_TEMPLATE)),
    sab("E04", "fancy-expr TS: length bound", EXPR / "src" / "parse.ts",
        "const bytes = utf8LengthExceeds(source, MAX_EXPRESSION_BYTES);", "const bytes = null;", suites=(VITEST_EXPR,)),
    sab("E05", "fancy-expr TS: depth guard on the way down", EXPR / "src" / "parse.ts",
        "if (s.nest >= MAX_EXPRESSION_DEPTH) throw tooDeep(s);", "if (false) throw tooDeep(s);", suites=(VITEST_EXPR,)),
    sab("E06", "fancy-expr TS: depth check on finished nodes", EXPR / "src" / "parse.ts",
        "if (depth > MAX_EXPRESSION_DEPTH) throw tooDeep(s);", "if (false) throw tooDeep(s);", suites=(VITEST_EXPR,)),
    sab("E07", "fancy-expr Python: length bound", EXPR / "python" / "src" / "fancy_expr" / "_parse.py",
        "if n > limit or (n * 4 > limit and _utf8_length(expression) > limit):", "if False:",
        suites=(PYTEST_EXPR, PYTEST_TEMPLATE)),
    sab("E08", "fancy-expr Python: depth guard on the way down", EXPR / "python" / "src" / "fancy_expr" / "_parse.py",
        "if s.nest >= MAX_EXPRESSION_DEPTH:", "if False:", suites=(PYTEST_EXPR, PYTEST_TEMPLATE)),
    sab("E09", "fancy-expr Python: depth check on finished nodes", EXPR / "python" / "src" / "fancy_expr" / "_parse.py",
        "if depth > MAX_EXPRESSION_DEPTH:", "if False:", suites=(PYTEST_EXPR, PYTEST_TEMPLATE)),
]

ANSI = re.compile(r"\x1b\[[0-9;]*m")
SUMMARY = re.compile(r"^\s*(Tests:.*|Tests\s{2,}.*|\d+ (passed|failed).*)$")


def run(suite: tuple) -> tuple[int, str]:
    _, cwd, cmd = suite
    log = HERE / "scripts" / ".sabotage.log"
    with open(log, "wb") as out:
        code = subprocess.run(cmd, cwd=cwd, stdout=out, stderr=subprocess.STDOUT).returncode
    text = ANSI.sub("", log.read_text(encoding="utf-8", errors="replace"))
    log.unlink()
    lines = [line.strip() for line in text.splitlines() if SUMMARY.match(line)]
    return code, lines[-1] if lines else "no summary line"


def apply(s: Sabotage) -> bytes:
    original = s.file.read_bytes()
    text = original.decode("utf-8")
    for find, replace, count, nth in s.edits:
        found = text.count(find)
        if found != count:
            raise SystemExit(f"{s.id}: expected {count} occurrence(s) of the guarded text in {s.file.name}, found {found}")
        if nth is None:
            text = text.replace(find, replace)
        else:
            parts = text.split(find)
            text = find.join(parts[:nth]) + replace + find.join(parts[nth:])
    s.file.write_bytes(text.encode("utf-8"))
    return original


def main() -> int:
    only = sys.argv[1] if len(sys.argv) > 1 else None
    chosen = [s for s in S if only is None or s.leg == only or (only == "expr" and s.id.startswith("E"))]
    legs = sorted({s.leg for s in chosen} | ({"php", "ts", "py"} if only in (None, "expr") else set()))
    controls = sorted({suite[0]: suite for leg in legs if leg in LEG_SUITES for suite in LEG_SUITES[leg]}.values(), key=lambda x: x[0])

    print("CONTROL (nothing sabotaged)")
    for suite in controls:
        code, summary = run(suite)
        print(f"  {suite[0]}: exit={code} {summary}")
        if code != 0:
            print("  the control is not green; a sabotage result would mean nothing")
            return 2

    caught = 0
    for s in chosen:
        suites = s.suites or (LEG_SUITES[s.leg][0],)
        original = apply(s)
        try:
            results = [(suite, *run(suite)) for suite in suites]
        finally:
            s.file.write_bytes(original)
        assert s.file.read_bytes() == original
        red = any(code != 0 for _, code, _ in results)
        caught += red
        detail = " | ".join(f"{suite[0]}: exit={code} {summary}" for suite, code, summary in results)
        print(f"{s.id} {'RED  ' if red else 'GREEN'} {s.what}\n      {detail}")

    print(f"\n{caught} / {len(chosen)} sabotages turned a suite red")
    return 0 if caught == len(chosen) else 1


if __name__ == "__main__":
    sys.exit(main())
