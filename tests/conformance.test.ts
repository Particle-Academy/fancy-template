/**
 * The template/* tables, run against the TypeScript leg.
 *
 * The rows were published in fancy-conformance 0.35.0 BEFORE this leg existed,
 * and it was written against them and SPEC.md -- not against the PHP source.
 * Where the two disagreed, the spec won; see CHANGELOG.md.
 *
 * No row is skipped for any language since 0.36.0 dropped the 11 phase-6 PHP
 * skips; every row runs here and must pass.
 */
import { describe, expect, it } from "vitest";
import { formatSummary, deepEquals, loadSuite, runTable, suiteVersion } from "@particle-academy/fancy-conformance";
import COMPOSER from "../composer.json" with { type: "json" };
import PACKAGE from "../package.json" with { type: "json" };
import { runTemplateCase, type TemplateCase } from "./runner";

/**
 * The fixture set this leg runs against -- and, by the assertions below, the one
 * the PHP leg installs too. Rule 4 of fancy-conformance's runners/README.md:
 * print AND assert it.
 *
 * Moved deliberately, never automatically: a pin that follows disk asserts
 * nothing. Moving it means package.json + package-lock.json, composer.json's
 * EXACT require-dev constraint, PINNED_SUITE_VERSION here, in
 * php/tests/ConformanceTest.php and in python/tests/test_conformance.py, and the
 * CI checkout ref -- the tests on every side fail until all agree.
 */
const PINNED_SUITE_VERSION = "0.36.0";

const SUITES = [
  "template/tokenize",
  "template/resolve",
  "template/filters",
  "template/directives",
  "template/limits",
  "template/profiles",
  "template/inspect",
] as const;

describe("the pinned fixture set", () => {
  it("is the one installed, printed and asserted", () => {
    console.info(`fancy-conformance installed: ${suiteVersion()}, pinned: ${PINNED_SUITE_VERSION}`);
    expect(suiteVersion()).toBe(PINNED_SUITE_VERSION);
  });

  it("is what package.json and composer.json both declare", () => {
    // Node gets its pin from the COMMITTED lockfile, so the range keeps its
    // caret. PHP has no committed lockfile, so its constraint must be exact.
    expect(PACKAGE.devDependencies["@particle-academy/fancy-conformance"]).toBe(`^${PINNED_SUITE_VERSION}`);
    expect(COMPOSER["require-dev"]["particle-academy/fancy-conformance"]).toBe(PINNED_SUITE_VERSION);
  });

  it("keeps fancy-expr on the sibling range, never a caret", () => {
    expect(PACKAGE.dependencies["@particle-academy/fancy-expr"]).toBe(">=0.2.0 <2.0");
    expect(COMPOSER.require["particle-academy/fancy-expr"]).toBe(">=0.2.0 <2.0");
  });
});

for (const suite of SUITES) {
  describe(suite, () => {
    const cases = loadSuite(suite).cases as unknown as TemplateCase[];

    it("loaded the shared table", () => {
      // The vacuity guard: an empty array passes every assertion below.
      expect(cases.length).toBeGreaterThan(10);
    });

    it("prints the table summary, skips and all, and fails nothing", () => {
      const summary = runTable(suite, (c) => runTemplateCase(c as unknown as TemplateCase), { language: "node" });
      console.info(`\n${formatSummary(summary)}`);
      expect(summary.ok, formatSummary(summary)).toBe(true);
      expect(summary.skipped).toBe(0);
      expect(summary.passed).toBe(cases.length);
    });

    it("can fail: a runner returning nothing fails every row", () => {
      // The control for the comparison itself. If the loader's equality were
      // vacuous, the green above would mean nothing.
      const summary = runTable(suite, () => null, { language: "node" });
      expect(summary.passed).toBe(0);
      expect(summary.failed).toBe(cases.length);
    });

    for (const c of cases) {
      it(`${c.id}`, () => {
        const actual = runTemplateCase(c);
        expect(deepEquals(actual, c.expected), JSON.stringify(actual)).toBe(true);
      });
    }
  });
}
