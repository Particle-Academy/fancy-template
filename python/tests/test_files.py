"""Office-file templates (SPEC.md section 12) are PHP-only so far. This leg must
refuse LOUDLY and by name -- never return the input, never return nothing."""

from __future__ import annotations

from pathlib import Path

import pytest

from fancy_template import FileTemplatesNotImplementedError, TemplateError, inspect_file, render_file

DOCX = (Path(__file__).resolve().parents[2] / "fixtures" / "files" / "proposal.docx").read_bytes()


def test_inspect_file_raises_the_named_error() -> None:
    with pytest.raises(FileTemplatesNotImplementedError) as info:
        inspect_file(DOCX)
    assert info.value.code == "FILE_NOT_IMPLEMENTED"
    assert info.value.operation == "inspect_file"
    assert isinstance(info.value, NotImplementedError)


def test_render_file_raises_the_named_error_not_a_template_refusal() -> None:
    with pytest.raises(FileTemplatesNotImplementedError) as info:
        render_file(DOCX, {"client": "Ada"})
    assert info.value.operation == "render_file"
    assert not isinstance(info.value, TemplateError)
    assert "SPEC.md section 12" in str(info.value)
