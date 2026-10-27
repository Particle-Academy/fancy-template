"""The fixed filter list (SPEC.md section 7).

Not extensible from a template, and not from a host either in v1: every filter
is a conformance-pinned behaviour, and a host-registered one would be a fourth
dialect.
"""

from __future__ import annotations

from typing import Any, Final

from ._dates import compile_pattern, format_date, parse_date
from ._errors import TemplateError
from ._json import is_number
from ._numbers import CURRENCIES, fixed, money, to_js_string
from ._scanner import is_digits


class _Absent:
    """The absent value. A private singleton rather than a magic string because
    data can contain any string -- a sentinel spelled in characters is one a
    hostile value can forge -- and nothing that went through ``plain()`` is ever
    an instance of this class."""

    __slots__ = ()

    def __repr__(self) -> str:
        return "ABSENT"


ABSENT: Final = _Absent()

FILTER_NAMES = ("default", "upper", "lower", "number", "money", "date", "join")


def check_filter(filt: dict[str, str]) -> str | None:
    """Static check of one filter. None when fine, else the issue code."""
    name = filt["name"]
    if name not in FILTER_NAMES:
        return "FILTER_UNKNOWN"
    arg = filt.get("arg")
    if name == "default":
        ok = arg is not None
    elif name in ("upper", "lower"):
        ok = arg is None
    elif name == "number":
        ok = arg is not None and is_digits(arg) and len(arg) <= 2 and int(arg) <= 10
    elif name == "money":
        ok = arg is not None and arg in CURRENCIES
    elif name == "date":
        ok = arg is not None and compile_pattern(arg) is not None
    else:  # join
        ok = True
    return None if ok else "FILTER_ARG"


def _input() -> TemplateError:
    return TemplateError("FILTER_INPUT", "")


def scalar_text(value: Any) -> str:
    """A scalar as text (SPEC.md section 6). Arrays and objects are not scalars."""
    if isinstance(value, str):
        return value
    if isinstance(value, bool):  # before int: bool IS an int in Python
        return "true" if value else "false"
    if is_number(value):
        return to_js_string(value)
    raise TemplateError("TEMPLATE_NOT_SCALAR", "")


def _ascii_case(s: str, upper: bool) -> str:
    # str.upper() is Unicode-aware ("ß".upper() is "SS"); the spec maps ASCII only.
    if upper:
        return "".join(chr(ord(c) - 32) if "a" <= c <= "z" else c for c in s)
    return "".join(chr(ord(c) + 32) if "A" <= c <= "Z" else c for c in s)


def apply_filter(filt: dict[str, str], value: Any) -> Any:
    """Apply one filter. ``value`` may be ABSENT. Raises TemplateError with an empty path."""
    name = filt["name"]
    arg = filt.get("arg")

    if name == "default":
        return arg if value is ABSENT or value is None else value
    if value is ABSENT:
        raise TemplateError("TEMPLATE_MISSING", "")

    if name == "upper":
        return _ascii_case(scalar_text(value), True)
    if name == "lower":
        return _ascii_case(scalar_text(value), False)
    if name == "number":
        if not is_number(value):
            raise _input()
        return fixed(value, int(arg or "0"))
    if name == "money":
        minor = value
        if isinstance(minor, float) and minor.is_integer() and abs(minor) < 2**53:
            minor = int(minor)
        if isinstance(minor, bool) or not isinstance(minor, int):
            raise _input()
        return money(minor, str(arg))
    if name == "date":
        date = parse_date(value)
        pieces = compile_pattern(str(arg))
        if date is None or pieces is None:
            raise _input()
        return format_date(date, pieces)
    # join
    if not isinstance(value, list):
        raise _input()
    parts = []
    for item in value:
        if item is None or isinstance(item, (dict, list)):
            raise _input()
        parts.append(scalar_text(item))
    return (", " if arg is None else arg).join(parts)
