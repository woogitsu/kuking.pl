"""#2815: bez świeżej kontroli konta import po sankcji musi oblać wyścig.

Wywoływane po przygotowaniu izolowanej bazy grupy `dwa-polaczenia`.
"""

import os
import subprocess
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
    result = subprocess.run(
        ["php", "artisan", "test", "--group=dwa-polaczenia", "--filter=" + TEST, "--no-ansi"],
        cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300, check=False,
    )
    print(result.stdout, flush=True)
    if "No tests found" in result.stdout or '"tests":0' in result.stdout:
        raise RuntimeError("Nie uruchomił się żaden test #2815.")
    if expected_success:
        if result.returncode != 0 or '"result":"passed"' not in result.stdout:
            raise RuntimeError("Test #2815 nie przeszedł po przywróceniu źródła.")
    elif result.returncode == 0 or MARKER not in result.stdout or '"result":"failed"' not in result.stdout:
        raise RuntimeError("Mutant #2815 nie oblał testu z oczekiwanej przyczyny.")


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
