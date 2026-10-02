"""#2404: UnfollowUser bez ZamekPary musi oblać test dwóch połączeń.

Uruchamiane po przygotowaniu osobnej bazy kuking_race przez
scripts/testy-dwa-polaczenia.sh. Podmienia UnfollowUser na gołe detach()
(stan sprzed poprawki), wymaga, żeby test kolejki obserwuj/przestań oblał,
potem przywraca dokładne bajty i wymaga, żeby test przeszedł.
Lokalnie wymaga jawnego opt-in i własnego portu PG.
"""

import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Social/Actions/UnfollowUser.php"
TEST = "ObserwowanieIPrzestanObserwowacTenSamZamekParyTest"
# Komunikat TestDwochPolaczen::czekajNaZablokowane(): bez zamka druga operacja
# nie staje w kolejce, więc przeplot się nie ustawia.
OBLANIA = "a miało stać"


if os.environ.get("CI") != "true":
    if os.environ.get("KUKING_KONTROLA_2404_LOKALNIE") != "1":
        raise SystemExit("Kontrola #2404 wymaga CI albo jawnego KUKING_KONTROLA_2404_LOKALNIE=1.")
    if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
        raise SystemExit("Kontrola #2404 wymaga własnego lokalnego PostgreSQL poza portem 5432.")


MUTANT_STARY = """        ZamekPary::zablokuj($follower, $target, static function () use ($follower, $target): void {
            $follower->following()->detach($target->getKey());
            ListyWidza::uniewaznij();
        });
"""
MUTANT_NOWY = """        $follower->following()->detach($target->getKey());
        ListyWidza::uniewaznij();
"""


def zepsuj(source: str) -> str:
    if source.count(MUTANT_STARY) != 1:
        raise RuntimeError("Kotwica mutacji #2404 nie występuje dokładnie raz.")
    return source.replace(MUTANT_STARY, MUTANT_NOWY, 1)


def run_test(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    result = subprocess.run(
        ["php", "artisan", "test", "--group=dwa-polaczenia",
         "--filter=" + TEST, "--no-ansi"],
        cwd=ROOT,
        env=env,
        text=True,
        encoding="utf-8",
        errors="replace",
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        timeout=300,
        check=False,
    )
    print(result.stdout, flush=True)
    if "No tests found" in result.stdout or "Tests:" not in result.stdout:
        raise RuntimeError("Nie ma dowodu, że test #2404 naprawdę się uruchomił.")
    if expect_success:
        if result.returncode != 0:
            raise RuntimeError("Test #2404 nie przeszedł po przywróceniu źródła.")
    elif result.returncode == 0 or "FAILED" not in result.stdout or OBLANIA not in result.stdout:
        raise RuntimeError("Gołe detach() nie oblało testu kolejki z oczekiwanej przyczyny.")


original = SOURCE.read_bytes()
mutated = zepsuj(original.decode("utf-8")).encode("utf-8")
if mutated == original:
    raise RuntimeError("Mutacja #2404 nie zmieniła źródła.")

try:
    SOURCE.write_bytes(mutated)
    run_test(expect_success=False)
finally:
    SOURCE.write_bytes(original)
    if SOURCE.read_bytes() != original:
        raise RuntimeError("Źródło #2404 nie zostało przywrócone.")

run_test(expect_success=True)
print("Kontrola ujemna #2404 oblała test bez zamka; po przywróceniu test jest zielony.", flush=True)
