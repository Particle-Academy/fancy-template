import { ExprSyntaxError, references } from "@particle-academy/fancy-expr";
import { inspectDecl } from "./declarations";
import { TemplateError, type Issue } from "./errors";
import { checkFilter } from "./filters";
import { firstTooDeep, imageType, isObject, own, pointer, stringBytes, type Json, type JsonObject } from "./json";
import type { Limits } from "./limits";
import { Profile } from "./profile";
import { countTokens, isName, LOOP_HEADS, pathOnly, tokenize, type Part, type TokenPart } from "./scanner";

/**
 * Everything that can be known about a template without data (SPEC.md
 * sections 2, 3, 5, 8, 9, 10 and 11). The answer an admin gets at SAVE time.
 *
 * Issues come in document order so that three runtimes produce comparable
 * lists: envelope, declarations, then the document depth-first with an
 * object's `$` keys before its other entries.
 */

export interface DeclFrame {
  name: string;
  decl: Json | undefined;
}

const ENVELOPE_REQUIRED: [string, string][] = [
  ["fancyTemplate", "TEMPLATE_VERSION"],
  ["writer", "TEMPLATE_WRITER"],
  ["document", "TEMPLATE_SHAPE"],
];

const isLoopHead = (s: string): boolean => (LOOP_HEADS as readonly string[]).includes(s);

/**
 * The declared variables of a template, in written order. Every entry, as the
 * other legs read it: on a template that failed inspect, validateData still
 * validates what it can rather than guessing which entries were meant.
 */
export function declaredVariables(template: Json): Map<string, Json> {
  const out = new Map<string, Json>();
  if (!isObject(template)) return out;
  const variables = own(template, "variables");
  if (!isObject(variables)) return out;
  for (const [name, decl] of Object.entries(variables)) out.set(name, decl);
  return out;
}

/**
 * Walk a path through the declarations (SPEC.md section 6). [found, decl];
 * decl is undefined when the shape below is unknown (a broken declaration has
 * already been reported, and is not reported twice).
 */
export function resolveDecl(steps: readonly string[], frames: readonly DeclFrame[], variables: Map<string, Json>): [boolean, Json | undefined] {
  const head = steps[0]!;
  if (isLoopHead(head)) {
    if (frames.length === 0 || steps.length > 1) return [false, undefined];
    return [true, { type: head === "$index" ? "integer" : "boolean" }];
  }

  let decl: Json | undefined;
  let found = false;
  for (let f = frames.length - 1; f >= 0; f--) {
    if (frames[f]!.name === head) {
      found = true;
      decl = frames[f]!.decl;
      break;
    }
  }
  if (!found) {
    if (!variables.has(head)) return [false, undefined];
    decl = variables.get(head);
  }

  for (const step of steps.slice(1)) {
    if (!isObject(decl) || own(decl, "type") == null) return [true, undefined];
    const properties = own(decl, "properties");
    if (decl.type === "object" && isObject(properties) && Object.hasOwn(properties, step)) {
      decl = properties[step];
      continue;
    }
    if (decl.type === "array" && /^[0-9]+$/.test(step)) {
      decl = own(decl, "items");
      continue;
    }
    return [false, undefined];
  }
  return [true, decl];
}

export class Inspector {
  private readonly issues: Issue[] = [];
  private readonly variables = new Map<string, Json>();
  private profile: Profile | null = null;

  private constructor(private readonly limits: Limits) {}

  static run(template: Json, limits: Limits): Issue[] {
    const self = new Inspector(limits);
    self.inspect(template);
    return self.issues;
  }

  private inspect(template: Json): void {
    if (!isObject(template)) {
      this.issue("TEMPLATE_SHAPE", "");
      return;
    }

    // The three bounds that STOP inspect come first, in this order, so that an
    // oversized template costs nothing more to refuse (template/inspect 0020).
    const tooDeep = firstTooDeep(template, this.limits.depth);
    if (tooDeep !== null) {
      this.issue("TEMPLATE_TOO_DEEP", tooDeep);
      return;
    }
    if (stringBytes(template) > this.limits.templateBytes) {
      this.issue("TEMPLATE_TOO_LARGE", "");
      return;
    }
    if (this.countTokens(own(template, "document")) > this.limits.tokens) {
      this.issue("TEMPLATE_TOO_MANY_TOKENS", "");
      return;
    }

    for (const [key, value] of Object.entries(template)) {
      const at = pointer("", key);
      switch (key) {
        case "fancyTemplate":
          if (value !== 1) this.issue("TEMPLATE_VERSION", at);
          break;
        case "writer":
          this.profile = Profile.for(value);
          if (this.profile === null) this.issue("TEMPLATE_WRITER", at);
          break;
        case "description":
          if (typeof value !== "string") this.issue("TEMPLATE_SHAPE", at);
          break;
        case "variables":
        case "document":
          if (!isObject(value)) this.issue("TEMPLATE_SHAPE", at);
          break;
        default:
          this.issue("TEMPLATE_SHAPE", at);
      }
    }
    for (const [key, code] of ENVELOPE_REQUIRED) {
      if (!Object.hasOwn(template, key)) this.issue(code, pointer("", key));
    }

    const variables = own(template, "variables");
    if (isObject(variables)) {
      for (const [name, decl] of Object.entries(variables)) {
        const at = pointer("/variables", name);
        if (!isName(name)) {
          this.issue("DECL_INVALID", at);
          continue;
        }
        inspectDecl(decl, at, this.issues);
        this.variables.set(name, decl);
      }
    }

    const document = own(template, "document");
    if (this.profile !== null && isObject(document)) {
      this.walk(document, "/document", [], [], 0, false);
    }
  }

