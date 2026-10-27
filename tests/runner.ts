/**
 * One case in, the shape its suite's expected column uses out. Shared by
 * conformance.test.ts and the cross-leg parity driver (scripts/parity-node.ts)
 * so the two measure the same thing -- the same contract as php/tests/runner.php
 * and python/tests/runner.py.
 */
import { inspect, preview, render, TemplateError, tokenize, validateData } from "../src/index";

export interface TemplateCase {
  id: string;
  title?: string;
  fn: "tokenize" | "render" | "preview" | "validateData" | "inspect";
  input: { text?: string; template?: unknown; data?: unknown; options?: Record<string, unknown> };
  expected: unknown;
  skip?: Record<string, string>;
}

export function runTemplateCase(c: TemplateCase): unknown {
  const input = c.input;
  const options = (input.options ?? {}) as Parameters<typeof render>[2];

  switch (c.fn) {
    case "tokenize":
      try {
        return { ok: true, parts: tokenize(input.text as string, options) };
      } catch (e) {
        if (e instanceof TemplateError) return { ok: false, code: e.code };
        throw e;
      }
    case "render":
    case "preview":
      try {
        const document = c.fn === "render" ? render(input.template, input.data, options) : preview(input.template, options);
        return { ok: true, document };
      } catch (e) {
        if (e instanceof TemplateError) return { ok: false, code: e.code, path: e.path };
        throw e;
      }
    case "validateData":
      return { errors: validateData(input.template, input.data, options) };
    case "inspect":
      return { issues: inspect(input.template, options).issues.map((i) => ({ code: i.code, path: i.path })) };
  }
}
