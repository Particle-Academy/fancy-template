import type { DataError, Issue } from "./errors";
import { parseDate } from "./dates";
import { codePoints, imageType, isList, isObject, own, pointer, setOwn, type Json, type JsonObject } from "./json";
import { isName } from "./scanner";

/**
 * Variable declarations: checking them (inspect) and checking data against
 * them (validateData). SPEC.md sections 3 and 4.
 */

export const TYPES = ["string", "number", "integer", "boolean", "date", "image", "array", "object"] as const;

const isNonNegativeInteger = (v: unknown): boolean => typeof v === "number" && Number.isInteger(v) && v >= 0;

/** Issues in one declaration, its own keys before its nested declarations. */
export function inspectDecl(decl: Json | undefined, path: string, issues: Issue[]): void {
  if (!isObject(decl) || Object.keys(decl).length === 0 || !Object.hasOwn(decl, "type")) {
    issues.push({ code: "DECL_INVALID", path });
    return;
  }
  const type = decl.type;
  if (typeof type !== "string" || !(TYPES as readonly string[]).includes(type)) {
    issues.push({ code: "DECL_INVALID", path: pointer(path, "type") });
    return;
  }

  const nested: [Json, string][] = [];
  for (const [key, value] of Object.entries(decl)) {
    const at = pointer(path, key);
    let bad: boolean;
    switch (key) {
      case "type":
        bad = false;
        break;
      case "required":
        bad = typeof value !== "boolean";
        break;
      case "description":
        bad = typeof value !== "string";
        break;
      case "enum":
        bad = !["string", "number", "integer"].includes(type) || !isList(value) || value.length === 0
          || !value.every((v) => typeOk(type, v));
        break;
      case "maxLength":
        bad = !["string", "date", "image"].includes(type) || !isNonNegativeInteger(value);
        break;
      case "maxItems":
        bad = type !== "array" || !isNonNegativeInteger(value);
        break;
      case "items":
        bad = type !== "array";
        break;
      case "properties":
        bad = type !== "object" || !isObject(value);
        break;
      case "default":
      case "example":
        bad = validate(value, decl, "").length > 0;
        break;
      default:
        bad = true;
    }
    if (bad) {
      issues.push({ code: "DECL_INVALID", path: at });
      continue;
    }
    if (key === "items") nested.push([value, at]);
    if (key === "properties") {
      for (const [name, sub] of Object.entries(value as JsonObject)) {
        if (!isName(name)) {
          issues.push({ code: "DECL_INVALID", path: pointer(at, name) });
          continue;
        }
        nested.push([sub, pointer(at, name)]);
      }
    }
  }

  if ((type === "array" && !Object.hasOwn(decl, "items")) || (type === "object" && !Object.hasOwn(decl, "properties"))) {
    issues.push({ code: "DECL_INVALID", path });
  }

  for (const [sub, at] of nested) inspectDecl(sub, at, issues);
}

/** Whether a declaration passed inspect, so that validation may lean on its shape. */
export function usable(decl: Json | undefined): decl is JsonObject {
  const issues: Issue[] = [];
  inspectDecl(decl, "", issues);
  return issues.length === 0;
}

/**
 * Validate one PRESENT value against a declaration. Returns errors (data
 * pointers relative to `path`); defaults are applied by applyDefaults().
 */
export function validate(value: Json, decl: JsonObject, path: string): DataError[] {
  const type = decl.type as string;
  if (!typeOk(type, value)) return [{ path, code: "DATA_TYPE" }];
  if (type === "date" && parseDate(value) === null) return [{ path, code: "DATA_FORMAT" }];
  if (type === "image" && imageType(value) === null) return [{ path, code: "DATA_FORMAT" }];

  const errors: DataError[] = [];
  const enumList = own(decl, "enum");
  if (enumList !== undefined && enumList !== null && !inEnum(value, enumList)) {
    errors.push({ path, code: "DATA_ENUM" });
  }
  const maxLength = own(decl, "maxLength");
  if (typeof maxLength === "number" && typeof value === "string" && codePoints(value) > maxLength) {
    errors.push({ path, code: "DATA_MAX_LENGTH" });
  }
  if (type === "array" && Array.isArray(value)) {
    const maxItems = own(decl, "maxItems");
    if (typeof maxItems === "number" && value.length > maxItems) {
      errors.push({ path, code: "DATA_MAX_ITEMS" });
    }
    const items = own(decl, "items");
    if (isObject(items)) {
      value.forEach((item, i) => errors.push(...validate(item, items, pointer(path, i))));
    }
  }
  if (type === "object" && isObject(value)) {
    const properties = own(decl, "properties");
    if (isObject(properties)) errors.push(...validateMembers(value, properties, path));
  }
  return errors;
}

/** Validate an object's declared members, in declaration order. */
export function validateMembers(object: JsonObject, members: JsonObject, path: string): DataError[] {
  const errors: DataError[] = [];
  for (const [name, decl] of Object.entries(members)) {
    if (!usable(decl)) continue;
    const at = pointer(path, name);
    if (!Object.hasOwn(object, name)) {
      if (!Object.hasOwn(decl, "default") && decl.required === true) {
        errors.push({ path: at, code: "DATA_REQUIRED" });
      }
      continue;
    }
    errors.push(...validate(object[name]!, decl, at));
  }
  return errors;
}

/** Fill absent members that declare a default, recursively (SPEC.md section 4). */
export function applyDefaults(object: JsonObject, members: JsonObject): JsonObject {
  for (const [name, decl] of Object.entries(members)) {
    if (!isObject(decl)) continue;
    if (!Object.hasOwn(object, name) && Object.hasOwn(decl, "default")) {
      // A copy: the default is the template's, and must not be edited in place below.
      setOwn(object, name, structuredClone(decl.default));
    }
    if (!Object.hasOwn(object, name)) continue;
    const current = object[name];
    const properties = own(decl, "properties");
    if (decl.type === "object" && isObject(properties) && isObject(current)) {
      setOwn(object, name, applyDefaults(current, properties));
    }
    const items = own(decl, "items");
    if (decl.type === "array" && isObject(items) && items.type === "object" && Array.isArray(current)) {
      const itemProperties = own(items, "properties");
      if (isObject(itemProperties)) {
        current.forEach((item, i) => {
          if (isObject(item)) current[i] = applyDefaults(item, itemProperties);
        });
      }
    }
  }
  return object;
}

export function typeOk(type: string, v: unknown): boolean {
  switch (type) {
    case "string":
    case "date":
    case "image":
      return typeof v === "string";
    case "number":
      return typeof v === "number" && Number.isFinite(v);
    case "integer":
      return typeof v === "number" && Number.isInteger(v);
    case "boolean":
      return typeof v === "boolean";
    case "array":
      return Array.isArray(v);
    case "object":
      return isObject(v);
    default:
      return false;
  }
}

function inEnum(value: Json, list: Json): boolean {
  if (!Array.isArray(list)) return false;
  for (const candidate of list) {
    if (typeof value === "string" && typeof candidate === "string" && value === candidate) return true;
    if (typeof value === "number" && typeof candidate === "number" && value === candidate) return true;
  }
  return false;
}
