// The Node writers, for scripts/parity/writers.py: each from its own checkout's
// BUILT dist/ in the envelope (repos/<writer>-js/dist).
//
//   node write-node.mjs <writer> <in.json> <out>   validate, then write the bytes;
//                                                  prints {"errors": [...]}

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const repos = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../..");
const [writer, input, output] = process.argv.slice(2);
const { Agent } = await import(pathToFileURL(path.join(repos, `${writer}-js`, "dist", "index.js")).href);

const doc = JSON.parse(fs.readFileSync(input, "utf8"));
const errors = Agent.validate(doc);
fs.writeFileSync(output, Agent.toBytes(doc));
process.stdout.write(JSON.stringify({ errors }));