  private countTokens(value: Json | undefined): number {
    if (typeof value === "string") return countTokens(value, this.limits.tokenLength);
    if (typeof value !== "object" || value === null) return 0;
    let n = 0;
    for (const v of Array.isArray(value) ? value : Object.values(value)) {
      n += this.countTokens(v);
      if (n > this.limits.tokens) break;
    }
    return n;
  }

  private walk(value: Json, path: string, segments: string[], frames: DeclFrame[], directiveDepth: number, isElement: boolean): void {
    if (typeof value === "string") {
      this.string(value, path, segments, frames);
      return;
    }
    if (typeof value !== "object" || value === null) return;
    if (Array.isArray(value)) {
      value.forEach((item, i) => this.walk(item, pointer(path, i), [...segments, String(i)], frames, directiveDepth, true));
      return;
    }

    const keys = Object.keys(value);
    const directives = keys.filter((k) => k.startsWith("$"));
    if (directives.length > 0) {
      if (!isElement) {
        for (const key of directives) this.issue("DIRECTIVE_MISPLACED", pointer(path, key));
      } else {
        directiveDepth++;
        if (directiveDepth > this.limits.directiveDepth) {
          this.issue("DIRECTIVE_TOO_DEEP", path);
          return;
        }
        frames = this.directives(value, path, frames);
        if (Object.hasOwn(value, "$value")) {
          this.splice(value, path, segments, frames, directiveDepth);
          return;
        }
      }
    }

    for (const key of keys) {
      if (key.startsWith("$")) continue;
      const at = pointer(path, key);
      if (key.includes("{{")) this.issue("TOKEN_IN_KEY", at);
      this.walk(value[key]!, at, [...segments, key], frames, directiveDepth, false);
    }
  }

  /**
   * The rest of a splice (SPEC.md section 8), after its `$` keys: every other
   * key is DIRECTIVE_SPLICE_KEY and is not walked, then `$value` is walked AT
   * THE ELEMENT'S PATH (`$value` is a pointer step, never a profile segment)
   * and as a non-element, so a `$`-key on its top is DIRECTIVE_MISPLACED.
   * Without `$each` it is not walked: that is already DIRECTIVE_INVALID.
   */
  private splice(object: JsonObject, path: string, segments: string[], frames: DeclFrame[], directiveDepth: number): void {
    for (const key of Object.keys(object)) {
      if (!key.startsWith("$")) this.issue("DIRECTIVE_SPLICE_KEY", pointer(path, key));
    }
    if (Object.hasOwn(object, "$each")) {
      this.walk(own(object, "$value") as Json, pointer(path, "$value"), segments, frames, directiveDepth, false);
    }
  }

  /**
   * The frames an element's `$each` introduces, or null when it introduces
   * none (absent, or invalid -- which is reported in the ordered pass).
   * Worked out BEFORE the ordered pass so that `$if` sees the binding
   * whichever key was written first: SPEC.md section 8 says `$each` runs first,
   * and JSON key order is not something an author should have to get right.
   */
  private eachBinding(object: JsonObject, frames: DeclFrame[]): DeclFrame[] | null {
    const each = own(object, "$each");
    if (typeof each !== "string" || new TextEncoder().encode(each).length > this.limits.tokenLength) return null;
    const steps = pathOnly(each);
    if (steps === null) return null;
    const [found, decl] = resolveDecl(steps, frames, this.variables);
    if (!found || (isObject(decl) && decl.type !== "array")) return null;
    const as = own(object, "$as") ?? "item";
    if (typeof as !== "string" || !isName(as)) return null;
    return [...frames, { name: as, decl: isObject(decl) ? own(decl, "items") : undefined }];
  }

