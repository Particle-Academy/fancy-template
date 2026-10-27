"""Everything that can be known about a template without data (SPEC.md sections
2, 3, 5, 8, 9, 10 and 11). The answer an admin gets at SAVE time.

Issues come in document order so that three runtimes produce comparable lists:
envelope, declarations, then the document depth-first with an object's ``$``
keys before its other entries.
"""

from __future__ import annotations

from typing import Any

from fancy_expr import ExprSyntaxError, references

from ._declarations import inspect_decl
from ._errors import TemplateError
from ._filters import check_filter
from ._json import first_too_deep, image_type, pointer, string_bytes, utf8_length
from ._limits import Limits
from ._profile import Profile
from ._scanner import LOOP_HEADS, count_tokens, is_digits, is_name, path_only, tokenize

DeclFrame = dict[str, Any]  # {"name": str, "decl": Any}

_ENVELOPE_REQUIRED = (
    ("fancyTemplate", "TEMPLATE_VERSION"),
    ("writer", "TEMPLATE_WRITER"),
    ("document", "TEMPLATE_SHAPE"),
)


def declared_variables(template: Any) -> dict[str, Any]:
    """The declared variables, every entry in written order, as the other legs read them."""
    if not isinstance(template, dict):
        return {}
    variables = template.get("variables")
    return dict(variables) if isinstance(variables, dict) else {}


def resolve_decl(steps: list[str], frames: list[DeclFrame], variables: dict[str, Any]) -> tuple[bool, Any]:
    """Walk a path through the declarations (SPEC.md section 6).

    ``(found, decl)``; decl is None when the shape below is unknown (a broken
    declaration has already been reported, and is not reported twice).
    """
    head = steps[0]
    if head in LOOP_HEADS:
        if not frames or len(steps) > 1:
            return False, None
        return True, {"type": "integer" if head == "$index" else "boolean"}

    decl: Any = None
    found = False
    for frame in reversed(frames):
        if frame["name"] == head:
            found, decl = True, frame["decl"]
            break
    if not found:
        if head not in variables:
            return False, None
        decl = variables[head]

    for step in steps[1:]:
        if not isinstance(decl, dict) or decl.get("type") is None:
            return True, None
        properties = decl.get("properties")
        if decl["type"] == "object" and isinstance(properties, dict) and step in properties:
            decl = properties[step]
            continue
        if decl["type"] == "array" and is_digits(step):
            decl = decl.get("items")
            continue
        return False, None
    return True, decl


