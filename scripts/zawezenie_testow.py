"""Zawężenie `php artisan test --filter=X` do plików, w których X coś wybiera (#2299).

DLACZEGO. `--filter` bez ścieżki każe PHPUnitowi zbudować CAŁY zestaw: wczytać
~1500 plików testów i wywołać ich dostawców danych, zanim filtr cokolwiek
odrzuci. Na runnerze CI to ok. 5–6 s na jedno wywołanie, a kontrole negatywne
robią ich ok. 270 na część (wpis = przebieg po mutacji + zielony po
przywróceniu, do tego kontrole dodatnie). Pomiar z 30.09.2026 (main b1c96678,
run 36703902256): części kontroli trwały 20,1 / 28,6 / 28,0 min i były ścieżką
krytyczną całego CI. Ten sam test z plikiem w argumencie startuje w ułamek
sekundy.

CO ZOSTAJE BEZ ZMIAN. Filtr idzie do PHPUnita jak dotąd; ścieżki tylko
zawężają zestaw, z którego filtr wybiera. Zestaw wybranych testów jest ten sam,
jeśli lista plików obejmuje każdy test, który filtr wybrałby z całości.

SKĄD LISTA PLIKÓW. Raz na proces pytamy PHPUnita o jego własny spis
(`artisan test --list-tests` i `--list-tests-xml`, ok. 3 s każde) — z tych
samych `<testsuites>` i tych samych dostawców danych, więc nazwy zawierają
zbiory danych. Dla filtra z samych identyfikatorów, `::` i alternatyw `|`
(`FILTR_PROSTY`, zaczyna się od litery lub cyfry) PHPUnit buduje wyrażenie
`{filtr}i` i dopasowuje je do `Klasa::metoda with data set …`
(NameFilterIterator), czyli szuka któregoś z podciągów bez względu na wielkość
liter ASCII. Robimy dokładnie to samo na pełnych nazwach ze spisu i bierzemy
pliki klas, w których coś pasuje.

KIEDY NIE ZAWĘŻAMY (stare polecenie, pełny zestaw): filtr ma inne znaki
(wyrażenia, `#`, `@`, `\`), nic ze spisu mu nie pasuje, klasa
nie ma pliku w spisie albo spisu nie udało się zbudować. Zawężenie może więc
tylko przyspieszyć, nigdy nie zgubić testu po cichu. A gdyby jednak zgubiło,
werdykt i tak nie zaliczy kontroli: zero testów to „filtr nie wybrał żadnego
testu” (ZLA_PRZYCZYNA), a zielony test przed mutacją wymaga co najmniej
jednego testu (`sprawdz_zielony`).
"""

import os
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

IDENTYFIKATOR = re.compile(r"[A-Za-z0-9_]+")
# Filtr, który PHPUnit traktuje jak podciąg: identyfikatory z `::` (dwukropek
# jest w PCRE literałem), ewentualnie kilka takich alternatyw rozdzielonych `|`.
FILTR_PROSTY = re.compile(r"[A-Za-z0-9][A-Za-z0-9_:]*(?:\|[A-Za-z0-9_:]+)*")
# PCRE bez flagi `u` porównuje wielkość liter tylko w ASCII; str.lower() Pythona
# zmieniłby też litery spoza ASCII (np. „İ” daje „i” z kropką łączącą).
_MALE_ASCII = str.maketrans("ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")
ZNACZNIK_ZBIORU = " with data set "


def male_ascii(tekst):
    return tekst.translate(_MALE_ASCII)


def pelne_nazwy(spis_tekstowy):
    """Wiersze ` - Klasa::metoda"zbiór"` z `--list-tests` → `Klasa::metoda with data set "zbiór"`.

    PHPUnit usuwa z wypisu ` with data set ` (ListTestsAsTextCommand), a nazwa
    metody to sam identyfikator, więc zbiór danych zaczyna się od pierwszego
    `"` albo `#` po `::`. Odtwarzamy nazwę, do której PHPUnit dopasowuje filtr.
    """
    nazwy = []
    for wiersz in spis_tekstowy.splitlines():
        if not wiersz.startswith(" - "):
            continue
        nazwa = wiersz[3:].rstrip()
        klasa, dwukropek, reszta = nazwa.partition("::")
        if not dwukropek:
            continue
        dopasowanie = IDENTYFIKATOR.match(reszta)
        metoda = dopasowanie.group(0) if dopasowanie else ""
        zbior = reszta[len(metoda):]
        nazwy.append((klasa, klasa + "::" + metoda + (ZNACZNIK_ZBIORU + zbior if zbior else "")))
    return nazwy


def pliki_klas(spis_xml, katalog):
    """`<testClass name file>` z `--list-tests-xml` → {klasa: ścieżka względna}."""
    korzen = ET.fromstring(spis_xml)
    pliki = {}
    for element in korzen.iter():
        if element.tag.rsplit("}", 1)[-1] != "testClass":
            continue
        nazwa, plik = element.get("name"), element.get("file")
        if not nazwa or not plik:
            continue
        sciezka = Path(plik)
        try:
            sciezka = sciezka.resolve().relative_to(Path(katalog).resolve())
        except ValueError:
            pass
        pliki[nazwa] = str(sciezka)
    return pliki


