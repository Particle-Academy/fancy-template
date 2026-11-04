import { describe, expect, it } from "vitest";
import { render, TemplateError } from "../src/index";

/*
 * User data must not become a live field: a value holding a writer's band token
 * is refused, never rewritten. The PHP and Python legs hold the same rows.
 */

function tpl(writer: string, document: Record<string, unknown>) {
  return { fancyTemplate: 1, writer, variables: { v: { type: "string" } }, document };
}

function code(template: unknown, value: string): string | null {
  try {
    render(template as never, { v: value });
    return null;
  } catch (e) {
    if (e instanceof TemplateError) return e.code;
    throw e;
  }
}

const TOKENS = ["{page}", "{pages}", "{section}", "{sectionpages}", "{author}", "{title}", "{filename}", "{date}", "{time}", "see {section} here", "{date:d MMMM yyyy}", "{time:HH:mm}"];
const BANDS = ["header", "footer", "firstHeader", "firstFooter", "evenHeader", "evenFooter"];

describe("a band value holding a field token", () => {
  it.each(TOKENS)("refuses %s for both writers", (value) => {
    for (const writer of ["fine-print", "last-word"]) {
      expect(code(tpl(writer, { blocks: [], page: { footer: { left: "{{ v }}" } } }), value)).toBe("VALUE_PAGE_FIELD");
    }
  });

  it.each(["{Page}", "{pagex}", "x{ page }", "{date:}", "{date:a{b}", "no braces"])("keeps %s, which is only brace-shaped", (value) => {
    const out = render(tpl("fine-print", { blocks: [], page: { footer: { left: "{{ v }}" } } }) as never, { v: value }) as never as { page: { footer: { left: string } } };
    expect(out.page.footer.left).toBe(value);
  });

  it("takes tokens in every fine-print band slot, and refuses a token value there", () => {
    for (const key of BANDS) {
      for (const slot of ["left", "center", "right"]) {
        const template = tpl("fine-print", { blocks: [], page: { [key]: { [slot]: "{{ v }}" } } });
        expect(code(template, "Ada")).toBeNull();
        expect(code(template, "{section}")).toBe("VALUE_PAGE_FIELD");
      }
    }
  });

  it("takes tokens in a band on a fine-print break, and refuses a token value there", () => {
    for (const key of BANDS) {
      const template = tpl("fine-print", { blocks: [{ type: "pageBreak", page: { orientation: "landscape", [key]: { left: "{{ v }}" } } }] });
      expect(code(template, "Ada")).toBeNull();
      expect(code(template, "{pages}")).toBe("VALUE_PAGE_FIELD");
    }
  });

  it.each(["fine-print", "last-word"])("refuses it in a blocks band, in a table cell too (%s)", (writer) => {
    const para = { type: "paragraph", runs: [{ text: "{{ v }}" }] };
    const cell = { type: "table", rows: [{ cells: [{ blocks: [para] }] }] };
    for (const block of [para, cell]) {
      expect(code(tpl(writer, { blocks: [], page: { footer: { blocks: [block] } } }), "see {page}")).toBe("VALUE_PAGE_FIELD");
    }
  });

  it("keeps a token-shaped value literal in a body run: only a band is live", () => {
    expect(code(tpl("fine-print", { blocks: [{ type: "paragraph", runs: [{ text: "{{ v }}" }] }] }), "{page}")).toBeNull();
  });

  it("keeps page geometry on a break closed to tokens", () => {
    expect(code(tpl("fine-print", { blocks: [{ type: "pageBreak", page: { orientation: "{{ v }}" } }] }), "landscape")).not.toBeNull();
  });
});
