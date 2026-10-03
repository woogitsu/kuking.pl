"""#2815: bez świeżej kontroli konta import po sankcji musi oblać wyścig.

Wywoływane po przygotowaniu izolowanej bazy grupy `dwa-polaczenia`.
"""

import os
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Users/Import/WczytajPaczke.php"
ANCHOR = b"if (! Gate::forUser($swiezy)->allows('create', WczytanaZPaczki::class)) {"
MUTANT = b"if (false) {"
MARKER = "IMPORT_2815_SANKCJA_PRZED_ZAPISEM"
TEST = "test_sankcja_zatwierdzona_przed_blokada_pozycji_odmawia_bez_sladu"


if os.environ.get("CI") != "true":
    if os.environ.get("KUKING_KONTROLA_2815_LOKALNIE") != "1":
        raise SystemExit("Kontrola #2815 wymaga CI albo jawnego lokalnego opt-in.")
    if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
        raise SystemExit("Kontrola #2815 wymaga własnego lokalnego PostgreSQL poza portem 5432.")


def test(expected_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    with tempfile.TemporaryDirectory(prefix="kuking-2815-result-") as directory:
        report = Path(directory) / "junit.xml"
        result = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia", "--filter=" + TEST,
             "--no-ansi", "--log-junit=" + str(report)],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300, check=False,
        )
        print(result.stdout, flush=True)
        if not report.is_file():
            raise RuntimeError("Brak raportu JUnit #2815; błąd środowiska nie jest dowodem.")
        cases = list(ET.parse(report).getroot().iter("testcase"))
        names = {case.get("name") for case in cases}
        expected_names = {f'{TEST} with data set "{rodzaj}"' for rodzaj in ("przepis", "wpis", "zeszyt")}
        if len(cases) != 3 or names != expected_names or any(
            case.find("skipped") is not None or case.find("error") is not None for case in cases
        ):
            raise RuntimeError("Trzy warianty testu #2815 nie wykonały się bez błędu środowiska.")
        failures = {
            case.get("name"): case.findall("failure") for case in cases if case.findall("failure")
        }
        if expected_success:
            if result.returncode != 0 or failures:
                raise RuntimeError("Test #2815 nie przeszedł po przywróceniu źródła.")
        elif result.returncode == 0 or set(failures) != {
            f'{TEST} with data set "wpis"', f'{TEST} with data set "zeszyt"'
        } or any(
            len(found) != 1 or MARKER not in ((found[0].text or "") + found[0].get("message", ""))
            for found in failures.values()
        ):
            raise RuntimeError("Mutant #2815 nie oblał dwóch wariantów z oczekiwanej przyczyny.")


original = SOURCE.read_bytes()
mtime = SOURCE.stat().st_mtime_ns
if original.count(ANCHOR) != 1:
    raise RuntimeError("Kotwica #2815 nie występuje dokładnie raz.")

try:
    SOURCE.write_bytes(original.replace(ANCHOR, MUTANT, 1))
    test(expected_success=False)
finally:
    SOURCE.write_bytes(original)
    os.utime(SOURCE, ns=(SOURCE.stat().st_atime_ns, mtime))
    if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != mtime:
        raise RuntimeError("Nie przywrócono dokładnie źródła #2815.")

test(expected_success=True)
print("#2815: mutant oblał nazwanym markerem, a przywrócony kod przeszedł.", flush=True)
