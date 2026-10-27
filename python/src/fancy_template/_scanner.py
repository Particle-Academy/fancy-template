"""Splits one string into text and ``{{ }}`` tokens (SPEC.md section 5).

Hand-written and single-pass: every index only moves forward, and no regular
expression ever runs over template text (plan section 5). Template text is
authored by admins and agents and arrives over the wire, and a backtracking
regex over it is a denial of service waiting for the right string.

It scans UTF-8 BYTES, because the token-length bound is defined in bytes
(SPEC.md section 10) and the three legs must refuse the same token at the same
place. Every delimiter is ASCII, so a split never lands inside a character.

Character classes are ASCII by hand: ``str.isalpha`` and ``str.isdigit`` are
Unicode-aware, so ``"é".isalpha()`` and ``"٣".isdigit()`` are True and a name
like ``café`` would parse here and nowhere else.

Errors are raised as TemplateError with an empty path; the caller knows which
field it was scanning and re-raises with the pointer.
"""

from __future__ import annotations

from collections.abc import Callable
from typing import Any

from ._errors import TemplateError

LOOP_HEADS = ("$index", "$first", "$last")

_OPEN = ord("{")
_CLOSE = ord("}")
_QUOTE = ord('"')
_BACKSLASH = ord("\\")
_SPACE = ord(" ")
_TAB = ord("\t")


def _is_alpha(c: int) -> bool:
    return 0x41 <= c <= 0x5A or 0x61 <= c <= 0x7A


def _is_digit(c: int) -> bool:
    return 0x30 <= c <= 0x39


def _is_name_char(c: int) -> bool:
    return _is_alpha(c) or _is_digit(c) or c == 0x5F


def _is_lower(c: int) -> bool:
    return 0x61 <= c <= 0x7A


def _is_bare(c: int) -> bool:
    return _is_name_char(c) or c in (0x2E, 0x2B, 0x2D)


def _syntax() -> TemplateError:
    return TemplateError("TOKEN_SYNTAX", "")


class _Cursor:
    """A forward-only position in a byte string. Nothing here can move backwards."""

    __slots__ = ("b", "i", "n")

    def __init__(self, b: bytes) -> None:
        self.b = b
        self.i = 0
        self.n = len(b)

    def peek(self) -> int | None:
        return self.b[self.i] if self.i < self.n else None

    def advance(self) -> None:
        self.i += 1

    def done(self) -> bool:
        return self.i >= self.n

    def skip_ws(self) -> None:
        # Spaces and tabs only. A newline is never whitespace inside a token.
        while self.i < self.n and self.b[self.i] in (_SPACE, _TAB):
            self.i += 1

    def take_while(self, accept: Callable[[int], bool]) -> str:
        start = self.i
        while self.i < self.n and accept(self.b[self.i]):
            self.i += 1
        return self.b[start : self.i].decode("utf-8")


def tokenize(text: str, token_length: int) -> list[dict[str, Any]]:
    if "{{" not in text:
        return [] if text == "" else [{"text": text}]

    b = text.encode("utf-8")
    n = len(b)
    parts: list[dict[str, Any]] = []
    buffer = ""
    i = 0
    while i < n:
        open_at = b.find(b"{{", i)
        if open_at == -1:
            buffer += b[i:].decode("utf-8")
            break
        buffer += b[i:open_at].decode("utf-8")

        start = open_at + 2
        close = _find_close(b, start, n, token_length)
        token = _parse_token(b[start:close])

        if buffer != "":
            parts.append({"text": buffer})
            buffer = ""
        parts.append(token)
        i = close + 2

    if buffer != "":
        parts.append({"text": buffer})
    return parts


def count_tokens(text: str, token_length: int) -> int:
    """How many tokens a string opens, stopping quietly at the first error (reported elsewhere)."""
    if "{{" not in text:
        return 0
    b = text.encode("utf-8")
    count = 0
    i = 0
    while True:
        open_at = b.find(b"{{", i)
        if open_at == -1:
            break
        count += 1
        try:
            i = _find_close(b, open_at + 2, len(b), token_length) + 2
        except TemplateError:
            break
    return count