  /** Check an element's directives in written order; return the frames its contents see. */
  private directives(object: JsonObject, path: string, frames: DeclFrame[]): DeclFrame[] {
    const hasEach = Object.hasOwn(object, "$each");
    const inner = this.eachBinding(object, frames) ?? frames;

    for (const [key, value] of Object.entries(object)) {
      if (!key.startsWith("$")) continue;
      const at = pointer(path, key);

      if (key === "$each") {
        if (typeof value !== "string") {
          this.issue("DIRECTIVE_INVALID", at);
          continue;
        }
        if (new TextEncoder().encode(value).length > this.limits.tokenLength) {
          this.issue("TOKEN_TOO_LONG", at);
          continue;
        }
        const steps = pathOnly(value);
        if (steps === null) {
          this.issue("DIRECTIVE_INVALID", at);
          continue;
        }
        const [found, decl] = resolveDecl(steps, frames, this.variables);
        if (!found) {
          this.issue("DIRECTIVE_UNDECLARED", at, steps.join("."));
          continue;
        }
        if (isObject(decl) && decl.type !== "array") {
          this.issue("DIRECTIVE_NOT_ARRAY", at, steps.join("."));
        }
        continue;
      }

      if (key === "$as") {
        const shadows = typeof value === "string" && (this.variables.has(value) || frames.some((f) => f.name === value));
        if (!hasEach || typeof value !== "string" || !isName(value) || shadows) {
          this.issue("DIRECTIVE_INVALID", at);
        }
        continue;
      }

      if (key === "$value") {
        // Any JSON is a valid $value; only a splice with nothing to repeat
        // over is wrong (SPEC.md section 8).
        if (!hasEach) this.issue("DIRECTIVE_INVALID", at);
        continue;
      }

      if (key === "$if") {
        if (typeof value !== "string") {
          this.issue("DIRECTIVE_INVALID", at);
          continue;
        }
        if (new TextEncoder().encode(value).length > this.limits.tokenLength) {
          this.issue("TOKEN_TOO_LONG", at);
          continue;
        }
        let roots: string[];
        try {
          roots = references(value);
        } catch (e) {
          if (!(e instanceof ExprSyntaxError)) throw e;
          this.issue("DIRECTIVE_EXPR", at);
          continue;
        }
        // `$if` sees this element's own loop binding (SPEC.md section 8).
        const scope = hasEach ? inner : frames;
        for (const root of roots) {
          if (!this.inScope(root, scope)) {
            this.issue("DIRECTIVE_UNDECLARED", at, root);
            break;
          }
        }
        continue;
      }

      this.issue("DIRECTIVE_UNKNOWN", at);
    }

    return inner;
  }

  private inScope(root: string, frames: DeclFrame[]): boolean {
    if (isLoopHead(root)) return frames.length > 0;
    return frames.some((f) => f.name === root) || this.variables.has(root);
  }

  private string(value: string, path: string, segments: string[], frames: DeclFrame[]): void {
    let parts: Part[];
    try {
      parts = tokenize(value, this.limits.tokenLength);
    } catch (e) {
      if (!(e instanceof TemplateError)) throw e;
      this.issue(e.code, path);
      return;
    }

    const tokens = parts.filter((p): p is TokenPart => !("text" in p));
    const field = this.profile!.field(segments);

    if (tokens.length === 0) {
      if (field?.encode === "image" && !(field.mime ?? []).includes(imageType(value) ?? "")) {
        this.issue("TEMPLATE_IMAGE_INVALID", path);
      }
      return;
    }
    if (field === null) {
      this.issue("TOKEN_FORBIDDEN_FIELD", path);
      return;
    }

    if (field.encode === "image") {
      const token = tokens[0]!;
      const isWhole = parts.length === 1 && "path" in token && token.filters.length === 0;
      const [found, decl] = isWhole ? resolveDecl((token as { path: string[] }).path, frames, this.variables) : [false, undefined];
      if (!isWhole || !found || !isObject(decl) || decl.type !== "image") {
        this.issue("TOKEN_IMAGE_FIELD", path);
      }
      return;
    }

    for (const token of tokens) {
      if ("path" in token) {
        const [found] = resolveDecl(token.path, frames, this.variables);
        if (!found) this.issue("TOKEN_UNDECLARED", path, token.path.join("."));
      }
      for (const filter of token.filters) {
        const code = checkFilter(filter);
        if (code !== null) this.issue(code, path);
      }
    }
  }

  private issue(code: string, path: string, variable?: string): void {
    this.issues.push(variable === undefined ? { code, path } : { code, path, variable });
  }
}
