#!/usr/bin/env python3
"""#2861/#2862: dwa wejścia 2FA muszą ponownie sprawdzić starą sesję pod zamkiem."""

import os
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
MUTACJE = (
    (ROOT / "app/Domain/Security/Actions/WlaczDwuetapowa.php", "włączenie"),
    (ROOT / "app/Domain/Security/Actions/WylaczDwuetapowa.php", "wyłączenie"),
)
KOTWICA = "            $this->potwierdzSesje->sprawdz($swiezy, $haslo, $generacjaSesji);\n"
MARKER = "SESJA_2861_2862_BEZ_AWANSU"


def srodowisko():
    if not shutil.which("php") or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("#2861/#2862: brak PHP lub własnego vendor.")
    if os.environ.get("DB_URL") or not os.environ.get("DB_DATABASE", "").startswith("kuking_race"):
        raise RuntimeError("#2861/#2862: wymagana jawna izolowana baza kuking_race_* bez DB_URL.")
    if any(not os.environ.get(k) for k in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("#2861/#2862: wymagany jawny host, port i użytkownik bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2861_LOKALNIE") != "1":
            raise RuntimeError("#2861/#2862: lokalnie wymagane jawne opt-in.")
        if os.environ["DB_HOST"] not in ("127.0.0.1", "localhost") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("#2861/#2862: lokalnie tylko własny PG18 poza portem 5432.")


def przebieg(mutowana=None):
    with tempfile.TemporaryDirectory(prefix="kuking-2861-2862-") as katalog:
        raport = Path(katalog) / "junit.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        wynik = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia",
             "--filter=test_stare_zadanie_nie_zmienia_2fa_ani_nie_odnawia_sesji", "--no-ansi", "--log-junit=" + str(raport)],
            cwd=ROOT, env=env, timeout=180, check=False,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            text=True, encoding="utf-8", errors="replace",
        )
        print(wynik.stdout, flush=True)
        if not raport.is_file():
            raise RuntimeError("#2861/#2862: brak JUnit; scenariusz mógł się nie wykonać.")
        przypadki = list(ET.parse(raport).getroot().iter("testcase"))
        if len(przypadki) != 6:
            raise RuntimeError(f"#2861/#2862: oczekiwano 6 testów, jest {len(przypadki)}.")
        widziane = set()
        porazki = 0
        for przypadek in przypadki:
            nazwa = przypadek.get("name", "")
            etykiety = [e for e in (
                "włączenie po resecie", "włączenie po zmianie", "włączenie po resecie do tego samego hasła",
                "wyłączenie po resecie", "wyłączenie po zmianie", "wyłączenie po resecie do tego samego hasła",
            ) if f'with data set "{e}"' in nazwa]
            if len(etykiety) != 1 or etykiety[0] in widziane:
                raise RuntimeError(f"#2861/#2862: nieznany lub powtórzony test: {nazwa!r}.")
            etykieta = etykiety[0]
            widziane.add(etykieta)
            if przypadek.find("skipped") is not None or przypadek.find("error") is not None:
                raise RuntimeError("#2861/#2862: pominięcie/błąd środowiska nie jest dowodem.")
            failures = przypadek.findall("failure")
            oczekiwany = mutowana is not None and etykieta.startswith(mutowana)
            if oczekiwany:
                if len(failures) != 1:
                    raise RuntimeError(f"#2861/#2862: mutant nie oblał dokładnie raz: {nazwa!r}.")
                szczegoly = " ".join(" ".join(f.itertext()) + " " + str(f.attrib) for f in failures)
                if MARKER not in szczegoly:
                    raise RuntimeError("#2861/#2862: mutant oblał z niewłaściwego powodu: " + szczegoly)
                porazki += 1
            elif failures:
                raise RuntimeError("#2861/#2862: inna ścieżka lub kod po restore jest czerwony.")
        if mutowana is None and (wynik.returncode != 0 or porazki != 0):
            raise RuntimeError("#2861/#2862: kod bez mutacji nie przeszedł.")
        if mutowana is not None and (wynik.returncode == 0 or porazki != 3):
            raise RuntimeError("#2861/#2862: mutant nie dał trzech własnych porażek.")


def main():
    srodowisko()
    przebieg()
    for sciezka, rodzina in MUTACJE:
        oryginal = sciezka.read_bytes()
        stat = sciezka.stat()
        tekst = oryginal.decode("utf-8")
        if tekst.count(KOTWICA) != 1:
            raise RuntimeError(f"#2861/#2862: kotwica nie występuje raz: {sciezka}.")
        try:
            sciezka.write_bytes(tekst.replace(KOTWICA, "", 1).encode("utf-8"))
            przebieg(rodzina)
        finally:
            sciezka.write_bytes(oryginal)
            os.utime(sciezka, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if sciezka.read_bytes() != oryginal or sciezka.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("#2861/#2862: bajty/mtime źródła nieprzywrócone.")
    przebieg()
    print("#2861/#2862: 6 PASS; każdy mutant 3 właściwe FAIL + 3 PASS; restore 6 PASS.")


if __name__ == "__main__":
    main()
