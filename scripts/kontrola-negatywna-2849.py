"""#2849: DELETE wyłącznie po ID musi skasować odnowiony punkt i oblać test."""

import os
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Recipes/Odzyskiwanie/PrzedawnionePunktyOdzyskaniaSzkicu.php"
START = b"            ->whereIn('id', $kandydaci->all())\n            ->where(function ($q) use ($prog): void {"
END = b"            ->delete();"
MUTANT = b"            ->whereIn('id', $kandydaci->all())\n            ->delete();"
TEST = "test_odnowienie_po_wyborze_kandydata_chroni_ten_sam_punkt"
MARKER = "PUNKT_2849_ODNOWIONY"


def run(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    env["DB_URL"] = ""
    with tempfile.TemporaryDirectory(prefix="kuking-2849-junit-") as tmp:
        junit = Path(tmp) / "wynik.xml"
        result = subprocess.run(
            ["php", "-d", "opcache.enable_cli=0", "artisan", "test", "--group=dwa-polaczenia",
             "--filter=" + TEST, "--log-junit", str(junit), "--no-ansi"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=180, check=False,
        )
        print(result.stdout, flush=True)
        if not junit.is_file():
            raise RuntimeError("Brak raportu JUnit #2849.")
        cases = ET.parse(junit).findall(".//testcase")
        if len(cases) != 1 or TEST not in cases[0].attrib.get("name", ""):
            raise RuntimeError("JUnit nie potwierdza dokładnie jednego przeplotu #2849.")
        case = cases[0]
        if case.find("skipped") is not None or case.find("error") is not None:
            raise RuntimeError("Przeplot #2849 pominięty albo przerwany błędem środowiska.")
        failures = case.findall("failure")
        if expect_success:
            if result.returncode != 0 or failures:
                raise RuntimeError("Bazowy test #2849 nie przeszedł.")
        elif result.returncode == 0 or len(failures) != 1 or MARKER not in ET.tostring(failures[0], encoding="unicode"):
            raise RuntimeError("Mutant ID-only nie oblał przeplotu własnym markerem #2849.")


def main() -> None:
    database = os.environ.get("DB_DATABASE", "")
    if not re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", database) or os.environ.get("DB_URL"):
        raise RuntimeError("Wymagana jawna izolowana baza kuking_race* bez DB_URL.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2849_LOKALNIE") != "1":
            raise RuntimeError("Lokalna kontrola wymaga jawnego opt-in.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise RuntimeError("Lokalna kontrola wymaga własnego PostgreSQL poza portem 5432.")

    original = SOURCE.read_bytes()
    stat = SOURCE.stat()
    start = original.find(START)
    end = original.find(END, start + len(START))
    if original.count(START) != 1 or start < 0 or end < 0:
        raise RuntimeError("Kotwica warunkowego DELETE #2849 nie jest jednoznaczna.")
    run(expect_success=True)
    try:
        SOURCE.write_bytes(original[:start] + MUTANT + original[end + len(END):])
        run(expect_success=False)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("Nie przywrócono dokładnych bajtów i mtime #2849.")
    run(expect_success=True)
    print("#2849: mutant DELETE po ID oblał przeplot; kod po odtworzeniu przeszedł.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ET.ParseError, RuntimeError, subprocess.TimeoutExpired) as exc:
        raise SystemExit(f"Kontrola #2849 nie powiodła się: {exc}") from exc
