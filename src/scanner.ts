import { TemplateError } from "./errors";

/**
 * Splits one string into text and `{{ }}` tokens (SPEC.md section 5).
 *
 * Hand-written and single-pass: every index only moves forward, and no regular
 * expression ever runs over template text (plan section 5). Template text is
 * authored by admins and agents and arrives over the wire, and a backtracking
 * regex over it is a denial of service waiting for the right string.
 *
 * It scans UTF-8 BYTES, not UTF-16 code units, because the token-length bound
 * is defined in bytes (SPEC.md section 10) and the three legs must refuse the
 * same token at the same place. Every delimiter is ASCII, so a split never lands
 * inside a multi-byte character.
 *
 * The search for a token's closing `}}` is BOUNDED by the token-length limit
 * (template/tokenize 0027): a `{{` followed by a megabyte of anything costs
 * `tokenLength` bytes of work to refuse, not a megabyte.
 *
 * Errors are thrown as TemplateError with an empty path; the caller knows which
 * field it was scanning and re-throws with the pointer.
 */

export const LOOP_HEADS = ["$index", "$first", "$last"] as const;

export interface Filter {
  name: string;
  arg?: string;
}
export type TokenPart = { path: string[]; filters: Filter[] } | { literal: string; filters: Filter[] };
export type TextPart = { text: string };
export type Part = TextPart | TokenPart;

const encoder = new TextEncoder();
const decoder = new TextDecoder();

const OPEN = 0x7b; // {
const CLOSE = 0x7d; // }
const QUOTE = 0x22; // "
const BACKSLASH = 0x5c; // \
const SPACE = 0x20;
const TAB = 0x09;

const isAlpha = (c: number): boolean => (c >= 0x41 && c <= 0x5a) || (c >= 0x61 && c <= 0x7a);
const isDigit = (c: number): boolean => c >= 0x30 && c <= 0x39;
const isNameChar = (c: number): boolean => isAlpha(c) || isDigit(c) || c === 0x5f;
const isLower = (c: number): boolean => c >= 0x61 && c <= 0x7a;
const isBare = (c: number): boolean => isNameChar(c) || c === 0x2e || c === 0x2b || c === 0x2d;

function syntax(): never {
  throw new TemplateError("TOKEN_SYNTAX", "");
}

/** A forward-only position in a byte string. Nothing here can move backwards. */
class Cursor {
  i = 0;
  constructor(
    private readonly b: Uint8Array,
    private readonly n: number = b.length,
  ) {}

  peek(): number | null {
    return this.i < this.n ? this.b[this.i]! : null;
  }

  advance(): void {
    this.i++;
  }

  done(): boolean {
    return this.i >= this.n;
  }

  /** Spaces and tabs only. A newline is never whitespace inside a token (template/tokenize 0020). */
  skipWs(): void {
    while (this.i < this.n && (this.b[this.i] === SPACE || this.b[this.i] === TAB)) this.i++;
  }

  takeWhile(accept: (c: number) => boolean): string {
    const start = this.i;
    while (this.i < this.n && accept(this.b[this.i]!)) this.i++;
    return decoder.decode(this.b.subarray(start, this.i));
  }
}

function indexOfOpen(b: Uint8Array, from: number): number {
  for (let i = from; i + 1 < b.length; i++) {
    if (b[i] === OPEN && b[i + 1] === OPEN) return i;
  }
  return -1;
}

export function tokenize(text: string, tokenLength: number): Part[] {
  if (!text.includes("{{")) return text === "" ? [] : [{ text }];

  const b = encoder.encode(text);
  const n = b.length;
  const parts: Part[] = [];
  let buffer = "";
  let i = 0;

  while (i < n) {
    const open = indexOfOpen(b, i);
    if (open === -1) {
      buffer += decoder.decode(b.subarray(i));
      break;
    }
    buffer += decoder.decode(b.subarray(i, open));

    const start = open + 2;
    const close = findClose(b, start, n, tokenLength);
    const token = parseToken(b.subarray(start, close));

    if (buffer !== "") {
      parts.push({ text: buffer });
      buffer = "";
    }
    parts.push(token);
    i = close + 2;
  }

  if (buffer !== "") parts.push({ text: buffer });
  return parts;
}

/** How many tokens a string opens, stopping quietly at the first error (that is reported elsewhere). */
export function countTokens(text: string, tokenLength: number): number {
  if (!text.includes("{{")) return 0;
  const b = encoder.encode(text);
  let count = 0;
  let i = 0;
  for (;;) {
    const open = indexOfOpen(b, i);
    if (open === -1) break;
    count++;
    try {
      i = findClose(b, open + 2, b.length, tokenLength) + 2;
    } catch {
      break;
    }
  }
  return count;
}

