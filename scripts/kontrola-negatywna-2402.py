"""#2402: inne połączenie i afterCommit muszą złamać pomiar atomowego outboxa.

Tylko po przygotowaniu izolowanej bazy kuking_race przez testy-dwa-polaczenia.sh.
Nie wykonuje workera, sieci ani płatnych wywołań. Mutuje trzy akcje importu,
przywraca ich dokładne bajty i mtime, potem wymaga ponownie zielonego testu.
"""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parent.parent
TEST = "ImportIKolejkaWJednejTransakcjiTest"
SOURCES = {
    "app/Domain/Import/Url/ZlecImportZAdresu.php":
        "ImportujPrzepisZAdresu::dispatch((string) $zlecenie->getKey(), $zgodaAi)",
    "app/Domain/Import/Pdf/ZlecImportZPdf.php":
        "ImportujPrzepisZPdf::dispatch((string) $zlecenie->getKey(), $zgodaAi)",
    "app/Domain/Import/ZlecImportPrzepisu.php":
        "OdczytajPrzepis::dispatch((string) $zlecenie->getKey())",
}


def check_environment():
    if shutil.which('php') is None or not (ROOT / 'vendor/autoload.php').is_file():
        raise RuntimeError("Kontrola #2402 wymaga PHP i zależności; źródła nie zostały zmienione.")
    if not os.environ.get("DB_DATABASE", "").startswith("kuking_race"):
        raise RuntimeError("Kontrola #2402 wymaga jawnej izolowanej bazy kuking_race*.")
    if os.environ.get("DB_URL"):
        raise RuntimeError("Kontrola #2402 odmawia przy DB_URL; użyj jawnego hosta/portu/bazy.")
    if any(not os.environ.get(key) for key in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("Kontrola #2402 wymaga jawnego hosta, portu i właściciela bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2402_LOKALNIE") != "1":
            raise RuntimeError("Lokalnie wymagany jest KUKING_KONTROLA_2402_LOKALNIE=1.")
        if os.environ["DB_HOST"] not in ("localhost", "127.0.0.1") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("Lokalnie wymagany jest własny PostgreSQL poza portem 5432.")


def run_test(marker=None):
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    with tempfile.TemporaryDirectory(prefix="kuking-2402-result-") as directory:
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
            raise RuntimeError("Brak raportu JUnit #2402; błąd środowiska nie jest dowodem mutacji.")
        cases = list(ET.parse(report).getroot().iter("testcase"))
    if len(cases) != 6 or any(case.find("skipped") is not None or case.find("error") is not None for case in cases):
        raise RuntimeError("Brak dowodu wykonania testów #2402; to nie jest kontrola ujemna.")
    if marker is None:
        if result.returncode != 0 or any(case.find("failure") is not None for case in cases):
            raise RuntimeError("Test dodatni #2402 musi przejść bez pominięć.")
    else:
        for case in cases:
            failures = case.findall("failure")
            if result.returncode == 0 or len(failures) != 1 or marker not in (
                (failures[0].text or "") + failures[0].get("message", "")
            ):
                raise RuntimeError("Mutacja #2402 nie wykazała oczekiwanej porażki: " + marker)


def main():
    check_environment()
    originals = {}
    for relative, anchor in SOURCES.items():
        source = ROOT / relative
        original = source.read_bytes()
        if original.decode("utf-8").count(anchor + ";") != 1:
            raise RuntimeError("Kotwica mutacji #2402 nie występuje dokładnie raz: " + relative)
        originals[source] = (original, source.stat().st_atime_ns, source.stat().st_mtime_ns)

    run_test()
    # Kopia przed edycją poza repo zachowuje także dowód stanu pierwotnego.
    with tempfile.TemporaryDirectory(prefix="kuking-2402-") as backup:
        for index, (source, (original, _, _)) in enumerate(originals.items()):
            (Path(backup) / str(index)).write_bytes(original)
        for suffix, marker in (
            ("->onConnection('import_independent')", "IMPORT_OUTBOX_WORKER_VISIBILITY"),
            ("->afterCommit()", "IMPORT_OUTBOX_JOB_IN_TRANSACTION"),
        ):
            try:
                for relative, anchor in SOURCES.items():
                    source = ROOT / relative
                    original = originals[source][0].decode("utf-8")
                    source.write_bytes(original.replace(anchor + ";", anchor + suffix + ";", 1).encode("utf-8"))
                run_test(marker)
            finally:
                for source, (original, atime, mtime) in originals.items():
                    source.write_bytes(original)
                    os.utime(source, ns=(atime, mtime))
                    if source.read_bytes() != original or source.stat().st_mtime_ns != mtime:
                        raise RuntimeError("Nie przywrócono źródła #2402: " + str(source))
            run_test()
    print("#2402: osobne połączenie i afterCommit złapane; przywrócone testy zielone.", flush=True)


if __name__ == "__main__":
    main()
