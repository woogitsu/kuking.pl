#!/usr/bin/env python3
"""#2827: fizyczna mutacja nasłuchu toggle na izolowanej kopii modułu."""

from pathlib import Path
import shutil
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "resources/js/dyktowanie.js"
TEST = ROOT / "resources/js/dyktowanie-dom.test.mjs"
ANCHOR = b"formularzOdpowiedzi?.addEventListener('toggle', poZamknieciu);"
MUTANT = b"// mutant #2827: zamkniecie nie konczy rozpoznawania"
MARKER = "DYKTOWANIE_2827_ZAMKNIECIE"


def run(directory: Path):
    result = subprocess.run(
        ["node", "--test", "dyktowanie-dom.test.mjs"],
        cwd=directory, capture_output=True, text=True, timeout=30, check=False,
    )
    return result.returncode, result.stdout + result.stderr


def main():
    if shutil.which("node") is None:
        raise RuntimeError("Brak Node.js; kontrola #2827 nie została wykonana.")
    original = SOURCE.read_bytes()
    mtime = SOURCE.stat().st_mtime_ns
    if original.count(ANCHOR) != 1:
        raise RuntimeError("Kotwica zamknięcia odpowiedzi musi wystąpić dokładnie raz.")

    with tempfile.TemporaryDirectory(prefix="kuking-2827-") as scratch:
        directory = Path(scratch)
        (directory / "package.json").write_text('{"type":"module"}', encoding="utf-8")
        (directory / TEST.name).write_bytes(TEST.read_bytes())
        module = directory / SOURCE.name
        module.write_bytes(original)
        code, log = run(directory)
        if code != 0 or "fail 0" not in log or "pass 19" not in log:
            raise RuntimeError("Test bazowy #2827 nie przeszedł:\n" + log)

        module.write_bytes(original.replace(ANCHOR, MUTANT, 1))
        code, log = run(directory)
        if code == 0 or MARKER not in log or "fail 1" not in log or "pass 18" not in log:
            raise RuntimeError("Mutant #2827 nie oblał wyłącznie właściwego testu:\n" + log)

        module.write_bytes(original)
        code, log = run(directory)
        if code != 0 or "fail 0" not in log or "pass 19" not in log:
            raise RuntimeError("Po odtworzeniu test #2827 nie przeszedł:\n" + log)

    if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != mtime:
        raise RuntimeError("Źródło #2827 zostało zmienione przez kontrolę.")
    print("#2827: 19 PASS → mutant 1 FAIL z markerem → 19 PASS; źródło nietknięte.")


if __name__ == "__main__":
    main()
