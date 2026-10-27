"""Small JSON-shaped helpers shared by the inspector, validator and renderer.

Everything that walks a value of unknown depth here is ITERATIVE. Python's
recursion limit (1000 by default) sits well below the 4096 levels the other
legs accept as plain JSON, so a recursive walk would refuse, or crash on, data
the PHP and TypeScript legs handle.
"""

from __future__ import annotations

import base64
import binascii
import math
from typing import Any, TypeGuard

#: The deepest container a value may hold and still be plain JSON. PHP's
#: ``json_encode`` refuses anything deeper (its depth argument, 4096), and the
#: three legs must agree on what counts as plain, so this leg refuses it too.
PLAIN_MAX_DEPTH = 4096

_INT64_MIN = -(2**63)
_INT64_MAX = 2**63 - 1


def pointer(base: str, key: str | int) -> str:
    return base + "/" + str(key).replace("~", "~0").replace("/", "~1")


def is_object(v: Any) -> bool:
    return isinstance(v, dict)


def is_list(v: Any) -> bool:
    return isinstance(v, list)


def is_number(v: Any) -> TypeGuard[int | float]:
    """A JSON number. ``bool`` is an ``int`` in Python and is NOT one here."""
    return isinstance(v, (int, float)) and not isinstance(v, bool)


def utf8_length(s: str) -> int:
    return len(s.encode("utf-8"))


def code_points(s: str) -> int:
    """Unicode code points, JSON Schema's unit for maxLength. A ``str`` IS code points."""
    return len(s)


class _NotPlainError(Exception):
    pass


def _key(k: Any) -> str:
    if isinstance(k, str):
        _check_text(k)
        return str.__str__(k)
    if isinstance(k, int) and not isinstance(k, bool):
        return str(int.__int__(k))
    raise _NotPlainError


def _check_text(s: str) -> None:
    # A lone surrogate is not Unicode text, and no other leg can represent it.
    try:
        str.encode(s, "utf-8")
    except UnicodeEncodeError as exc:
        raise _NotPlainError from exc


def _scalar(v: Any) -> Any:
    if v is None or isinstance(v, bool):
        return v
    if isinstance(v, int):
        v = int.__int__(v)
        if _INT64_MIN <= v <= _INT64_MAX:
            return v
        # Past int64 PHP decodes a JSON integer as a float; so does this leg.
        try:
            f = float(v)
        except OverflowError as exc:
            raise _NotPlainError from exc
        if not math.isfinite(f):
            raise _NotPlainError
        return f
    if isinstance(v, float):
        if not math.isfinite(v):
            raise _NotPlainError
        return float.__float__(v)
    if isinstance(v, str):
        _check_text(v)
        return str.__str__(v)
    raise _NotPlainError


def _is_container(v: Any) -> bool:
    return isinstance(v, (dict, list, tuple))


def plain(value: Any) -> tuple[bool, Any]:
    """Reduce a host value to plain JSON data (SPEC.md section 4).

    ``(True, copy)`` or ``(False, None)``. Accepts exactly what ``json.dumps``
    accepts without a ``default`` hook -- dict (str or int keys), list, tuple,
    str, int, float, bool, None -- refuses non-finite numbers like
    ``allow_nan=False``, refuses a lone surrogate and a cycle, and never calls a
    method on a host object: a subclass is read through its base type.
    """
    try:
        return True, _convert(value)
    except _NotPlainError:
        return False, None


def _convert(value: Any) -> Any:
    if not _is_container(value):
        return _scalar(value)

    root: Any = {} if isinstance(value, dict) else []
    # (source, destination, level) frames, with exit markers so the set of
    # containers on the CURRENT path is known: a shared reference is fine
    # (json.dumps allows it), a cycle is not.
    stack: list[tuple[Any, Any, int] | int] = [(value, root, 1)]
    on_path: set[int] = set()
    while stack:
        top = stack.pop()
        if isinstance(top, int):
            on_path.discard(top)
            continue
        src, dst, level = top
        if level > PLAIN_MAX_DEPTH or id(src) in on_path:
            raise _NotPlainError
        on_path.add(id(src))
        stack.append(id(src))
        items = src.items() if isinstance(src, dict) else enumerate(src)
        children: list[tuple[Any, Any, int]] = []
        for k, v in items:
            if _is_container(v):
                child: Any = {} if isinstance(v, dict) else []
                children.append((v, child, level + 1))
            else:
                child = _scalar(v)
            if isinstance(dst, dict):
                dst[_key(k)] = child
            else:
                dst.append(child)
        stack.extend(reversed(children))
    return root


def first_too_deep(value: Any, limit: int) -> str | None:
    """Pointer of the first container, in document order, above ``limit`` levels (the root is level 1)."""
    stack: list[tuple[Any, str, int]] = [(value, "", 1)]
    while stack:
        v, path, level = stack.pop()
        if not isinstance(v, (dict, list)):
            continue
        if level > limit:
            return path
        entries = list(v.items()) if isinstance(v, dict) else list(enumerate(v))
        for k, child in reversed(entries):
            stack.append((child, pointer(path, k), level + 1))
    return None


def string_bytes(value: Any) -> int:
    """UTF-8 bytes of every string, object keys included."""
    total = 0
    stack = [value]
    while stack:
        v = stack.pop()
        if isinstance(v, str):
            total += utf8_length(v)
        elif isinstance(v, list):
            stack.extend(v)
        elif isinstance(v, dict):
            for k, child in v.items():
                total += utf8_length(k)
                stack.append(child)
    return total


_B64 = frozenset(b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=")
_MAGIC = (("image/png", b"\x89PNG\r\n\x1a\n"), ("image/jpeg", b"\xff\xd8\xff"))


def image_type(value: Any) -> str | None:
    """The MIME type of a valid PNG or JPEG data URL (base64 strict, magic bytes checked), else None."""
    if not isinstance(value, str):
        return None
    for mime, magic in _MAGIC:
        prefix = f"data:{mime};base64,"
        if not value.startswith(prefix):
            continue
        b64 = value[len(prefix) :]
        if not b64.isascii():
            return None
        raw_b64 = b64.encode("ascii")
        if raw_b64 == b"" or len(raw_b64) % 4 != 0 or not set(raw_b64) <= _B64:
            return None
        try:
            raw = base64.b64decode(raw_b64, validate=True)
        except (binascii.Error, ValueError):
            return None
        return mime if raw.startswith(magic) else None
    return None
