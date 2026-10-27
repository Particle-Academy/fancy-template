"""A writer profile (SPEC.md section 9): which string fields may hold tokens and
how a value is encoded there.

The profiles are DATA in the repository's ``profiles/``, shared by every
implementation. An installed wheel carries a copy inside the package (built
from that same directory); a source checkout reads the repository's own files,
so there is no second copy to drift.

An allowlist. A field that matches no pattern refuses tokens, so a writer gaining
a new field (a link, a font, a URL) is closed to data by default.
"""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any, ClassVar

#: The encodings this implementation has built. A profile needing any other is not offered.
IMPLEMENTED_ENCODINGS = ("text", "band", "markup", "markdown", "cell", "image")

_WRITER = re.compile(r"[a-z][a-z-]*", re.ASCII)


def _profiles_dir() -> Path:
    here = Path(__file__).resolve().parent
    packaged = here / "profiles"
    if packaged.is_dir():
        return packaged
    # python/src/fancy_template -> the repository root.
    checkout = here.parents[2] / "profiles"
    if checkout.is_dir():
        return checkout
    raise RuntimeError(
        "fancy-template: no profiles/ directory, neither packaged nor in the checkout. "
        "This is an error and not an empty profile set: a template engine that silently "
        "knows no writers refuses everything and reads as broken data."
    )


class Profile:
    _cache: ClassVar[dict[str, Profile | None]] = {}

    def __init__(self, writer: str, fields: list[dict[str, Any]]) -> None:
        self.writer = writer
        self._fields = fields

    @classmethod
    def for_writer(cls, writer: Any) -> Profile | None:
        """The profile for a writer, or None when unknown or not implemented here."""
        if not isinstance(writer, str) or _WRITER.fullmatch(writer) is None:
            return None
        if writer in cls._cache:
            return cls._cache[writer]
        file = _profiles_dir() / f"{writer}.json"
        profile: Profile | None = None
        if file.is_file():
            data = json.loads(file.read_text(encoding="utf-8"))
            fields = [{**f, "pattern": f["path"].split(".")} for f in data["fields"]]
            if all(f["encode"] in IMPLEMENTED_ENCODINGS for f in fields):
                profile = cls(writer, fields)
        cls._cache[writer] = profile
        return profile

    def field(self, segments: list[str]) -> dict[str, Any] | None:
        """The field rule for a string at ``segments`` inside ``document``. First match wins."""
        for f in self._fields:
            if _matches(f["pattern"], 0, segments, 0):
                return f
        return None


def _matches(pattern: list[str], p: int, path: list[str], s: int) -> bool:
    """Glob match: ``*`` is one segment, ``**`` zero or more. Patterns come from this package."""
    if p == len(pattern):
        return s == len(path)
    if pattern[p] == "**":
        return any(_matches(pattern, p + 1, path, k) for k in range(s, len(path) + 1))
    if s == len(path):
        return False
    return (pattern[p] == "*" or pattern[p] == path[s]) and _matches(pattern, p + 1, path, s + 1)
