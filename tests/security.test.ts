/**
 * The plan's section 5 properties that a conformance row cannot express,
 * because they are about what an error CONTAINS and what the engine TOUCHES
 * rather than what it returns -- plus the ways JavaScript in particular could
 * be reached through a key or a value.
 */
import { describe, expect, it } from "vitest";
import { inspect, render, TemplateError, tokenize, validateData } from "../src/index";

const SECRET = "S3CRET-recipient-9f2c";

function fp(blocks: unknown[], variables: Record<string, unknown>, extra: Record<string, unknown> = {}) {
  return { fancyTemplate: 1, writer: "fine-print", variables, document: { ...extra, blocks } };
}

function failure(fn: () => unknown): TemplateError {
  try {
    fn();
  } catch (e) {
    if (e instanceof TemplateError) return e;
    throw e;
  }
  throw new Error("expected a TemplateError");
}

const para = (text: string) => ({ type: "paragraph", runs: [{ text }] });

describe("errors never carry a data value", () => {
  const cases: [string, unknown, unknown, string][] = [
    ["enum", fp([], { v: { type: "string", enum: ["a"] } }), { v: SECRET }, "DATA_ENUM"],
    ["max length", fp([], { v: { type: "string", maxLength: 3 } }), { v: SECRET }, "DATA_MAX_LENGTH"],
    ["date format", fp([], { v: { type: "date" } }), { v: SECRET }, "DATA_FORMAT"],
    ["page field", fp([], { v: { type: "string" } }, { page: { footer: { left: "{{ v }}" } } }), { v: `${SECRET}{page}` }, "VALUE_PAGE_FIELD"],
    ["filter input", fp([para('{{ v | date:"d" }}')], { v: { type: "string" } }), { v: SECRET }, "FILTER_INPUT"],
    ["not scalar", fp([para("{{ v }}")], { v: { type: "array", items: { type: "string" } } }), { v: [SECRET] }, "TEMPLATE_NOT_SCALAR"],
  ];
  for (const [label, template, data, code] of cases) {
    it(label, () => {
      const e = failure(() => render(template, data));
      expect(e.code).toBe(code);
      expect(e.message).not.toContain(SECRET);
      expect(JSON.stringify(e)).not.toContain(SECRET);
    });
  }

  it("names the variable by path, never by value", () => {
    const e = failure(() =>
      render(fp([para("{{ person.middle }}")], { person: { type: "object", properties: { middle: { type: "string" } } } }), {
        person: { first: SECRET },
      }),
    );
    expect(e.toJSON()).toEqual({ code: "TEMPLATE_MISSING", path: "/document/blocks/0/runs/0/text", variable: "person.middle" });
  });
});

describe("host values are reduced to plain JSON before anything reads them", () => {
  it("runs toJSON once, at the boundary, and never a method during render", () => {
    let calls = 0;
    const host = {
      toJSON() {
        calls++;
        return "Ada";
      },
    };
    const doc = render(fp([para("{{ v }}")], { v: { type: "string" } }), { v: host });
    expect((doc.blocks as { runs: { text: string }[] }[])[0]!.runs[0]!.text).toBe("Ada");
    // render() reduces the data once (validateData + defaults share the copy).
    expect(calls).toBe(1);
  });

  it("refuses data that is not JSON, at the root, like the other legs", () => {
    const t = fp([], { v: { type: "number" } });
    expect(validateData(t, { v: Infinity })).toEqual([{ path: "", code: "DATA_TYPE" }]);
    expect(validateData(t, { v: NaN })).toEqual([{ path: "", code: "DATA_TYPE" }]);
    expect(validateData(t, { v: 10n })).toEqual([{ path: "", code: "DATA_TYPE" }]);
    expect(validateData(t, { v: "\ud800" })).toEqual([{ path: "", code: "DATA_TYPE" }]);
    const cyclic: Record<string, unknown> = {};
    cyclic.self = cyclic;
    expect(validateData(t, cyclic)).toEqual([{ path: "", code: "DATA_TYPE" }]);
  });

  it("refuses a template that is not JSON", () => {
    expect(inspect({ fancyTemplate: 1, writer: "fine-print", document: { title: "\udc00" } }).issues).toEqual([
      { code: "TEMPLATE_SHAPE", path: "" },
    ]);
  });
});

