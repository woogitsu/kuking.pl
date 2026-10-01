"""#2437: afterCommit musi złamać realny pomiar atomowego joba CSAM.

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
TEST = "ZabezpieczenieDowoduIKolejkaWJednejTransakcjiTest"
EXPECTED = {
    "test_drugi_worker_widzi_stan_i_job_dopiero_razem_po_commicie":
        "CSAM_OUTBOX_JOB_IN_TRANSACTION",
    "test_awaria_wstawiania_zadania_cofa_status_rejestr_i_decyzje":
        "CSAM_OUTBOX_STATE_AFTER_ENQUEUE_FAILURE",
    "test_inna_kolejka_odmawia_zanim_powstanie_decyzja": None,
}
ANCHOR = "PrzeniesPubliczneWariantyDowodu::dispatch($zabezpieczoneId);"
MUTANT = "PrzeniesPubliczneWariantyDowodu::dispatch($zabezpieczoneId)->afterCommit();"


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


def main():
    environment()
    original = SOURCE.read_bytes()
    if original.decode("utf-8").count(ANCHOR) != 1:
        raise RuntimeError("Kotwica mutacji #2437 nie występuje dokładnie raz.")
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
    print("#2437: afterCommit daje oczekiwaną porażkę; stan przywrócony i test zielony.", flush=True)


if __name__ == "__main__":
    main()
