/**
 * SPEC.md clauses no conformance row covers yet, each one found while building
 * this leg against the spec rather than the PHP source. The PHP and Python legs
 * carry the same assertions (php/tests/SpecTest.php, python/tests/test_spec.py),
 * so a clause is held in three places rather than one.
 */
import { describe, expect, it } from "vitest";
import { inspect, render, validateData } from "../src/index";

const vars = { xs: { type: "array", items: { type: "object", properties: { ok: { type: "boolean" }, n: { type: "string" } } } } };
const fp = (blocks: unknown[], variables: Record<string, unknown> = vars) => ({ fancyTemplate: 1, writer: "fine-print", variables, document: { blocks } });

describe("section 8: $if sees the $each binding whatever the key order", () => {
  it("$if written before $each", () => {
    const t = fp([{ $if: "item.ok", $each: "xs", type: "paragraph", runs: [{ text: "{{ item.n }}" }] }]);
    expect(inspect(t).issues).toEqual([]);
    const doc = render(t, { xs: [{ ok: true, n: "a" }, { ok: false, n: "b" }, { ok: true, n: "c" }] });
    expect((doc.blocks as { runs: { text: string }[] }[]).map((b) => b.runs[0]!.text)).toEqual(["a", "c"]);
  });
});

describe("section 10: templateBytes counts every key, numeric ones too", () => {
  it("a key spelled with digits is still UTF-8 bytes", () => {
    // Document: {"1234567890": "x"} under title-free blocks. Measure exactly.
    const base = { fancyTemplate: 1, writer: "fine-print", document: { blocks: [] as unknown[] } };
    const withKey = { ...base, document: { blocks: [{ type: "hr", "1234567890": "x" }] } };
    const bytes = (t: unknown): number => {
      let n = 0;
      const walk = (v: unknown): void => {
        if (typeof v === "string") n += new TextEncoder().encode(v).length;
        else if (Array.isArray(v)) v.forEach(walk);
        else if (v && typeof v === "object") for (const [k, x] of Object.entries(v)) { n += new TextEncoder().encode(k).length; walk(x); }
      };
      walk(t);
      return n;
    };
    const total = bytes(withKey);
    expect(inspect(withKey, { limits: { templateBytes: total } }).issues).toEqual([]);
    expect(inspect(withKey, { limits: { templateBytes: total - 1 } }).issues).toEqual([{ code: "TEMPLATE_TOO_LARGE", path: "" }]);
  });
});

describe("section 2: the version is the integer 1 and never true", () => {
  it("refuses true and '1'", () => {
    const base = { writer: "fine-print", document: { title: "x" } };
    expect(inspect({ fancyTemplate: 1, ...base }).issues).toEqual([]);
    expect(inspect({ fancyTemplate: true, ...base }).issues).toEqual([{ code: "TEMPLATE_VERSION", path: "/fancyTemplate" }]);
    expect(inspect({ fancyTemplate: "1", ...base }).issues).toEqual([{ code: "TEMPLATE_VERSION", path: "/fancyTemplate" }]);
  });
});

describe("section 9: holy-sheet cells", () => {
  const hs = (rows: unknown[][], variables: Record<string, unknown>) => ({ fancyTemplate: 1, writer: "holy-sheet", variables, document: { sheets: [{ name: "S", rows }] } });
  const cell = (doc: Record<string, unknown>) => ((doc.sheets as { rows: unknown[][] }[])[0]!.rows[0]![0]);

  it("a declared date stays bare; a date-shaped STRING variable is wrapped", () => {
    expect(cell(render(hs([["{{ d }}"]], { d: { type: "date" } }), { d: "2026-10-27" }))).toBe("2026-10-27");
    expect(cell(render(hs([["{{ s }}"]], { s: { type: "string" } }), { s: "2026-10-27" }))).toEqual({ value: "2026-10-27" });
  });

  it("a filter that turns a date into text is wrapped", () => {
    expect(cell(render(hs([['{{ d | date:"d MMMM yyyy" }}']], { d: { type: "date" } }), { d: "2026-10-27" }))).toEqual({ value: "27 October 2026" });
  });

  it("a whole-token number keeps its type through default, and a formatted one is text", () => {
    expect(cell(render(hs([['{{ n | default:"0" }}']], { n: { type: "number" } }), { n: 4.5 }))).toBe(4.5);
    expect(cell(render(hs([['{{ n | default:"=1+1" }}']], { n: { type: "number" } }), {}))).toEqual({ value: "=1+1" });
    expect(cell(render(hs([["{{ n | number:2 }}"]], { n: { type: "number" } }), { n: 4.5 }))).toEqual({ value: "4.50" });
  });

  it("a literal cell is the author's, left as written", () => {
    expect(cell(render(hs([["=SUM(A2:A9)"]], {}), {}))).toBe("=SUM(A2:A9)");
  });

  it("$index in a cell is a number", () => {
    const t = { fancyTemplate: 1, writer: "holy-sheet", variables: { xs: { type: "array", items: { type: "string" } } },
      document: { sheets: [{ $each: "xs", $as: "x", name: "{{ x }}", rows: [["{{ $index }}", "{{ x }}"]] }] } };
    const doc = render(t, { xs: ["a", "=b"] });
    expect(doc.sheets).toEqual([
      { name: "a", rows: [[0, { value: "a" }]] },
      { name: "=b", rows: [[1, { value: "=b" }]] },
    ]);
  });
});

describe("section 9: dark-slide markup", () => {
  const ds = (element: Record<string, unknown>) => ({ fancyTemplate: 1, writer: "dark-slide", variables: { v: { type: "string" } },
    document: { slides: [{ elements: [{ type: "text", x: 0, y: 0, w: 1, h: 1, ...element }] }] } });
  const content = (doc: Record<string, unknown>) => (doc.slides as { elements: { content: string }[] }[])[0]!.elements[0]!.content;

  it("escapes only the value, never the template's own markup", () => {
    expect(content(render(ds({ content: "**Dear** {{ v }}", format: "markdown" }), { v: "**x**" }))).toBe("**Dear** \\*\\*x\\*\\*");
  });

  it("escapes a literal token's text too: every token result is a value", () => {
    expect(content(render(ds({ content: '{{ "{{" }}', format: "markdown" }), {}))).toBe("\\{\\{");
  });

  it("leaves non-ASCII punctuation alone", () => {
    expect(content(render(ds({ content: "{{ v }}", format: "markdown" }), { v: "café — “q”" }))).toBe("café — “q”");
  });
});

describe("section 4: validateData reads every declared entry", () => {
  it("validates in declaration order, depth-first", () => {
    const t = fp([], { a: { type: "object", properties: { b: { type: "integer" }, c: { type: "string", required: true } } }, d: { type: "boolean" } });
    expect(validateData(t, { a: { b: 1.5 }, d: "no" })).toEqual([
      { path: "/a/b", code: "DATA_TYPE" },
      { path: "/a/c", code: "DATA_REQUIRED" },
      { path: "/d", code: "DATA_TYPE" },
    ]);
  });
});
