/**
 * Every refusal this package makes: a stable code, a JSON Pointer into the
 * TEMPLATE, and optionally the variable PATH a token named (SPEC.md section 6).
 *
 * Never a data value, in the message or anywhere else (plan section 5). A
 * certificate's recipient name is personal data, and an error is the thing that
 * gets logged, shown to an admin, and pasted into an issue.
 */
export class TemplateError extends Error {
  readonly code: string;
  readonly path: string;
  readonly variable: string | undefined;

  constructor(code: string, path: string, variable?: string) {
    const where = path === "" ? "the root" : path;
    const what = variable === undefined ? "" : ` (variable \`${variable}\`)`;
    super(`${code} at ${where}${what}`);
    this.name = "TemplateError";
    this.code = code;
    this.path = path;
    this.variable = variable;
  }

  toJSON(): Issue {
    return this.variable === undefined
      ? { code: this.code, path: this.path }
      : { code: this.code, path: this.path, variable: this.variable };
  }
}

/** One `inspect` issue or one thrown error, as data. */
export interface Issue {
  code: string;
  path: string;
  variable?: string;
}

/** One `validateData` error: a JSON Pointer into the DATA. */
export interface DataError {
  path: string;
  code: string;
}
