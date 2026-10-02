#!/usr/bin/env python3
"""#2596: cofnięcie odmowy HTML w parametrze porcji oblewa test HTTP."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "scripts/przegladarka/skladniki-gotowania-server.mjs"
TEST = "scripts/przegladarka/skladniki-gotowania-http.test.mjs"
FIX = "if (!Number.isSafeInteger(porcje) || porcje < 1) {"
MUTANT = "if (false) {"
MARKER = "FIXTURE_2596_ODMAWIA_HTML"


def run(module=None):
    env = os.environ.copy()
    if module is not None:
        env["KUKING_SKLADNIKI_HTTP_SERVER"] = module.as_uri()
    result = subprocess.run(
        ["node", "--test", TEST], cwd=ROOT, env=env,
        capture_output=True, text=True, timeout=30, check=False,
    )
    return result.returncode, result.stdout + result.stderr


def main():
    if shutil.which("node") is None:
        raise RuntimeError("Brak Node.js; kontroli #2596 nie uruchomiono.")
    source = SOURCE.read_text(encoding="utf-8")
    if source.count(FIX) != 1:
        raise RuntimeError("Odmowa HTML #2596 nie występuje dokładnie raz.")

    code, log = run()
    if code != 0 or "pass 2" not in log or "fail 0" not in log:
        raise RuntimeError("Bazowy test HTTP #2596 nie przeszedł:\n" + log)

    with tempfile.TemporaryDirectory(prefix="kuking-2596-") as directory:
        module = Path(directory) / "skladniki-gotowania-server.mjs"
        module.write_text(source.replace(FIX, MUTANT, 1), encoding="utf-8")
        code, log = run(module)
    if code == 0 or MARKER not in log or "pass 1" not in log or "fail 1" not in log:
        raise RuntimeError("Mutant #2596 nie oblał wyłącznie odmowy HTML:\n" + log)

    code, log = run()
    if code != 0 or "pass 2" not in log or "fail 0" not in log:
        raise RuntimeError("Odtworzony test HTTP #2596 nie przeszedł:\n" + log)
    print("#2596: HTTP 4/8 PASS, mutant odbicia HTML FAIL z markerem, oryginał PASS.")


if __name__ == "__main__":
    main()
