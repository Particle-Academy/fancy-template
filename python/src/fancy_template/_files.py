"""Office files as templates (SPEC.md section 12): ``inspect_file`` and
``render_file``. Implemented in the PHP leg only, so far.

They exist in this leg so that a caller gets a LOUD, named refusal rather than
a missing name or a silent no-op: a ``render_file`` that returned its input
unchanged would hand back a document full of ``{{ placeholders }}`` and look
like success. The behaviour to implement is SPEC.md section 12; the shared rows
and fixtures are in ``fixtures/files/``.
"""

from __future__ import annotations

from typing import Any, Literal, NoReturn


class FileTemplatesNotImplementedError(NotImplementedError):
    """Raised by ``inspect_file`` and ``render_file`` in this language.

    Office-file templates are implemented in PHP (``Template::inspectFile``),
    not yet here. ``code`` is always ``FILE_NOT_IMPLEMENTED``; ``operation``
    names the function.
    """

    code = "FILE_NOT_IMPLEMENTED"

    def __init__(self, operation: Literal["inspect_file", "render_file"]) -> None:
        super().__init__(
            f"{operation} is not yet implemented in the Python leg of fancy-template (SPEC.md section 12; PHP has it)."
        )
        self.operation = operation


def inspect_file(data: bytes, options: dict[str, Any] | None = None) -> NoReturn:
    """The placeholders in a .docx / .pptx / .xlsx. Not yet implemented here: always raises."""
    raise FileTemplatesNotImplementedError("inspect_file")


def render_file(data: bytes, values: Any, options: dict[str, Any] | None = None) -> NoReturn:
    """Fill a .docx / .pptx / .xlsx in place. Not yet implemented here: always raises."""
    raise FileTemplatesNotImplementedError("render_file")
