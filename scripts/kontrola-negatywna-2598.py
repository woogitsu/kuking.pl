"""#2598: pierwszy obcy lock nie może udawać właściwej bariery przyrządu.

Uruchamiana na izolowanej kuking_race_*; po mutacji przywraca bajty i mtime.
"""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "tests/Dwa/ZapisDoZeszytuBiezacyStanTest.php"
TEST = "ZapisDoZeszytuBiezacyStanTest::test_przyrzad_czeka_na_wlasciwa_bariere_po_obcym_locku"
MARKER = "ZESZYT_2598_OBCY_LOCK_POMINIETY"
FIX = "if ((int) $row['expected'] === 1) {"
OLD = "if ($row) {"


def environment():
    if shutil.which("php") is None or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("Brak PHP lub własnego vendor; mutacja #2598 nie została uruchomiona.")
    if os.environ.get("DB_URL") or not os.environ.get("DB_DATABASE", "").startswith("kuking_race"):
        raise RuntimeError("#2598 wymaga jawnej izolowanej bazy kuking_race_* bez DB_URL.")
    if any(not os.environ.get(key) for key in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("#2598 wymaga jawnego hosta, portu i właściciela bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2598_LOKALNIE") != "1":
            raise RuntimeError("Lokalnie wymagane KUKING_KONTROLA_2598_LOKALNIE=1.")
        if os.environ["DB_HOST"] not in ("localhost", "127.0.0.1") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("Lokalnie wymagany własny PostgreSQL poza portem 5432.")


def run_test(marker=None):
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    with tempfile.TemporaryDirectory(prefix="kuking-2598-result-") as directory:
        report = Path(directory) / "junit.xml"
        result = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia", "--filter=" + TEST,
             "--no-ansi", "--log-junit=" + str(report)],
            cwd=ROOT, env=env, timeout=180, check=False,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            text=True, encoding="utf-8", errors="replace",
        )
        print(result.stdout, flush=True)
        if not report.is_file():
            raise RuntimeError("Brak raportu JUnit #2598; błąd środowiska nie jest dowodem.")
        cases = list(ET.parse(report).getroot().iter("testcase"))
        if len(cases) != 1 or any(case.find("skipped") is not None or case.find("error") is not None for case in cases):
            raise RuntimeError("Test #2598 nie wykonał się dokładnie raz bez błędu środowiska.")
        failures = cases[0].findall("failure")
        if marker is None:
            if result.returncode != 0 or failures:
                raise RuntimeError("Dodatni test #2598 nie przeszedł.")
        elif result.returncode == 0 or len(failures) != 1 or marker not in (
            (failures[0].text or "") + failures[0].get("message", "")
        ):
            raise RuntimeError("Mutacja #2598 nie oblała się z właściwego powodu: " + marker)


def main():
    environment()
    original = SOURCE.read_bytes()
    source = original.decode("utf-8")
    if source.count(FIX) != 1:
        raise RuntimeError("Kotwica mutacji #2598 nie występuje dokładnie raz.")
    stat = SOURCE.stat()
    run_test()
    with tempfile.TemporaryDirectory(prefix="kuking-2598-backup-") as directory:
        backup = Path(directory) / SOURCE.name
        backup.write_bytes(original)
        try:
            SOURCE.write_bytes(source.replace(FIX, OLD, 1).encode("utf-8"))
            run_test(MARKER)
        finally:
            SOURCE.write_bytes(backup.read_bytes())
            os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
    if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
        raise RuntimeError("Nie odtworzono dokładnych bajtów i mtime źródła #2598.")
    run_test()


if __name__ == "__main__":
    main()
