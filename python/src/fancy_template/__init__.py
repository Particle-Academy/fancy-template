"""fancy-template -- document templates for the Fancy writers.

One specification (``SPEC.md`` in the repository root, normative), three
implementations: this one, ``@particle-academy/fancy-template`` (TypeScript) and
``particle-academy/fancy-template`` (PHP). All three assert the same
``template/*`` rows from ``fancy-conformance``.

    >>> from fancy_template import inspect, render
    >>> template = {"fancyTemplate": 1, "writer": "fine-print",
    ...     "variables": {"name": {"type": "string", "required": True}},
    ...     "document": {"blocks": [{"type": "paragraph", "runs": [{"text": "Dear {{ name }}"}]}]}}
    >>> inspect(template)["ok"]
    True
    >>> render(template, {"name": "Ada"})["blocks"][0]["runs"][0]["text"]
    'Dear Ada'

The output is plain writer JSON, so the writers never depend on this package and
stay zero-dependency. Pass it through the writer's own validate and write.

Missing data is LOUD: ``render`` raises :class:`TemplateError` with a stable
``code``, a JSON Pointer ``path`` into the template and the ``variable`` path --
never a data value.
"""

from __future__ import annotations

from typing import Any

from ._declarations import apply_defaults, validate_members
from ._errors import TemplateError
from ._inspector import Inspector, declared_variables
from ._json import first_too_deep, plain
from ._limits import DEFAULT_LIMITS, Limits, limits_from
from ._profile import Profile
from ._renderer import Renderer
from ._scanner import tokenize as _scan

__all__ = [
    "DEFAULT_LIMITS",
    "TemplateError",
    "inspect",
    "preview",
    "render",
    "tokenize",
    "validate_data",
]

__version__ = "0.2.0"


def inspect(template: Any, options: dict[str, Any] | None = None) -> dict[str, Any]:
    """``{"ok": bool, "issues": [{code, path, variable?}]}`` -- at SAVE time, no data."""
    limits = limits_from(options)
    is_plain, value = plain(template)
    issues = Inspector.run(value, limits) if is_plain else [{"code": "TEMPLATE_SHAPE", "path": ""}]
    return {"ok": not issues, "issues": issues}


def validate_data(template: Any, data: Any, options: dict[str, Any] | None = None) -> list[dict[str, str]]:
    """Data errors in declaration order; empty when valid."""
    return _prepare(template, data, limits_from(options))[1]


def render(template: Any, data: Any, options: dict[str, Any] | None = None) -> dict[str, Any]:
    """Render a template with data: inspect, then validate the data, then render.

    Raises :class:`TemplateError` with the first failure (SPEC.md section 11).
    """
    limits = limits_from(options)
    is_plain, value = plain(template)
    if not is_plain or not isinstance(value, dict):
        raise TemplateError("TEMPLATE_SHAPE", "")
    issues = Inspector.run(value, limits)
    if issues:
        first = issues[0]
        raise TemplateError(first["code"], first["path"], first.get("variable"))

    defaulted, errors = _prepare(value, data, limits)
    if errors:
        raise TemplateError(errors[0]["code"], errors[0]["path"])

    profile = Profile.for_writer(value["writer"])
    assert profile is not None
    return Renderer.run(value["document"], defaulted, declared_variables(value), profile, limits)


def preview(template: Any, options: dict[str, Any] | None = None) -> dict[str, Any]:
    """Render with each variable's ``example``, falling back to its ``default``.

    A required variable with neither fails, rather than inventing a value the
    real data could not produce (template/resolve 0202).
    """
    data: dict[str, Any] = {}
    is_plain, value = plain(template)
    variables = value.get("variables") if is_plain and isinstance(value, dict) else None
    if isinstance(variables, dict):
        for name, decl in variables.items():
            if isinstance(decl, dict) and "example" in decl:
                data[name] = decl["example"]
    return render(template, data, options)


def tokenize(text: str, options: dict[str, Any] | None = None) -> list[dict[str, Any]]:
    """Split one string into text and token parts (SPEC.md section 5)."""
    return _scan(text, limits_from(options).token_length)


def _prepare(template: Any, data: Any, limits: Limits) -> tuple[dict[str, Any], list[dict[str, str]]]:
    plain_template, t = plain(template)
    plain_data, d = plain(data)
    if not plain_data:
        return {}, [{"path": "", "code": "DATA_TYPE"}]
    too_deep = first_too_deep(d, limits.depth)
    if too_deep is not None:
        return {}, [{"path": too_deep, "code": "DATA_TOO_DEEP"}]
    if not isinstance(d, dict):
        return {}, [{"path": "", "code": "DATA_TYPE"}]

    members = declared_variables(t) if plain_template else {}
    errors = validate_members(d, members, "")
    return apply_defaults(d, members), errors
