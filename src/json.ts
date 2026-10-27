/**
 * Small JSON-shaped helpers shared by the inspector, validator and renderer.
 *
 * Every read of a key goes through `own()` and every write through `setOwn()`.
 * Template and data keys arrive over the wire, and in JavaScript a key spelled
 * `__proto__` or `constructor` is not just a key: `out[k] = v` with
 * `k === "__proto__"` replaces the output object's prototype, and `obj[k]`
 * with `k === "constructor"` reads a FUNCTION from `Object.prototype`. Neither
 * may ever happen here.
 */

export type Json = null | boolean | number | string | Json[] | JsonObject;
export interface JsonObject {
  [key: string]: Json;
}

/** A JSON object. Arrays and null are not. */
export function isObject(v: unknown): v is JsonObject {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

export function isList(v: unknown): v is Json[] {
  return Array.isArray(v);
}

export function hasOwn(o: object, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(o, key);
}

/** An own property, or undefined. Never reaches the prototype chain. */
export function own(o: JsonObject, key: string): Json | undefined {
  return hasOwn(o, key) ? o[key] : undefined;
}

/** Define an own, enumerable property. `__proto__` stays a key. */
export function setOwn(o: Record<string, unknown>, key: string, value: unknown): void {
  Object.defineProperty(o, key, { value, enumerable: true, writable: true, configurable: true });
}

export function pointer(base: string, key: string | number): string {
  return `${base}/${String(key).replaceAll("~", "~0").replaceAll("/", "~1")}`;
}

/** Unicode code points, JSON Schema's unit for maxLength. */
export function codePoints(s: string): number {
  let n = 0;
  for (const _ of s) n++;
  return n;
}

const encoder = new TextEncoder();

export function utf8Length(s: string): number {
  return encoder.encode(s).length;
}

/**
 * The deepest container a value may hold and still be plain JSON. PHP's
 * `json_encode` refuses anything deeper (its depth argument, 4096), and the
 * three legs must agree on what counts as plain, so this leg refuses it too.
 */
export const PLAIN_MAX_DEPTH = 4096;

class NotPlain extends Error {}

/** No lone surrogate. `String.prototype.isWellFormed` is ES2024; this target is ES2022. */
function wellFormed(s: string): boolean {
  for (let i = 0; i < s.length; i++) {
    const c = s.charCodeAt(i);
    if (c >= 0xd800 && c <= 0xdbff) {
      const next = s.charCodeAt(i + 1);
      if (!(next >= 0xdc00 && next <= 0xdfff)) return false;
      i++;
    } else if (c >= 0xdc00 && c <= 0xdfff) {
      return false;
    }
  }
  return true;
}

/**
 * Reduce a host value to plain JSON data (SPEC.md section 4), or report that it
 * cannot be. Goes through the JSON encoder on purpose: that is the one
 * conversion every host already trusts (a `Date` becomes its ISO string, a
 * `toJSON` runs here and only here), and after it nothing in the value can
 * call back into host code.
 *
 * Refused, where `JSON.stringify` would quietly substitute: a non-finite
 * number (it writes `null`, the PHP and Python encoders refuse), a string that
 * is not well-formed Unicode (a lone surrogate, which no other leg can
 * represent), and nesting past PLAIN_MAX_DEPTH.
 */
export function plain(value: unknown): [true, Json] | [false, null] {
  let text: string | undefined;
  try {
    text = JSON.stringify(value, function (this: unknown, key: string, v: unknown) {
      if (!wellFormed(key)) throw new NotPlain();
      if (typeof v === "number" && !Number.isFinite(v)) throw new NotPlain();
      if (typeof v === "string" && !wellFormed(v)) throw new NotPlain();
      return v;
    });
  } catch {
    return [false, null];
  }
  if (text === undefined) return [false, null];
  const parsed = JSON.parse(text) as Json;
  if (depthOver(parsed, PLAIN_MAX_DEPTH)) return [false, null];
  return [true, parsed];
}

/** Whether any container sits above `limit` levels. Iterative: never the stack. */
function depthOver(value: Json, limit: number): boolean {
  const stack: [Json, number][] = [[value, 1]];
  while (stack.length > 0) {
    const [v, level] = stack.pop()!;
    if (typeof v !== "object" || v === null) continue;
    if (level > limit) return true;
    for (const child of Array.isArray(v) ? v : Object.values(v)) stack.push([child, level + 1]);
  }
  return false;
}

/** Pointer of the first container, in document order, above `limit` levels (the root is level 1). */
export function firstTooDeep(value: Json, limit: number, path = "", level = 1): string | null {
  if (typeof value !== "object" || value === null) return null;
  if (level > limit) return path;
  const entries: [string | number, Json][] = Array.isArray(value)
    ? value.map((v, i) => [i, v])
    : Object.entries(value);
  for (const [k, v] of entries) {
    const found = firstTooDeep(v, limit, pointer(path, k), level + 1);
    if (found !== null) return found;
  }
  return null;
}

/** UTF-8 bytes of every string, object keys included. */
export function stringBytes(value: Json): number {
  if (typeof value === "string") return utf8Length(value);
  if (typeof value !== "object" || value === null) return 0;
  let total = 0;
  if (Array.isArray(value)) {
    for (const v of value) total += stringBytes(v);
    return total;
  }
  for (const [k, v] of Object.entries(value)) total += utf8Length(k) + stringBytes(v);
  return total;
}

const B64 = /^[A-Za-z0-9+/]*={0,2}$/;
const MAGIC: [string, number[]][] = [
  ["image/png", [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]],
  ["image/jpeg", [0xff, 0xd8, 0xff]],
];

/**
 * The MIME type of a valid PNG or JPEG data URL, else null: base64 decoded
 * strictly (padding only at the end, length a multiple of 4) and the decoded
 * bytes starting with the format's magic number.
 */
export function imageType(value: unknown): string | null {
  if (typeof value !== "string") return null;
  for (const [mime, magic] of MAGIC) {
    const prefix = `data:${mime};base64,`;
    if (!value.startsWith(prefix)) continue;
    const b64 = value.slice(prefix.length);
    // The class is a bounded, anchored single pass over data (not template
    // text): no nested quantifier, so it cannot backtrack.
    if (b64 === "" || b64.length % 4 !== 0 || !B64.test(b64)) return null;
    let raw: string;
    try {
      raw = atob(b64);
    } catch {
      return null;
    }
    if (raw.length < magic.length) return null;
    for (let i = 0; i < magic.length; i++) {
      if (raw.charCodeAt(i) !== magic[i]) return null;
    }
    return mime;
  }
  return null;
}
