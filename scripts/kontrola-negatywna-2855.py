#!/usr/bin/env python3
"""#2855: stara wizyta nie może wejść w nowy okres zgody po OFF→ON."""

import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from kontrola_przyczyny import POTWIERDZONA, WynikTestu, czytaj_junit, werdykt


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Recipes/OstatnioOgladane.php"
TEST_FILE = "tests/Dwa/OstatnioOgladanePoPonownymWlaczeniuTest.php"
TEST = "test_stare_zadanie_po_wylaczeniu_i_ponownym_wlaczeniu_nie_odtwarza_historii"
MUTANTS = (
    (
        "dawny warunek zapisu",
        b"AND u.ostatnio_ogladane_wlaczone_at = ?::timestamptz ",
        b"AND ?::timestamptz IS NOT NULL AND u.ostatnio_ogladane_wlaczone_at IS NOT NULL ",
        r"HISTORIA_2855_NIE_WRACA_PO_WLACZENIU",
    ),
    (
        "sekundowy znacznik zgody",
        b"->update(['ostatnio_ogladane_wlaczone_at' => now()->format('Y-m-d H:i:s.uP')]);",
        b"->update(['ostatnio_ogladane_wlaczone_at' => now()]);",
        r"HISTORIA_2855_MIKROSEKUNDY_OKRESU",
    ),
)

def sprawdz_cel() -> None:
    if os.environ.get("DB_URL"):
        raise SystemExit("Kontrola #2855 odmawia DB_URL: może wskazać inną bazę niż DB_DATABASE.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2855_LOKALNIE") != "1":
            raise SystemExit("Kontrola #2855 wymaga jawnego lokalnego opt-in.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise SystemExit("Kontrola #2855 wymaga izolowanego lokalnego PostgreSQL poza portem 5432.")

    if re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", os.environ.get("DB_DATABASE", "")) is None:
        raise SystemExit("Kontrola #2855 wymaga bazy kuking_race albo kuking_race_<sufiks>.")


sprawdz_cel()
if sys.argv[1:] == ["--sprawdz-cel"]:
    raise SystemExit(0)


def run_test() -> WynikTestu:
    with tempfile.TemporaryDirectory(prefix="kuking-2855-junit-") as directory:
        report = Path(directory) / "junit.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        result = subprocess.run(
            ["php", "vendor/bin/phpunit", TEST_FILE, "--group=dwa-polaczenia",
             "--filter=" + TEST, "--log-junit", str(report), "--colors=never"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90, check=False,
        )
        junit = report.read_text(encoding="utf-8", errors="replace") if report.is_file() else None
    return WynikTestu(result.returncode, result.stdout, junit)


def positive() -> None:
    result = run_test()
    if result.junit is None:
        raise RuntimeError("Brak JUnit w dodatnim przebiegu #2855: " + result.wyjscie)
    number, failures = czytaj_junit(result.junit)
    if result.kod != 0 or number != 1 or failures:
        raise RuntimeError("Dodatni przeplot #2855 nie przeszedł: " + result.wyjscie)


original = SOURCE.read_bytes()
mtime_ns = SOURCE.stat().st_mtime_ns
for name, old, new, marker in MUTANTS:
    if original.count(old) != 1:
        raise RuntimeError(name + ": kotwica #2855 nie występuje dokładnie raz.")
    positive()
    try:
        SOURCE.write_bytes(original.replace(old, new, 1))
        result = run_test()
        if result.junit is None or czytaj_junit(result.junit)[0] != 1:
            raise RuntimeError(name + ": mutant #2855 nie uruchomił dokładnie jednego testu.")
        verdict = werdykt(TEST, marker, result)
        if verdict.werdykt != POTWIERDZONA:
            raise RuntimeError(name + ": mutant #2855 oblał z niewłaściwej przyczyny: " + verdict.powod + "\n" + result.wyjscie)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(SOURCE.stat().st_atime_ns, mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != mtime_ns:
            raise RuntimeError("Nie przywrócono dokładnie źródła #2855.")
    positive()
    print(name + ": mutant oblał na własnym markerze, przywrócony przeplot przeszedł.", flush=True)
