#!/usr/bin/env python3
"""#2880: po zmianie decyzji jubilata nie powstaje spóźnione przypomnienie."""

import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from kontrola_przyczyny import POTWIERDZONA, WynikTestu, czytaj_junit, werdykt


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Console/Commands/PrzypomnijOUrodzinach.php"
TEST_FILE = "tests/Dwa/PrzypomnieniaUrodzinPoZmianieDecyzjiTest.php"
MUTANTS = (
    (
        "wyłączona widoczność",
        b"|| ! $aktualnySolenizant->birthday_visible_to_followers",
        b"|| false /* mutant 2880: visibility */",
        "test_wylaczenie_widocznosci_przed_zapisem_nie_tworzy_powiadomienia",
        r"URODZINY_2880_OFF_NIE_TWORZY",
    ),
    (
        "zmieniona data",
        b"|| ! Urodziny::czyDzis($aktualnySolenizant)",
        b"|| false /* mutant 2880: date */",
        "test_zmiana_daty_na_inny_dzien_przed_zapisem_nie_tworzy_powiadomienia",
        r"URODZINY_2880_INNY_DZIEN_NIE_TWORZY",
    ),
)


def sprawdz_cel() -> None:
    if os.environ.get("DB_URL"):
        raise SystemExit("Kontrola #2880 odmawia DB_URL: może wskazać inną bazę.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2880_LOKALNIE") != "1":
            raise SystemExit("Kontrola #2880 wymaga jawnego lokalnego opt-in.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise SystemExit("Kontrola #2880 wymaga izolowanego lokalnego PostgreSQL poza portem 5432.")
    if re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", os.environ.get("DB_DATABASE", "")) is None:
        raise SystemExit("Kontrola #2880 wymaga bazy kuking_race albo kuking_race_<sufiks>.")


sprawdz_cel()
if sys.argv[1:] == ["--sprawdz-cel"]:
    raise SystemExit(0)


def run_test(name: str) -> WynikTestu:
    with tempfile.TemporaryDirectory(prefix="kuking-2880-junit-") as directory:
        report = Path(directory) / "junit.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        result = subprocess.run(
            ["php", "vendor/bin/phpunit", TEST_FILE, "--group=dwa-polaczenia",
             "--filter=" + name, "--log-junit", str(report), "--colors=never"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90, check=False,
        )
        junit = report.read_text(encoding="utf-8", errors="replace") if report.is_file() else None
    return WynikTestu(result.returncode, result.stdout, junit)


def positive(name: str) -> None:
    result = run_test(name)
    if result.junit is None:
        raise RuntimeError("Brak JUnit w dodatnim przebiegu #2880: " + result.wyjscie)
    number, failures = czytaj_junit(result.junit)
    if result.kod != 0 or number != 1 or failures:
        raise RuntimeError("Dodatni przeplot #2880 nie przeszedł: " + result.wyjscie)


original = SOURCE.read_bytes()
mtime_ns = SOURCE.stat().st_mtime_ns
for label, old, new, test, marker in MUTANTS:
    if original.count(old) != 1:
        raise RuntimeError(label + ": kotwica #2880 nie występuje dokładnie raz.")
    positive(test)
    try:
        SOURCE.write_bytes(original.replace(old, new, 1))
        result = run_test(test)
        if result.junit is None or czytaj_junit(result.junit)[0] != 1:
            raise RuntimeError(label + ": mutant #2880 nie uruchomił dokładnie jednego testu.")
        verdict = werdykt(test, marker, result)
        if verdict.werdykt != POTWIERDZONA:
            raise RuntimeError(label + ": niewłaściwa przyczyna porażki: " + verdict.powod + "\n" + result.wyjscie)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(SOURCE.stat().st_atime_ns, mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != mtime_ns:
            raise RuntimeError("Nie przywrócono dokładnie źródła #2880.")
    positive(test)
    print(label + ": mutant oblał na własnym markerze, przywrócony przeplot przeszedł.", flush=True)
