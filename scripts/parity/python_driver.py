"""The Python leg's side of the cross-leg parity harness (php/tests/parity.php).

Writes one line per template/* fixture row and corpus case to --out:

    <id> TAB <canonical JSON of the result>

and, with --expected, the fixture's expected column in the same spelling (Python
decodes JSON faithfully: key order kept, {} distinct from []). Canonical JSON is
JSON.stringify's spelling, which the Node and PHP drivers write too.

    python -I scripts/parity/python_driver.py --out <file> [--expected <file>] [--render <dir>]

Run from inside the envelope: the loader and fancy-expr come from the sibling
checkouts, as pytest's `pythonpath` finds them.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
ENVELOPE_REPOS = ROOT.parent
# `-I` ignores PYTHONPATH, so CI names its own checkouts in FANCY_PARITY_PATH
# (os.pathsep-separated); in the envelope the sibling checkouts are found as-is.
_extra = [p for p in os.environ.get("FANCY_PARITY_PATH", "").split(os.pathsep) if p]
for extra in (
    *_extra,
    ENVELOPE_REPOS / "fancy-conformance" / "python" / "src",
    ENVELOPE_REPOS / "fancy-expr" / "python" / "src",
    ROOT / "python" / "src",
    ROOT / "python" / "tests",
):
    sys.path.insert(0, str(extra))

from fancy_conformance import cases  # noqa: E402
from fancy_template._numbers import to_js_string  # noqa: E402
from template_runner import run_template_case  # noqa: E402

SUITES = (
    "template/tokenize",
    "template/resolve",
    "template/filters",
    "template/directives",
    "template/limits",
    "template/profiles",
    "template/inspect",
)


def canon(v: Any) -> str:
    if v is None:
        return "null"
    if isinstance(v, bool):
        return "true" if v else "false"
    if isinstance(v, (int, float)):
        return to_js_string(v)
    if isinstance(v, str):
        return json.dumps(v, ensure_ascii=False)
    if isinstance(v, list):
        return "[" + ",".join(canon(x) for x in v) + "]"
    return (
        "{"
        + ",".join(
            json.dumps(k, ensure_ascii=False) + ":" + canon(x) for k, x in v.items()
        )
        + "}"
    )


def corpus_cases() -> list[dict[str, Any]]:
    corpus = json.loads((ROOT / "examples" / "corpus.json").read_text(encoding="utf-8"))
    out = []
    for c in corpus["cases"]:
        template = json.loads(
            (ROOT / "examples" / c["template"]).read_text(encoding="utf-8")
        )
        if c.get("preview"):
            out.append(
                {
                    "id": f"corpus/{c['id']}",
                    "fn": "preview",
                    "input": {"template": template},
                }
            )
        else:
            out.append(
                {
                    "id": f"corpus/{c['id']}",
                    "fn": "render",
                    "input": {"template": template, "data": c["data"]},
                }
            )
    return out


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True)
    parser.add_argument("--expected")
    parser.add_argument("--render")
    args = parser.parse_args()

    lines, expected = [], []
    for suite in SUITES:
        for case in cases(suite):
            try:
                result = canon(run_template_case(case))
            except Exception as exc:  # a crash is data about the implementation
                result = f"THREW {exc}".replace("\n", " ")
            lines.append(f"{suite}/{case['id']}\t{result}")
            expected.append(f"{suite}/{case['id']}\t{canon(case['expected'])}")
    for case in corpus_cases():
        result_obj = run_template_case(case)
        lines.append(f"{case['id']}\t{canon(result_obj)}")
        if args.render and result_obj.get("ok"):
            out_dir = Path(args.render)
            out_dir.mkdir(parents=True, exist_ok=True)
            name = case["id"].replace("corpus/", "").replace("/", "__")
            (out_dir / f"{name}.python.json").write_text(
                json.dumps(result_obj["document"], ensure_ascii=False), encoding="utf-8"
            )

    with open(args.out, "w", encoding="utf-8", newline="\n") as f:
        f.write("\n".join(lines) + "\n")
    if args.expected:
        with open(args.expected, "w", encoding="utf-8", newline="\n") as f:
            f.write("\n".join(expected) + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
