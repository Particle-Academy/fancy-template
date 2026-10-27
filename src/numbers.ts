/**
 * Numbers to text, the same in every runtime (SPEC.md sections 6 and 7).
 *
 * Everything starts from the SHORTEST round-trip decimal digits of the double
 * (`toExponential()` with no argument gives exactly those) and then works on
 * the digits as a decimal string. `toFixed` and `Intl` are never used: the
 * first rounds the binary double (`(2.675).toFixed(2)` is "2.67", the spec says
 * "2.68"), the second is locale data that differs between runtimes.
 */

/** Currency => [minor-unit exponent, prefix symbol or null for "CODE "]. */
export const CURRENCIES: Readonly<Record<string, readonly [number, string | null]>> = Object.freeze({
  USD: [2, "$"], EUR: [2, "€"], GBP: [2, "£"], JPY: [0, "¥"], INR: [2, "₹"], KRW: [0, "₩"],
  CAD: [2, null], AUD: [2, null], NZD: [2, null], CHF: [2, null], CNY: [2, null], BRL: [2, null],
  MXN: [2, null], SEK: [2, null], NOK: [2, null], DKK: [2, null], PLN: [2, null], SGD: [2, null],
  HKD: [2, null], ZAR: [2, null], CLP: [0, null], VND: [0, null], ISK: [0, null],
  KWD: [3, null], BHD: [3, null], JOD: [3, null], OMR: [3, null], TND: [3, null],
});

/**
 * Shortest round-trip decimal of a finite number: [negative, digits, point].
 * The value is 0.DIGITS x 10^point, with no leading or trailing zeros in
 * DIGITS ("0" for zero).
 */
export function shortest(value: number): [boolean, string, number] {
  if (value === 0) return [false, "0", 1];
  const negative = value < 0;
  const [mantissa, exponent] = Math.abs(value).toExponential().split("e") as [string, string];
  const digits = mantissa.replace(".", "").replace(/0+$/, "");
  return [negative, digits === "" ? "0" : digits, Number(exponent) + 1];
}

/** JavaScript's Number.prototype.toString, for a finite number. Here it is the thing itself. */
export function toJsString(value: number): string {
  return String(value);
}

/** `number:decimals` -- decimal rounding, half away from zero, comma groups. */
export function fixed(value: number, decimals: number): string {
  const [negative, digits, point] = shortest(value);

  let int: string;
  let frac: string;
  if (digits === "0") {
    int = "0";
    frac = "";
  } else if (point <= 0) {
    int = "0";
    frac = "0".repeat(-point) + digits;
  } else if (point >= digits.length) {
    int = digits + "0".repeat(point - digits.length);
    frac = "";
  } else {
    int = digits.slice(0, point);
    frac = digits.slice(point);
  }

  frac = frac.padEnd(decimals + 1, "0");
  const roundUp = frac[decimals]! >= "5";
  let kept = int + frac.slice(0, decimals);
  if (roundUp) kept = increment(kept);

  let intPart = kept.slice(0, kept.length - decimals).replace(/^0+/, "");
  const fracPart = kept.slice(kept.length - decimals);
  if (intPart === "") intPart = "0";

  const isZero = (intPart + fracPart).replace(/0/g, "") === "";
  const out = group(intPart) + (decimals > 0 ? "." + fracPart : "");
  return (negative && !isZero ? "-" : "") + out;
}

/** `money:CUR` -- integer minor units, never through a float. */
export function money(minor: number, currency: string): string {
  const [exponent, symbol] = CURRENCIES[currency]!;
  const negative = minor < 0;
  const digits = String(Math.abs(minor)).padStart(exponent + 1, "0");
  const int = digits.slice(0, digits.length - exponent);
  const frac = exponent > 0 ? "." + digits.slice(digits.length - exponent) : "";
  const prefix = symbol ?? `${currency} `;
  return (negative ? "-" : "") + prefix + group(int) + frac;
}

function increment(digits: string): string {
  const d = digits.split("");
  let i = d.length - 1;
  while (i >= 0) {
    if (d[i] === "9") {
      d[i] = "0";
      i--;
      continue;
    }
    d[i] = String(Number(d[i]) + 1);
    return d.join("");
  }
  return "1" + d.join("");
}

function group(int: string): string {
  let out = "";
  const n = int.length;
  for (let i = 0; i < n; i++) {
    if (i > 0 && (n - i) % 3 === 0) out += ",";
    out += int[i];
  }
  return out;
}
