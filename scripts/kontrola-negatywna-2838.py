"""#2838: stara kolejność zaproszenie → konta musi odtworzyć 40P01."""

import os
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Collections/Wspoldzielenie/OdpowiedzNaZaproszenie.php"
OPEN = b"        ZamekPary::zablokuj($osoba, $wlasciciel, function (?User $swiezaOsoba, ?User $swiezyWlasciciel) use ($zaproszenie): void {"
CLOSE = b"        });\n    }\n\n    /**\n     * Zaproszenie po nazwie konta"
MUTANT_OPEN = (
    b"        DB::transaction(function () use ($osoba, $wlasciciel, $zaproszenie): void {\n"
    b"            CollectionInvitation::query()->whereKey($zaproszenie->getKey())->lockForUpdate()->first();\n"
    b"            ZamekPary::zablokuj($osoba, $wlasciciel, function (?User $swiezaOsoba, ?User $swiezyWlasciciel) use ($zaproszenie): void {"
)
MUTANT_CLOSE = b"            });\n        });\n    }\n\n    /**\n     * Zaproszenie po nazwie konta"
TEST = "test_odpowiedzi_na_link_nie_zakleszczaja_sie"
MARKER = "ZAPROSZENIE_2838_BEZ_40P01"


def run(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    env["DB_URL"] = ""
    with tempfile.TemporaryDirectory(prefix="kuking-2838-junit-") as tmp:
        junit = Path(tmp) / "wynik.xml"
        result = subprocess.run(
            ["php", "-d", "opcache.enable_cli=0", "artisan", "test", "--group=dwa-polaczenia",
             "--filter=" + TEST, "--log-junit", str(junit), "--no-ansi"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120, check=False,
        )
        print(result.stdout, flush=True)
        if not junit.is_file():
            raise RuntimeError("Brak raportu JUnit #2838.")
        cases = ET.parse(junit).findall(".//testcase")
        if len(cases) != 2 or any(TEST not in case.attrib.get("name", "") for case in cases):
            raise RuntimeError("JUnit nie potwierdza obu przeplotów #2838.")
        if any(case.find("skipped") is not None or case.find("error") is not None for case in cases):
            raise RuntimeError("Przeplot pominięty albo przerwany błędem środowiska.")
        failures = [failure for case in cases for failure in case.findall("failure")]
        if expect_success:
            if result.returncode != 0 or failures:
                raise RuntimeError("Bazowy test #2838 nie przeszedł.")
        elif result.returncode == 0 or len(failures) != 1 or MARKER not in ET.tostring(failures[0], encoding="unicode"):
            raise RuntimeError("Mutant #2838 nie oblał właściwego przeplotu markerem 40P01.")


def main() -> None:
    database = os.environ.get("DB_DATABASE", "")
    if not re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", database) or os.environ.get("DB_URL"):
        raise RuntimeError("Wymagana jawna izolowana baza kuking_race* bez DB_URL.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2838_LOKALNIE") != "1":
            raise RuntimeError("Lokalna kontrola wymaga jawnego opt-in.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise RuntimeError("Lokalna kontrola wymaga własnego PostgreSQL poza portem 5432.")

    original = SOURCE.read_bytes()
    stat = SOURCE.stat()
    if original.count(OPEN) != 1 or original.count(CLOSE) != 1:
        raise RuntimeError("Kotwice kolejności #2838 nie występują dokładnie raz.")
    run(expect_success=True)
    try:
        SOURCE.write_bytes(original.replace(OPEN, MUTANT_OPEN, 1).replace(CLOSE, MUTANT_CLOSE, 1))
        run(expect_success=False)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("Nie przywrócono dokładnych bajtów i mtime #2838.")
    run(expect_success=True)
    print("#2838: odwrócona kolejność dała 40P01 z własnym markerem, przywrócony kod przeszedł.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ET.ParseError, RuntimeError, subprocess.TimeoutExpired) as exc:
        raise SystemExit(f"Kontrola #2838 nie powiodła się: {exc}") from exc
