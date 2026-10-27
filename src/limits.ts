/**
 * The bounds from SPEC.md section 10 (plan section 5). Every one has a stable
 * error code and every one is host-overridable through `options.limits`.
 */
export const DEFAULT_LIMITS = Object.freeze({
  templateBytes: 262144,
  tokens: 10000,
  tokenLength: 512,
  directiveDepth: 8,
  iterations: 10000,
  outputNodes: 50000,
  outputBytes: 8388608,
  depth: 64,
});

export type Limits = { readonly [K in keyof typeof DEFAULT_LIMITS]: number };

export interface Options {
  limits?: Partial<Limits>;
}

export function limitsFrom(options: Options | undefined): Limits {
  const given: unknown = options?.limits ?? {};
  if (typeof given !== "object" || given === null || Array.isArray(given)) {
    throw new TypeError("options.limits must be an object");
  }
  const values: Record<string, number> = { ...DEFAULT_LIMITS };
  for (const [name, value] of Object.entries(given)) {
    if (!Object.hasOwn(DEFAULT_LIMITS, name)) {
      throw new TypeError(`Unknown limit \`${name}\``);
    }
    if (typeof value !== "number" || !Number.isSafeInteger(value) || value < 0) {
      throw new TypeError(`Limit \`${name}\` must be a non-negative integer`);
    }
    values[name] = value;
  }
  return values as unknown as Limits;
}
