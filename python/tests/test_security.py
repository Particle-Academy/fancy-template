"""The plan's section 5 properties no conformance row can express -- what an
error CONTAINS and what the engine TOUCHES -- plus the places Python's own
instincts would diverge from the other two legs."""

from __future__ import annotations

import json
import time
from typing import Any

import pytest

from fancy_template import TemplateError, inspect, render, tokenize, validate_data

SECRET = "S3CRET-recipient-9f2c"


def fp(blocks: list[Any], variables: dict[str, Any], extra: dict[str, Any] | None = None) -> dict[str, Any]:
    return {
        "fancyTemplate": 1,
        "writer": "fine-print",
        "variables": variables,
        "document": {**(extra or {}), "blocks": blocks},
    }


def para(text: str) -> dict[str, Any]:
    return {"type": "paragraph", "runs": [{"text": text}]}


@pytest.mark.parametrize(
    ("template", "data", "code"),
    [
        (fp([], {"v": {"type": "string", "enum": ["a"]}}), {"v": SECRET}, "DATA_ENUM"),
        (fp([], {"v": {"type": "string", "maxLength": 3}}), {"v": SECRET}, "DATA_MAX_LENGTH"),
        (fp([], {"v": {"type": "date"}}), {"v": SECRET}, "DATA_FORMAT"),
        (
            fp([], {"v": {"type": "string"}}, {"page": {"footer": {"left": "{{ v }}"}}}),
            {"v": SECRET + "{page}"},
            "VALUE_PAGE_FIELD",
        ),
        (fp([para('{{ v | date:"d" }}')], {"v": {"type": "string"}}), {"v": SECRET}, "FILTER_INPUT"),
        (
            fp([para("{{ v }}")], {"v": {"type": "array", "items": {"type": "string"}}}),
            {"v": [SECRET]},
            "TEMPLATE_NOT_SCALAR",
        ),
    ],
    ids=["enum", "max length", "date format", "page field", "filter input", "not scalar"],
)
def test_never_puts_a_data_value_in_an_error(template: dict[str, Any], data: dict[str, Any], code: str) -> None:
    with pytest.raises(TemplateError) as caught:
        render(template, data)
    assert caught.value.code == code
    assert SECRET not in str(caught.value)
    assert SECRET not in json.dumps(caught.value.to_dict())


def test_names_the_variable_by_path_never_by_value() -> None:
    t = fp([para("{{ person.middle }}")], {"person": {"type": "object", "properties": {"middle": {"type": "string"}}}})
    with pytest.raises(TemplateError) as caught:
        render(t, {"person": {"first": SECRET}})
    assert caught.value.to_dict() == {
        "code": "TEMPLATE_MISSING",
        "path": "/document/blocks/0/runs/0/text",
        "variable": "person.middle",
    }


class _Sneaky(str):
    """A host string whose methods must never run: plain() reads it through ``str`` itself."""

    def __str__(self) -> str:  # pragma: no cover - must never run
        raise AssertionError("a host method ran: __str__")

    def encode(self, *args: object, **kwargs: object) -> bytes:  # type: ignore[override]  # pragma: no cover
        raise AssertionError("a host method ran: encode")


def test_reads_host_subclasses_through_their_base_type_and_calls_nothing() -> None:
    doc = render(fp([para("{{ v }}")], {"v": {"type": "string"}}), {"v": _Sneaky("Ada")})
    assert doc["blocks"][0]["runs"][0]["text"] == "Ada"


@pytest.mark.parametrize(
    "bad",
    [float("inf"), float("nan"), object(), {1.5: "x"}, "\ud800"],
    ids=["inf", "nan", "object", "float-key", "lone-surrogate"],
)
def test_refuses_data_that_is_not_json_at_the_root(bad: Any) -> None:
    assert validate_data(fp([], {"v": {"type": "number"}}), {"v": bad}) == [{"path": "", "code": "DATA_TYPE"}]


def test_refuses_a_cycle_but_allows_a_shared_reference() -> None:
    cyclic: dict[str, Any] = {}
    cyclic["self"] = cyclic
    assert validate_data(fp([], {}), cyclic) == [{"path": "", "code": "DATA_TYPE"}]
    shared = ["x"]
    assert (
        validate_data(fp([], {"a": {"type": "array", "items": {"type": "string"}}}), {"a": shared, "b": shared}) == []
    )


def test_deep_data_is_too_deep_not_a_crash() -> None:
    # Far past Python's recursion limit, inside the 4096 levels every leg accepts as JSON.
    deep: Any = "x"
    for _ in range(3000):
        deep = [deep]
    assert validate_data(fp([], {}), {"v": deep}) == [{"path": "/v" + "/0" * 63, "code": "DATA_TOO_DEEP"}]


def test_true_is_not_one_anywhere() -> None:
    t = fp([], {"n": {"type": "integer", "enum": [1]}})
    assert validate_data(t, {"n": True}) == [{"path": "/n", "code": "DATA_TYPE"}]
    t2 = fp([], {"n": {"type": "number", "enum": [1]}})
    assert validate_data(t2, {"n": 1.0}) == []


def test_unicode_digits_are_not_dates_or_names() -> None:
    assert validate_data(fp([], {"d": {"type": "date"}}), {"d": "٢٠٢٦-١٠-٢٧"}) == [  # noqa: RUF001 -- deliberate: Arabic-Indic digits
        {"path": "/d", "code": "DATA_FORMAT"}
    ]
    assert validate_data(fp([], {"d": {"type": "date"}}), {"d": "2026-10-27\n"}) == [
        {"path": "/d", "code": "DATA_FORMAT"}
    ]
    assert inspect(fp([], {"café": {"type": "string"}}))["issues"] == [
        {"code": "DECL_INVALID", "path": "/variables/café"}
    ]


@pytest.mark.parametrize("expression", ["phpinfo()", 'v.constructor("x")', 'system("id")', "__import__('os')"])
def test_conditions_cannot_call_anything(expression: str) -> None:
    issues = inspect(fp([{"$if": expression, "type": "hr"}], {"v": {"type": "string"}}))["issues"]
    assert issues == [{"code": "DIRECTIVE_EXPR", "path": "/document/blocks/0/$if"}]


def test_refuses_a_megabyte_after_an_open_brace_in_token_length_time() -> None:
    text = 'x{{"' + "a" * 1_000_000
    start = time.perf_counter()
    with pytest.raises(TemplateError) as caught:
        tokenize(text)
    elapsed = (time.perf_counter() - start) * 1000
    assert caught.value.code == "TOKEN_TOO_LONG"
    assert elapsed < 100


def test_scans_many_tokens_and_many_lone_braces_linearly() -> None:
    text = "{ } }} x" * 50_000 + "{{a}}" * 2_000
    start = time.perf_counter()
    parts = tokenize(text)
    elapsed = (time.perf_counter() - start) * 1000
    assert len(parts) == 2_001
    assert elapsed < 1000


def test_measures_the_token_limit_in_utf8_bytes() -> None:
    assert tokenize('{{ "' + "é" * 200 + '" }}') == [{"literal": "é" * 200, "filters": []}]
    with pytest.raises(TemplateError) as caught:
        tokenize('{{ "' + "é" * 300 + '" }}')
    assert caught.value.code == "TOKEN_TOO_LONG"