describe("a key is only ever a key", () => {
  it("never reads the prototype: `constructor` is absent, not a function", () => {
    const t = fp([para("{{ o.constructor }}")], { o: { type: "object", properties: { constructor: { type: "string" } } } });
    expect(failure(() => render(t, { o: {} })).code).toBe("TEMPLATE_MISSING");
  });

  it("an undeclared `__proto__` or `toString` head is refused at inspect", () => {
    for (const head of ["__proto__", "toString", "constructor", "hasOwnProperty"]) {
      expect(inspect(fp([para(`{{ ${head} }}`)], {})).issues).toEqual([
        { code: "TOKEN_UNDECLARED", path: "/document/blocks/0/runs/0/text", variable: head },
      ]);
    }
  });

  it("writes a `__proto__` key as a key and never replaces a prototype", () => {
    const template = JSON.parse('{"fancyTemplate":1,"writer":"fine-print","document":{"blocks":[{"type":"hr","__proto__":{"polluted":true}}]}}');
    const doc = render(template, {});
    const block = (doc.blocks as Record<string, unknown>[])[0]!;
    expect(Object.getPrototypeOf(block)).toBe(Object.prototype);
    expect(Object.hasOwn(block, "__proto__")).toBe(true);
    expect((block as { polluted?: unknown }).polluted).toBeUndefined();
    expect(({} as { polluted?: unknown }).polluted).toBeUndefined();
  });

  it("takes a `__proto__` data key as data, and defaults never pollute", () => {
    const t = fp([para("{{ v }}")], { v: { type: "string", default: "d" } });
    const data = JSON.parse('{"__proto__":{"v":"from-prototype"}}');
    const doc = render(t, data);
    expect((doc.blocks as { runs: { text: string }[] }[])[0]!.runs[0]!.text).toBe("d");
  });
});

describe("conditions cannot call anything", () => {
  for (const expression of ["phpinfo()", 'v.constructor("x")', 'system("id")', "v.toString()"]) {
    it(expression, () => {
      const issues = inspect(fp([{ $if: expression, type: "hr" }], { v: { type: "string" } })).issues;
      expect(issues).toEqual([{ code: "DIRECTIVE_EXPR", path: "/document/blocks/0/$if" }]);
    });
  }
});

describe("the scanner is bounded and linear", () => {
  it("refuses a megabyte after an open brace in token-length time", () => {
    const text = 'x{{"' + "a".repeat(1_000_000);
    const start = performance.now();
    const e = failure(() => tokenize(text));
    const ms = performance.now() - start;
    expect(e.code).toBe("TOKEN_TOO_LONG");
    // Generous: it is about scanning 512 bytes (plus one UTF-8 encode), not about this machine.
    expect(ms).toBeLessThan(100);
  });

  it("scans many tokens and many lone braces linearly", () => {
    const text = "{ } }} x".repeat(50_000) + "{{a}}".repeat(2_000);
    const start = performance.now();
    const parts = tokenize(text);
    const ms = performance.now() - start;
    expect(parts.length).toBe(2_001);
    expect(ms).toBeLessThan(500);
  });

  it("measures the token limit in UTF-8 bytes, not UTF-16 units", () => {
    // 200 x U+00E9 is 200 code units but 400 bytes: inside 512. 300 is 600 bytes: over.
    expect(tokenize(`{{ "${"é".repeat(200)}" }}`)).toEqual([{ literal: "é".repeat(200), filters: [] }]);
    expect(failure(() => tokenize(`{{ "${"é".repeat(300)}" }}`)).code).toBe("TOKEN_TOO_LONG");
  });
});
