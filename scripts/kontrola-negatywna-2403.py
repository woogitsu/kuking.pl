"""#2403: bez ponowienia konfliktu slugu test dwóch połączeń musi wykazać 23505.

Uruchamiane na przygotowanej, izolowanej bazie ``kuking_race*``. Najpierw
sprawdza test dodatni, potem usuwa na chwilę tylko retry slugu, a po
przywróceniu dokładnych bajtów i mtime uruchamia test jeszcze raz.
"""

import os
import re
import subprocess
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Recipes/Actions/PublishRecipe.php"
TEST = "test_konflikt_sluga_w_zewnetrznej_transakcji_cofa_tylko_savepoint"
ANCHOR = b"if ($indeks === 'recipes_slug_unique') {"
MUTATION = b"if (false && $indeks === 'recipes_slug_unique') {"


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
    # Puste, jawne DB_URL nie pozwala, by .env przesłonił zweryfikowaną
    # izolowaną DB_DATABASE adresem innej bazy.
    env["DB_URL"] = ""
    try:
        result = subprocess.run(
            ["php", "-d", "opcache.enable_cli=0", "artisan", "test",
             "--group=dwa-polaczenia", "--filter=" + TEST, "--no-ansi"],
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
    # Artisan/PHPUnit potrafi dodać ANSI nawet z --no-ansi. W CI kolor
    # stał między „Tests:” a liczbą i dawał fałszywy brak testów mimo
    # prawdziwego 1 passed / 15 assertions na PostgreSQL 18.
    # GitHub CLI pokazuje niektóre przechwycone ESC jako literalne „^[[”;
    # obsługujemy obie postacie tego samego kolorowego wydruku.
    wynik = re.sub(r"(?:\x1b\[|\^\[\[)[0-?]*[ -/]*[@-~]", "", result.stdout)
    if "No tests found" in wynik or not re.search(r"Tests:\s*1(?:\D|$)", wynik):
        raise RuntimeError("Brak dowodu uruchomienia dokładnie jednego testu #2403.")

    if expect_success:
        if result.returncode != 0 or re.search(r"(?:Skipped|Incomplete|Risky):\s*[1-9]", wynik):
            raise RuntimeError("Test dodatni #2403 nie przeszedł; to błąd kodu lub środowiska, nie kontrola ujemna.")
    elif result.returncode == 0 or "23505" not in wynik or '"recipes_slug_unique"' not in wynik:
        raise RuntimeError("Mutacja #2403 nie wywołała dokładnie konfliktu 23505/recipes_slug_unique.")


def main() -> None:
    check_environment()
    original = SOURCE.read_bytes()
    original_stat = SOURCE.stat()
    if original.count(ANCHOR) != 1:
        raise RuntimeError("Kotwica retry #2403 nie występuje dokładnie raz. Nie dotknięto źródła.")

    # Brak zależności, bazy albo testu wychodzi PRZED mutacją źródła.
    run_test(expect_success=True)

    with tempfile.TemporaryDirectory(prefix="kuking-2403-") as backup_dir:
        backup = Path(backup_dir) / "PublishRecipe.php"
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
    print("Kontrola ujemna #2403: mutacja dała 23505/recipes_slug_unique; przywrócony test jest zielony.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except RuntimeError as exc:
        raise SystemExit(f"Kontrola #2403 nie powiodła się: {exc}") from exc
