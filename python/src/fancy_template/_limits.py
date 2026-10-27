"""The bounds from SPEC.md section 10. Every one has a stable error code and
every one is host-overridable through ``options["limits"]``."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

DEFAULT_LIMITS: dict[str, int] = {
    "templateBytes": 262144,
    "tokens": 10000,
    "tokenLength": 512,
    "directiveDepth": 8,
    "iterations": 10000,
    "outputNodes": 50000,
    "outputBytes": 8388608,
    "depth": 64,
}


@dataclass(frozen=True)
class Limits:
    template_bytes: int
    tokens: int
    token_length: int
    directive_depth: int
    iterations: int
    output_nodes: int
    output_bytes: int
    depth: int


def limits_from(options: dict[str, Any] | None) -> Limits:
    given = (options or {}).get("limits") or {}
    if not isinstance(given, dict):
        raise TypeError("options.limits must be an object")
    values = dict(DEFAULT_LIMITS)
    for name, value in given.items():
        if name not in DEFAULT_LIMITS:
            raise ValueError(f"Unknown limit `{name}`")
        # bool is an int in Python; a limit of `True` is not a number.
        if isinstance(value, bool) or not isinstance(value, int) or value < 0:
            raise ValueError(f"Limit `{name}` must be a non-negative integer")
        values[name] = value
    return Limits(
        template_bytes=values["templateBytes"],
        tokens=values["tokens"],
        token_length=values["tokenLength"],
        directive_depth=values["directiveDepth"],
        iterations=values["iterations"],
        output_nodes=values["outputNodes"],
        output_bytes=values["outputBytes"],
        depth=values["depth"],
    )
