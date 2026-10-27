"""The template/* tables, run against the Python leg.

The rows were published in fancy-conformance 0.35.0 BEFORE this leg existed, and
it was written against them and SPEC.md -- not against the PHP or TypeScript
source. No row is skipped for any language since 0.36.0 dropped the 11 phase-6
PHP skips; every row runs here and must pass.
"""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

import pytest
from fancy_conformance import cases, equals, format_summary, run_table, version

from template_runner import run_template_case

# The fixture set the PYTHON job runs against. It checks fancy-conformance out
# from git (its loader is never on PyPI), so without a pin and a matching `ref`
# it would test whatever `main` is on the day. The Node and PHP legs pin the SAME
# version (package.json's range + the committed lockfile; composer.json's exact
# require-dev). Move all of them together, and only after re-running the tables in
# every runtime; the tests below fail otherwise.
PINNED_SUITE_VERSION = "0.36.0"

SUITES = (
    "template/tokenize",
    "template/resolve",
    "template/filters",
    "template/directives",
    "template/limits",
    "template/profiles",
    "template/inspect",
)

REPO = Path(__file__).resolve().parents[2]


def test_the_pinned_fixture_version_is_the_one_on_disk(capsys: pytest.CaptureFixture[str]) -> None:
    with capsys.disabled():
        print(f"\nfancy-conformance on disk: {version()}, pinned: {PINNED_SUITE_VERSION}")
    assert version() == PINNED_SUITE_VERSION


def test_every_manifest_pins_the_same_fixture_set() -> None:
    package = json.loads((REPO / "package.json").read_text(encoding="utf-8"))
    composer = json.loads((REPO / "composer.json").read_text(encoding="utf-8"))
    assert package["devDependencies"]["@particle-academy/fancy-conformance"] == f"^{PINNED_SUITE_VERSION}"
    assert composer["require-dev"]["particle-academy/fancy-conformance"] == PINNED_SUITE_VERSION


def test_fancy_expr_takes_the_sibling_range_everywhere() -> None:
    pyproject = (REPO / "python" / "pyproject.toml").read_text(encoding="utf-8")
    package = json.loads((REPO / "package.json").read_text(encoding="utf-8"))
    composer = json.loads((REPO / "composer.json").read_text(encoding="utf-8"))
    assert '"fancy-expr>=0.2.0,<2.0"' in pyproject
    assert package["dependencies"]["@particle-academy/fancy-expr"] == ">=0.2.0 <2.0"
    assert composer["require"]["particle-academy/fancy-expr"] == ">=0.2.0 <2.0"


def _checkout_refs(workflow: str, repository: str) -> list[str | None]:
    """The `ref:` of every workflow step that checks out ``repository``.

    Plain text on purpose: a YAML parser would be a dependency for one assertion.
    A step is a `- ` line plus everything indented deeper. `None` is a step with
    no `ref`, which checks out whatever `main` is at that moment.
    """
    lines = workflow.splitlines()
    refs: list[str | None] = []
    for index, line in enumerate(lines):
        start = re.match(r"(\s*)- ", line)
        if not start:
            continue
        step = [line]
        for following in lines[index + 1 :]:
            body = following.strip()
            indent = len(following) - len(following.lstrip())
            if body and not body.startswith("#") and indent <= len(start.group(1)):
                break
            step.append(following)
        text = "\n".join(step)
        if re.search(rf"^\s*(- )?repository:\s*[\"']?{re.escape(repository)}[\"']?\s*(#.*)?$", text, re.MULTILINE):
            ref = re.search(r"^\s*(- )?ref:\s*[\"']?([^\"'\s#]+)", text, re.MULTILINE)
            refs.append(ref.group(2) if ref else None)
    return refs


def test_the_checkout_ref_parser_sees_a_missing_ref() -> None:
    workflow = """
      - uses: actions/checkout@v7
        with:
          repository: Particle-Academy/fancy-conformance
          path: .fancy-conformance
      - name: Pinned
        uses: actions/checkout@v7
        with:
          repository: "Particle-Academy/fancy-conformance"
          ref: 'v1.2.3'  # a comment
    """
    assert _checkout_refs(workflow, "Particle-Academy/fancy-conformance") == [None, "v1.2.3"]


def test_ci_checks_out_the_fixture_tag_this_suite_pins() -> None:
    workflow = (REPO / ".github" / "workflows" / "ci.yml").read_text(encoding="utf-8")
    refs = _checkout_refs(workflow, "Particle-Academy/fancy-conformance")
    # Vacuity guard: a parser that matched nothing would satisfy the comparison.
    assert refs, "ci.yml does not check out Particle-Academy/fancy-conformance"
    assert refs == [f"v{PINNED_SUITE_VERSION}"] * len(refs)


def test_ci_checks_out_fancy_expr_at_a_tag_never_main() -> None:
    workflow = (REPO / ".github" / "workflows" / "ci.yml").read_text(encoding="utf-8")
    refs = _checkout_refs(workflow, "Particle-Academy/fancy-expr")
    assert refs, "ci.yml does not check out Particle-Academy/fancy-expr"
    assert all(ref is not None and re.fullmatch(r"v\d+\.\d+\.\d+", ref) for ref in refs), refs


@pytest.mark.parametrize("suite", SUITES)
def test_matches_the_table(suite: str, capsys: pytest.CaptureFixture[str]) -> None:
    summary = run_table(suite, run_template_case)
    with capsys.disabled():
        print("\n" + format_summary(summary))

    failures = [r["id"] for r in summary["results"] if r["status"] == "fail"]
    assert failures == [], f"Python disagrees with {suite} on: {', '.join(failures)}"
    assert summary["skipped"] == 0
    # The vacuity floor: a suite that loaded nothing reports zero failures too.
    assert summary["passed"] == len(cases(suite)) > 10


@pytest.mark.parametrize("suite", SUITES)
def test_a_runner_returning_nothing_fails_every_row(suite: str) -> None:
    # The control for the comparison itself.
    summary = run_table(suite, lambda _case: None)
    assert summary["passed"] == 0
    assert summary["failed"] == len(cases(suite))


def _all_rows() -> list[tuple[str, dict[str, Any]]]:
    return [(f"{suite}/{c['id']}", c) for suite in SUITES for c in cases(suite)]


@pytest.mark.parametrize(("row", "case"), _all_rows(), ids=[r for r, _ in _all_rows()])
def test_row(row: str, case: dict[str, Any]) -> None:
    actual = run_template_case(case)
    assert equals(actual, case["expected"]), f"{row}: {actual}"
