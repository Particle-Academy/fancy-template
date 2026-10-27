"""Variable declarations: checking them (inspect) and checking data against
them (validate_data). SPEC.md sections 3 and 4."""

from __future__ import annotations

import copy
import math
from typing import Any

from ._dates import parse_date
from ._json import code_points, image_type, is_number, pointer
from ._scanner import is_name

TYPES = ("string", "number", "integer", "boolean", "date", "image", "array", "object")

Issue = dict[str, str]


def _is_count(v: Any) -> bool:
    """A non-negative integer as section 3 defines integer: an integral VALUE, so 3.0 counts."""
    return is_number(v) and math.isfinite(v) and float(v).is_integer() and v >= 0


def inspect_decl(decl: Any, path: str, issues: list[Issue]) -> None:
    """Issues in one declaration, its own keys before its nested declarations."""
    if not isinstance(decl, dict) or len(decl) == 0 or "type" not in decl:
        issues.append({"code": "DECL_INVALID", "path": path})
        return
    type_ = decl["type"]
    if not isinstance(type_, str) or type_ not in TYPES:
        issues.append({"code": "DECL_INVALID", "path": pointer(path, "type")})
        return

    nested: list[tuple[Any, str]] = []
    for key, value in decl.items():
        at = pointer(path, key)
        if key == "type":
            bad = False
        elif key == "required":
            bad = not isinstance(value, bool)
        elif key == "description":
            bad = not isinstance(value, str)
        elif key == "enum":
            bad = (
                type_ not in ("string", "number", "integer")
                or not isinstance(value, list)
                or len(value) == 0
                or not all(type_ok(type_, v) for v in value)
            )
        elif key == "maxLength":
            bad = type_ not in ("string", "date", "image") or not _is_count(value)
        elif key == "maxItems":
            bad = type_ != "array" or not _is_count(value)
        elif key == "items":
            bad = type_ != "array"
        elif key == "properties":
            bad = type_ != "object" or not isinstance(value, dict)
        elif key in ("default", "example"):
            bad = len(validate(value, decl, "")) > 0
        else:
            bad = True
        if bad:
            issues.append({"code": "DECL_INVALID", "path": at})
            continue
        if key == "items":
            nested.append((value, at))
        if key == "properties":
            for name, sub in value.items():
                if not is_name(name):
                    issues.append({"code": "DECL_INVALID", "path": pointer(at, name)})
                    continue
                nested.append((sub, pointer(at, name)))

    if (type_ == "array" and "items" not in decl) or (type_ == "object" and "properties" not in decl):
        issues.append({"code": "DECL_INVALID", "path": path})

    for sub, at in nested:
        inspect_decl(sub, at, issues)


def usable(decl: Any) -> bool:
    """Whether a declaration passed inspect, so that validation may lean on its shape."""
    issues: list[Issue] = []
    inspect_decl(decl, "", issues)
    return not issues


def validate(value: Any, decl: dict[str, Any], path: str) -> list[dict[str, str]]:
    """Validate one PRESENT value against a declaration."""
    type_ = decl["type"]
    if not type_ok(type_, value):
        return [{"path": path, "code": "DATA_TYPE"}]
    if type_ == "date" and parse_date(value) is None:
        return [{"path": path, "code": "DATA_FORMAT"}]
    if type_ == "image" and image_type(value) is None:
        return [{"path": path, "code": "DATA_FORMAT"}]

    errors: list[dict[str, str]] = []
    enum = decl.get("enum")
    if enum is not None and not _in_enum(value, enum):
        errors.append({"path": path, "code": "DATA_ENUM"})
    max_length = decl.get("maxLength")
    if is_number(max_length) and isinstance(value, str) and code_points(value) > max_length:
        errors.append({"path": path, "code": "DATA_MAX_LENGTH"})
    if type_ == "array" and isinstance(value, list):
        max_items = decl.get("maxItems")
        if is_number(max_items) and len(value) > max_items:
            errors.append({"path": path, "code": "DATA_MAX_ITEMS"})
        items = decl.get("items")
        if isinstance(items, dict):
            for i, item in enumerate(value):
                errors.extend(validate(item, items, pointer(path, i)))
    if type_ == "object" and isinstance(value, dict):
        properties = decl.get("properties")
        if isinstance(properties, dict):
            errors.extend(validate_members(value, properties, path))
    return errors


def validate_members(obj: dict[str, Any], members: dict[str, Any], path: str) -> list[dict[str, str]]:
    """Validate an object's declared members, in declaration order."""
    errors: list[dict[str, str]] = []
    for name, decl in members.items():
        if not usable(decl):
            continue
        at = pointer(path, name)
        if name not in obj:
            if "default" not in decl and decl.get("required") is True:
                errors.append({"path": at, "code": "DATA_REQUIRED"})
            continue
        errors.extend(validate(obj[name], decl, at))
    return errors


def apply_defaults(obj: dict[str, Any], members: dict[str, Any]) -> dict[str, Any]:
    """Fill absent members that declare a default, recursively (SPEC.md section 4)."""
    for name, decl in members.items():
        if not isinstance(decl, dict):
            continue
        if name not in obj and "default" in decl:
            # A copy: the default is the template's, and must not be edited in place below.
            obj[name] = copy.deepcopy(decl["default"])
        if name not in obj:
            continue
        current = obj[name]
        properties = decl.get("properties")
        if decl.get("type") == "object" and isinstance(properties, dict) and isinstance(current, dict):
            obj[name] = apply_defaults(current, properties)
        items = decl.get("items")
        if (
            decl.get("type") == "array"
            and isinstance(items, dict)
            and items.get("type") == "object"
            and isinstance(items.get("properties"), dict)
            and isinstance(current, list)
        ):
            for i, item in enumerate(current):
                if isinstance(item, dict):
                    current[i] = apply_defaults(item, items["properties"])
    return obj


def type_ok(type_: str, v: Any) -> bool:
    if type_ in ("string", "date", "image"):
        return isinstance(v, str)
    if type_ == "number":
        return is_number(v) and math.isfinite(v)
    if type_ == "integer":
        return is_number(v) and math.isfinite(v) and float(v).is_integer()
    if type_ == "boolean":
        return isinstance(v, bool)
    if type_ == "array":
        return isinstance(v, list)
    if type_ == "object":
        return isinstance(v, dict)
    return False


def _in_enum(value: Any, candidates: Any) -> bool:
    if not isinstance(candidates, list):
        return False
    for c in candidates:
        if isinstance(value, str) and isinstance(c, str) and value == c:
            return True
        # Compared by type AND value: `True == 1` is True in Python and must not be here.
        if is_number(value) and is_number(c) and value == c:
            return True
    return False
