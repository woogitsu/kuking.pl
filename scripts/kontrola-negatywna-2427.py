"""#2427: odwrócenie media→users w CSAM musi odtworzyć 40P01.

Wywoływane na izolowanej bazie kuking_race przez testy-dwa-polaczenia.sh.
Przywraca dokładne bajty i mtime źródła także po błędzie testu.
"""

import hashlib
import os
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Moderation/Actions/ZabezpieczDowodCsam.php"
TEST = "test_zabezpieczenie_obok_edycji_przepisu_z_tym_samym_zdjeciem_nie_zakleszcza_sie"

if os.environ.get("CI") != "true":
    if os.environ.get("KUKING_KONTROLA_2427_LOKALNIE") != "1":
        raise SystemExit("Kontrola #2427 wymaga CI albo jawnego KUKING_KONTROLA_2427_LOKALNIE=1.")
    if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
        raise SystemExit("Kontrola #2427 wymaga własnego lokalnego PostgreSQL poza portem 5432.")

if not os.environ.get("DB_DATABASE", "").startswith("kuking_race"):
    raise SystemExit("Kontrola #2427 odmawia bazy spoza rodziny kuking_race.")


def replace_once(source: str, old: str, new: str) -> str:
    if source.count(old) != 1:
        raise RuntimeError("Kotwica mutacji #2427 nie występuje dokładnie raz.")
    return source.replace(old, new, 1)


def mutate(source: str) -> str:
    # Wstępny odczyt zdjęcia nie trzyma już blokady. Akcja bierze teraz
    # users przez ZamekUprzywilejowanegoAktora, a dopiero potem media.
    source = replace_once(
        source,
        "$zdjecie = Media::query()->whereKey($media)->lockForUpdate()->first();",
        "$zdjecie = Media::query()->whereKey($media)->first();",
    )
    return replace_once(
        source,
        "                Gate::forUser($swiezy)->authorize('secureCsam', User::class);",
        "                Gate::forUser($swiezy)->authorize('secureCsam', User::class);\n"
        "                foreach ($mediaId as $media) {\n"
        "                    Media::query()->whereKey($media)->lockForUpdate()->first();\n"
        "                }",
    )


def run_test(expect_deadlock: bool) -> None:
    with tempfile.TemporaryDirectory(prefix="kuking-2427-") as tmp:
        junit = Path(tmp) / "wynik.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        result = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia", "--filter=" + TEST,
             "--log-junit", str(junit), "--no-ansi"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=180, check=False,
        )
        print(result.stdout, flush=True)
        if not junit.exists():
            raise RuntimeError("Brak raportu JUnit #2427; nie wiadomo, czy test się uruchomił.")
        report = ET.parse(junit).getroot()
        cases = report.findall(".//testcase")
        if len(cases) != 1 or TEST not in cases[0].attrib.get("name", ""):
            raise RuntimeError("JUnit #2427 nie zawiera dokładnie właściwego testu.")
        failures = cases[0].findall("failure") + cases[0].findall("error")
        if expect_deadlock:
            details = " ".join(" ".join(f.itertext()) + " " + str(f.attrib) for f in failures)
            if result.returncode == 0 or "40P01" not in details:
                raise RuntimeError("Odwrócona kolejność #2427 nie wykazała 40P01.")
        elif result.returncode != 0 or failures:
            raise RuntimeError("Test #2427 nie przeszedł po przywróceniu źródła.")


original = SOURCE.read_bytes()
source_stat = SOURCE.stat()
newline = "\r\n" if b"\r\n" in original else "\n"
mutated_text = mutate(original.decode("utf-8").replace("\r\n", "\n"))
mutated = mutated_text.replace("\n", newline).encode("utf-8")
if mutated == original:
    raise RuntimeError("Mutacja #2427 nie zmieniła źródła.")

try:
    SOURCE.write_bytes(mutated)
    run_test(expect_deadlock=True)
finally:
    SOURCE.write_bytes(original)
    os.utime(SOURCE, ns=(source_stat.st_atime_ns, source_stat.st_mtime_ns))
    if hashlib.sha256(SOURCE.read_bytes()).digest() != hashlib.sha256(original).digest() or SOURCE.stat().st_mtime_ns != source_stat.st_mtime_ns:
        raise RuntimeError("Źródło #2427 nie zostało przywrócone bajt w bajt i z tym samym mtime.")

run_test(expect_deadlock=False)
print("Kontrola ujemna #2427 wykazała 40P01; przywrócony kod przeszedł test.", flush=True)