class Indeks:
    """Spis testów PHPUnita: pełne nazwy (ze zbiorami danych) i pliki klas."""

    def __init__(self, nazwy, pliki):
        self.nazwy = [(klasa, male_ascii(pelna)) for klasa, pelna in nazwy]
        self.pliki = pliki

    def pliki_dla(self, filtr):
        """Posortowana lista plików albo None, gdy trzeba uruchomić pełny zestaw."""
        if not FILTR_PROSTY.fullmatch(filtr):
            return None
        szukane = male_ascii(filtr).split("|")
        klasy = {klasa for klasa, pelna in self.nazwy if any(s in pelna for s in szukane)}
        if not klasy or any(klasa not in self.pliki for klasa in klasy):
            return None
        return sorted({self.pliki[klasa] for klasa in klasy})


def zbuduj_indeks(katalog=".", uruchom=subprocess.run):
    """Indeks z własnego spisu PHPUnita; None, gdy spisu nie da się zbudować."""
    try:
        tekst = uruchom(
            ["php", "artisan", "test", "--list-tests", "--no-ansi"],
            cwd=katalog, text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300,
        )
        with tempfile.TemporaryDirectory(prefix="kuking-spis-") as tymczasowy:
            plik_xml = Path(tymczasowy) / "spis.xml"
            xml = uruchom(
                ["php", "artisan", "test", "--list-tests-xml", str(plik_xml), "--no-ansi"],
                cwd=katalog, text=True, encoding="utf-8", errors="replace",
                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300,
            )
            spis_xml = plik_xml.read_text(encoding="utf-8") if plik_xml.is_file() else None
        if tekst.returncode != 0 or xml.returncode != 0 or spis_xml is None:
            return None
        nazwy = pelne_nazwy(tekst.stdout)
        pliki = pliki_klas(spis_xml, katalog)
    except (OSError, subprocess.SubprocessError, ET.ParseError):
        return None
    if not nazwy or not pliki:
        return None
    return Indeks(nazwy, pliki)


_INDEKS = []


def _indeks():
    # Wyłącznik awaryjny: KUKING_KONTROLE_BEZ_ZAWEZENIA=1 wraca do pełnego zestawu.
    if os.environ.get("KUKING_KONTROLE_BEZ_ZAWEZENIA") == "1":
        return None
    if not _INDEKS:
        indeks = zbuduj_indeks()
        opis = "pełny zestaw przy każdym teście (spisu nie udało się zbudować)" if indeks is None \
            else f"{len(indeks.nazwy)} testów w {len(set(indeks.pliki.values()))} plikach"
        print(f"Zawężenie `--filter` do plików (#2299): {opis}.", flush=True)
        _INDEKS.append(indeks)
    return _INDEKS[0]


def polecenie_testu(nazwa, *dodatkowe, indeks=None):
    """`php artisan test [pliki…] --filter=nazwa --no-ansi [dodatkowe…]`."""
    indeks = indeks if indeks is not None else _indeks()
    pliki = indeks.pliki_dla(nazwa) if indeks is not None else None
    return ["php", "artisan", "test", *(pliki or []), "--filter=" + nazwa, "--no-ansi", *dodatkowe]


def wykonane_testy(polecenie, uruchom=subprocess.run):
    """(kod, posortowane (klasa, nazwa) z raportu JUnit) jednego przebiegu; raport None → pusta lista."""
    with tempfile.TemporaryDirectory(prefix="kuking-zgodnosc-") as katalog:
        raport = Path(katalog) / "junit.xml"
        wynik = uruchom(
            [*polecenie, "--log-junit", str(raport)],
            text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300,
        )
        if not raport.is_file():
            return wynik.returncode, []
        korzen = ET.fromstring(raport.read_text(encoding="utf-8", errors="replace"))
    return wynik.returncode, sorted((p.get("class", ""), p.get("name", "")) for p in korzen.iter("testcase"))


def sprawdz_zgodnosc(filtry, indeks=None, uruchom=subprocess.run):
    """Próba na żywym PHPUnicie: zawężony przebieg wykonuje DOKŁADNIE te testy co pełny.

    Tania straż przed rozjazdem spisu z uruchomieniem (np. inny format
    `--list-tests` po aktualizacji PHPUnita). Idzie na nietkniętych źródłach,
    przed pierwszą mutacją; różnica albo pusty zbiór zatrzymuje kontrole.
    """
    indeks = indeks if indeks is not None else _indeks()
    if indeks is None:
        return
    for filtr in filtry:
        if indeks.pliki_dla(filtr) is None:
            continue
        pelny = ["php", "artisan", "test", "--filter=" + filtr, "--no-ansi"]
        kod_pelny, pelne = wykonane_testy(pelny, uruchom)
        kod_zawezony, zawezone = wykonane_testy(polecenie_testu(filtr, indeks=indeks), uruchom)
        if not pelne or pelne != zawezone or kod_pelny != kod_zawezony:
            brak = sorted(set(pelne) - set(zawezone))
            nadmiar = sorted(set(zawezone) - set(pelne))
            raise RuntimeError(
                f"Zawężenie `--filter={filtr}` (#2299) rozjechało się z pełnym zestawem: "
                f"pełny {len(pelne)} testów (kod {kod_pelny}), zawężony {len(zawezone)} (kod {kod_zawezony}); "
                f"brak {brak[:5]}, nadmiar {nadmiar[:5]}. Tymczasowo: KUKING_KONTROLE_BEZ_ZAWEZENIA=1."
            )
        print(f"ZGODNOŚĆ zawężenia: {filtr} — {len(pelne)} testów, te same w obu przebiegach.", flush=True)
