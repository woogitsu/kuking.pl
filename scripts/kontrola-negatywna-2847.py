"""#2847: osobne odczyty zakupów muszą pomieszać nazwy w prawdziwym ZIP-ie."""

import os
import re
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Users/Exports/CollectUserExportData.php"
TEST = "EksportZakupowKontraZmianaNazwyTest"
MARKER = "EKSPORT_2847_NAZWY_Z_JEDNEJ_MIGAWKI"
FIX = b"""        [$pozycjeZakupow, $listyZakupow] = DB::transaction(function () use ($user): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            return [
                $user->shoppingListItems()->with('list:id,name')->orderBy('position')->orderBy('id')->get(),
                $user->shoppingLists()->orderBy('created_at')->orderBy('id')->get(),
            ];
        });"""
MUTANT = b"""        $pozycjeZakupow = $user->shoppingListItems()->with('list:id,name')->orderBy('position')->orderBy('id')->get();
        $listyZakupow = $user->shoppingLists()->orderBy('created_at')->orderBy('id')->get();"""


def run(expect_success: bool) -> None:
    env = os.environ.copy()
    env["APP_BASE_PATH"] = str(ROOT)
    with tempfile.TemporaryDirectory(prefix="kuking-2847-junit-") as tmp:
        report = Path(tmp) / "wynik.xml"
        result = subprocess.run(
            ["php", "-d", "opcache.enable_cli=0", "artisan", "test", "--group=dwa-polaczenia",
             "--filter=" + TEST, "--log-junit", str(report), "--no-ansi"],
            cwd=ROOT, env=env, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=180, check=False,
        )
        print(result.stdout, flush=True)
        if not report.is_file():
            raise RuntimeError("Brak JUnit #2847; błąd środowiska nie jest dowodem.")
        cases = ET.parse(report).findall(".//testcase")
        if len(cases) != 1 or TEST not in cases[0].attrib.get("classname", ""):
            raise RuntimeError("JUnit nie potwierdza dokładnie jednego testu #2847.")
        case = cases[0]
        if case.find("skipped") is not None or case.find("error") is not None:
            raise RuntimeError("Test #2847 pominięty albo przerwany błędem środowiska.")
        failures = case.findall("failure")
        if expect_success:
            if result.returncode != 0 or failures:
                raise RuntimeError("Bazowy test #2847 nie przeszedł.")
        elif result.returncode == 0 or len(failures) != 1 or MARKER not in ET.tostring(failures[0], encoding="unicode"):
            raise RuntimeError("Mutant osobnych odczytów nie oblał ZIP-a własnym markerem #2847.")


def main() -> None:
    database = os.environ.get("DB_DATABASE", "")
    if not re.fullmatch(r"kuking_race(?:_[A-Za-z0-9_]+)?", database) or os.environ.get("DB_URL"):
        raise RuntimeError("Wymagana jawna izolowana baza kuking_race* bez DB_URL.")
    if not shutil.which("php") or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("Brak PHP lub własnego vendor; kontroli nie wykonano.")
    if any(not os.environ.get(key) for key in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("Wymagany jawny host, port i użytkownik bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2847_LOKALNIE") != "1":
            raise RuntimeError("Lokalna kontrola wymaga jawnego opt-in.")
        if os.environ["DB_HOST"] not in ("127.0.0.1", "localhost") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("Lokalna kontrola wymaga własnego PostgreSQL poza portem 5432.")

    original = SOURCE.read_bytes()
    stat = SOURCE.stat()
    if original.count(FIX) != 1:
        raise RuntimeError("Kotwica transakcji #2847 nie występuje dokładnie raz.")
    run(expect_success=True)
    try:
        SOURCE.write_bytes(original.replace(FIX, MUTANT, 1))
        run(expect_success=False)
    finally:
        SOURCE.write_bytes(original)
        os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if SOURCE.read_bytes() != original or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("Nie odtworzono dokładnych bajtów i mtime #2847.")
    run(expect_success=True)
    print("#2847: mutant oblał prawdziwy ZIP; po odtworzeniu test przeszedł.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ET.ParseError, RuntimeError, subprocess.TimeoutExpired) as exc:
        raise SystemExit(f"Kontrola #2847 nie powiodła się: {exc}") from exc
