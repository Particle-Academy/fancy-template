import DARK_SLIDE from "../profiles/dark-slide.json" with { type: "json" };
import FINE_PRINT from "../profiles/fine-print.json" with { type: "json" };
import HOLY_SHEET from "../profiles/holy-sheet.json" with { type: "json" };
import LAST_WORD from "../profiles/last-word.json" with { type: "json" };

/**
 * A writer profile (SPEC.md section 9): which string fields may hold tokens and
 * how a value is encoded there. The profiles are DATA in `profiles/`, shared by
 * every implementation -- this leg imports the very files the PHP and Python
 * legs read, so there is no second copy to drift.
 *
 * An allowlist. A field that matches no pattern refuses tokens, so a writer
 * gaining a new field (a link, a font, a URL) is closed to data by default.
 */

export type Encoding = "text" | "band" | "markup" | "markdown" | "cell" | "image";

/** The encodings this implementation has built. A profile needing any other is not offered. */
export const IMPLEMENTED_ENCODINGS: readonly string[] = ["text", "band", "markup", "markdown", "cell", "image"];

export interface FieldRule {
  path: string;
  encode: Encoding;
  mime?: string[];
}

interface ProfileData {
  writer: string;
  fields: { path: string; encode: string; mime?: string[] }[];
}

const SOURCES: Record<string, ProfileData> = {
  "dark-slide": DARK_SLIDE,
  "fine-print": FINE_PRINT,
  "holy-sheet": HOLY_SHEET,
  "last-word": LAST_WORD,
};

export class Profile {
  private static readonly cache = new Map<string, Profile | null>();

  private constructor(
    readonly writer: string,
    private readonly fields: readonly (FieldRule & { pattern: string[] })[],
  ) {}

  /** The profile for a writer, or null when unknown or not implemented here. */
  static for(writer: unknown): Profile | null {
    if (typeof writer !== "string" || !/^[a-z][a-z-]*$/.test(writer) || !Object.hasOwn(SOURCES, writer)) return null;
    const cached = Profile.cache.get(writer);
    if (cached !== undefined) return cached;

    const data = SOURCES[writer]!;
    const usable = data.fields.every((f) => IMPLEMENTED_ENCODINGS.includes(f.encode));
    const profile = usable
      ? new Profile(writer, data.fields.map((f) => ({ ...(f as FieldRule), pattern: f.path.split(".") })))
      : null;
    Profile.cache.set(writer, profile);
    return profile;
  }

  /** The field rule for a string at `segments` inside `document`, or null. First match wins. */
  field(segments: readonly string[]): FieldRule | null {
    for (const f of this.fields) {
      if (matches(f.pattern, 0, segments, 0)) return f;
    }
    return null;
  }
}

/** Glob match: `*` is one segment, `**` zero or more. Patterns come from this package, never from a template. */
function matches(pattern: readonly string[], p: number, path: readonly string[], s: number): boolean {
  if (p === pattern.length) return s === path.length;
  if (pattern[p] === "**") {
    for (let k = s; k <= path.length; k++) {
      if (matches(pattern, p + 1, path, k)) return true;
    }
    return false;
  }
  if (s === path.length) return false;
  return (pattern[p] === "*" || pattern[p] === path[s]) && matches(pattern, p + 1, path, s + 1);
}
