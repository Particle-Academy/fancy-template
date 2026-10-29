/**
 * Office files as templates (SPEC.md section 12): `inspectFile` and
 * `renderFile`. Implemented in the PHP leg only, so far.
 *
 * These exist in this leg so that a caller gets a LOUD, named refusal rather
 * than a missing export or a silent no-op: a renderFile that returned its
 * input unchanged would hand back a document full of `{{ placeholders }}` and
 * look like success. The behaviour to implement is SPEC.md section 12; the
 * shared rows and fixtures are in `fixtures/files/`.
 */
import type { Options } from "./limits";

/** Options for inspectFile / renderFile (SPEC.md section 12.2). */
export interface FileOptions extends Options {
  /** Declarations, as an envelope's `variables` (SPEC.md section 3). Without them, names are inferred. */
  variables?: Record<string, unknown>;
  /** Archive bounds (SPEC.md section 12.8). */
  fileLimits?: Partial<Record<"archiveBytes" | "entries" | "partBytes" | "expandedBytes" | "compressionRatio", number>>;
}

/**
 * Thrown by `inspectFile` and `renderFile` in this language: office-file
 * templates are implemented in PHP (`Template::inspectFile`), not yet here.
 * `code` is always `FILE_NOT_IMPLEMENTED`; `operation` names the function.
 */
export class FileTemplatesNotImplementedError extends Error {
  readonly code = "FILE_NOT_IMPLEMENTED";
  readonly operation: "inspectFile" | "renderFile";

  constructor(operation: "inspectFile" | "renderFile") {
    super(`${operation} is not yet implemented in the TypeScript leg of fancy-template (SPEC.md section 12; PHP has it).`);
    this.name = "FileTemplatesNotImplementedError";
    this.operation = operation;
  }
}

/** The placeholders in a .docx / .pptx / .xlsx. Not yet implemented here: always throws. */
export function inspectFile(_bytes: Uint8Array, _options?: FileOptions): never {
  throw new FileTemplatesNotImplementedError("inspectFile");
}

/** Fill a .docx / .pptx / .xlsx in place. Not yet implemented here: always throws. */
export function renderFile(_bytes: Uint8Array, _data: unknown, _options?: FileOptions): never {
  throw new FileTemplatesNotImplementedError("renderFile");
}
