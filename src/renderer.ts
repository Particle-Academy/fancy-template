import { evaluate, truthy } from "@particle-academy/fancy-expr";
import { parseDate } from "./dates";
import { TemplateError } from "./errors";
import { ABSENT, applyFilter, scalarText, type Value } from "./filters";
import { resolveDecl, type DeclFrame } from "./inspector";
import { hasOwn, imageType, isObject, own, pointer, setOwn, utf8Length, type Json, type JsonObject } from "./json";
import type { Limits } from "./limits";
import type { FieldRule, Profile } from "./profile";
import { LOOP_HEADS, pathOnly, tokenize } from "./scanner";

/**
 * Template + validated, defaulted data -> writer document (SPEC.md sections 6-10).
 *
 * Runs only on a template that passed inspect and data that passed
 * validateData, so it does not re-check what those guarantee; it checks what
 * only data can decide (absent paths, non-scalars, filter inputs, {page} in a
 * band value) and the output bounds, counted AS the output is produced so that
 * an oversized result is refused before it is built rather than after.
 */

interface Frame extends DeclFrame {
  value: Json;
  $index: number;
  $first: boolean;
  $last: boolean;
}

const isLoopHead = (s: string): s is (typeof LOOP_HEADS)[number] => (LOOP_HEADS as readonly string[]).includes(s);

/** CommonMark: a backslash before every ASCII punctuation character (SPEC.md section 9). */
export function escapeMarkdown(s: string): string {
  let out = "";
  for (const ch of s) {
    const c = ch.charCodeAt(0);
    const punct = ch.length === 1 && ((c >= 33 && c <= 47) || (c >= 58 && c <= 64) || (c >= 91 && c <= 96) || (c >= 123 && c <= 126));
    out += punct ? "\\" + ch : ch;
  }
  return out;
}

const HTML: Record<string, string> = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };

export function escapeHtml(s: string): string {
  let out = "";
  for (const ch of s) out += Object.hasOwn(HTML, ch) ? HTML[ch] : ch;
  return out;
}

export class Renderer {
  private iterations = 0;
  private nodes = 0;
  private bytes = 0;

  private constructor(
    private readonly profile: Profile,
    private readonly data: JsonObject,
    private readonly variables: Map<string, Json>,
    private readonly limits: Limits,
  ) {}

  static run(document: JsonObject, data: JsonObject, variables: Map<string, Json>, profile: Profile, limits: Limits): JsonObject {
    const self = new Renderer(profile, data, variables, limits);
    self.count(null);
    return self.object(document, "/document", [], []);
  }

  private object(object: JsonObject, path: string, segments: string[], frames: Frame[]): JsonObject {
    const out: JsonObject = {};
    for (const [key, value] of Object.entries(object)) {
      if (key.startsWith("$")) continue;
      setOwn(out, key, this.value(value, pointer(path, key), [...segments, key], frames, object));
    }
    return out;
  }

  private value(value: Json, path: string, segments: string[], frames: Frame[], parent: JsonObject | null): Json {
    if (typeof value === "string") {
      const out = this.string(value, path, segments, frames, parent);
      if (isObject(out)) {
        // A cell wrapper: one object and one string (SPEC.md section 10).
        this.count(null);
        this.count(out.value as Json);
      } else {
        this.count(out);
      }
      return out;
    }
    if (typeof value !== "object" || value === null) {
      this.count(value);
      return value;
    }
    this.count(null);
    if (!Array.isArray(value)) return this.object(value, path, segments, frames);

    const out: Json[] = [];
    value.forEach((item, i) => {
      const at = pointer(path, i);
      const itemSegments = [...segments, String(i)];
      if (!isObject(item) || !Object.keys(item).some((k) => k.startsWith("$"))) {
        out.push(this.value(item, at, itemSegments, frames, null));
        return;
      }
      const isSplice = Object.hasOwn(item, "$value");
      for (const innerFrames of this.expand(item, at, frames)) {
        if (isSplice) {
          // The splice object is not output: each copy is $value, rendered at
          // the element's path (SPEC.md section 8).
          out.push(this.value(own(item, "$value") as Json, pointer(at, "$value"), itemSegments, innerFrames, null));
          continue;
        }
        this.count(null);
        out.push(this.object(item, at, itemSegments, innerFrames));
      }
    });
    return out;
  }

  /**
   * The frames each copy of a directive element renders with: one per kept
   * `$each` element, or one (or none) for a lone `$if`. A generator on purpose:
   * iterations are counted as copies are rendered, interleaved, exactly as the
   * other legs count them, so the same template fails with the same code.
   */
  private *expand(item: JsonObject, path: string, frames: Frame[]): Generator<Frame[]> {
    const condition = own(item, "$if");
    if (!hasOwn(item, "$each")) {
      if (this.condition(condition as string, frames)) yield frames;
      return;
    }

    const at = pointer(path, "$each");
    const steps = pathOnly(item.$each as string)!;
    const list = this.resolve(steps, frames);
    if (list === ABSENT) throw new TemplateError("TEMPLATE_MISSING", at, steps.join("."));
    if (!Array.isArray(list)) throw new TemplateError("DIRECTIVE_NOT_ARRAY", at, steps.join("."));

    const as = (own(item, "$as") ?? "item") as string;
    const [, decl] = resolveDecl(steps, frames, this.variables);
    const itemsDecl = isObject(decl) ? own(decl, "items") : undefined;
    const last = list.length - 1;
    for (let index = 0; index < list.length; index++) {
      if (++this.iterations > this.limits.iterations) {
        throw new TemplateError("RENDER_TOO_MANY_ITERATIONS", at);
      }
      const inner: Frame[] = [...frames, { name: as, decl: itemsDecl, value: list[index]!, $index: index, $first: index === 0, $last: index === last }];
      if (condition !== undefined && !this.condition(condition as string, inner)) continue;
      yield inner;
    }
  }

