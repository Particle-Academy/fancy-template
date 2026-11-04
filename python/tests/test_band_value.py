"""User data must not become a live field: a value holding a band token is refused,
never rewritten. The PHP and TypeScript legs hold the same rows."""

from __future__ import annotations

from typing import Any

import pytest

from fancy_template import TemplateError, render

BANDS = ["header", "footer", "firstHeader", "firstFooter", "evenHeader", "evenFooter"]


def tpl(writer: str, document: dict[str, Any]) -> dict[str, Any]:
    return {"fancyTemplate": 1, "writer": writer, "variables": {"v": {"type": "string"}}, "document": document}


def code(template: dict[str, Any], value: str) -> str | None:
    try:
        render(template, {"v": value})
    except TemplateError as e:
        return e.code
    return None


@pytest.mark.parametrize(
    "value",
    [
        "{page}",
        "{pages}",
        "{section}",
        "{sectionpages}",
        "{author}",
        "{title}",
        "{filename}",
        "{date}",
        "{time}",
        "see {section} here",
        "{date:d MMMM yyyy}",
        "{time:HH:mm}",
    ],
)
@pytest.mark.parametrize("writer", ["fine-print", "last-word"])
def test_refuses_a_band_value_holding_any_field_token(writer: str, value: str) -> None:
    assert code(tpl(writer, {"blocks": [], "page": {"footer": {"left": "{{ v }}"}}}), value) == "VALUE_PAGE_FIELD"


@pytest.mark.parametrize("value", ["{Page}", "{pagex}", "x{ page }", "{date:}", "{date:a{b}", "no braces"])
def test_keeps_a_value_that_is_only_brace_shaped(value: str) -> None:
    out = render(tpl("fine-print", {"blocks": [], "page": {"footer": {"left": "{{ v }}"}}}), {"v": value})
    assert out["page"]["footer"]["left"] == value


@pytest.mark.parametrize("key", BANDS)
@pytest.mark.parametrize("slot", ["left", "center", "right"])
def test_every_fine_print_band_slot(key: str, slot: str) -> None:
    template = tpl("fine-print", {"blocks": [], "page": {key: {slot: "{{ v }}"}}})
    assert code(template, "Ada") is None
    assert code(template, "{section}") == "VALUE_PAGE_FIELD"


@pytest.mark.parametrize("key", BANDS)
def test_a_band_on_a_fine_print_break(key: str) -> None:
    template = tpl(
        "fine-print",
        {"blocks": [{"type": "pageBreak", "page": {"orientation": "landscape", key: {"left": "{{ v }}"}}}]},
    )
    assert code(template, "Ada") is None
    assert code(template, "{pages}") == "VALUE_PAGE_FIELD"


@pytest.mark.parametrize("writer", ["fine-print", "last-word"])
def test_a_blocks_band_and_a_table_cell_in_it(writer: str) -> None:
    para = {"type": "paragraph", "runs": [{"text": "{{ v }}"}]}
    cell = {"type": "table", "rows": [{"cells": [{"blocks": [para]}]}]}
    for block in (para, cell):
        assert (
            code(tpl(writer, {"blocks": [], "page": {"footer": {"blocks": [block]}}}), "see {page}")
            == "VALUE_PAGE_FIELD"
        )


def test_a_body_run_keeps_a_token_shaped_value_literal() -> None:
    assert code(tpl("fine-print", {"blocks": [{"type": "paragraph", "runs": [{"text": "{{ v }}"}]}]}), "{page}") is None


def test_page_geometry_on_a_break_stays_closed_to_tokens() -> None:
    assert (
        code(tpl("fine-print", {"blocks": [{"type": "pageBreak", "page": {"orientation": "{{ v }}"}}]}), "landscape")
        is not None
    )
