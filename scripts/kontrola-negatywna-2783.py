#!/usr/bin/env python3
"""#2783: usunięcie blokady odczytu musi oblać oba przeploty opakowań.

Uruchamia się po zielonej grupie `dwa-polaczenia` na jej własnej bazie.
Lokalnie wymaga jawnej zgody i portu izolowanego PG; przywraca bajty i mtime.
"""

import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from kontrola_przyczyny import POTWIERDZONA, WynikTestu, czytaj_junit, werdykt


ROOT = Path(__file__).resolve().parent.parent
TEST_FILE = "tests/Dwa/DwaOpakowaniaPrzeplotTest.php"
OLD = ("->where('user_id', $produkt->user_id)\n"
       "                ->lockForUpdate()\n"
       "                ->first(['id', 'first_package_id'")
NEW = ("->where('user_id', $produkt->user_id)\n"
       "                ->first(['id', 'first_package_id'")
CASES = (
    ("app/Domain/Pantry/ZmienTerminProduktu.php",
     "test_awans_b_przed_starym_zapisem_a_blokuje_odczyt_i_odmawia_bez_zmiany_b",
     r"ODCISK_2783_WYS_CZYTA_POD_BLOKADA"),
    ("app/Domain/Pantry/DrugieOpakowanieProduktu.php",
     "test_zapis_a_przed_awansem_b_blokuje_awans_a_po_zatwierdzeniu_zachowuje_b",
     r"ODCISK_2783_AWANS_CZYTA_POD_BLOKADA"),
)


def sprawdz_cel() -> None:
    if os.environ.get("DB_URL"):
        raise SystemExit("Kontrola #2783 odmawia DB_URL: mógłby wskazać inną bazę niż DB_DATABASE.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2783_LOKALNIE") != "1":
            raise SystemExit("Kontrola #2783 lokalnie wymaga KUKING_KONTROLA_2783_LOKALNIE=1.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise SystemExit("Kontrola #2783 wymaga własnego lokalnego PostgreSQL poza portem 5432.")

    if re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", os.environ.get("DB_DATABASE", "")) is None:
        raise SystemExit("Kontrola #2783 wymaga bazy kuking_race albo kuking_race_<sufiks>.")


sprawdz_cel()
if sys.argv[1:] == ["--sprawdz-cel"]:
    raise SystemExit(0)


def run_test(method: str) -> WynikTestu:
    with tempfile.TemporaryDirectory(prefix="kuking-2783-junit-") as directory:
        report = Path(directory) / "junit.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        result = subprocess.run(
            ["php", "vendor/bin/phpunit", TEST_FILE, "--group=dwa-polaczenia",
             "--filter=" + method, "--log-junit", str(report), "--colors=never"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90, check=False,
        )
        junit = report.read_text(encoding="utf-8", errors="replace") if report.is_file() else None
    return WynikTestu(result.returncode, result.stdout, junit)


def positive(method: str) -> None:
    result = run_test(method)
    if result.junit is None:
        raise RuntimeError(method + ": brak raportu JUnit z dodatniego przebiegu. " + result.wyjscie)
    number, failures = czytaj_junit(result.junit)
    if result.kod != 0 or number != 1 or failures:
        raise RuntimeError(method + ": oczekiwano jednego zielonego testu. " + result.wyjscie)


for filename, method, marker in CASES:
    path = ROOT / filename
    original = path.read_bytes()
    mtime_ns = path.stat().st_mtime_ns
    text = original.decode("utf-8")
    if text.count(OLD) != 1:
        raise RuntimeError(filename + ": kotwica blokady nie występuje dokładnie raz.")

    positive(method)
    try:
        path.write_bytes(text.replace(OLD, NEW, 1).encode("utf-8"))
        result = run_test(method)
        verdict = werdykt(method, marker, result)
        if verdict.werdykt != POTWIERDZONA:
            raise RuntimeError(filename + ": mutant oblał z niewłaściwej przyczyny: "
                               + verdict.powod + "\n" + result.wyjscie)
    finally:
        path.write_bytes(original)
        os.utime(path, ns=(path.stat().st_atime_ns, mtime_ns))
        if path.read_bytes() != original or path.stat().st_mtime_ns != mtime_ns:
            raise RuntimeError(filename + ": źródło nie zostało dokładnie przywrócone.")
    positive(method)
    print(filename + ": 1 asercja z własnym markerem oblała po mutacji; test po przywróceniu zielony.", flush=True)

print("Kontrola ujemna #2783: oba przeploty potwierdzone.", flush=True)
