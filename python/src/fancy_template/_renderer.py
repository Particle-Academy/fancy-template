"""Template + validated, defaulted data -> writer document (SPEC.md sections 6-10).

Runs only on a template that passed inspect and data that passed validate_data,
so it does not re-check what those guarantee; it checks what only data can
decide (absent paths, non-scalars, filter inputs, {page} in a band value) and
the output bounds, counted AS the output is produced so that an oversized result
is refused before it is built rather than after.
"""

from __future__ import annotations

from collections.abc import Callable, Iterator
from typing import Any

from fancy_expr import evaluate, truthy

from ._dates import parse_date
from ._errors import TemplateError
from ._filters import ABSENT, apply_filter, scalar_text
from ._inspector import resolve_decl
from ._json import image_type, is_number, pointer, utf8_length
from ._limits import Limits
from ._profile import Profile
from ._scanner import LOOP_HEADS, is_digits, path_only, tokenize


def escape_markdown(s: str) -> str:
    """CommonMark: a backslash before every ASCII punctuation character (SPEC.md section 9)."""
    out = []
    for ch in s:
        o = ord(ch)
        punct = 33 <= o <= 47 or 58 <= o <= 64 or 91 <= o <= 96 or 123 <= o <= 126
        out.append("\\" + ch if punct else ch)
    return "".join(out)


_HTML = {"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"}


def escape_html(s: str) -> str:
    return "".join(_HTML.get(ch, ch) for ch in s)


def _identity(s: str) -> str:
    return s


