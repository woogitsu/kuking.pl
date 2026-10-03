"""#2863: bez wspólnej blokady spóźniony builder odtwarza wycofany Atom."""

import os
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Kanaly/CacheKanalu.php"
ANCHOR = (b"    public static function zapomnij(string $klucz): void\n"
          b"    {\n"
          b"        DB::transaction(static function () use ($klucz): void {\n"
          b"            self::zablokuj($klucz);")
MUTANT = ANCHOR.replace(b"            self::zablokuj($klucz);",
                        b"            // mutant: uniewaznienie bez blokady buildera")
TEST = "KanalAtomUniewaznienieNaDwochPolaczeniachTest::test_pozny_builder_nie_przywraca_ukrytego_wpisu"
MARKER = "ATOM_2863_BEZ_POWROTU"


def run(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    env["DB_URL"] = ""
    env["CACHE_STORE"] = "database"
    with tempfile.TemporaryDirectory(prefix="kuking-2863-junit-") as tmp:
        junit = Path(tmp) / "wynik.xml"
        result = subprocess.run(
            ["php", "-d", "opcache.enable_cli=0", "artisan", "test", "--group=dwa-polaczenia",
             "--filter=" + TEST, "--log-junit", str(junit), "--no-ansi"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=180, check=False,
        )
        if not junit.is_file():
            raise RuntimeError("Brak raportu JUnit #2863: " + result.stdout[-1000:])
        cases = ET.parse(junit).findall(".//testcase")
        if len(cases) != 3 or any(TEST.split("::")[1] not in c.attrib.get("name", "") for c in cases):
            raise RuntimeError("JUnit nie potwierdza trzech kanałów i rzeczywistego przeplotu #2863.")
        if any(c.find("skipped") is not None or c.find("error") is not None for c in cases):
            raise RuntimeError("Przeplot #2863 pominięty lub przerwany błędem środowiska.")
        failures = [f for c in cases for f in c.findall("failure")]
        if expect_success:
            if result.returncode != 0 or failures:
                raise RuntimeError("Bazowe trzy przeploty #2863 nie przeszły: " + result.stdout[-1000:])
        elif result.returncode == 0 or len(failures) != 3 or any(
            MARKER not in ET.tostring(f, encoding="unicode") for f in failures
        ):
            raise RuntimeError("Mutant nie oblał wszystkich trzech kanałów własnym markerem #2863.")
        print(f"#2863: {len(cases)} kanały, {len(failures)} właściwe porażki, exit={result.returncode}", flush=True)


def main() -> None:
    database = os.environ.get("DB_DATABASE", "")
    if not re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", database) or os.environ.get("DB_URL"):
        raise RuntimeError("Wymagana jawna izolowana baza kuking_race* bez DB_URL.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2863_LOKALNIE") != "1":
            raise RuntimeError("Lokalna kontrola wymaga jawnego opt-in.")
        if os.environ.get("DB_HOST") not in ("127.0.0.1", "localhost") or os.environ.get("DB_PORT") in (None, "", "5432"):
            raise RuntimeError("Lokalna kontrola wymaga własnego PostgreSQL poza portem 5432.")

    original = SOURCE.read_bytes()
    stat = SOURCE.stat()
    if original.count(ANCHOR) != 1:
        raise RuntimeError("Kotwica blokady unieważnienia #2863 nie jest jednoznaczna.")
    run(expect_success=True)
    try:
        SOURCE.write_bytes(original.replace(ANCHOR, MUTANT, 1))
        run(expect_success=False)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("Nie przywrócono dokładnych bajtów i mtime #2863.")
    run(expect_success=True)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ET.ParseError, RuntimeError, subprocess.TimeoutExpired) as exc:
        raise SystemExit(f"Kontrola #2863 nie powiodła się: {exc}") from exc
