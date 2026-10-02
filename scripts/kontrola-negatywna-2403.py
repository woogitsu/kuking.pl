"""#2403: bez retry równoległy zapis musi trafić w recipes_slug_unique.

Kontrola działa wyłącznie na przygotowanej, izolowanej bazie wyścigów.
Przed mutacją i po przywróceniu źródła wymaga sukcesu dokładnie jednego
rzeczywistego testu dwóch połączeń, potwierdzonego raportem JUnit.
"""

import os
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Recipes/Actions/GenerateRecipeSlug.php"
TEST = "test_dwa_rownolegle_nowe_przepisy_o_tym_samym_tytule_dostaja_rozne_slugi"
ANCHOR = b"if ($proba >= self::MAKSYMALNA_LICZBA_PROB || ! str_contains($e->getMessage(), self::INDEKS_SLUGU)) {"
MUTATION = b"if (true) { // kontrola ujemna #2403: bez ponowienia konfliktu slugu"


def check_environment() -> None:
    database = os.environ.get("DB_DATABASE", "")
    if not re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", database):
        raise RuntimeError("Kontrola #2403 wymaga jawnego DB_DATABASE=kuking_race*. Nie dotknięto źródła.")
    if os.environ.get("DB_URL"):
        raise RuntimeError("DB_URL przesłania DB_DATABASE; kontrola #2403 odmawia. Nie dotknięto źródła.")

    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2403_LOKALNIE") != "1":
            raise RuntimeError("Kontrola #2403 wymaga CI albo KUKING_KONTROLA_2403_LOKALNIE=1.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise RuntimeError("Lokalna kontrola #2403 wymaga własnego PostgreSQL poza portem 5432.")


def run_test(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    env["DB_URL"] = ""

    with tempfile.TemporaryDirectory(prefix="kuking-2403-junit-") as directory:
        junit = Path(directory) / "wynik.xml"
        try:
            result = subprocess.run(
                ["php", "-d", "opcache.enable_cli=0", "artisan", "test",
                 "--group=dwa-polaczenia", "--filter=" + TEST,
                 "--log-junit", str(junit), "--no-ansi"],
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
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise RuntimeError("Nie udało się uruchomić testu #2403 w tym środowisku.") from exc

        print(result.stdout, flush=True)
        if not junit.is_file():
            raise RuntimeError("Brak raportu JUnit #2403; nie wiadomo, czy test się uruchomił.")
        try:
            tree = ET.parse(junit)
        except ET.ParseError as exc:
            raise RuntimeError("Raport JUnit #2403 jest nieczytelny.") from exc

        cases = tree.findall(".//testcase")
        if len(cases) != 1 or TEST not in cases[0].attrib.get("name", ""):
            raise RuntimeError("JUnit nie potwierdza uruchomienia dokładnie właściwego testu #2403.")
        case = cases[0]
        if case.find("skipped") is not None or case.find("error") is not None:
            raise RuntimeError("Test #2403 został pominięty albo przerwany błędem środowiska.")
        failures = case.findall("failure")

        if expect_success:
            if result.returncode != 0 or failures:
                raise RuntimeError("Test dodatni #2403 nie przeszedł; to nie jest dowód kontroli ujemnej.")
            return

        if result.returncode == 0 or len(failures) != 1:
            raise RuntimeError("Mutacja #2403 nie oblała dokładnie właściwego testu.")
        proof = ET.tostring(failures[0], encoding="unicode")
        if "23505" not in proof or "recipes_slug_unique" not in proof:
            raise RuntimeError("Mutacja #2403 nie wykazała konfliktu 23505/recipes_slug_unique.")


def main() -> None:
    check_environment()
    original = SOURCE.read_bytes()
    original_stat = SOURCE.stat()
    if original.count(ANCHOR) != 1:
        raise RuntimeError("Kotwica retry #2403 nie występuje dokładnie raz. Nie dotknięto źródła.")

    run_test(expect_success=True)

    with tempfile.TemporaryDirectory(prefix="kuking-2403-backup-") as directory:
        backup = Path(directory) / "GenerateRecipeSlug.php"
        backup.write_bytes(original)
        os.utime(backup, ns=(original_stat.st_atime_ns, original_stat.st_mtime_ns))

        mutation_error = None
        try:
            SOURCE.write_bytes(original.replace(ANCHOR, MUTATION, 1))
            try:
                run_test(expect_success=False)
            except RuntimeError as exc:
                mutation_error = exc
        finally:
            SOURCE.write_bytes(backup.read_bytes())
            os.utime(SOURCE, ns=(original_stat.st_atime_ns, original_stat.st_mtime_ns))
            if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != original_stat.st_mtime_ns:
                raise RuntimeError("Źródło #2403 nie wróciło do dokładnych bajtów i mtime!")

    run_test(expect_success=True)
    if mutation_error is not None:
        raise mutation_error
    print("Kontrola ujemna #2403: 23505/recipes_slug_unique, a po przywróceniu test zielony.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except RuntimeError as exc:
        raise SystemExit(f"Kontrola #2403 nie powiodła się: {exc}") from exc
