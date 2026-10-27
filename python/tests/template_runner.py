"""One case in, the shape its suite's expected column uses out.

Shared by test_conformance.py and the cross-leg parity driver
(scripts/parity_python.py) so the two measure the same thing -- the same contract
as php/tests/runner.php and tests/runner.ts.
"""

from __future__ import annotations

from typing import Any

from fancy_template import TemplateError, inspect, preview, render, tokenize, validate_data


def run_template_case(case: dict[str, Any]) -> dict[str, Any]:
    given = case["input"]
    options = given.get("options") or {}
    fn = case["fn"]

    if fn == "tokenize":
        try:
            return {"ok": True, "parts": tokenize(given["text"], options)}
        except TemplateError as e:
            return {"ok": False, "code": e.code}
    if fn in ("render", "preview"):
        try:
            document = (
                render(given["template"], given["data"], options)
                if fn == "render"
                else preview(given["template"], options)
            )
            return {"ok": True, "document": document}
        except TemplateError as e:
            return {"ok": False, "code": e.code, "path": e.path}
    if fn == "validateData":
        return {"errors": validate_data(given["template"], given["data"], options)}
    if fn == "inspect":
        return {
            "issues": [{"code": i["code"], "path": i["path"]} for i in inspect(given["template"], options)["issues"]]
        }
    raise ValueError(f"unknown fn {fn!r}")
