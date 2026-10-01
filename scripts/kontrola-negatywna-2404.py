"""#2404: dawny detach bez ZamekPary musi oblać rzeczywisty test wyścigu.

Uruchamiać po przygotowaniu izolowanej bazy kuking_race_* przez
scripts/testy-dwa-polaczenia.sh. Źródło zmienia się wyłącznie na czas testu;
po próbie przywracamy jego bajty i czas modyfikacji, także przy błędzie.
"""

import os
import re
import shutil
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Social/Actions/UnfollowUser.php"
TEST = "test_pozniejsze_cofniecie_wygrywa_z_wczesniejszym_obserwowaniem"
OLD = """        ZamekPary::zablokuj($follower, $target, static function (?User $obserwujacy, ?User $obserwowany): void {
            // Oba konta mogły zniknąć, zanim przyszła kolej na blokadę.
            // Cofnięcie nieistniejącego obserwowania jest idempotentne.
            if ($obserwujacy === null || $obserwowany === null) {
                return;
            }

            $obserwujacy->following()->detach($obserwowany->getKey());
        });"""
MUTATION = "        $follower->following()->detach($target->getKey());"
EXPECTED_FAILURE = "Przeplot się nie ustawił"


def check_environment() -> None:
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2404_LOKALNIE") != "1":
            raise RuntimeError("Kontrola #2404 wymaga CI albo jawnego KUKING_KONTROLA_2404_LOKALNIE=1.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise RuntimeError("Lokalna kontrola #2404 wymaga własnego PostgreSQL poza portem 5432.")

    for name in ("DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME"):
        if not os.environ.get(name):
            raise RuntimeError(f"Brak jawnego {name}; kontrola #2404 nie może odgadnąć bazy testowej.")
    if not os.environ["DB_DATABASE"].startswith("kuking_race"):
        raise RuntimeError("Kontrola #2404 może działać wyłącznie na bazie kuking_race_*.")
    if os.environ.get("DB_URL"):
        raise RuntimeError("DB_URL nadpisuje jawny cel połączenia; usuń go przed kontrolą #2404.")
    if shutil.which("php") is None or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("Brak PHP albo vendor/autoload.php; test #2404 nie może się uruchomić.")


def run_test(expected_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    try:
        result = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia", "--filter=" + TEST, "--no-ansi"],
            cwd=ROOT,
            env=env,
            text=True,
            encoding="utf-8",
            errors="replace",
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=180,
            check=False,
        )
    except subprocess.TimeoutExpired as error:
        raise RuntimeError("Test #2404 przekroczył 180 s; to nie jest oczekiwana porażka mutacji.") from error

    output = result.stdout
    print(output, flush=True)
    if "No tests found" in output or not re.search(r"Tests:\s+1(?:\s|,|$)", output):
        raise RuntimeError("Nie ma dowodu, że uruchomił się dokładnie jeden test #2404.")

    if expected_success:
        if result.returncode != 0:
            raise RuntimeError("Dodatni test #2404 nie przeszedł; kontrola ujemna jest nierozstrzygająca.")
    elif result.returncode == 0 or EXPECTED_FAILURE not in output or "a miało stać 2" not in output:
        raise RuntimeError("Dawny detach nie wywołał oczekiwanej porażki bariery dwóch procesów.")


def main() -> None:
    check_environment()
    before_stat = SOURCE.stat()
    original = SOURCE.read_bytes()
    source = original.decode("utf-8")
    if source.count(OLD) != 1:
        raise RuntimeError("Kotwica mutacji #2404 nie występuje dokładnie raz; nie zmieniono źródła.")
    mutated = source.replace(OLD, MUTATION, 1).encode("utf-8")
    if mutated == original:
        raise RuntimeError("Mutacja #2404 nie zmieniła źródła.")

    # Najpierw kontrola dodatnia: bez niej dowolna awaria środowiska mogłaby
    # zostać błędnie odczytana jako udana kontrola ujemna.
    run_test(expected_success=True)

    try:
        SOURCE.write_bytes(mutated)
        run_test(expected_success=False)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(before_stat.st_atime_ns, before_stat.st_mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != before_stat.st_mtime_ns:
            raise RuntimeError("Źródło #2404 nie wróciło do pierwotnych bajtów i mtime.")

    run_test(expected_success=True)
    print("Kontrola #2404: dawny detach oblał barierę; przywrócony kod przeszedł.", flush=True)


if __name__ == "__main__":
    main()
