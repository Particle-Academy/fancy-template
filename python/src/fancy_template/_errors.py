from __future__ import annotations


class TemplateError(Exception):
    """Every refusal this package makes (SPEC.md section 6).

    A stable ``code``, a JSON Pointer ``path`` into the TEMPLATE, and optionally
    the ``variable`` PATH a token named. Never a data value, in the message or
    anywhere else (plan section 5): a certificate's recipient name is personal
    data, and an error is the thing that gets logged, shown to an admin and
    pasted into an issue.
    """

    def __init__(self, code: str, path: str, variable: str | None = None) -> None:
        where = "the root" if path == "" else path
        what = "" if variable is None else f" (variable `{variable}`)"
        super().__init__(f"{code} at {where}{what}")
        self.code = code
        self.path = path
        self.variable = variable

    def to_dict(self) -> dict[str, str]:
        out = {"code": self.code, "path": self.path}
        if self.variable is not None:
            out["variable"] = self.variable
        return out
