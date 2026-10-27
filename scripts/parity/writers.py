"""Writer read-back for the realistic corpus (php/tests/parity.php --writers).

Every corpus document each template leg rendered (PHP, TypeScript, Python) goes
through every language of its REAL writer that exists in the envelope -- fine-print
(PHP only), last-word, dark-slide and holy-sheet (PHP, Node, Python) -- and the
file is read back:

- PDF through fine-print's own reader (unredacted),
- .docx / .pptx / .xlsx through ONE neutral extractor here (stdlib zipfile + XML),
  so the instrument is the same for all three writer languages and is not any of
  the writers' own (lossy) readers. For .xlsx it records each cell's type and
  whether it holds a FORMULA, which is the property the holy-sheet profile exists
  to guarantee.

Then the extracted text is compared across all template-leg x writer-language
combinations of each case. A case passes when every combination extracts the
identical text.

    python -I scripts/parity/writers.py --rendered <dir>     (php/tests/parity.php passes the dir)
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import zipfile
from collections import Counter
from pathlib import Path
from xml.etree import ElementTree as ET

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
REPOS = ROOT.parent

LANGS = {
    "fine-print": ("php",),
    "last-word": ("php", "node", "python"),
    "dark-slide": ("php", "node", "python"),
    "holy-sheet": ("php", "node", "python"),
}
EXT = {
    "fine-print": "pdf",
    "last-word": "docx",
    "dark-slide": "pptx",
    "holy-sheet": "xlsx",
}

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
A = "{http://schemas.openxmlformats.org/drawingml/2006/main}"
S = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
R = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"
PR = "{http://schemas.openxmlformats.org/package/2006/relationships}"


def run(cmd: list[str], log: Path) -> str:
    """Output to a file, exit code checked: never a pipe for a verdict."""
    with open(log, "wb") as out:
        code = subprocess.run(
            cmd, cwd=ROOT, stdout=out, stderr=subprocess.STDOUT
        ).returncode
    text = log.read_text(encoding="utf-8", errors="replace")
    if code != 0:
        raise SystemExit(f"{' '.join(cmd)} exited {code}:\n{text}")
    return text


def write(writer: str, lang: str, doc: Path, out: Path) -> list[object]:
    log = out.with_suffix(out.suffix + ".log")
    if lang == "php":
        cmd = ["php", str(HERE / "write.php"), "write", writer, str(doc), str(out)]
    elif lang == "node":
        cmd = ["node", str(HERE / "write-node.mjs"), writer, str(doc), str(out)]
    else:
        cmd = [
            sys.executable,
            "-I",
            str(HERE / "write_python.py"),
            writer,
            str(doc),
            str(out),
        ]
    errors = json.loads(run(cmd, log))["errors"]
    return list(errors)


def _numbered(names: list[str], pattern: str) -> list[str]:
    found = [(int(m.group(1)), n) for n in names if (m := re.fullmatch(pattern, n))]
    return [n for _, n in sorted(found)]


def _para_text(p: ET.Element, ns: str) -> str:
    out = []
    for el in p.iter():
        if el.tag == ns + "t":
            out.append(el.text or "")
        elif el.tag in (ns + "tab",):
            out.append("\t")
        elif el.tag in (ns + "br",):
            out.append("\n")
    return "".join(out)


def extract_docx(z: zipfile.ZipFile) -> str:
    names = z.namelist()
    parts = [
        "word/document.xml",
        *_numbered(names, r"word/header(\d+)\.xml"),
        *_numbered(names, r"word/footer(\d+)\.xml"),
    ]
    lines = []
    for part in parts:
        root = ET.fromstring(z.read(part))
        lines.append(f"== {part}")
        lines.extend(_para_text(p, W) for p in root.iter(W + "p"))
    return "\n".join(lines)


def extract_pptx(z: zipfile.ZipFile) -> str:
    names = z.namelist()
    lines = []
    for part in [
        *_numbered(names, r"ppt/slides/slide(\d+)\.xml"),
        *_numbered(names, r"ppt/notesSlides/notesSlide(\d+)\.xml"),
    ]:
        root = ET.fromstring(z.read(part))
        lines.append(f"== {part}")
        lines.extend(_para_text(p, A) for p in root.iter(A + "p"))
    return "\n".join(lines)


def extract_xlsx(z: zipfile.ZipFile) -> str:
    shared: list[str] = []
    if "xl/sharedStrings.xml" in z.namelist():
        for si in ET.fromstring(z.read("xl/sharedStrings.xml")).iter(S + "si"):
            shared.append("".join(t.text or "" for t in si.iter(S + "t")))
    rels = {
        r.get("Id"): r.get("Target", "")
        for r in ET.fromstring(z.read("xl/_rels/workbook.xml.rels")).iter(
            PR + "Relationship"
        )
    }
    lines = []
    for sheet in ET.fromstring(z.read("xl/workbook.xml")).iter(S + "sheet"):
        target = rels[sheet.get(R + "id")].lstrip("/")
        part = target if target.startswith("xl/") else "xl/" + target
        lines.append(f"== sheet {sheet.get('name')}")
        for c in ET.fromstring(z.read(part)).iter(S + "c"):
            kind = c.get("t") or "n"
            f = c.find(S + "f")
            v = c.find(S + "v")
            if kind == "s" and v is not None:
                value = shared[int(v.text or "0")]
            elif kind == "inlineStr":
                value = "".join(t.text or "" for t in c.iter(S + "t"))
            else:
                value = v.text if v is not None and v.text is not None else ""
            formula = f" FORMULA[{f.text}]" if f is not None else ""
            lines.append(f"{c.get('r')} {kind}{formula}: {value}")
    return "\n".join(lines)


def extract(path: Path, writer: str) -> str:
    if writer == "fine-print":
        pages = json.loads(
            run(
                ["php", str(HERE / "write.php"), "pdftext", str(path)],
                path.with_suffix(".txt.log"),
            )
        )
        return "\n\f\n".join(p.replace("\r\n", "\n") for p in pages)
    with zipfile.ZipFile(path) as z:
        return {
            "last-word": extract_docx,
            "dark-slide": extract_pptx,
            "holy-sheet": extract_xlsx,
        }[writer](z)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--rendered", required=True)
    args = parser.parse_args()
    rendered = Path(args.rendered)
    out_dir = rendered / "written"
    out_dir.mkdir(exist_ok=True)

    corpus = json.loads((ROOT / "examples" / "corpus.json").read_text(encoding="utf-8"))
    print(
        "WRITER READ-BACK (template leg x writer language; text extracted and compared)"
    )
    print(
        f"{'case':<22} {'writer':<11} {'files':>5}  {'valid':>7}  {'identical text':>14}"
    )
    total_files = total_same = 0
    failed = False
    for case in corpus["cases"]:
        name = case["id"].replace("/", "__")
        writer = json.loads(
            (ROOT / "examples" / case["template"]).read_text(encoding="utf-8")
        )["writer"]
        texts: dict[str, str] = {}
        invalid = 0
        for leg in ("php", "node", "python"):
            doc = rendered / f"{name}.{leg}.json"
            for lang in LANGS[writer]:
                out = out_dir / f"{name}.{leg}.{lang}.{EXT[writer]}"
                errors = write(writer, lang, doc, out)
                invalid += 1 if errors else 0
                if errors:
                    print(
                        f"  INVALID for {writer} {lang}: {name}.{leg}: {json.dumps(errors)[:300]}"
                    )
                texts[f"{leg}->{lang}"] = extract(out, writer)
                (out.with_suffix(out.suffix + ".txt")).write_text(
                    texts[f"{leg}->{lang}"], encoding="utf-8"
                )
        counts = Counter(texts.values())
        majority, same = counts.most_common(1)[0]
        n = len(texts)
        total_files += n
        total_same += same
        ok = same == n and invalid == 0
        failed |= not ok
        print(
            f"{case['id']:<22} {writer:<11} {n:>5}  {n - invalid:>3}/{n:<3}  {same:>6}/{n:<7}{'' if ok else '  <-- DIFFERS'}"
        )
        if same != n:
            for combo, text in texts.items():
                if text != majority:
                    print(f"    differs: {combo} (see {out_dir})")
    print(
        f"\nTOTAL identical extracted text: {total_same}/{total_files} = {100 * total_same / total_files:.1f}%"
    )
    print(f"written files and their extracted text: {out_dir}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
