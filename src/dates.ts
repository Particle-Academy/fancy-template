/**
 * The `date` type and the `date:"pattern"` filter (SPEC.md sections 3 and 7).
 *
 * English names only, no time-zone database, no Intl, never `Date`: a
 * certificate's date is the date the issuer wrote, and an offset is carried but
 * never applied. `new Date("2026-10-27")` is midnight UTC, which is the previous
 * day in every time zone west of Greenwich -- exactly the bug this avoids.
 */

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];

/** Pattern letter => allowed run lengths. */
const RUNS: Record<string, readonly number[]> = { y: [2, 4], M: [1, 2, 3, 4], d: [1, 2], E: [3, 4], H: [1, 2], h: [1, 2], m: [2], s: [2], a: [1] };

export interface CivilDate {
  y: number;
  m: number;
  d: number;
  H: number;
  i: number;
  s: number;
}

export type Piece = ["lit", string] | [string, number];

/*
 * Data, not template text, and anchored with no nested quantifier, so it is
 * linear. `[0-9]`, never `\d`: in some engines `\d` matches every Unicode digit.
 */
const ISO = /^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:T([0-9]{2}):([0-9]{2})(?::([0-9]{2})(?:\.[0-9]+)?)?(Z|[+-]([0-9]{2}):([0-9]{2}))?)?$/;

/** Parse an ISO date or date-time. Null when it is not one, or not a real calendar date and time. */
export function parseDate(value: unknown): CivilDate | null {
  if (typeof value !== "string") return null;
  const m = ISO.exec(value);
  if (m === null) return null;
  const y = Number(m[1]);
  const mo = Number(m[2]);
  const d = Number(m[3]);
  const H = m[4] !== undefined ? Number(m[4]) : 0;
  const i = m[5] !== undefined ? Number(m[5]) : 0;
  const s = m[6] !== undefined ? Number(m[6]) : 0;

  if (mo < 1 || mo > 12 || d < 1 || d > daysIn(y, mo)) return null;
  if (H > 23 || i > 59 || s > 59) return null;
  if (m[8] !== undefined && (Number(m[8]) > 23 || Number(m[9]) > 59)) return null;
  return { y, m: mo, d, H, i, s };
}

const isAsciiAlpha = (c: string): boolean => (c >= "A" && c <= "Z") || (c >= "a" && c <= "z");

/** Compile a pattern to pieces, or null if invalid. A forward-only scan; quotes are literal text, '' is one quote. */
export function compilePattern(pattern: string): Piece[] | null {
  const out: Piece[] = [];
  const n = pattern.length;
  let i = 0;
  while (i < n) {
    const c = pattern[i]!;
    if (c === "'") {
      if (i + 1 < n && pattern[i + 1] === "'") {
        out.push(["lit", "'"]);
        i += 2;
        continue;
      }
      i++;
      let lit = "";
      for (;;) {
        if (i >= n) return null;
        if (pattern[i] === "'") {
          if (i + 1 < n && pattern[i + 1] === "'") {
            lit += "'";
            i += 2;
            continue;
          }
          i++;
          break;
        }
        lit += pattern[i++];
      }
      out.push(["lit", lit]);
      continue;
    }
    if (isAsciiAlpha(c)) {
      let run = 1;
      while (i + run < n && pattern[i + run] === c) run++;
      const allowed = Object.hasOwn(RUNS, c) ? RUNS[c]! : null;
      if (allowed === null || !allowed.includes(run)) return null;
      out.push([c, run]);
      i += run;
      continue;
    }
    out.push(["lit", c]);
    i++;
  }
  return out;
}

const pad2 = (n: number): string => String(n).padStart(2, "0");

export function formatDate(date: CivilDate, pieces: Piece[]): string {
  let out = "";
  for (const [kind, value] of pieces) {
    switch (kind) {
      case "lit":
        out += value as string;
        break;
      case "y":
        out += value === 4 ? String(date.y).padStart(4, "0") : pad2(date.y % 100);
        break;
      case "M":
        out += value === 4 ? MONTHS[date.m - 1] : value === 3 ? MONTHS[date.m - 1]!.slice(0, 3) : value === 2 ? pad2(date.m) : String(date.m);
        break;
      case "d":
        out += value === 2 ? pad2(date.d) : String(date.d);
        break;
      case "E": {
        const day = DAYS[weekday(date)]!;
        out += value === 4 ? day : day.slice(0, 3);
        break;
      }
      case "H":
        out += value === 2 ? pad2(date.H) : String(date.H);
        break;
      case "h":
        out += value === 2 ? pad2(twelve(date.H)) : String(twelve(date.H));
        break;
      case "m":
        out += pad2(date.i);
        break;
      case "s":
        out += pad2(date.s);
        break;
      case "a":
        out += date.H < 12 ? "AM" : "PM";
        break;
    }
  }
  return out;
}

function twelve(hour: number): number {
  const h = hour % 12;
  return h === 0 ? 12 : h;
}

/** 0 = Monday, from days since the civil epoch (Howard Hinnant's algorithm). */
function weekday(date: CivilDate): number {
  const y = date.y - (date.m <= 2 ? 1 : 0);
  const era = Math.trunc((y >= 0 ? y : y - 399) / 400);
  const yoe = y - era * 400;
  const mp = (date.m + 9) % 12;
  const doy = Math.trunc((153 * mp + 2) / 5) + date.d - 1;
  const doe = yoe * 365 + Math.trunc(yoe / 4) - Math.trunc(yoe / 100) + doy;
  const days = era * 146097 + doe - 719468;
  // 1970-01-01 was a Thursday (3).
  return (((days % 7) + 7 + 3) % 7);
}

function daysIn(y: number, m: number): number {
  if (m === 2) return y % 4 === 0 && (y % 100 !== 0 || y % 400 === 0) ? 29 : 28;
  return [4, 6, 9, 11].includes(m) ? 30 : 31;
}
