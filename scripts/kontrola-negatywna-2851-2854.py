#!/usr/bin/env python3
"""#2851/#2854: stary precheck nie może awansować odwołanej sesji.

Tylko izolowana baza wyścigów. Dwie osobne mutacje zdejmują bramkę pod
ZamekKonta, a JUnit wymaga dokładnie własnych trzech porażek dla każdej drogi.
"""

import os
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
HASLO = ROOT / "app/Domain/Users/Actions/UstawNoweHaslo.php"
SESJE = ROOT / "app/Domain/Users/Actions/WylogujInneSesje.php"
MUTACJE = (
    (HASLO, "                $this->potwierdzSesje->sprawdz($swiezy, (string) $obecneHaslo, (int) $generacjaSesji);\n",
     "zmiana hasła", "HASLO_2851_STARA_SESJA_NIE_ZMIENIA"),
    (SESJE, "            $this->potwierdzSesje->sprawdz($swiezy, $obecneHaslo, $generacjaSesji);\n",
     "wylogowanie innych", "SESJE_2854_STARA_SESJA_NIE_AWANSUJE"),
)


def sprawdz_srodowisko():
    if not shutil.which("php") or not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("#2851/#2854: brak PHP albo własnego vendor; kontrola nie ruszyła.")
    if os.environ.get("DB_URL") or not os.environ.get("DB_DATABASE", "").startswith("kuking_race"):
        raise RuntimeError("#2851/#2854: wymagana jawna kuking_race_* bez DB_URL.")
    if any(not os.environ.get(key) for key in ("DB_HOST", "DB_PORT", "DB_USERNAME")):
        raise RuntimeError("#2851/#2854: brak jawnego hosta, portu albo właściciela bazy.")
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLA_2851_LOKALNIE") != "1":
            raise RuntimeError("#2851/#2854: lokalnie wymagane jawne opt-in.")
        if os.environ["DB_HOST"] not in ("127.0.0.1", "localhost") or os.environ["DB_PORT"] == "5432":
            raise RuntimeError("#2851/#2854: lokalnie wyłącznie własny PG18 poza portem 5432.")


def przebieg(mutacja=None):
    with tempfile.TemporaryDirectory(prefix="kuking-2851-2854-") as katalog:
        raport = Path(katalog) / "junit.xml"
        env = os.environ.copy()
        env["APP_BASE_PATH"] = str(ROOT)
        wynik = subprocess.run(
            ["php", "artisan", "test", "--group=dwa-polaczenia",
             "--filter=SpoznioneZabezpieczenieKontaTest", "--no-ansi", "--log-junit=" + str(raport)],
            cwd=ROOT, env=env, timeout=180, check=False,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            text=True, encoding="utf-8", errors="replace",
        )
        print(wynik.stdout, flush=True)
        if not raport.is_file():
            raise RuntimeError("#2851/#2854: brak JUnit, nie wiadomo czy test się wykonał.")
        przypadki = list(ET.parse(raport).getroot().iter("testcase"))
        if len(przypadki) != 6:
            raise RuntimeError(f"#2851/#2854: oczekiwano sześciu testów, jest {len(przypadki)}.")
        etykiety = set()
        porazki = 0
        for przypadek in przypadki:
            nazwa = przypadek.get("name", "")
            pasuje = [etykieta for etykieta in (
                "zmiana hasła po resecie", "zmiana hasła po zmianie w drugiej sesji",
                "zmiana hasła po resecie do tego samego hasła",
                "wylogowanie innych po resecie", "wylogowanie innych po zmianie w drugiej sesji",
                "wylogowanie innych po resecie do tego samego hasła",
            ) if f'with data set "{etykieta}"' in nazwa]
            if len(pasuje) != 1 or pasuje[0] in etykiety:
                raise RuntimeError(f"#2851/#2854: nieznany/powtórzony test JUnit: {nazwa!r}.")
            etykiety.add(pasuje[0])
            if przypadek.find("skipped") is not None or przypadek.find("error") is not None:
                raise RuntimeError("#2851/#2854: pominięty test lub błąd środowiska nie jest dowodem.")
            bledy = przypadek.findall("failure")
            ma_oblac = mutacja is not None and pasuje[0].startswith(mutacja[0])
            if ma_oblac:
                if len(bledy) != 1:
                    raise RuntimeError(f"#2851/#2854: mutant nie oblał dokładnie raz: {nazwa!r}.")
                szczegoly = " ".join(" ".join(b.itertext()) + " " + str(b.attrib) for b in bledy)
                if mutacja[1] not in szczegoly:
                    raise RuntimeError("#2851/#2854: mutant oblał bez właściwego znacznika: " + szczegoly)
                porazki += 1
            elif bledy:
                raise RuntimeError("#2851/#2854: inna droga albo kod przywrócony ma porażkę.")
        if mutacja is None and (wynik.returncode != 0 or porazki != 0):
            raise RuntimeError("#2851/#2854: kod bez mutacji nie przeszedł.")
        if mutacja is not None and (wynik.returncode == 0 or porazki != 3):
            raise RuntimeError("#2851/#2854: mutacja nie dała trzech właściwych porażek.")


def main():
    sprawdz_srodowisko()
    przebieg()
    for sciezka, kotwica, rodzina, marker in MUTACJE:
        oryginal = sciezka.read_bytes()
        stat = sciezka.stat()
        tekst = oryginal.decode("utf-8")
        if tekst.count(kotwica) != 1:
            raise RuntimeError(f"#2851/#2854: kotwica mutacji nie występuje raz: {sciezka}.")
        try:
            sciezka.write_bytes(tekst.replace(kotwica, "", 1).encode("utf-8"))
            przebieg((rodzina, marker))
        finally:
            sciezka.write_bytes(oryginal)
            os.utime(sciezka, ns=(stat.st_atime_ns, stat.st_mtime_ns))
        if sciezka.read_bytes() != oryginal or sciezka.stat().st_mtime_ns != stat.st_mtime_ns:
            raise RuntimeError("#2851/#2854: źródło nie zostało przywrócone bajtowo i czasowo.")
    przebieg()
    print("#2851/#2854: 6 PASS; każdy mutant 3 własne FAIL i 3 PASS; po przywróceniu 6 PASS.")


if __name__ == "__main__":
    main()
