#!/usr/bin/env python3
"""#2887: fizyczne wyłączenie świeżej autoryzacji odtwarza późny INSERT."""

import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

from kontrola_przyczyny import POTWIERDZONA, WynikTestu, czytaj_junit, werdykt


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Moderation/Actions/ReportContent.php"
TEST_FILE = "tests/Dwa/ZgloszenieWskazowkiKontraWycofanieTest.php"
TEST_CLASS = r"Tests\Dwa\ZgloszenieWskazowkiKontraWycofanieTest"
METHOD = "test_withdraw_and_edit_first_refuses_late_report"
DATASET = "ordinary-cook-lower"
CASE = METHOD + ' with data set "' + DATASET + '"'
MARKER = "WSKAZOWKA_2887_PO_WYCOFANIU_BEZ_REPORT"
OLD = b"if ($target instanceof RecipeHint) {"
NEW = b"if (false && $target instanceof RecipeHint) {"


def sprawdz_cel() -> None:
    if os.environ.get("DB_URL") or os.environ.get("DATABASE_URL"):
        raise SystemExit("Kontrola #2887 odmawia adresu URL zastępującego jawne połączenie.")
    database = os.environ.get("DB_DATABASE", "")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2887_LOKALNIE") != "1":
            raise SystemExit("Kontrola #2887 wymaga jawnego lokalnego opt-in.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise SystemExit("Kontrola #2887 wymaga jawnego lokalnego PostgreSQL poza portem 5432.")
        if re.fullmatch(r"kuking_race_[A-Za-z0-9_]+", database) is None:
            raise SystemExit("Lokalna kontrola #2887 odmawia gołej kuking_race i obcej bazy.")
    if re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", database) is None or not os.environ.get("DB_USERNAME"):
        raise SystemExit("Kontrola #2887 wymaga jawnej bazy wyścigów i roli.")


def run_test() -> WynikTestu:
    with tempfile.TemporaryDirectory(prefix="kuking-2887-junit-") as directory:
        report = Path(directory) / "junit.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        result = subprocess.run(
            ["php", "vendor/bin/phpunit", TEST_FILE, "--group=dwa-polaczenia",
             "--filter=" + METHOD + "@" + DATASET, "--log-junit", str(report), "--colors=never"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90, check=False,
        )
        junit = report.read_text(encoding="utf-8", errors="replace") if report.is_file() else None
    return WynikTestu(result.returncode, result.stdout, junit)


def wykonany_test(result: WynikTestu) -> None:
    if result.junit is None:
        raise RuntimeError("PRZYRZAD_2887_BRAK_JUNIT: " + result.wyjscie)
    try:
        cases = list(ET.fromstring(result.junit).iter("testcase"))
    except ET.ParseError as error:
        raise RuntimeError("PRZYRZAD_2887_NIECZYTELNY_JUNIT: " + str(error)) from error
    if len(cases) != 1:
        raise RuntimeError("PRZYRZAD_2887_LICZBA_TESTOW: oczekiwano jednego przypadku.")
    case = cases[0]
    if case.get("class") != TEST_CLASS or case.get("name") != CASE:
        raise RuntimeError("PRZYRZAD_2887_OBCY_TEST: niewłaściwa klasa, metoda albo wariant.")
    if case.find("skipped") is not None or case.find("error") is not None:
        raise RuntimeError("PRZYRZAD_2887_NIE_WYKONANO: pominięcie albo błąd wykonania.")


def positive(result: WynikTestu) -> None:
    wykonany_test(result)
    number, failures = czytaj_junit(result.junit)
    if result.kod != 0 or number != 1 or failures:
        raise RuntimeError("Dodatni przeplot #2887 nie przeszedł: " + result.wyjscie)


def negative(result: WynikTestu) -> None:
    wykonany_test(result)
    verdict = werdykt(METHOD, MARKER, result)
    if result.kod != 1 or verdict.werdykt != POTWIERDZONA:
        raise RuntimeError("Niewłaściwa przyczyna porażki #2887: " + verdict.powod + "\n" + result.wyjscie)


def main() -> None:
    sprawdz_cel()
    if sys.argv[1:] == ["--sprawdz-cel"]:
        return
    original = SOURCE.read_bytes()
    mtime_ns = SOURCE.stat().st_mtime_ns
    original_md5 = hashlib.md5(original).hexdigest()
    if original.count(OLD) != 1:
        raise RuntimeError("Kotwica FIX #2887 nie występuje dokładnie raz.")
    before = run_test()
    positive(before)
    try:
        SOURCE.write_bytes(original.replace(OLD, NEW, 1))
        mutant_md5 = hashlib.md5(SOURCE.read_bytes()).hexdigest()
        if mutant_md5 == original_md5:
            raise RuntimeError("Fizyczny mutant #2887 nie zmienił źródła.")
        mutant = run_test()
        negative(mutant)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(SOURCE.stat().st_atime_ns, mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != mtime_ns:
            raise RuntimeError("Nie przywrócono dokładnych bajtów i mtime źródła #2887.")
    after = run_test()
    positive(after)
    receipt = {
        "source": str(SOURCE), "test": TEST_CLASS + "::" + CASE,
        "database": os.environ["DB_DATABASE"], "host": os.environ.get("DB_HOST"),
        "port": os.environ.get("DB_PORT"), "owner": os.environ["DB_USERNAME"],
        "original_md5": original_md5, "mutant_md5": mutant_md5,
        "restored_md5": hashlib.md5(SOURCE.read_bytes()).hexdigest(),
        "original_mtime_ns": mtime_ns, "restored_mtime_ns": SOURCE.stat().st_mtime_ns,
        "before": before._asdict(), "mutant": mutant._asdict(), "after": after._asdict(),
        "marker": MARKER,
    }
    path = os.environ.get("KUKING_2887_RECEIPT")
    if path:
        Path(path).write_text(json.dumps(receipt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("#2887: PASS → własny FAIL późnego INSERT → dokładny restore bajtów/mtime → PASS.", flush=True)


if __name__ == "__main__":
    main()
