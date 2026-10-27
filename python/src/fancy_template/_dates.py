"""The ``date`` type and the ``date:"pattern"`` filter (SPEC.md sections 3 and 7).

English names only, no time-zone database, no locale, never ``datetime``
arithmetic: a certificate's date is the date the issuer wrote, and an offset is
carried but never applied.
"""

from __future__ import annotations

import re

_MONTHS = (
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
)
_DAYS = ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday")

#: Pattern letter => allowed run lengths.
_RUNS: dict[str, tuple[int, ...]] = {
    "y": (2, 4),
    "M": (1, 2, 3, 4),
    "d": (1, 2),
    "E": (3, 4),
    "H": (1, 2),
    "h": (1, 2),
    "m": (2,),
    "s": (2,),
    "a": (1,),
}

# Data, not template text, anchored with no nested quantifier: linear.
# `[0-9]` and re.ASCII, never a bare `\d`: in Python `\d` matches every Unicode
# digit, so a date spelled in Arabic-Indic digits would be one here and in no other leg. fullmatch,
# never `$`, which also matches before a trailing newline.
_ISO = re.compile(
    r"([0-9]{4})-([0-9]{2})-([0-9]{2})"
    r"(?:T([0-9]{2}):([0-9]{2})(?::([0-9]{2})(?:\.[0-9]+)?)?(Z|[+-]([0-9]{2}):([0-9]{2}))?)?",
    re.ASCII,
)

CivilDate = dict[str, int]
Piece = tuple[str, "str | int"]


def parse_date(value: object) -> CivilDate | None:
    """Parse an ISO date or date-time. None when it is not one, or not a real calendar date and time."""
    if not isinstance(value, str):
        return None
    m = _ISO.fullmatch(value)
    if m is None:
        return None
    y, mo, d = int(m.group(1)), int(m.group(2)), int(m.group(3))
    hour = int(m.group(4)) if m.group(4) is not None else 0
    minute = int(m.group(5)) if m.group(5) is not None else 0
    second = int(m.group(6)) if m.group(6) is not None else 0
    if mo < 1 or mo > 12 or d < 1 or d > _days_in(y, mo):
        return None
    if hour > 23 or minute > 59 or second > 59:
        return None
    if m.group(8) is not None and (int(m.group(8)) > 23 or int(m.group(9)) > 59):
        return None
    return {"y": y, "m": mo, "d": d, "H": hour, "i": minute, "s": second}


def _is_ascii_alpha(c: str) -> bool:
    return "A" <= c <= "Z" or "a" <= c <= "z"


def compile_pattern(pattern: str) -> list[Piece] | None:
    """Compile a pattern to pieces, or None if invalid. Forward-only; quotes are literal, '' is one quote."""
    out: list[Piece] = []
    n = len(pattern)
    i = 0
    while i < n:
        c = pattern[i]
        if c == "'":
            if i + 1 < n and pattern[i + 1] == "'":
                out.append(("lit", "'"))
                i += 2
                continue
            i += 1
            lit = ""
            while True:
                if i >= n:
                    return None
                if pattern[i] == "'":
                    if i + 1 < n and pattern[i + 1] == "'":
                        lit += "'"
                        i += 2
                        continue
                    i += 1
                    break
                lit += pattern[i]
                i += 1
            out.append(("lit", lit))
            continue
        if _is_ascii_alpha(c):
            run = 1
            while i + run < n and pattern[i + run] == c:
                run += 1
            if c not in _RUNS or run not in _RUNS[c]:
                return None
            out.append((c, run))
            i += run
            continue
        out.append(("lit", c))
        i += 1
    return out


def format_date(date: CivilDate, pieces: list[Piece]) -> str:
    out = ""
    for kind, value in pieces:
        if kind == "lit":
            out += str(value)
        elif kind == "y":
            out += f"{date['y']:04d}" if value == 4 else f"{date['y'] % 100:02d}"
        elif kind == "M":
            name = _MONTHS[date["m"] - 1]
            out += {4: name, 3: name[:3], 2: f"{date['m']:02d}"}.get(int(value), str(date["m"]))
        elif kind == "d":
            out += f"{date['d']:02d}" if value == 2 else str(date["d"])
        elif kind == "E":
            day = _DAYS[_weekday(date)]
            out += day if value == 4 else day[:3]
        elif kind == "H":
            out += f"{date['H']:02d}" if value == 2 else str(date["H"])
        elif kind == "h":
            h = _twelve(date["H"])
            out += f"{h:02d}" if value == 2 else str(h)
        elif kind == "m":
            out += f"{date['i']:02d}"
        elif kind == "s":
            out += f"{date['s']:02d}"
        elif kind == "a":
            out += "AM" if date["H"] < 12 else "PM"
    return out


def _twelve(hour: int) -> int:
    h = hour % 12
    return 12 if h == 0 else h


def _weekday(date: CivilDate) -> int:
    """0 = Monday, from days since the civil epoch (Howard Hinnant's algorithm)."""
    y = date["y"] - (1 if date["m"] <= 2 else 0)
    # Truncating division, as in C: Python's // floors, which differs for negatives.
    era = int((y if y >= 0 else y - 399) / 400)
    yoe = y - era * 400
    mp = (date["m"] + 9) % 12
    doy = (153 * mp + 2) // 5 + date["d"] - 1
    doe = yoe * 365 + yoe // 4 - yoe // 100 + doy
    days = era * 146097 + doe - 719468
    # 1970-01-01 was a Thursday (3). Python's % is already non-negative.
    return (days % 7 + 3) % 7


def _days_in(y: int, m: int) -> int:
    if m == 2:
        return 29 if y % 4 == 0 and (y % 100 != 0 or y % 400 == 0) else 28
    return 30 if m in (4, 6, 9, 11) else 31
