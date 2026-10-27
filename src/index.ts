/**
 * fancy-template -- document templates for the Fancy writers.
 *
 * One specification (`SPEC.md`, normative), three implementations: this one,
 * `particle-academy/fancy-template` (PHP) and `fancy-template` (PyPI). All three
 * assert the same `template/*` rows from `@particle-academy/fancy-conformance`.
 *
 * ```ts
 * import { inspect, validateData, render } from "@particle-academy/fancy-template";
 *
 * inspect(template).issues;          // at SAVE time, no data
 * validateData(template, data);      // before rendering
 * const doc = render(template, data); // plain writer JSON, or throws TemplateError
 * ```
 *
 * The output is plain writer JSON, so the writers never depend on this package
 * and stay zero-dependency (plan section 4). Pass it through the writer's own
 * validate and write.
 */
import { applyDefaults, validateMembers } from "./declarations";
import { TemplateError, type DataError, type Issue } from "./errors";
import { declaredVariables, Inspector } from "./inspector";
import { firstTooDeep, isObject, own, plain, type Json, type JsonObject } from "./json";
import { limitsFrom, type Limits, type Options } from "./limits";
import { Profile } from "./profile";
import { Renderer } from "./renderer";
import { tokenize as scan, type Part } from "./scanner";

export { TemplateError, type DataError, type Issue } from "./errors";
export { DEFAULT_LIMITS, type Limits, type Options } from "./limits";
export type { Filter, Part, TextPart, TokenPart } from "./scanner";
export type { Json, JsonObject } from "./json";

export interface InspectResult {
  ok: boolean;
  issues: Issue[];
}

export function inspect(template: unknown, options?: Options): InspectResult {
  const limits = limitsFrom(options);
  const [isPlain, value] = plain(template);
  const issues = isPlain ? Inspector.run(value, limits) : [{ code: "TEMPLATE_SHAPE", path: "" }];
  return { ok: issues.length === 0, issues };
}

/**
 * Data errors in declaration order; empty when valid. Assumes a template that
 * passes inspect; declarations that do not are skipped, not guessed at.
 */
export function validateData(template: unknown, data: unknown, options?: Options): DataError[] {
  return prepare(template, data, limitsFrom(options))[1];
}

/**
 * Render a template with data. Inspect first, then validate the data, then
 * render; the first failure is thrown (SPEC.md section 11).
 */
export function render(template: unknown, data: unknown, options?: Options): JsonObject {
  const limits = limitsFrom(options);
  const [isPlain, value] = plain(template);
  if (!isPlain || !isObject(value)) throw new TemplateError("TEMPLATE_SHAPE", "");
  const issues = Inspector.run(value, limits);
  if (issues.length > 0) {
    const first = issues[0]!;
    throw new TemplateError(first.code, first.path, first.variable);
  }

  const [defaulted, errors] = prepare(value, data, limits);
  if (errors.length > 0) throw new TemplateError(errors[0]!.code, errors[0]!.path);

  return Renderer.run(
    own(value, "document") as JsonObject,
    defaulted,
    declaredVariables(value),
    Profile.for(own(value, "writer"))!,
    limits,
  );
}

/**
 * Render with each variable's `example`, falling back to its `default`. A
 * required variable with neither fails, rather than inventing a value the real
 * data could not produce (template/resolve 0202).
 */
export function preview(template: unknown, options?: Options): JsonObject {
  const data: Record<string, unknown> = {};
  const [isPlain, value] = plain(template);
  const variables = isPlain && isObject(value) ? own(value, "variables") : undefined;
  if (isObject(variables)) {
    for (const [name, decl] of Object.entries(variables)) {
      if (isObject(decl) && Object.hasOwn(decl, "example")) {
        Object.defineProperty(data, name, { value: decl.example, enumerable: true, writable: true, configurable: true });
      }
    }
  }
  return render(template, data, options);
}

/** Split one string into text and token parts (SPEC.md section 5). */
export function tokenize(text: string, options?: Options): Part[] {
  return scan(text, limitsFrom(options).tokenLength);
}

/** Reduce data to plain JSON, bound its depth, apply defaults, validate. */
function prepare(template: unknown, data: unknown, limits: Limits): [JsonObject, DataError[]] {
  const [plainTemplate, t] = plain(template);
  const [plainData, d] = plain(data);
  if (!plainData) return [{}, [{ path: "", code: "DATA_TYPE" }]];
  const tooDeep = firstTooDeep(d as Json, limits.depth);
  if (tooDeep !== null) return [{}, [{ path: tooDeep, code: "DATA_TOO_DEEP" }]];
  if (!isObject(d)) return [{}, [{ path: "", code: "DATA_TYPE" }]];

  const members: JsonObject = {};
  if (plainTemplate) {
    for (const [name, decl] of declaredVariables(t)) {
      Object.defineProperty(members, name, { value: decl, enumerable: true, writable: true, configurable: true });
    }
  }
  const errors = validateMembers(d, members, "");
  return [applyDefaults(d, members), errors];
}
