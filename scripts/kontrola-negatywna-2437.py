"""#2437: afterCommit i pominięty publiczny legacy muszą złamać testy CSAM.

Uruchamia się tylko z testy-dwa-polaczenia.sh na izolowanej bazie PG18.
Zachowuje dokładne bajty i mtime źródła, nie dotyka mediów produkcyjnych.
"""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Moderation/Actions/ZabezpieczDowodCsam.php"
JOB_SOURCE = ROOT / "app/Jobs/PrzeniesPubliczneWariantyDowodu.php"
TEST = "ZabezpieczenieDowoduIKolejkaWJednejTransakcjiTest"
LEGACY_TEST = "test_stary_wspolny_publiczny_bucket_nie_udaje_zakonczonego_przeniesienia"
EXPECTED = {
    "test_drugi_worker_widzi_stan_i_job_dopiero_razem_po_commicie":
        "CSAM_OUTBOX_JOB_IN_TRANSACTION",
    "test_awaria_wstawiania_zadania_cofa_status_rejestr_i_decyzje":
        "CSAM_OUTBOX_STATE_AFTER_ENQUEUE_FAILURE",
    "test_inna_kolejka_odmawia_zanim_powstanie_decyzja": None,
}
ANCHOR = "PrzeniesPubliczneWariantyDowodu::dispatch($zabezpieczoneId);"
MUTANT = "PrzeniesPubliczneWariantyDowodu::dispatch($zabezpieczoneId)->afterCommit();"
LEGACY_ANCHOR = "in_array($nazwaDysku, ['r2_legacy', 'public'], true)"
LEGACY_MARKER = "CSAM_LEGACY_MUST_STAY_PENDING"


def environment():
    if shutil.which("php") is None or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("Kontrola #2437 wymaga PHP i zależności; źródło nietknięte.")
    if not os.environ.get("DB_DATABASE", "").startswith("kuking_race"):
        raise RuntimeError("Kontrola #2437 wymaga izolowanej bazy kuking_race*.")
    if os.environ.get("DB_URL"):
        raise RuntimeError("Kontrola #2437 odmawia przy DB_URL; użyj jawnych parametrów bazy.")
    if any(not os.environ.get(key) for key in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("Kontrola #2437 wymaga jawnego hosta, portu i właściciela bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2437_LOKALNIE") != "1":
            raise RuntimeError("Lokalnie ustaw KUKING_KONTROLA_2437_LOKALNIE=1.")
        if os.environ["DB_HOST"] not in ("localhost", "127.0.0.1") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("Lokalnie wymagany jest własny PostgreSQL poza portem 5432.")


def run_test(expect_mutation):
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    with tempfile.TemporaryDirectory(prefix="kuking-2437-junit-") as directory:
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
            raise RuntimeError("Brak JUnit #2437; awaria środowiska nie dowodzi mutacji.")
        cases = list(ET.parse(report).getroot().iter("testcase"))
    names = {case.get("name", ""): case for case in cases}
    if len(cases) != len(EXPECTED) or set(names) != set(EXPECTED) or any(
        case.find("skipped") is not None or case.find("error") is not None
        for case in cases
    ):
        raise RuntimeError("Nie wykonano dokładnie trzech właściwych testów #2437 bez skip/error.")
    if expect_mutation:
        if result.returncode == 0:
            raise RuntimeError("afterCommit nie oblał testów #2437.")
        for name, marker in EXPECTED.items():
            failures = names[name].findall("failure")
            if marker is None:
                if failures:
                    raise RuntimeError("Mutacja zmieniła niezależny guard kolejki #2437.")
                continue
            proof = "".join((item.text or "") + item.get("message", "") for item in failures)
            if len(failures) != 1 or marker not in proof:
                raise RuntimeError("afterCommit nie oblał testu z przyczyną: " + marker)
    elif result.returncode != 0 or any(case.find("failure") is not None for case in cases):
        raise RuntimeError("Bazowy/przywrócony test #2437 nie jest zielony.")


def run_legacy_test(expect_mutation):
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    with tempfile.TemporaryDirectory(prefix="kuking-2437-legacy-junit-") as directory:
        report = Path(directory) / "junit.xml"
        result = subprocess.run(
            ["php", "artisan", "test", "--filter=" + LEGACY_TEST,
             "--no-ansi", "--log-junit=" + str(report)],
            cwd=ROOT, env=env, timeout=180, check=False,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            text=True, encoding="utf-8", errors="replace",
        )
        print(result.stdout, flush=True)
        if not report.is_file():
            raise RuntimeError("Brak JUnit legacy #2437; błąd środowiska nie jest dowodem mutacji.")
        cases = list(ET.parse(report).getroot().iter("testcase"))
    if len(cases) != 1 or LEGACY_TEST not in cases[0].get("name", "") or (
        cases[0].find("skipped") is not None or cases[0].find("error") is not None
    ):
        raise RuntimeError("Nie wykonano dokładnie właściwego testu legacy #2437 bez skip/error.")
    failures = cases[0].findall("failure")
    if expect_mutation:
        proof = "".join((item.text or "") + item.get("message", "") for item in failures)
        if result.returncode == 0 or len(failures) != 1 or LEGACY_MARKER not in proof:
            raise RuntimeError("Wyłączenie ochrony legacy nie oblało testu z właściwą przyczyną.")
    elif result.returncode != 0 or failures:
        raise RuntimeError("Bazowy/przywrócony test legacy #2437 nie jest zielony.")


def main():
    environment()
    original = SOURCE.read_bytes()
    if original.decode("utf-8").count(ANCHOR) != 1:
        raise RuntimeError("Kotwica mutacji #2437 nie występuje dokładnie raz.")
    legacy_original = JOB_SOURCE.read_bytes()
    if legacy_original.decode("utf-8").count(LEGACY_ANCHOR) != 1:
        raise RuntimeError("Kotwica ochrony publicznego legacy #2437 nie występuje dokładnie raz.")
    stat = SOURCE.stat()
    run_test(False)
    with tempfile.TemporaryDirectory(prefix="kuking-2437-backup-") as directory:
        (Path(directory) / "ZabezpieczDowodCsam.php").write_bytes(original)
        try:
            SOURCE.write_bytes(original.replace(ANCHOR.encode(), MUTANT.encode(), 1))
            run_test(True)
        finally:
            SOURCE.write_bytes(original)
            os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
            if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
                raise RuntimeError("Nie przywrócono dokładnych bajtów/mtime #2437.")
    run_test(False)
    run_legacy_test(False)
    legacy_stat = JOB_SOURCE.stat()
    with tempfile.TemporaryDirectory(prefix="kuking-2437-legacy-backup-") as directory:
        (Path(directory) / "PrzeniesPubliczneWariantyDowodu.php").write_bytes(legacy_original)
        try:
            JOB_SOURCE.write_bytes(legacy_original.replace(LEGACY_ANCHOR.encode(), b"false", 1))
            run_legacy_test(True)
        finally:
            JOB_SOURCE.write_bytes(legacy_original)
            os.utime(JOB_SOURCE, ns=(legacy_stat.st_atime_ns, legacy_stat.st_mtime_ns))
            if JOB_SOURCE.read_bytes() != legacy_original or JOB_SOURCE.stat().st_mtime_ns != legacy_stat.st_mtime_ns:
                raise RuntimeError("Nie przywrócono dokładnych bajtów/mtime joba #2437.")
    run_legacy_test(False)
    print("#2437: afterCommit i pominięty legacy wykryte; źródła przywrócone i testy zielone.", flush=True)


if __name__ == "__main__":
    main()
