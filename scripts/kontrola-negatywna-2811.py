"""#2811: bez sesyjnej blokady dwa wysłania tworzą osierocone Media.

Tylko CI lub jawnie wskazana lokalna baza wyścigów PostgreSQL 18.
Zachowuje bajty i mtime źródła, wymaga czerwieni własnego markera i PASS po
przywróceniu; fatal/no tests/błąd środowiska nie jest dowodem.
"""

import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Recipes/Actions/BlokadaWyslaniaZdjecWykonania.php"
TEST = "PonowienieZdjeciaWykonaniaNaDwochPolaczeniachTest"
MARKER = "DOLACZENIE_2811_RYWAL_CZEKA_PRZED_MEDIA"
OLD = "$polaczenie->select('SELECT pg_advisory_lock(2811, hashtext(?))', [$zasob]);"
NEW = "$polaczenie->select('SELECT 1');"

if os.environ.get("CI") != "true":
    if os.environ.get("KUKING_KONTROLA_2811_LOKALNIE") != "1":
        raise SystemExit("Kontrola #2811 wymaga CI lub jawnej zgody lokalnej.")
    if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
        raise SystemExit("Kontrola #2811 wymaga własnego lokalnego portu PostgreSQL.")
    if not os.environ.get("DB_DATABASE", "").startswith("kuking_race_"):
        raise SystemExit("Kontrola #2811 wymaga własnej bazy kuking_race_*.")


def run_test(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    result = subprocess.run(
        ["php", "vendor/phpunit/phpunit/phpunit", "--group=dwa-polaczenia",
         "--filter=" + TEST, "--no-progress"],
        cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90, check=False,
    )
    print(result.stdout, flush=True)
    if '"tests":1' not in result.stdout:
        raise RuntimeError("Brak dokładnie jednego uruchomionego testu #2811.")
    if expect_success:
        if result.returncode != 0 or '"passed":1' not in result.stdout:
            raise RuntimeError("Test #2811 nie przeszedł po przywróceniu źródła.")
    elif result.returncode == 0 or MARKER not in result.stdout or '"failed":1' not in result.stdout:
        raise RuntimeError("Mutant #2811 nie oblał własnego markera; fatal i błąd środowiska nie są dowodem.")


original = SOURCE.read_bytes()
mtime = SOURCE.stat().st_mtime_ns
source = original.decode("utf-8")
if source.count(OLD) != 1:
    raise RuntimeError("Kotwica blokady #2811 nie występuje dokładnie raz.")

try:
    SOURCE.write_bytes(source.replace(OLD, NEW, 1).encode("utf-8"))
    run_test(expect_success=False)
finally:
    SOURCE.write_bytes(original)
    os.utime(SOURCE, ns=(SOURCE.stat().st_atime_ns, mtime))
    if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != mtime:
        raise RuntimeError("Bajty lub mtime źródła #2811 nie zostały przywrócone.")

run_test(expect_success=True)
print("Kontrola ujemna #2811: własny marker czerwony, po przywróceniu zielony.", flush=True)
