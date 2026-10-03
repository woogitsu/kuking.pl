#!/usr/bin/env python3
"""#2861: literalny panel moderatora i wspólna transakcja potwierdzenia 2FA."""

import hashlib
import os
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "app/Domain/Security/Actions/WlaczDwuetapowa.php"
CLASS = r"Tests\Dwa\Potwierdzenie2faPodWspolnaBlokadaTest"
COOKIE_TEST = "test_spoznione_potwierdzenie_nie_otwiera_panelu_literalnym_cookie"
WINDOW_TEST = "test_potwierdzenie_i_odwolanie_sesji_serializuja_reset"
CASES = {
    **{f'{COOKIE_TEST} with data set "{label}"': "cookie" for label in
       ("reset", "zmiana", "reset identycznego hasła")},
    **{f'{WINDOW_TEST} with data set "{label}"': "okno" for label in
       ("nowe hasło", "identyczne hasło")},
}
MARKERS = {"cookie": "2FA_2861_COOKIE_A_PANEL_ODMOWA", "okno": "2FA_2861_OKNO_WSPOLNA_BLOKADA"}


def srodowisko():
    if not shutil.which("php") or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("#2861: brak PHP lub własnego vendor.")
    baza = os.environ.get("DB_DATABASE", "")
    if (os.environ.get("DB_URL") or not baza.startswith("kuking_race")
            or (baza == "kuking_race" and os.environ.get("CI") != "true")):
        raise RuntimeError("#2861: wymagana jawna izolowana baza kuking_race_* bez DB_URL.")
    if any(not os.environ.get(k) for k in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("#2861: wymagany jawny host, port i użytkownik bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2861_LOKALNIE") != "1":
            raise RuntimeError("#2861: lokalnie wymagane jawne opt-in.")
        if os.environ["DB_HOST"] not in ("127.0.0.1", "localhost") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("#2861: lokalnie tylko własny PG18 poza portem 5432.")


def sprawdz_junit(raport, kod, mutowana=None):
    """Pięć dokładnych przypadków; pominięcie lub cudza porażka odmawia dowodu."""
    if not raport.is_file():
        raise RuntimeError("#2861: brak JUnit.")
    przypadki = list(ET.parse(raport).getroot().iter("testcase"))
    if len(przypadki) != len(CASES):
        raise RuntimeError("#2861: oczekiwano dokładnie pięciu przypadków.")
    widziane = set()
    porazki = 0
    for przypadek in przypadki:
        nazwa = przypadek.get("name", "")
        if (przypadek.get("class") != CLASS or przypadek.get("classname") != CLASS.replace("\\", ".")
                or nazwa not in CASES or nazwa in widziane):
            raise RuntimeError("#2861: obca klasa/metoda, nieznany wariant lub duplikat.")
        widziane.add(nazwa)
        if przypadek.find("skipped") is not None or przypadek.find("error") is not None:
            raise RuntimeError("#2861: pominięcie/błąd środowiska nie jest dowodem.")
        failures = przypadek.findall("failure")
        if mutowana is not None and CASES[nazwa] == mutowana:
            if len(failures) != 1:
                raise RuntimeError("#2861: mutant nie oblał dokładnie raz właściwego przypadku.")
            szczegoly = " ".join(" ".join(f.itertext()) + " " + str(f.attrib) for f in failures)
            if MARKERS[mutowana] not in szczegoly:
                raise RuntimeError("#2861: porażka nie ma własnego markera.")
            porazki += 1
        elif failures:
            raise RuntimeError("#2861: kod dodatni lub druga rodzina przypadków jest czerwony.")
    oczekiwane = sum(rodzina == mutowana for rodzina in CASES.values()) if mutowana else 0
    if porazki != oczekiwane or (kod != 0 if mutowana is None else kod != 1):
        raise RuntimeError("#2861: liczba porażek lub kod wyjścia nie odpowiada właściwemu przebiegowi.")


def przebieg(mutowana=None):
    with tempfile.TemporaryDirectory(prefix="kuking-2861-uzupelnienie-") as katalog:
        raport = Path(katalog) / "junit.xml"
        env = os.environ.copy()
        env.update(APP_BASE_PATH=str(ROOT), APP_ENV="testing")
        wynik = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia", "--filter=Potwierdzenie2faPodWspolnaBlokadaTest",
             "--no-ansi", "--log-junit=" + str(raport)],
            cwd=ROOT, env=env, timeout=180, check=False,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            text=True, encoding="utf-8", errors="replace",
        )
        print(wynik.stdout, flush=True)
        sprawdz_junit(raport, wynik.returncode, mutowana)


def zastap_raz(tekst, stare, nowe):
    if tekst.count(stare) != 1:
        raise RuntimeError("#2861: kotwica mutacji nie występuje dokładnie raz.")
    return tekst.replace(stare, nowe, 1)


def mutant(tekst, rodzina):
    if rodzina == "cookie":
        return zastap_raz(tekst, "            $this->potwierdzSesje->sprawdz($swiezy, $haslo, $generacjaSesji);\n", "")
    tekst = zastap_raz(tekst, "            $swiezy->invalidateSessions($zachowajSesje);\n", "")
    return zastap_raz(tekst, "        AuditLogEntry::recordBezWywracania('account.two_factor_enabled'",
                     "        $user->invalidateSessions($zachowajSesje);\n\n        AuditLogEntry::recordBezWywracania('account.two_factor_enabled'")


def main():
    srodowisko()
    przebieg()
    for rodzina in MARKERS:
        oryginal = SOURCE.read_bytes()
        stat = SOURCE.stat()
        try:
            SOURCE.write_bytes(mutant(oryginal.decode("utf-8"), rodzina).encode("utf-8"))
            przebieg(rodzina)
        finally:
            SOURCE.write_bytes(oryginal)
            os.utime(SOURCE, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if SOURCE.read_bytes() != oryginal or SOURCE.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("#2861: bajty/mtime źródła nieprzywrócone.")
        print(f"#2861 {rodzina}: restore SHA256={hashlib.sha256(oryginal).hexdigest()} mtime_ns={stat.st_mtime_ns}", flush=True)
        przebieg()
    print("#2861 uzupełnienie: 5 PASS; cookie 3 właściwe FAIL + 2 PASS; okno 2 właściwe FAIL + 3 PASS; każde restore 5 PASS.")


if __name__ == "__main__":
    main()