class Renderer:
    def __init__(self, profile: Profile, data: dict[str, Any], variables: dict[str, Any], limits: Limits) -> None:
        self.profile = profile
        self.data = data
        self.variables = variables
        self.limits = limits
        self.iterations = 0
        self.nodes = 0
        self.bytes = 0

    @classmethod
    def run(
        cls, document: dict[str, Any], data: dict[str, Any], variables: dict[str, Any], profile: Profile, limits: Limits
    ) -> dict[str, Any]:
        self = cls(profile, data, variables, limits)
        self._count(None)
        return self._object(document, "/document", [], [])

    def _object(
        self, obj: dict[str, Any], path: str, segments: list[str], frames: list[dict[str, Any]]
    ) -> dict[str, Any]:
        out: dict[str, Any] = {}
        for key, value in obj.items():
            if key.startswith("$"):
                continue
            out[key] = self._value(value, pointer(path, key), [*segments, key], frames, obj)
        return out

    def _value(
        self, value: Any, path: str, segments: list[str], frames: list[dict[str, Any]], parent: dict[str, Any] | None
    ) -> Any:
        if isinstance(value, str):
            out = self._string(value, path, segments, frames, parent)
            if isinstance(out, dict):
                # A cell wrapper: one object and one string (SPEC.md section 10).
                self._count(None)
                self._count(out["value"])
            else:
                self._count(out)
            return out
        if not isinstance(value, (dict, list)):
            self._count(value)
            return value
        self._count(None)
        if isinstance(value, dict):
            return self._object(value, path, segments, frames)

        result: list[Any] = []
        for i, item in enumerate(value):
            at = pointer(path, i)
            item_segments = [*segments, str(i)]
            if not isinstance(item, dict) or not any(k.startswith("$") for k in item):
                result.append(self._value(item, at, item_segments, frames, None))
                continue
            for inner in self._expand(item, at, frames):
                self._count(None)
                result.append(self._object(item, at, item_segments, inner))
        return result

    def _expand(self, item: dict[str, Any], path: str, frames: list[dict[str, Any]]) -> Iterator[list[dict[str, Any]]]:
        """The frames each copy of a directive element renders with.

        A generator on purpose: iterations are counted as copies are rendered,
        interleaved, exactly as the other legs count them, so the same template
        fails with the same code.
        """
        if "$each" not in item:
            if self._condition(item["$if"], frames):
                yield frames
            return

        at = pointer(path, "$each")
        steps = path_only(item["$each"])
        assert steps is not None
        lst = self._resolve(steps, frames)
        if lst is ABSENT:
            raise TemplateError("TEMPLATE_MISSING", at, ".".join(steps))
        if not isinstance(lst, list):
            raise TemplateError("DIRECTIVE_NOT_ARRAY", at, ".".join(steps))

        as_name = item.get("$as")
        if as_name is None:
            as_name = "item"
        _, decl = resolve_decl(steps, frames, self.variables)
        items_decl = decl.get("items") if isinstance(decl, dict) else None
        last = len(lst) - 1
        for index, element in enumerate(lst):
            self.iterations += 1
            if self.iterations > self.limits.iterations:
                raise TemplateError("RENDER_TOO_MANY_ITERATIONS", at)
            inner = [
                *frames,
                {
                    "name": as_name,
                    "decl": items_decl,
                    "value": element,
                    "$index": index,
                    "$first": index == 0,
                    "$last": index == last,
                },
            ]
            if "$if" in item and not self._condition(item["$if"], inner):
                continue
            yield inner

    def _condition(self, expression: str, frames: list[dict[str, Any]]) -> bool:
        context = dict(self.data)
        for frame in frames:
            context[frame["name"]] = frame["value"]
        if frames:
            innermost = frames[-1]
            for head in LOOP_HEADS:
                context[head] = innermost[head]
        # Parsed and bounded at inspect; fancy-expr never reaches the host.
        return bool(truthy(evaluate(expression, context)))

    def _resolve(self, steps: list[str], frames: list[dict[str, Any]]) -> Any:
        head = steps[0]
        if head in LOOP_HEADS:
            return frames[-1][head] if frames else ABSENT

        value: Any = ABSENT
        for frame in reversed(frames):
            if frame["name"] == head:
                value = frame["value"]
                break
        if value is ABSENT:
            if head not in self.data:
                return ABSENT
            value = self.data[head]

        for step in steps[1:]:
            if isinstance(value, list):
                if not is_digits(step):
                    return ABSENT
                index = int(step)
                if index >= len(value):
                    return ABSENT
                value = value[index]
                continue
            if not isinstance(value, dict) or step not in value:
                return ABSENT
            value = value[step]
        return value

    def _string(
        self, text: str, path: str, segments: list[str], frames: list[dict[str, Any]], parent: dict[str, Any] | None
    ) -> Any:
        if "{{" not in text:
            return text

        field = self.profile.field(segments)
        assert field is not None
        parts = tokenize(text, self.limits.token_length)
        escape = self._escaper(field, parent)
        out = ""

        for part in parts:
            if "text" in part:
                out += part["text"]
                continue
            variable = ".".join(part["path"]) if "path" in part else None
            value = self._resolve(part["path"], frames) if "path" in part else part["literal"]

            try:
                for filt in part["filters"]:
                    value = apply_filter(filt, value)
                if value is ABSENT:
                    raise TemplateError("TEMPLATE_MISSING", "")
                if field["encode"] == "image":
                    if image_type(value) not in field["mime"]:
                        raise TemplateError("VALUE_IMAGE_TYPE", "")
                    return value
                if field["encode"] == "cell" and len(parts) == 1:
                    kept = self._typed_cell(value, part.get("path"), frames)
                    if kept is not ABSENT:
                        return kept
                piece = scalar_text(value)
            except TemplateError as e:
                raise TemplateError(e.code, path, variable) from None

            if field["encode"] == "band" and ("{page}" in piece or "{pages}" in piece):
                raise TemplateError("VALUE_PAGE_FIELD", path, variable)
            out += escape(piece)

        # Every other cell result is wrapped, so user data can never become a
        # formula: holy-sheet promotes a BARE string starting with "=" and takes
        # {value: s} as literal text (template/profiles 0201).
        return {"value": out} if field["encode"] == "cell" else out

    def _typed_cell(self, value: Any, steps: list[str] | None, frames: list[dict[str, Any]]) -> Any:
        """A whole token in a cell keeps a number, a boolean, or a valid date DECLARED ``date``.

        ABSENT when the result is to be wrapped. A valid date cannot begin with
        "=", so nothing kept bare here can become a formula.
        """
        if is_number(value) or isinstance(value, bool):
            return value
        if not isinstance(value, str) or steps is None or parse_date(value) is None:
            return ABSENT
        found, decl = resolve_decl(steps, frames, self.variables)
        return value if found and isinstance(decl, dict) and decl.get("type") == "date" else ABSENT

    @staticmethod
    def _escaper(field: dict[str, Any], parent: dict[str, Any] | None) -> Callable[[str], str]:
        if field["encode"] == "markdown":
            return escape_markdown
        if field["encode"] == "markup":
            fmt = parent.get("format") if parent is not None else None
            if fmt == "markdown":
                return escape_markdown
            if fmt == "html":
                return escape_html
        return _identity

    def _count(self, value: Any) -> None:
        """One output value; a string also adds its bytes (SPEC.md section 10)."""
        self.nodes += 1
        if self.nodes > self.limits.output_nodes:
            raise TemplateError("RENDER_TOO_MANY_NODES", "")
        if isinstance(value, str):
            self.bytes += utf8_length(value)
            if self.bytes > self.limits.output_bytes:
                raise TemplateError("RENDER_TOO_LARGE", "")
