"""#2418: cofnięcie drugiego filtra metadanych musi ujawnić pięć wyścigów.

Uruchamiane po przygotowaniu izolowanej bazy kuking_race w
scripts/testy-dwa-polaczenia.sh. JUnit odróżnia wyciek od błędu środowiska.
"""

import os
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/MojRok/MojRok.php"
ANCHOR = (
    "                ->widoczneDla($user)\n"
    "                ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())\n"
)
MARKER = "MOJ_ROK_2418_TYTUL_PO_UTRACIE_WIDOCZNOSCI"

if os.environ.get("CI") != "true":
    if os.environ.get("KUKING_KONTROLA_2418_LOKALNIE") != "1":
        raise SystemExit("Kontrola #2418 wymaga CI albo jawnego opt-in lokalnego.")
    if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
        raise SystemExit("Kontrola #2418 wymaga własnego PG18 poza domyślnym portem.")
if not os.environ.get("DB_DATABASE", "").startswith("kuking_race"):
    raise SystemExit("Kontrola #2418 odmawia bazy spoza kuking_race.")


def run_test(mutant: bool) -> None:
    with tempfile.TemporaryDirectory(prefix="kuking-2418-") as tmp:
        junit = Path(tmp) / "wynik.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        result = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia",
             "--filter=MojRokWidocznoscPoAgregacjiTest", "--log-junit", str(junit), "--no-ansi"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300, check=False,
        )
        print(result.stdout, flush=True)
        if not junit.exists():
            raise RuntimeError("Brak JUnit #2418; wynik nie dowodzi uruchomienia testów.")
        cases = ET.parse(junit).getroot().findall(".//testcase")
        if len(cases) != 6 or any(case.find("skipped") is not None for case in cases):
            raise RuntimeError("Oczekiwano dokładnie 6 wykonanych scenariuszy #2418.")

        failures = []
        for case in cases:
            found = case.findall("failure") + case.findall("error")
            if mutant and "test_zmiana_zatwierdzona" in case.attrib.get("name", ""):
                if len(found) != 1:
                    raise RuntimeError("Mutant nie oblał scenariusza widoczności dokładnie raz.")
                details = " ".join(" ".join(f.itertext()) + " " + str(f.attrib) for f in found)
                if MARKER not in details:
                    raise RuntimeError("Mutant oblał z innej przyczyny niż wyciek tytułu.")
            elif found:
                raise RuntimeError("Scenariusz dodatni oblał lub przywrócony kod nadal jest czerwony.")
            failures.extend(found)

        if mutant and (result.returncode == 0 or len(failures) != 5):
            raise RuntimeError("Mutant #2418 nie dał pięciu właściwych porażek.")
        if not mutant and (result.returncode != 0 or failures):
            raise RuntimeError("Przywrócony kod #2418 nie przeszedł sześciu scenariuszy.")


original = SOURCE.read_bytes()
atime = SOURCE.stat().st_atime_ns
mtime = SOURCE.stat().st_mtime_ns
source = original.decode("utf-8")
if source.count(ANCHOR) != 1:
    raise SystemExit("Kotwica drugiego filtra #2418 nie występuje dokładnie raz.")
mutated = source.replace(ANCHOR, "", 1).encode("utf-8")

try:
    SOURCE.write_bytes(mutated)
    run_test(mutant=True)
finally:
    SOURCE.write_bytes(original)
    os.utime(SOURCE, ns=(atime, mtime))
    if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != mtime:
        raise RuntimeError("Kod #2418 nie został przywrócony bajtowo i czasowo.")

run_test(mutant=False)
print("Kontrola #2418: pięć wycieków po usunięciu filtra, sześć zielonych po przywróceniu.", flush=True)
