"""#2811: bez sesyjnej blokady dwa wysłania tworzą osierocone Media.

Tylko CI lub jawnie wskazana lokalna baza wyścigów PostgreSQL 18.
Zachowuje bajty i mtime źródła, wymaga czerwieni własnego markera i PASS po
przywróceniu; fatal/no tests/błąd środowiska nie jest dowodem.
"""

import os
import subprocess
from pathlib import Path
import tempfile
import xml.etree.ElementTree as ET

from kontrola_przyczyny import POTWIERDZONA, WynikTestu, czytaj_junit, werdykt


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Recipes/Actions/BlokadaWyslaniaZdjecWykonania.php"
TEST_FILE = "tests/Dwa/PonowienieZdjeciaWykonaniaNaDwochPolaczeniachTest.php"
TEST_CLASS = r"Tests\Dwa\PonowienieZdjeciaWykonaniaNaDwochPolaczeniachTest"
TEST = "test_ten_sam_klucz_czeka_przed_utworzeniem_media"
MARKER = "DOLACZENIE_2811_RYWAL_CZEKA_PRZED_MEDIA"
OLD = "$polaczenie->select('SELECT pg_advisory_lock(2811, hashtext(?))', [$zasob]);"
NEW = "$polaczenie->select('SELECT 1');"

if os.environ.get("CI") != "true":
    if os.environ.get("KUKING_KONTROLA_2811_LOKALNIE") != "1":
        raise SystemExit("Kontrola #2811 wymaga CI lub jawnej zgody lokalnej.")
    if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
        raise SystemExit("Kontrola #2811 wymaga własnego lokalnego portu PostgreSQL.")
    if not os.environ.get("DB_DATABASE", "").startswith("kuking_race_"):
        raise SystemExit("Kontrola #2811 wymaga własnej bazy kuking_race_*.")


def ocen_wynik(result: WynikTestu, expect_success: bool) -> None:
    """Raport dowodzi wykonania właściwego przypadku i przyczyny porażki."""
    if result.junit is None:
        raise RuntimeError("PRZYRZAD_2811_BRAK_JUNIT: " + result.wyjscie)
    try:
        cases = list(ET.fromstring(result.junit).iter("testcase"))
    except ET.ParseError as error:
        raise RuntimeError("PRZYRZAD_2811_NIECZYTELNY_JUNIT: " + str(error)) from error
    if len(cases) != 1:
        raise RuntimeError("PRZYRZAD_2811_LICZBA_TESTOW: oczekiwano jednego przypadku.")
    case = cases[0]
    if case.get("class") != TEST_CLASS or case.get("name") != TEST:
        raise RuntimeError("PRZYRZAD_2811_OBCY_TEST: raport dotyczy innej klasy lub metody.")
    if case.find("skipped") is not None or case.find("error") is not None:
        raise RuntimeError("PRZYRZAD_2811_NIE_WYKONANO: pominięcie albo błąd wykonania.")
    if expect_success:
        number, failures = czytaj_junit(result.junit)
        if result.kod != 0 or number != 1 or failures:
            raise RuntimeError("PRZYRZAD_2811_BRAK_ZIELENI: test na nietkniętym źródle nie przeszedł.")
    elif werdykt(TEST, MARKER, result).werdykt != POTWIERDZONA:
        raise RuntimeError("PRZYRZAD_2811_ZLA_PRZYCZYNA: mutant nie oblał własnego markera.")


def run_test(expect_success: bool) -> None:
    with tempfile.TemporaryDirectory(prefix="kuking-2811-junit-") as directory:
        report = Path(directory) / "junit.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        result = subprocess.run(
            ["php", "vendor/bin/phpunit", TEST_FILE, "--group=dwa-polaczenia",
             "--filter=" + TEST, "--log-junit", str(report), "--colors=never", "--no-progress"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90, check=False,
        )
        junit = report.read_text(encoding="utf-8", errors="replace") if report.is_file() else None
    print(result.stdout, flush=True)
    ocen_wynik(WynikTestu(result.returncode, result.stdout, junit), expect_success)
    state = "PASS" if expect_success else "FAIL własnego markera"
    print(f"JUnit #2811: {TEST_CLASS}::{TEST} — {state}.", flush=True)


original = SOURCE.read_bytes()
mtime = SOURCE.stat().st_mtime_ns
source = original.decode("utf-8")
if source.count(OLD) != 1:
    raise RuntimeError("Kotwica blokady #2811 nie występuje dokładnie raz.")

run_test(expect_success=True)
try:
    SOURCE.write_bytes(source.replace(OLD, NEW, 1).encode("utf-8"))
    run_test(expect_success=False)
finally:
    SOURCE.write_bytes(original)
    os.utime(SOURCE, ns=(SOURCE.stat().st_atime_ns, mtime))
    if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != mtime:
        raise RuntimeError("Bajty lub mtime źródła #2811 nie zostały przywrócone.")

run_test(expect_success=True)
print("Kontrola ujemna #2811: własny marker czerwony, po przywróceniu zielony.", flush=True)
