#!/usr/bin/env python3
"""S4: dosłowne cookie A/B mierzone w nowych procesach, także fizyczna podmiana."""

import hashlib
import os
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "tests/Dwa/SpoznioneAkcjeLiteralnychCookiesTest.php"
CLASS = r"Tests\Dwa\SpoznioneAkcjeLiteralnychCookiesTest"
METHOD = "test_spozniona_akcja_odmawia_cookie_a_i_zachowuje_literalne_cookie_b"
CASES = {
    f'{METHOD} with data set "{a}-{b}"'
    for a in ("zmiana", "wyloguj", "wylacz2fa")
    for b in ("reset", "zmiana", "reset-identyczny")
}
MARKER = "COOKIE_S4_A_ODMOWA"
ANCHOR = "        $cookieA = $wynikA['wartosc']['cookie'];"
REPLACEMENT = "        $cookieA = $cookieB;"


def srodowisko():
    if not shutil.which("php") or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("S4 cookies: brak PHP lub własnego vendor.")
    baza = os.environ.get("DB_DATABASE", "")
    if (os.environ.get("DB_URL") or not baza.startswith("kuking_race")
            or (baza == "kuking_race" and os.environ.get("CI") != "true")):
        raise RuntimeError("S4 cookies: wymagana jawna izolowana baza kuking_race_* bez DB_URL.")
    if any(not os.environ.get(k) for k in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("S4 cookies: wymagany jawny host, port i użytkownik bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_S4_COOKIES_LOKALNIE") != "1":
            raise RuntimeError("S4 cookies: lokalnie wymagane jawne opt-in.")
        if os.environ["DB_HOST"] not in ("127.0.0.1", "localhost") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("S4 cookies: lokalnie tylko własny PG18 poza portem 5432.")


def sprawdz_junit(raport, kod, mutowany=False):
    """Dziewięć dokładnych przypadków; każda podmiana ma własną porażkę."""
    if not raport.is_file():
        raise RuntimeError("S4 cookies: brak JUnit.")
    przypadki = list(ET.parse(raport).getroot().iter("testcase"))
    if len(przypadki) != len(CASES):
        raise RuntimeError("S4 cookies: oczekiwano dokładnie dziewięciu przypadków.")
    widziane = set()
    for przypadek in przypadki:
        nazwa = przypadek.get("name", "")
        if (przypadek.get("class") != CLASS or przypadek.get("classname") != CLASS.replace("\\", ".")
                or nazwa not in CASES or nazwa in widziane):
            raise RuntimeError("S4 cookies: obca klasa/metoda, nieznany wariant lub duplikat.")
        widziane.add(nazwa)
        if przypadek.find("skipped") is not None or przypadek.find("error") is not None:
            raise RuntimeError("S4 cookies: pominięcie/błąd środowiska nie jest dowodem.")
        failures = przypadek.findall("failure")
        if mutowany:
            if len(failures) != 1:
                raise RuntimeError("S4 cookies: mutant nie oblał dokładnie raz każdego właściwego przypadku.")
            szczegoly = " ".join(" ".join(f.itertext()) + " " + str(f.attrib) for f in failures)
            if MARKER not in szczegoly:
                raise RuntimeError("S4 cookies: porażka nie ma własnego markera podmienionego cookie.")
        elif failures:
            raise RuntimeError("S4 cookies: dodatni przebieg jest czerwony.")
    if kod != (1 if mutowany else 0):
        raise RuntimeError("S4 cookies: kod wyjścia nie odpowiada właściwemu przebiegowi.")


def mutant(tekst):
    if tekst.count(ANCHOR) != 1:
        raise RuntimeError("S4 cookies: kotwica podmiany nie występuje dokładnie raz.")
    return tekst.replace(ANCHOR, REPLACEMENT, 1)


def przebieg(mutowany=False):
    with tempfile.TemporaryDirectory(prefix="kuking-s4-cookies-") as katalog:
        raport = Path(katalog) / "junit.xml"
        env = os.environ.copy()
        env.update(APP_BASE_PATH=str(ROOT), APP_ENV="testing")
        wynik = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia", "--filter=SpoznioneAkcjeLiteralnychCookiesTest",
             "--no-ansi", "--log-junit=" + str(raport)],
            cwd=ROOT, env=env, timeout=180, check=False,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            text=True, encoding="utf-8", errors="replace",
        )
        print(wynik.stdout, flush=True)
        sprawdz_junit(raport, wynik.returncode, mutowany)


def main():
    srodowisko()
    przebieg()
    oryginal = SOURCE.read_bytes()
    stat = SOURCE.stat()
    try:
        SOURCE.write_bytes(mutant(oryginal.decode("utf-8")).encode("utf-8"))
        przebieg(True)
    finally:
        SOURCE.write_bytes(oryginal)
        os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if SOURCE.read_bytes() != oryginal or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("S4 cookies: bajty/mtime przyrządu nieprzywrócone.")
        print(f"S4 cookies: restore SHA256={hashlib.sha256(oryginal).hexdigest()} mtime_ns={stat.st_mtime_ns}", flush=True)
    przebieg()
    print("S4 literalne cookies: 9 PASS → podmiana A na B 9 właściwych FAIL → bytes/mtime restore → 9 PASS.")


if __name__ == "__main__":
    main()
