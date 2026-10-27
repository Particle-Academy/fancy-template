"""The Python writers, for scripts/parity/writers.py: each from its own checkout's
source in the envelope (repos/<writer>-py/src). All three have zero dependencies.

    python -I write_python.py <writer> <in.json> <out>   validate, then write the bytes;
                                                          prints {"errors": [...]}
"""

from __future__ import annotations

import importlib
import json
import sys
from pathlib import Path

REPOS = Path(__file__).resolve().parents[3]


def main() -> int:
    writer, src, out = sys.argv[1:4]
    sys.path.insert(0, str(REPOS / f"{writer}-py" / "src"))
    module = importlib.import_module(writer.replace("-", "_"))
    doc = json.loads(Path(src).read_text(encoding="utf-8"))
    errors = module.validate(doc)
    Path(out).write_bytes(module.to_bytes(doc))
    sys.stdout.write(json.dumps({"errors": errors}, ensure_ascii=False, default=str))
    return 0


if __name__ == "__main__":
    sys.exit(main())
