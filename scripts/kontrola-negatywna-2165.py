"""#2165: odwrócona kolejność blokad musi odtworzyć 40P01 na dwóch połączeniach.

Uruchamiane po przygotowaniu osobnej bazy kuking_race przez
scripts/testy-dwa-polaczenia.sh. Zmienia źródło tylko na czas testu i zawsze
przywraca dokładne bajty. Lokalnie wymaga jawnego opt-in i własnego portu PG.
"""

import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Moderation/Actions/DecyzjaPoOdwolaniu.php"
TEST = "OdwolanieNieZakleszczaEdycjiPrzepisuTest::test_ban_po_odwolaniu_i_edycja_tego_samego_przepisu_nie_zakleszczaja_sie"


if os.environ.get("CI") != "true":
    if os.environ.get("KUKING_KONTROLA_2165_LOKALNIE") != "1":
        raise SystemExit("Kontrola #2165 wymaga CI albo jawnego KUKING_KONTROLA_2165_LOKALNIE=1.")
    if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
        raise SystemExit("Kontrola #2165 wymaga własnego lokalnego PostgreSQL poza portem 5432.")


def replace_once(source: str, old: str, new: str) -> str:
    if source.count(old) != 1:
        raise RuntimeError("Kotwica mutacji #2165 nie występuje dokładnie raz.")
    return source.replace(old, new, 1)


def odwroc_kolejnosc(source: str) -> str:
    source = replace_once(
        source,
        "        $cel = $this->zablokujCel($pierwotna);\n\n        if ($cel === null",
        "        if ($cel === null",
    )
    return replace_once(
        source,
        "        $zablokowanaOsoba = null;\n\n        if ($karaKonta) {",
        "        $zablokowanaOsoba = null;\n        $cel = $this->zablokujCel($pierwotna);\n\n        if ($karaKonta) {",
    )


def run_test(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    result = subprocess.run(
        ["php", "artisan", "test", "--filter=" + TEST, "--no-ansi"],
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
    print(result.stdout, flush=True)
    if "No tests found" in result.stdout or "Tests:" not in result.stdout:
        raise RuntimeError("Nie ma dowodu, że test #2165 naprawdę się uruchomił.")
    if expect_success:
        if result.returncode != 0:
            raise RuntimeError("Test #2165 nie przeszedł po przywróceniu źródła.")
    elif result.returncode == 0 or "FAILED" not in result.stdout or "40P01" not in result.stdout:
        raise RuntimeError("Odwrócona kolejność nie wykazała zakleszczenia 40P01.")


original = SOURCE.read_bytes()
mutated = odwroc_kolejnosc(original.decode("utf-8")).encode("utf-8")
if mutated == original:
    raise RuntimeError("Mutacja #2165 nie zmieniła źródła.")

try:
    SOURCE.write_bytes(mutated)
    run_test(expect_success=False)
finally:
    SOURCE.write_bytes(original)
    if SOURCE.read_bytes() != original:
        raise RuntimeError("Źródło #2165 nie zostało przywrócone.")

run_test(expect_success=True)
print("Kontrola ujemna #2165 wykazała 40P01; po przywróceniu test jest zielony.", flush=True)
