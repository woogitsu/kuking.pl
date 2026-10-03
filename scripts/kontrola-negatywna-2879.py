#!/usr/bin/env python3
"""#2879: świeże konto pod istniejącym zamkiem, pięć odmów i pięć odwrotnych przeplotów."""

import hashlib
import os
import re
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Recipes/Gotowanie/Wspolne/PostepWspolnegoGotowania.php"
CLASS = r"Tests\Dwa\PostepWspolnegoGotowaniaPoZawieszeniuTest"
REFUSAL = "test_zawieszenie_zatwierdzone_po_policy_odmawia_bez_zmiany_postepu"
REVERSE = "test_postep_pod_zamkiem_konta_konczy_sie_przed_publicznym_zawieszeniem"
VARIANTS = ("gospodarz-zrobiono", "gospodarz-cofnieto", "pomocnik-zrobiono", "pomocnik-cofnieto", "gospodarz-wyczysc")
REFUSALS = {f'{REFUSAL} with data set "{v}"' for v in VARIANTS}
CASES = REFUSALS | {f'{REVERSE} with data set "{v}"' for v in VARIANTS}
MARKER = "WSPOLNE_2879_SWIEZE_KONTO_ODMOWA"
ANCHOR = "        if ($swiezaOsoba === null || ! $swiezaOsoba->isActive()) {"
REPLACEMENT = "        if (! $osoba->isActive()) {"
ENV_FAILURE = re.compile(r"SQLSTATE|40P01|55P03|57014|timed out|lock_timeout|statement_timeout", re.I)


def srodowisko():
    if not shutil.which("php") or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("#2879: brak PHP lub własnego vendor.")
    baza = os.environ.get("DB_DATABASE", "")
    if (os.environ.get("DB_URL") or not baza.startswith("kuking_race")
            or (baza == "kuking_race" and os.environ.get("CI") != "true")):
        raise RuntimeError("#2879: wymagana jawna izolowana baza kuking_race_* bez DB_URL.")
    if any(not os.environ.get(k) for k in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("#2879: wymagany jawny host, port i użytkownik bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2879_LOKALNIE") != "1":
            raise RuntimeError("#2879: lokalnie wymagane jawne opt-in.")
        if os.environ["DB_HOST"] not in ("127.0.0.1", "localhost") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("#2879: lokalnie tylko własny PG18 poza portem 5432.")


def sprawdz_junit(raport, kod, mutowany=False):
    """Dokładnie 10 własnych przypadków; mutant oblewa tylko pięć odmów."""
    if not raport.is_file():
        raise RuntimeError("#2879: brak JUnit.")
    przypadki = list(ET.parse(raport).getroot().iter("testcase"))
    if len(przypadki) != len(CASES):
        raise RuntimeError("#2879: oczekiwano dokładnie dziesięciu przypadków.")
    widziane = set()
    for przypadek in przypadki:
        nazwa = przypadek.get("name", "")
        if (przypadek.get("class") != CLASS or przypadek.get("classname") != CLASS.replace("\\", ".")
                or nazwa not in CASES or nazwa in widziane):
            raise RuntimeError("#2879: obca klasa/metoda, nieznany wariant lub duplikat.")
        widziane.add(nazwa)
        if przypadek.find("skipped") is not None or przypadek.find("error") is not None:
            raise RuntimeError("#2879: pominięcie/błąd środowiska nie jest dowodem.")
        failures = przypadek.findall("failure")
        if mutowany and nazwa in REFUSALS:
            if len(failures) != 1:
                raise RuntimeError("#2879: mutant nie oblał dokładnie raz każdej właściwej odmowy.")
            szczegoly = " ".join(" ".join(f.itertext()) + " " + str(f.attrib) for f in failures)
            if MARKER not in szczegoly or ENV_FAILURE.search(szczegoly):
                raise RuntimeError("#2879: obca przyczyna, SQLSTATE lub timeout nie potwierdza mutacji.")
        elif failures:
            raise RuntimeError("#2879: dodatni przebieg lub odwrotna kolejność jest czerwona.")
    if kod != (1 if mutowany else 0):
        raise RuntimeError("#2879: kod wyjścia nie odpowiada właściwemu przebiegowi.")


def mutant(tekst):
    if tekst.count(ANCHOR) != 1:
        raise RuntimeError("#2879: kotwica podmiany nie występuje dokładnie raz.")
    return tekst.replace(ANCHOR, REPLACEMENT, 1)


def przebieg(mutowany=False):
    with tempfile.TemporaryDirectory(prefix="kuking-2879-") as katalog:
        raport = Path(katalog) / "junit.xml"
        env = os.environ.copy()
        env.update(APP_BASE_PATH=str(ROOT), APP_ENV="testing")
        wynik = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia", "--filter=PostepWspolnegoGotowaniaPoZawieszeniuTest",
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
            raise RuntimeError("#2879: bajty/mtime domeny nieprzywrócone.")
        print(f"#2879 restore SHA256={hashlib.sha256(oryginal).hexdigest()} mtime_ns={stat.st_mtime_ns}", flush=True)
    przebieg()
    print("#2879: 10 PASS → stary model 5 właściwych FAIL + 5 PASS → bytes/mtime restore → 10 PASS.")


if __name__ == "__main__":
    main()
