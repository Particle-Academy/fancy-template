import { compilePattern, formatDate, parseDate } from "./dates";
import { TemplateError } from "./errors";
import type { Json } from "./json";
import { CURRENCIES, fixed, money, toJsString } from "./numbers";
import type { Filter } from "./scanner";

/**
 * The absent value. A unique symbol rather than a magic string because data can
 * contain any string -- a sentinel spelled in characters is one a hostile value
 * can forge -- and nothing that went through JSON is ever a symbol.
 */
export const ABSENT: unique symbol = Symbol("fancy-template.absent");
export type Value = Json | typeof ABSENT;

/**
 * The fixed filter list (SPEC.md section 7). Not extensible from a template,
 * and not from a host either in v1: every filter is a conformance-pinned
 * behaviour, and a host-registered one would be a fourth dialect.
 */
export const FILTER_NAMES = ["default", "upper", "lower", "number", "money", "date", "join"] as const;

/** Static check of one filter. Null when fine, else the issue code. */
export function checkFilter(filter: Filter): string | null {
  const { name, arg } = filter;
  if (!(FILTER_NAMES as readonly string[]).includes(name)) return "FILTER_UNKNOWN";
  let ok: boolean;
  switch (name) {
    case "default":
      ok = arg !== undefined;
      break;
    case "upper":
    case "lower":
      ok = arg === undefined;
      break;
    case "number":
      ok = arg !== undefined && /^[0-9]{1,2}$/.test(arg) && Number(arg) <= 10;
      break;
    case "money":
      ok = arg !== undefined && Object.hasOwn(CURRENCIES, arg);
      break;
    case "date":
      ok = arg !== undefined && compilePattern(arg) !== null;
      break;
    default: // join
      ok = true;
  }
  return ok ? null : "FILTER_ARG";
}

function input(): never {
  throw new TemplateError("FILTER_INPUT", "");
}

/** A scalar as text (SPEC.md section 6). Arrays and objects are not scalars. */
export function scalarText(value: Value): string {
  if (typeof value === "string") return value;
  if (typeof value === "boolean") return value ? "true" : "false";
  if (typeof value === "number") return toJsString(value);
  throw new TemplateError("TEMPLATE_NOT_SCALAR", "");
}

/** ASCII letters only; every other character unchanged (SPEC.md section 7). */
function asciiCase(s: string, upper: boolean): string {
  let out = "";
  for (let i = 0; i < s.length; i++) {
    const c = s.charCodeAt(i);
    if (upper && c >= 0x61 && c <= 0x7a) out += String.fromCharCode(c - 32);
    else if (!upper && c >= 0x41 && c <= 0x5a) out += String.fromCharCode(c + 32);
    else out += s[i];
  }
  return out;
}

/**
 * Apply one filter. `value` may be ABSENT. Throws TemplateError with an empty
 * path; the renderer adds the field's pointer.
 */
export function applyFilter(filter: Filter, value: Value): Value {
  const { name, arg } = filter;

  if (name === "default") {
    return value === ABSENT || value === null ? arg! : value;
  }
  if (value === ABSENT) throw new TemplateError("TEMPLATE_MISSING", "");

  switch (name) {
    case "upper":
      return asciiCase(scalarText(value), true);
    case "lower":
      return asciiCase(scalarText(value), false);
    case "number":
      return typeof value === "number" ? fixed(value, Number(arg)) : input();
    case "money":
      // Integer minor units, and only those a double holds exactly.
      return typeof value === "number" && Number.isSafeInteger(value) ? money(value, arg!) : input();
    case "date": {
      const date = parseDate(value);
      return date === null ? input() : formatDate(date, compilePattern(arg!)!);
    }
    default: {
      // join
      if (!Array.isArray(value)) input();
      const parts: string[] = [];
      for (const item of value) {
        if (typeof item === "object") input(); // array, object or null
        parts.push(scalarText(item));
      }
      return parts.join(arg ?? ", ");
    }
  }
}
