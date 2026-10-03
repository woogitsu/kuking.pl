"""#2835: sankcja przed zaproszeniem wymaga świeżej Policy pod blokadą."""

import os
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Collections/Wspoldzielenie/ZaprosDoZeszytu.php"
ANCHOR = b"if ($wlasciciel === null || ! Gate::forUser($wlasciciel)->allows('share', $zeszyt)) {"
MUTANT = b"if (false) { // kontrola ujemna #2835: pominieta Policy"
TEST = "test_sankcja_i_zaproszenie_ukladaja_sie_w_jednej_kolejnosci"
MARKER = "ZAPROSZENIE_2835_SWIEZA_POLICY"


def run(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    env["DB_URL"] = ""
    with tempfile.TemporaryDirectory(prefix="kuking-2835-junit-") as tmp:
        junit = Path(tmp) / "wynik.xml"
        result = subprocess.run(
            ["php", "-d", "opcache.enable_cli=0", "artisan", "test", "--group=dwa-polaczenia",
             "--filter=" + TEST, "--log-junit", str(junit), "--no-ansi"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=180, check=False,
        )
        print(result.stdout, flush=True)
        if not junit.is_file():
            raise RuntimeError("Brak raportu JUnit #2835.")
        cases = ET.parse(junit).findall(".//testcase")
        if len(cases) != 4 or any(TEST not in case.attrib.get("name", "") for case in cases):
            raise RuntimeError("JUnit nie potwierdza czterech przeplotów #2835.")
        if any(case.find("skipped") is not None or case.find("error") is not None for case in cases):
            raise RuntimeError("Przeplot #2835 pominięty albo przerwany błędem środowiska.")
        failures = [failure for case in cases for failure in case.findall("failure")]
        if expect_success:
            if result.returncode != 0 or failures:
                raise RuntimeError("Bazowy test #2835 nie przeszedł.")
        elif result.returncode == 0 or len(failures) != 2 or any(
            MARKER not in ET.tostring(failure, encoding="unicode") for failure in failures
        ):
            raise RuntimeError("Mutant #2835 nie oblał obu przeplotów sankcja-pierwsza własnym markerem.")


def main() -> None:
    database = os.environ.get("DB_DATABASE", "")
    if not re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", database) or os.environ.get("DB_URL"):
        raise RuntimeError("Wymagana jawna izolowana baza kuking_race* bez DB_URL.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2835_LOKALNIE") != "1":
            raise RuntimeError("Lokalna kontrola wymaga jawnego KUKING_KONTROLA_2835_LOKALNIE=1.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise RuntimeError("Lokalna kontrola wymaga własnego PostgreSQL poza portem 5432.")

    original = SOURCE.read_bytes()
    stat = SOURCE.stat()
    if original.count(ANCHOR) != 1:
        raise RuntimeError("Kotwica świeżej Policy #2835 nie występuje dokładnie raz.")
    run(expect_success=True)
    try:
        SOURCE.write_bytes(original.replace(ANCHOR, MUTANT, 1))
        run(expect_success=False)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("Nie przywrócono dokładnych bajtów i mtime #2835.")
    run(expect_success=True)
    print("#2835: oba przeploty oblały własnym markerem; przywrócony kod przeszedł.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ET.ParseError, RuntimeError, subprocess.TimeoutExpired) as exc:
        raise SystemExit(f"Kontrola #2835 nie powiodła się: {exc}") from exc
