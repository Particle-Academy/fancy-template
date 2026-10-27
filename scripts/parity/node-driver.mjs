// The TypeScript leg's side of the cross-leg parity harness (php/tests/parity.php).
//
// Runs the BUILT package (dist/), which is what npm ships, against every
// template/* fixture row and every corpus case, and writes one line per case:
//
//     <id> TAB <canonical JSON of the result>
//
// Canonical JSON is JSON.stringify's spelling (numbers as Number#toString,
// strings with only the mandatory escapes, keys in output order), and the PHP and
// Python drivers write the same spelling, so the harness compares DOCUMENTS
// byte for byte rather than three encoders' opinions about `3.0`.
//
// The case runner is the same shape as tests/runner.ts, against dist/ instead of
// src/; it is repeated here because Node cannot import the TypeScript source
// directly.
//
//   node scripts/parity/node-driver.mjs --out <file> [--render <dir>]

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { loadSuite } from "@particle-academy/fancy-conformance";
import { inspect, preview, render, TemplateError, tokenize, validateData } from "../../dist/index.js";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
export const SUITES = ["template/tokenize", "template/resolve", "template/filters", "template/directives", "template/limits", "template/profiles", "template/inspect"];

function runCase(c) {
  const input = c.input;
  const options = input.options ?? {};
  switch (c.fn) {
    case "tokenize":
      try {
        return { ok: true, parts: tokenize(input.text, options) };
      } catch (e) {
        if (e instanceof TemplateError) return { ok: false, code: e.code };
        throw e;
      }
    case "render":
    case "preview":
      try {
        const document = c.fn === "render" ? render(input.template, input.data, options) : preview(input.template, options);
        return { ok: true, document };
      } catch (e) {
        if (e instanceof TemplateError) return { ok: false, code: e.code, path: e.path };
        throw e;
      }
    case "validateData":
      return { errors: validateData(input.template, input.data, options) };
    case "inspect":
      return { issues: inspect(input.template, options).issues.map((i) => ({ code: i.code, path: i.path })) };
    default:
      throw new Error(`unknown fn ${c.fn}`);
  }
}

export function canon(v) {
  if (v === null) return "null";
  if (typeof v === "boolean" || typeof v === "number") return String(v);
  if (typeof v === "string") return JSON.stringify(v);
  if (Array.isArray(v)) return "[" + v.map(canon).join(",") + "]";
  return "{" + Object.entries(v).map(([k, x]) => JSON.stringify(k) + ":" + canon(x)).join(",") + "}";
}

function corpusCases() {
  const corpus = JSON.parse(fs.readFileSync(path.join(ROOT, "examples/corpus.json"), "utf8"));
  return corpus.cases.map((c) => {
    const template = JSON.parse(fs.readFileSync(path.join(ROOT, "examples", c.template), "utf8"));
    return c.preview
      ? { id: `corpus/${c.id}`, fn: "preview", input: { template } }
      : { id: `corpus/${c.id}`, fn: "render", input: { template, data: c.data } };
  });
}

const args = process.argv.slice(2);
const out = args[args.indexOf("--out") + 1];
const renderDir = args.includes("--render") ? args[args.indexOf("--render") + 1] : null;

const lines = [];
for (const suite of SUITES) {
  for (const c of loadSuite(suite).cases) {
    let result;
    try {
      result = canon(runCase(c));
    } catch (e) {
      result = `THREW ${e instanceof Error ? e.message : String(e)}`.replaceAll("\n", " ");
    }
    lines.push(`${suite}/${c.id}\t${result}`);
  }
}
for (const c of corpusCases()) {
  const result = runCase(c);
  lines.push(`${c.id}\t${canon(result)}`);
  if (renderDir && result.ok) {
    fs.mkdirSync(renderDir, { recursive: true });
    fs.writeFileSync(path.join(renderDir, `${c.id.replace("corpus/", "").replaceAll("/", "__")}.node.json`), JSON.stringify(result.document));
  }
}
fs.writeFileSync(out, lines.join("\n") + "\n");
