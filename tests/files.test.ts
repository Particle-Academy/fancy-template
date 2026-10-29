/**
 * Office-file templates (SPEC.md section 12) are PHP-only so far. This leg must
 * refuse LOUDLY and by name -- never return the input, never return nothing.
 */
import { describe, expect, it } from "vitest";
import { FileTemplatesNotImplementedError, inspectFile, renderFile, TemplateError } from "../src/index";

// A zip's first bytes: what the function receives does not matter, only that
// it refuses rather than returning anything.
const docx = new Uint8Array([0x50, 0x4b, 0x03, 0x04, 0x14, 0x00]);

function thrown(fn: () => unknown): unknown {
  try {
    fn();
  } catch (e) {
    return e;
  }
  throw new Error("expected a throw");
}

describe("inspectFile / renderFile in TypeScript", () => {
  it("inspectFile throws FileTemplatesNotImplementedError", () => {
    const e = thrown(() => inspectFile(docx));
    expect(e).toBeInstanceOf(FileTemplatesNotImplementedError);
    expect((e as FileTemplatesNotImplementedError).code).toBe("FILE_NOT_IMPLEMENTED");
    expect((e as FileTemplatesNotImplementedError).operation).toBe("inspectFile");
    expect((e as Error).name).toBe("FileTemplatesNotImplementedError");
  });

  it("renderFile throws FileTemplatesNotImplementedError, and is not a template refusal", () => {
    const e = thrown(() => renderFile(docx, { client: "Ada" }));
    expect(e).toBeInstanceOf(FileTemplatesNotImplementedError);
    expect(e).not.toBeInstanceOf(TemplateError);
    expect((e as FileTemplatesNotImplementedError).operation).toBe("renderFile");
    expect((e as Error).message).toContain("SPEC.md section 12");
  });
});
