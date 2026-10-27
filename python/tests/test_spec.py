"""SPEC.md clauses no conformance row covers yet. The same assertions live in
tests/spec.test.ts and php/tests/SpecTest.php, so each clause is held in three
places rather than one."""

from __future__ import annotations

from typing import Any

from fancy_template import inspect, render, validate_data

VARS = {
    "xs": {
        "type": "array",
        "items": {"type": "object", "properties": {"ok": {"type": "boolean"}, "n": {"type": "string"}}},
    }
}


def fp(blocks: list[Any], variables: dict[str, Any] | None = None) -> dict[str, Any]:
    return {
        "fancyTemplate": 1,
        "writer": "fine-print",
        "variables": VARS if variables is None else variables,
        "document": {"blocks": blocks},
    }


def test_if_sees_the_each_binding_whatever_the_key_order() -> None:
    t = fp([{"$if": "item.ok", "$each": "xs", "type": "paragraph", "runs": [{"text": "{{ item.n }}"}]}])
    assert inspect(t)["issues"] == []
    doc = render(t, {"xs": [{"ok": True, "n": "a"}, {"ok": False, "n": "b"}, {"ok": True, "n": "c"}]})
    assert [b["runs"][0]["text"] for b in doc["blocks"]] == ["a", "c"]


def test_template_bytes_counts_every_key_numeric_ones_too() -> None:
    t = {"fancyTemplate": 1, "writer": "fine-print", "document": {"blocks": [{"type": "hr", "1234567890": "x"}]}}
    total = sum(
        len(s) for s in ("fancyTemplate", "writer", "fine-print", "document", "blocks", "type", "hr", "1234567890", "x")
    )
    assert inspect(t, {"limits": {"templateBytes": total}})["issues"] == []
    assert inspect(t, {"limits": {"templateBytes": total - 1}})["issues"] == [
        {"code": "TEMPLATE_TOO_LARGE", "path": ""}
    ]


def test_max_length_and_max_items_take_an_integral_value_written_as_3_0() -> None:
    t = fp(
        [],
        {
            "s": {"type": "string", "maxLength": 3.0},
            "a": {"type": "array", "items": {"type": "string"}, "maxItems": 1.0},
        },
    )
    assert inspect(t)["issues"] == []
    assert validate_data(t, {"s": "abcd", "a": ["x", "y"]}) == [
        {"path": "/s", "code": "DATA_MAX_LENGTH"},
        {"path": "/a", "code": "DATA_MAX_ITEMS"},
    ]


def test_the_version_is_the_integer_one_and_never_true() -> None:
    base = {"writer": "fine-print", "document": {}}
    assert inspect({"fancyTemplate": 1.0, **base})["issues"] == []
    assert inspect({"fancyTemplate": True, **base})["issues"] == [
        {"code": "TEMPLATE_VERSION", "path": "/fancyTemplate"}
    ]


def hs(rows: list[list[Any]], variables: dict[str, Any]) -> dict[str, Any]:
    return {
        "fancyTemplate": 1,
        "writer": "holy-sheet",
        "variables": variables,
        "document": {"sheets": [{"name": "S", "rows": rows}]},
    }


def cell(doc: dict[str, Any]) -> Any:
    return doc["sheets"][0]["rows"][0][0]


def test_a_declared_date_stays_bare_and_a_date_shaped_string_is_wrapped() -> None:
    assert cell(render(hs([["{{ d }}"]], {"d": {"type": "date"}}), {"d": "2026-10-27"})) == "2026-10-27"
    assert cell(render(hs([["{{ s }}"]], {"s": {"type": "string"}}), {"s": "2026-10-27"})) == {"value": "2026-10-27"}


def test_a_filter_that_turns_a_date_into_text_is_wrapped() -> None:
    doc = render(hs([['{{ d | date:"d MMMM yyyy" }}']], {"d": {"type": "date"}}), {"d": "2026-10-27"})
    assert cell(doc) == {"value": "27 October 2026"}


def test_a_whole_token_number_keeps_its_type_and_a_formatted_one_is_text() -> None:
    assert cell(render(hs([['{{ n | default:"0" }}']], {"n": {"type": "number"}}), {"n": 4.5})) == 4.5
    assert cell(render(hs([['{{ n | default:"=1+1" }}']], {"n": {"type": "number"}}), {})) == {"value": "=1+1"}
    assert cell(render(hs([["{{ n | number:2 }}"]], {"n": {"type": "number"}}), {"n": 4.5})) == {"value": "4.50"}


def test_a_boolean_cell_stays_a_boolean_not_an_int() -> None:
    value = cell(render(hs([["{{ b }}"]], {"b": {"type": "boolean"}}), {"b": True}))
    assert value is True


def test_a_literal_cell_is_the_authors() -> None:
    assert cell(render(hs([["=SUM(A2:A9)"]], {}), {})) == "=SUM(A2:A9)"


def test_index_in_a_cell_is_a_number() -> None:
    t = {
        "fancyTemplate": 1,
        "writer": "holy-sheet",
        "variables": {"xs": {"type": "array", "items": {"type": "string"}}},
        "document": {"sheets": [{"$each": "xs", "$as": "x", "name": "{{ x }}", "rows": [["{{ $index }}", "{{ x }}"]]}]},
    }
    assert render(t, {"xs": ["a", "=b"]})["sheets"] == [
        {"name": "a", "rows": [[0, {"value": "a"}]]},
        {"name": "=b", "rows": [[1, {"value": "=b"}]]},
    ]


def ds(element: dict[str, Any]) -> dict[str, Any]:
    return {
        "fancyTemplate": 1,
        "writer": "dark-slide",
        "variables": {"v": {"type": "string"}},
        "document": {"slides": [{"elements": [{"type": "text", "x": 0, "y": 0, "w": 1, "h": 1, **element}]}]},
    }


def content(doc: dict[str, Any]) -> str:
    return str(doc["slides"][0]["elements"][0]["content"])


def test_markup_escapes_only_the_value() -> None:
    doc = render(ds({"content": "**Dear** {{ v }}", "format": "markdown"}), {"v": "**x**"})
    assert content(doc) == "**Dear** \\*\\*x\\*\\*"


def test_a_literal_tokens_text_is_a_value_too() -> None:
    assert content(render(ds({"content": '{{ "{{" }}', "format": "markdown"}), {})) == "\\{\\{"


def test_non_ascii_punctuation_is_left_alone() -> None:
    assert content(render(ds({"content": "{{ v }}", "format": "markdown"}), {"v": "café — “q”"})) == "café — “q”"


def test_validate_data_in_declaration_order_depth_first() -> None:
    t = fp(
        [],
        {
            "a": {
                "type": "object",
                "properties": {"b": {"type": "integer"}, "c": {"type": "string", "required": True}},
            },
            "d": {"type": "boolean"},
        },
    )
    assert validate_data(t, {"a": {"b": 1.5}, "d": "no"}) == [
        {"path": "/a/b", "code": "DATA_TYPE"},
        {"path": "/a/c", "code": "DATA_REQUIRED"},
        {"path": "/d", "code": "DATA_TYPE"},
    ]
