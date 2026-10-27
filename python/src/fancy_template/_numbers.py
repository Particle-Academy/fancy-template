"""Numbers to text, the same in every runtime (SPEC.md sections 6 and 7).

Everything starts from the SHORTEST round-trip decimal digits -- ``repr()`` of a
float gives exactly those, the same digits JavaScript's ``Number.toString``
picks -- and then works on the digits as a decimal string. Never ``str()`` of a
float (``str(3.0)`` is ``"3.0"``, JavaScript writes ``"3"``), never ``round()``
(banker's rounding, and it rounds the binary double: ``round(2.675, 2)`` is
2.67), never ``format(x, ",")`` (locale-free but binary-rounded).
"""

from __future__ import annotations

from decimal import Decimal

#: Currency => (minor-unit exponent, prefix symbol or None for "CODE ").
CURRENCIES: dict[str, tuple[int, str | None]] = {
    "USD": (2, "$"),
    "EUR": (2, "€"),
    "GBP": (2, "£"),
    "JPY": (0, "¥"),
    "INR": (2, "₹"),
    "KRW": (0, "₩"),
    "CAD": (2, None),
    "AUD": (2, None),
    "NZD": (2, None),
    "CHF": (2, None),
    "CNY": (2, None),
    "BRL": (2, None),
    "MXN": (2, None),
    "SEK": (2, None),
    "NOK": (2, None),
    "DKK": (2, None),
    "PLN": (2, None),
    "SGD": (2, None),
    "HKD": (2, None),
    "ZAR": (2, None),
    "CLP": (0, None),
    "VND": (0, None),
    "ISK": (0, None),
    "KWD": (3, None),
    "BHD": (3, None),
    "JOD": (3, None),
    "OMR": (3, None),
    "TND": (3, None),
}


def shortest(value: int | float) -> tuple[bool, str, int]:
    """Shortest round-trip decimal of a finite number: (negative, digits, point).

    The value is 0.DIGITS x 10^point, with no leading or trailing zeros in
    DIGITS ("0" for zero). An int is exact (PHP's int path).
    """
    if value == 0:
        return False, "0", 1
    negative = value < 0
    if isinstance(value, int):
        text = str(abs(value))
        return negative, text.rstrip("0"), len(text)
    _sign, digit_tuple, exponent = Decimal(repr(abs(value))).as_tuple()
    raw = "".join(str(d) for d in digit_tuple)
    assert isinstance(exponent, int)
    point = len(raw) + exponent
    stripped = raw.lstrip("0")
    point -= len(raw) - len(stripped)
    digits = stripped.rstrip("0")
    return negative, digits or "0", point


def to_js_string(value: int | float) -> str:
    """JavaScript's Number.prototype.toString, for a finite number."""
    negative, digits, point = shortest(value)
    if digits == "0":
        return "0"
    k = len(digits)
    sign = "-" if negative else ""
    if k <= point <= 21:
        return sign + digits + "0" * (point - k)
    if 0 < point <= 21:
        return sign + digits[:point] + "." + digits[point:]
    if -6 < point <= 0:
        return sign + "0." + "0" * (-point) + digits
    e = point - 1
    exp = "e" + ("-" if e < 0 else "+") + str(abs(e))
    return sign + (digits if k == 1 else digits[0] + "." + digits[1:]) + exp


def fixed(value: int | float, decimals: int) -> str:
    """``number:decimals`` -- decimal rounding, half away from zero, comma groups."""
    negative, digits, point = shortest(value)
    if digits == "0":
        int_part, frac = "0", ""
    elif point <= 0:
        int_part, frac = "0", "0" * (-point) + digits
    elif point >= len(digits):
        int_part, frac = digits + "0" * (point - len(digits)), ""
    else:
        int_part, frac = digits[:point], digits[point:]

    frac = frac.ljust(decimals + 1, "0")
    round_up = frac[decimals] >= "5"
    kept = int_part + frac[:decimals]
    if round_up:
        kept = _increment(kept)

    whole = kept[: len(kept) - decimals].lstrip("0") or "0"
    frac_part = kept[len(kept) - decimals :]
    is_zero = (whole + frac_part).strip("0") == ""
    out = _group(whole) + ("." + frac_part if decimals > 0 else "")
    return ("-" if negative and not is_zero else "") + out


def money(minor: int, currency: str) -> str:
    """``money:CUR`` -- integer minor units, never through a float."""
    exponent, symbol = CURRENCIES[currency]
    negative = minor < 0
    digits = str(abs(minor)).rjust(exponent + 1, "0")
    whole = digits[: len(digits) - exponent]
    frac = "." + digits[len(digits) - exponent :] if exponent > 0 else ""
    prefix = symbol if symbol is not None else currency + " "
    return ("-" if negative else "") + prefix + _group(whole) + frac


def _increment(digits: str) -> str:
    d = list(digits)
    i = len(d) - 1
    while i >= 0:
        if d[i] == "9":
            d[i] = "0"
            i -= 1
            continue
        d[i] = str(int(d[i]) + 1)
        return "".join(d)
    return "1" + "".join(d)


def _group(whole: str) -> str:
    out = ""
    n = len(whole)
    for i, ch in enumerate(whole):
        if i > 0 and (n - i) % 3 == 0:
            out += ","
        out += ch
    return out