def _find_close(b: bytes, start: int, n: int, limit: int) -> int:
    """Index of the ``}}`` that closes a token whose inner text starts at ``start``."""
    j = start
    while True:
        if j - start > limit:
            raise TemplateError("TOKEN_TOO_LONG", "")
        if j >= n:
            raise TemplateError("TOKEN_UNCLOSED", "")
        c = b[j]
        if c == _QUOTE:
            # A string literal: ``}}`` inside it does not close the token. An escape skips one byte.
            j += 1
            while j < n and b[j] != _QUOTE:
                if j - start > limit:
                    raise TemplateError("TOKEN_TOO_LONG", "")
                j += 2 if b[j] == _BACKSLASH else 1
            if j >= n:
                if j - start > limit:
                    raise TemplateError("TOKEN_TOO_LONG", "")
                raise TemplateError("TOKEN_UNCLOSED", "")
            j += 1
            continue
        if c == _CLOSE and j + 1 < n and b[j + 1] == _CLOSE:
            return j
        j += 1


def _parse_token(inner: bytes) -> dict[str, Any]:
    if all(c in (_SPACE, _TAB) for c in inner):
        raise TemplateError("TOKEN_EMPTY", "")

    p = _Cursor(inner)
    p.skip_ws()
    token: dict[str, Any] = {"literal": _string(p)} if p.peek() == _QUOTE else {"path": _path(p)}

    filters: list[dict[str, str]] = []
    while True:
        p.skip_ws()
        if p.done():
            break
        if p.peek() != ord("|"):
            raise _syntax()
        p.advance()
        p.skip_ws()
        name = p.take_while(_is_lower)
        if name == "":
            raise _syntax()
        filt = {"name": name}
        p.skip_ws()
        if p.peek() == ord(":"):
            p.advance()
            p.skip_ws()
            if p.peek() == _QUOTE:
                filt["arg"] = _string(p)
            else:
                arg = p.take_while(_is_bare)
                if arg == "":
                    raise _syntax()
                filt["arg"] = arg
        filters.append(filt)

    token["filters"] = filters
    return token


def _path(p: _Cursor) -> list[str]:
    segments: list[str] = []
    if p.peek() == ord("$"):
        p.advance()
        head = "$" + p.take_while(_is_name_char)
        if head not in LOOP_HEADS:
            raise _syntax()
        segments.append(head)
    else:
        name = _name(p)
        if name is None:
            raise _syntax()
        segments.append(name)

    while p.peek() == ord("."):
        p.advance()
        digits = p.take_while(_is_digit)
        if digits != "":
            segments.append(digits)
            continue
        name = _name(p)
        if name is None:
            raise _syntax()
        segments.append(name)
    return segments


def path_only(text: str) -> list[str] | None:
    """Parse a whole string as a bare path, as ``$each`` takes one. None if it is not one."""
    p = _Cursor(text.encode("utf-8"))
    p.skip_ws()
    try:
        steps = _path(p)
    except TemplateError:
        return None
    p.skip_ws()
    return steps if p.done() else None


def is_name(s: str) -> bool:
    if s == "" or not s.isascii():
        return False
    b = s.encode("ascii")
    if not (_is_alpha(b[0]) or b[0] == 0x5F):
        return False
    return all(_is_name_char(c) for c in b[1:])


def is_digits(s: str) -> bool:
    return s != "" and s.isascii() and all(_is_digit(c) for c in s.encode("ascii"))


def _name(p: _Cursor) -> str | None:
    c = p.peek()
    if c is None or not (_is_alpha(c) or c == 0x5F):
        return None
    return p.take_while(_is_name_char)


def _string(p: _Cursor) -> str:
    p.advance()  # opening quote
    out = bytearray()
    while True:
        c = p.peek()
        if c is None:
            raise _syntax()
        p.advance()
        if c == _QUOTE:
            return out.decode("utf-8")
        if c == _BACKSLASH:
            nxt = p.peek()
            if nxt not in (_QUOTE, _BACKSLASH):
                raise _syntax()
            assert nxt is not None
            p.advance()
            out.append(nxt)
            continue
        out.append(c)