  private condition(expression: string, frames: Frame[]): boolean {
    const context: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(this.data)) setOwn(context, k, v);
    for (const frame of frames) setOwn(context, frame.name, frame.value);
    if (frames.length > 0) {
      const innermost = frames[frames.length - 1]!;
      for (const head of LOOP_HEADS) setOwn(context, head, innermost[head]);
    }
    // Parsed and bounded at inspect; fancy-expr never reaches the host.
    return truthy(evaluate(expression, context));
  }

  private resolve(steps: readonly string[], frames: Frame[]): Value {
    const head = steps[0]!;
    if (isLoopHead(head)) {
      return frames.length === 0 ? ABSENT : frames[frames.length - 1]![head];
    }

    let value: Value = ABSENT;
    for (let f = frames.length - 1; f >= 0; f--) {
      if (frames[f]!.name === head) {
        value = frames[f]!.value;
        break;
      }
    }
    if (value === ABSENT) {
      if (!hasOwn(this.data, head)) return ABSENT;
      value = this.data[head]!;
    }

    for (const step of steps.slice(1)) {
      if (typeof value !== "object" || value === null) return ABSENT;
      if (Array.isArray(value)) {
        if (!/^[0-9]+$/.test(step)) return ABSENT;
        const index = Number(step);
        if (index >= value.length) return ABSENT;
        value = value[index]!;
        continue;
      }
      if (!hasOwn(value, step)) return ABSENT;
      value = value[step]!;
    }
    return value;
  }

  private string(text: string, path: string, segments: string[], frames: Frame[], parent: JsonObject | null): Json {
    if (!text.includes("{{")) return text;

    const field = this.profile.field(segments) as FieldRule;
    const parts = tokenize(text, this.limits.tokenLength);
    const escape = this.escaper(field, parent);
    let out = "";

    for (const part of parts) {
      if ("text" in part) {
        out += part.text;
        continue;
      }
      const variable = "path" in part ? part.path.join(".") : undefined;
      let value: Value = "path" in part ? this.resolve(part.path, frames) : part.literal;

      let piece: string;
      try {
        for (const filter of part.filters) value = applyFilter(filter, value);
        if (value === ABSENT) throw new TemplateError("TEMPLATE_MISSING", "");
        if (field.encode === "image") {
          if (!(field.mime ?? []).includes(imageType(value) ?? "")) {
            throw new TemplateError("VALUE_IMAGE_TYPE", "");
          }
          return value;
        }
        if (field.encode === "cell" && parts.length === 1) {
          const kept = this.typedCell(value, "path" in part ? part.path : null, frames);
          if (kept !== null) return kept;
        }
        piece = scalarText(value);
      } catch (e) {
        if (e instanceof TemplateError) throw new TemplateError(e.code, path, variable);
        throw e;
      }

      if (field.encode === "band" && (piece.includes("{page}") || piece.includes("{pages}"))) {
        throw new TemplateError("VALUE_PAGE_FIELD", path, variable);
      }
      out += escape(piece);
    }

    // Every other cell result is wrapped, so user data can never become a
    // formula: holy-sheet promotes a BARE string starting with "=" and takes
    // {value: s} as literal text (template/profiles 0201).
    return field.encode === "cell" ? { value: out } : out;
  }

  /**
   * A whole token in a cell keeps a number or a boolean, and a string that is
   * a valid `date` (SPEC.md section 3) when its path is DECLARED `date`. Null
   * when the result is to be wrapped. A valid date cannot begin with "=", so
   * nothing kept bare here can become a formula.
   */
  private typedCell(value: Json, path: string[] | null, frames: Frame[]): Json | null {
    if (typeof value === "number" || typeof value === "boolean") return value;
    if (typeof value !== "string" || path === null || parseDate(value) === null) return null;
    const [found, decl] = resolveDecl(path, frames, this.variables);
    return found && isObject(decl) && decl.type === "date" ? value : null;
  }

  /** How a substituted value is written into this field (SPEC.md section 9). */
  private escaper(field: FieldRule, parent: JsonObject | null): (s: string) => string {
    if (field.encode === "markdown") return escapeMarkdown;
    if (field.encode === "markup") {
      const format = parent === null ? undefined : own(parent, "format");
      if (format === "markdown") return escapeMarkdown;
      if (format === "html") return escapeHtml;
    }
    return (s) => s;
  }

  /** One output value; a string also adds its bytes (SPEC.md section 10). */
  private count(value: Json): void {
    if (++this.nodes > this.limits.outputNodes) throw new TemplateError("RENDER_TOO_MANY_NODES", "");
    if (typeof value === "string") {
      this.bytes += utf8Length(value);
      if (this.bytes > this.limits.outputBytes) throw new TemplateError("RENDER_TOO_LARGE", "");
    }
  }
}