/** Index of the `}}` that closes a token whose inner text starts at `start`. */
function findClose(b: Uint8Array, start: number, n: number, limit: number): number {
  let j = start;
  for (;;) {
    if (j - start > limit) throw new TemplateError("TOKEN_TOO_LONG", "");
    if (j >= n) throw new TemplateError("TOKEN_UNCLOSED", "");
    const c = b[j];
    if (c === QUOTE) {
      // A string literal: `}}` inside it does not close the token
      // (template/tokenize 0013). An escape skips one byte.
      j++;
      while (j < n && b[j] !== QUOTE) {
        if (j - start > limit) throw new TemplateError("TOKEN_TOO_LONG", "");
        j += b[j] === BACKSLASH ? 2 : 1;
      }
      if (j >= n) {
        if (j - start > limit) throw new TemplateError("TOKEN_TOO_LONG", "");
        throw new TemplateError("TOKEN_UNCLOSED", "");
      }
      j++;
      continue;
    }
    if (c === CLOSE && j + 1 < n && b[j + 1] === CLOSE) return j;
    j++;
  }
}

function parseToken(inner: Uint8Array): TokenPart {
  let blank = true;
  for (const c of inner) {
    if (c !== SPACE && c !== TAB) {
      blank = false;
      break;
    }
  }
  if (blank) throw new TemplateError("TOKEN_EMPTY", "");

  const p = new Cursor(inner);
  p.skipWs();

  const head: { path: string[] } | { literal: string } =
    p.peek() === QUOTE ? { literal: stringLiteral(p) } : { path: path(p) };

  const filters: Filter[] = [];
  for (;;) {
    p.skipWs();
    if (p.done()) break;
    if (p.peek() !== 0x7c /* | */) syntax();
    p.advance();
    p.skipWs();
    const name = p.takeWhile(isLower);
    if (name === "") syntax();
    const filter: Filter = { name };
    p.skipWs();
    if (p.peek() === 0x3a /* : */) {
      p.advance();
      p.skipWs();
      if (p.peek() === QUOTE) {
        filter.arg = stringLiteral(p);
      } else {
        const arg = p.takeWhile(isBare);
        if (arg === "") syntax();
        filter.arg = arg;
      }
    }
    filters.push(filter);
  }

  return { ...head, filters };
}

/** A path for a token or for `$each` (SPEC.md section 5). */
function path(p: Cursor): string[] {
  const segments: string[] = [];
  if (p.peek() === 0x24 /* $ */) {
    p.advance();
    const head = "$" + p.takeWhile(isNameChar);
    if (!(LOOP_HEADS as readonly string[]).includes(head)) syntax();
    segments.push(head);
  } else {
    const head = name(p);
    if (head === null) syntax();
    segments.push(head);
  }

  while (p.peek() === 0x2e /* . */) {
    p.advance();
    const digits = p.takeWhile(isDigit);
    if (digits !== "") {
      segments.push(digits);
      continue;
    }
    const next = name(p);
    if (next === null) syntax();
    segments.push(next);
  }
  return segments;
}

/** Parse a whole string as a bare path, as `$each` takes one. Null if it is not one. */
export function pathOnly(text: string): string[] | null {
  const p = new Cursor(encoder.encode(text));
  p.skipWs();
  let steps: string[];
  try {
    steps = path(p);
  } catch (e) {
    if (e instanceof TemplateError) return null;
    throw e;
  }
  p.skipWs();
  return p.done() ? steps : null;
}

export function isName(s: string): boolean {
  if (s === "") return false;
  const c0 = s.charCodeAt(0);
  if (!(isAlpha(c0) || c0 === 0x5f)) return false;
  for (let i = 1; i < s.length; i++) {
    if (!isNameChar(s.charCodeAt(i))) return false;
  }
  return true;
}

function name(p: Cursor): string | null {
  const c = p.peek();
  if (c === null || !(isAlpha(c) || c === 0x5f)) return null;
  return p.takeWhile(isNameChar);
}

function stringLiteral(p: Cursor): string {
  p.advance(); // opening quote
  const out: number[] = [];
  for (;;) {
    const c = p.peek();
    if (c === null) syntax();
    p.advance();
    if (c === QUOTE) return decoder.decode(new Uint8Array(out));
    if (c === BACKSLASH) {
      const next = p.peek();
      if (next !== QUOTE && next !== BACKSLASH) syntax();
      p.advance();
      out.push(next);
      continue;
    }
    out.push(c);
  }
}