class Inspector:
    def __init__(self, limits: Limits) -> None:
        self.limits = limits
        self.issues: list[dict[str, str]] = []
        self.variables: dict[str, Any] = {}
        self.profile: Profile | None = None

    @classmethod
    def run(cls, template: Any, limits: Limits) -> list[dict[str, str]]:
        self = cls(limits)
        self._inspect(template)
        return self.issues

    def _inspect(self, template: Any) -> None:
        if not isinstance(template, dict):
            self._issue("TEMPLATE_SHAPE", "")
            return

        # The three bounds that STOP inspect come first, in this order (template/inspect 0020).
        too_deep = first_too_deep(template, self.limits.depth)
        if too_deep is not None:
            self._issue("TEMPLATE_TOO_DEEP", too_deep)
            return
        if string_bytes(template) > self.limits.template_bytes:
            self._issue("TEMPLATE_TOO_LARGE", "")
            return
        if self._count_tokens(template.get("document")) > self.limits.tokens:
            self._issue("TEMPLATE_TOO_MANY_TOKENS", "")
            return

        for key, value in template.items():
            at = pointer("", key)
            if key == "fancyTemplate":
                # `True == 1` in Python; the version is the integer 1 and nothing else.
                if not (type(value) is int and value == 1) and not (type(value) is float and value == 1.0):
                    self._issue("TEMPLATE_VERSION", at)
            elif key == "writer":
                self.profile = Profile.for_writer(value)
                if self.profile is None:
                    self._issue("TEMPLATE_WRITER", at)
            elif key == "description":
                if not isinstance(value, str):
                    self._issue("TEMPLATE_SHAPE", at)
            elif key in ("variables", "document"):
                if not isinstance(value, dict):
                    self._issue("TEMPLATE_SHAPE", at)
            else:
                self._issue("TEMPLATE_SHAPE", at)
        for key, code in _ENVELOPE_REQUIRED:
            if key not in template:
                self._issue(code, pointer("", key))

        variables = template.get("variables")
        if isinstance(variables, dict):
            for name, decl in variables.items():
                at = pointer("/variables", name)
                if not is_name(name):
                    self._issue("DECL_INVALID", at)
                    continue
                inspect_decl(decl, at, self.issues)
                self.variables[name] = decl

        document = template.get("document")
        if self.profile is not None and isinstance(document, dict):
            self._walk(document, "/document", [], [], 0, False)

    def _count_tokens(self, value: Any) -> int:
        if isinstance(value, str):
            return count_tokens(value, self.limits.token_length)
        if not isinstance(value, (dict, list)):
            return 0
        n = 0
        for v in value.values() if isinstance(value, dict) else value:
            n += self._count_tokens(v)
            if n > self.limits.tokens:
                break
        return n

    def _walk(
        self,
        value: Any,
        path: str,
        segments: list[str],
        frames: list[DeclFrame],
        directive_depth: int,
        is_element: bool,
    ) -> None:
        if isinstance(value, str):
            self._string(value, path, segments, frames)
            return
        if isinstance(value, list):
            for i, item in enumerate(value):
                self._walk(item, pointer(path, i), [*segments, str(i)], frames, directive_depth, True)
            return
        if not isinstance(value, dict):
            return

        directives = [k for k in value if k.startswith("$")]
        if directives:
            if not is_element:
                for key in directives:
                    self._issue("DIRECTIVE_MISPLACED", pointer(path, key))
            else:
                directive_depth += 1
                if directive_depth > self.limits.directive_depth:
                    self._issue("DIRECTIVE_TOO_DEEP", path)
                    return
                frames = self._directives(value, path, frames)

        for key, child in value.items():
            if key.startswith("$"):
                continue
            at = pointer(path, key)
            if "{{" in key:
                self._issue("TOKEN_IN_KEY", at)
            self._walk(child, at, [*segments, key], frames, directive_depth, False)

    def _each_binding(self, obj: dict[str, Any], frames: list[DeclFrame]) -> list[DeclFrame] | None:
        """The frames an element's ``$each`` introduces, or None.

        Worked out BEFORE the ordered pass so that ``$if`` sees the binding
        whichever key was written first: SPEC.md section 8 says ``$each`` runs
        first, and JSON key order is not something an author should get right.
        """
        each = obj.get("$each")
        if not isinstance(each, str) or utf8_length(each) > self.limits.token_length:
            return None
        steps = path_only(each)
        if steps is None:
            return None
        found, decl = resolve_decl(steps, frames, self.variables)
        if not found or (isinstance(decl, dict) and decl.get("type") != "array"):
            return None
        as_name = obj.get("$as")
        if as_name is None:
            as_name = "item"
        if not isinstance(as_name, str) or not is_name(as_name):
            return None
        return [*frames, {"name": as_name, "decl": decl.get("items") if isinstance(decl, dict) else None}]

    def _directives(self, obj: dict[str, Any], path: str, frames: list[DeclFrame]) -> list[DeclFrame]:
        has_each = "$each" in obj
        inner = self._each_binding(obj, frames) or frames

        for key, value in obj.items():
            if not key.startswith("$"):
                continue
            at = pointer(path, key)

            if key == "$each":
                if not isinstance(value, str):
                    self._issue("DIRECTIVE_INVALID", at)
                    continue
                if utf8_length(value) > self.limits.token_length:
                    self._issue("TOKEN_TOO_LONG", at)
                    continue
                steps = path_only(value)
                if steps is None:
                    self._issue("DIRECTIVE_INVALID", at)
                    continue
                found, decl = resolve_decl(steps, frames, self.variables)
                if not found:
                    self._issue("DIRECTIVE_UNDECLARED", at, ".".join(steps))
                    continue
                if isinstance(decl, dict) and decl.get("type") != "array":
                    self._issue("DIRECTIVE_NOT_ARRAY", at, ".".join(steps))
                continue

            if key == "$as":
                shadows = isinstance(value, str) and (
                    value in self.variables or any(f["name"] == value for f in frames)
                )
                if not has_each or not isinstance(value, str) or not is_name(value) or shadows:
                    self._issue("DIRECTIVE_INVALID", at)
                continue

            if key == "$if":
                if not isinstance(value, str):
                    self._issue("DIRECTIVE_INVALID", at)
                    continue
                if utf8_length(value) > self.limits.token_length:
                    self._issue("TOKEN_TOO_LONG", at)
                    continue
                try:
                    roots = references(value)
                except ExprSyntaxError:
                    self._issue("DIRECTIVE_EXPR", at)
                    continue
                # `$if` sees this element's own loop binding (SPEC.md section 8).
                scope = inner if has_each else frames
                for root in roots:
                    if not self._in_scope(root, scope):
                        self._issue("DIRECTIVE_UNDECLARED", at, root)
                        break
                continue

            self._issue("DIRECTIVE_UNKNOWN", at)

        return inner

    def _in_scope(self, root: str, frames: list[DeclFrame]) -> bool:
        if root in LOOP_HEADS:
            return bool(frames)
        return any(f["name"] == root for f in frames) or root in self.variables

    def _string(self, value: str, path: str, segments: list[str], frames: list[DeclFrame]) -> None:
        try:
            parts = tokenize(value, self.limits.token_length)
        except TemplateError as e:
            self._issue(e.code, path)
            return

        tokens = [p for p in parts if "text" not in p]
        assert self.profile is not None
        field = self.profile.field(segments)

        if not tokens:
            if field is not None and field["encode"] == "image" and image_type(value) not in field["mime"]:
                self._issue("TEMPLATE_IMAGE_INVALID", path)
            return
        if field is None:
            self._issue("TOKEN_FORBIDDEN_FIELD", path)
            return

        if field["encode"] == "image":
            token = tokens[0]
            is_whole = len(parts) == 1 and "path" in token and token["filters"] == []
            found, decl = resolve_decl(token["path"], frames, self.variables) if is_whole else (False, None)
            if not is_whole or not found or not isinstance(decl, dict) or decl.get("type") != "image":
                self._issue("TOKEN_IMAGE_FIELD", path)
            return

        for token in tokens:
            if "path" in token:
                found, _ = resolve_decl(token["path"], frames, self.variables)
                if not found:
                    self._issue("TOKEN_UNDECLARED", path, ".".join(token["path"]))
            for filt in token["filters"]:
                code = check_filter(filt)
                if code is not None:
                    self._issue(code, path)

    def _issue(self, code: str, path: str, variable: str | None = None) -> None:
        issue = {"code": code, "path": path}
        if variable is not None:
            issue["variable"] = variable
        self.issues.append(issue)
