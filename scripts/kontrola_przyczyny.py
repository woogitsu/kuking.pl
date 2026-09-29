"""Werdykt kontroli negatywnej: czerwień liczy się tylko z oczekiwanej przyczyny (#1011).

Do 29 września 2026 kontrola zaliczała KAŻDĄ porażkę: niezerowy kod i słowo
`FAILED` gdziekolwiek w wyjściu. Błąd składni po mutacji, zerwane połączenie
z bazą albo niezależna asercja z tej samej klasy dawały ten sam „dowód" co
asercja, którą mutacja miała zapalić (docs/PULAPKI_TESTOW.md §5b).

Teraz każdy wpis `checks` ma wzorzec oczekiwanej przyczyny (`oczekuj`, patrz
`kontrole_oczekiwana_przyczyna.py`) — wyrażenie regularne, do którego musi
pasować KAŻDA porażka. Wynik czytamy z raportu JUnit PHPUnita, nie z tekstu dla
człowieka (ten zmienia kształt, np. na JSON, gdy test woła agent). Werdykty:

  POTWIERDZONA   każda porażka to asercja (`<failure>`) właściwego testu
                 z komunikatem pasującym do `oczekuj`;
  BRAK_PORAZKI   test przeszedł mimo mutacji — strażnik nie strzeże;
  ZLA_PRZYCZYNA  wszystko inne: brak raportu (fatal, bootstrap, baza), wyjątek
                 zamiast asercji (`<error>`, chyba że wzorzec jest `Wyjatek`),
                 komunikat spoza wzorca — także drugiego, niezależnego testu
                 spod tego samego filtra.

Moduł nie zawiera nazw żadnych testów projektu w cudzysłowie: strażnik
`StraznikTekstuMaKontroleDodatniaTest` czyta pliki `scripts/*.py` i uznałby taką
nazwę za pokrycie.
"""

import hashlib
from pathlib import Path
import re
import subprocess
import tempfile
from typing import NamedTuple, Optional
import xml.etree.ElementTree as ET


POTWIERDZONA = "POTWIERDZONA"
BRAK_PORAZKI = "BRAK_PORAZKI"
ZLA_PRZYCZYNA = "ZLA_PRZYCZYNA"
# Kontrola bez wzorca: czerwień była, ale jej PRZYCZYNY nie da się sprawdzić.
# To NIE jest dowód — raport wymienia takie kontrole z nazwy (#1011, migracja etapowa).
BEZ_WZORCA = "BEZ_WZORCA"


class Wyjatek(str):
    """Wzorzec oczekiwanej przyczyny, który dopuszcza wyjątek (`<error>`) jako objaw.

    Domyślnie wyjątek po mutacji to ZLA_PRZYCZYNA (błąd składni, awaria bazy).
    Bywa jednak, że sama mutacja ma objaw w wyjątku: usunięta transakcja
    zostawia przerwaną transakcję (SQLSTATE 25P02), a niespełnione oczekiwanie
    atrapy Mockery to `InvalidCountException`. Wtedy wzorzec musi wskazać klasę
    wyjątku i treść — `.` nie wystarczy.
    """


class WynikTestu(NamedTuple):
    """Surowy wynik jednego `php artisan test`: kod, wyjście i raport JUnit."""

    kod: int
    wyjscie: str
    junit: Optional[str]


class Porazka(NamedTuple):
    klasa: str
    metoda: str
    rodzaj: str   # "failure" (asercja) albo "error" (wyjątek, fatal)
    typ: str
    tresc: str


class Werdykt(NamedTuple):
    werdykt: str
    powod: str


def digest(path):
    return hashlib.md5(path.read_bytes()).hexdigest()


def uruchom_test(name):
    """Jedno `php artisan test --filter=…` z raportem JUnit w katalogu tymczasowym.

    Raport, nie wyjście: format dla człowieka zmienia kształt (Collision, JSON
    dla agentów), a JUnit odróżnia asercję (`<failure>`) od wyjątku (`<error>`).
    Brak pliku po przebiegu znaczy, że proces padł, zanim PHPUnit skończył.
    """
    with tempfile.TemporaryDirectory(prefix="kuking-junit-") as katalog:
        raport = Path(katalog) / "junit.xml"
        result = subprocess.run(
            ["php", "artisan", "test", "--filter=" + name, "--no-ansi", "--log-junit", str(raport)],
            text=True, encoding="utf-8", errors="replace",
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            timeout=180,
        )
        junit = raport.read_text(encoding="utf-8", errors="replace") if raport.is_file() else None
    return WynikTestu(result.returncode, result.stdout, junit)


def _metoda(nazwa_przypadku):
    """`test_x with data set "…"` → `test_x`."""
    return nazwa_przypadku.split(" with data set ", 1)[0].strip()


def czytaj_junit(junit):
    """Zwraca (liczba testów, porażki). ValueError, gdy raportu nie da się odczytać."""
    try:
        korzen = ET.fromstring(junit)
    except ET.ParseError as blad:
        raise ValueError("raport JUnit nieczytelny: " + str(blad)) from blad

    przypadki = list(korzen.iter("testcase"))
    porazki = []
    for przypadek in przypadki:
        for rodzaj in ("failure", "error"):
            for element in przypadek.findall(rodzaj):
                tresc = (element.text or "").strip()
                # Pierwszy wiersz to identyfikator testu, a nie komunikat —
                # bez tego wzorzec pasowałby do samej nazwy testu.
                wiersze = tresc.split("\n", 1)
                if "::" in wiersze[0] and len(wiersze) == 2:
                    tresc = wiersze[1].strip()
                porazki.append(Porazka(
                    przypadek.get("class", ""), _metoda(przypadek.get("name", "")),
                    rodzaj, element.get("type", ""), tresc,
                ))
    return len(przypadki), porazki


def _skrot(tekst, ile=160):
    jeden = " ".join(tekst.split())
    return jeden if len(jeden) <= ile else jeden[: ile - 1] + "…"


def werdykt(test, oczekuj, wynik):
    """Ocena przebiegu PO mutacji. Tylko POTWIERDZONA zalicza kontrolę.

    Wzorzec pasuje do komunikatu ze zwiniętymi białymi znakami (nowy wiersz i
    wcięcia to jedna spacja): ten sam komunikat inaczej zawinięty nie ma
    unieważniać kontroli.
    """
    if wynik.junit is None:
        if wynik.kod == 0:
            return Werdykt(ZLA_PRZYCZYNA, "kod 0, ale brak raportu JUnit — nie wiadomo, co się wykonało")
        return Werdykt(ZLA_PRZYCZYNA, f"proces padł (kod {wynik.kod}) bez raportu JUnit — fatal, bootstrap albo baza, nie asercja")
    try:
        liczba, porazki = czytaj_junit(wynik.junit)
    except ValueError as blad:
        return Werdykt(ZLA_PRZYCZYNA, str(blad))

    if not porazki:
        if wynik.kod == 0:
            if liczba == 0:
                return Werdykt(ZLA_PRZYCZYNA, "filtr nie wybrał żadnego testu")
            return Werdykt(BRAK_PORAZKI, f"{liczba} testów przeszło mimo mutacji — strażnik nie strzeże")
        return Werdykt(ZLA_PRZYCZYNA, f"kod {wynik.kod}, ale żadna asercja nie oblała")

    wyjatki = [p for p in porazki if p.rodzaj == "error"]
    if wyjatki and not isinstance(oczekuj, Wyjatek):
        p = wyjatki[0]
        return Werdykt(ZLA_PRZYCZYNA, f"wyjątek zamiast asercji w {p.metoda}: {p.typ}: {_skrot(p.tresc)}")

    # Filtr `--filter` wybiera testy, więc porażka „innego testu" spod tego
    # samego filtra to porażka o innym komunikacie — rozstrzyga wzorzec poniżej.
    wzorzec = re.compile(oczekuj, re.DOTALL)
    niewyjasnione = [p for p in porazki if not wzorzec.search(" ".join(p.tresc.split()))]
    if niewyjasnione:
        p = niewyjasnione[0]
        return Werdykt(ZLA_PRZYCZYNA, f"komunikat {p.metoda} nie pasuje do /{oczekuj}/: {_skrot(p.tresc)}")

    if wynik.kod == 0:
        return Werdykt(ZLA_PRZYCZYNA, "raport ma porażki, a kod wyjścia 0")

    metody = sorted({p.metoda for p in porazki})
    return Werdykt(POTWIERDZONA, f"{len(porazki)} z {liczba} oblało z oczekiwanej przyczyny: " + ", ".join(metody))


def werdykt_bez_wzorca(wynik):
    """Kontrola bez `oczekuj`: samo ustalenie, że coś obleciało (dawna reguła).

    Mimo to odrzuca to, co da się odrzucić bez wzorca: brak raportu JUnit
    (fatal, bootstrap, baza), wyjątek zamiast asercji i test, który przeszedł.
    Nigdy nie zwraca POTWIERDZONA.
    """
    if wynik.junit is None:
        return Werdykt(ZLA_PRZYCZYNA, f"proces padł (kod {wynik.kod}) bez raportu JUnit — fatal, bootstrap albo baza, nie asercja")
    try:
        liczba, porazki = czytaj_junit(wynik.junit)
    except ValueError as blad:
        return Werdykt(ZLA_PRZYCZYNA, str(blad))
    if not porazki:
        if wynik.kod == 0:
            return Werdykt(BRAK_PORAZKI, f"{liczba} testów przeszło mimo mutacji — strażnik nie strzeże")
        return Werdykt(ZLA_PRZYCZYNA, f"kod {wynik.kod}, ale żadna asercja nie oblała")
    wyjatki = [p for p in porazki if p.rodzaj == "error"]
    if wyjatki:
        p = wyjatki[0]
        return Werdykt(ZLA_PRZYCZYNA, f"wyjątek zamiast asercji w {p.metoda}: {p.typ}: {_skrot(p.tresc)}")
    return Werdykt(BEZ_WZORCA, f"{len(porazki)} asercji oblało, ale kontrola nie ma wzorca oczekiwanej przyczyny — dowód niepełny")


def sprawdz_zielony(test, etap, runner):
    """Test ma przejść na nietkniętym źródle: przed mutacją i po przywróceniu."""
    wynik = runner(test)
    liczba, porazki = 0, []
    if wynik.junit is not None:
        try:
            liczba, porazki = czytaj_junit(wynik.junit)
        except ValueError:
            pass
    if wynik.kod != 0 or wynik.junit is None or porazki or liczba == 0:
        print(wynik.wyjscie, flush=True)
        raise RuntimeError(f"{etap}: {test} nie przechodzi na nietkniętym źródle (kod {wynik.kod}, testów {liczba}).")
    print(f"ZIELONY {etap}: {test} — {liczba} testów", flush=True)


def sprawdz_wzorce(checks, oczekuj):
    """Odmawia, ZANIM cokolwiek zmutuje: wzorzec musi być poprawny i przypisany do istniejącej kontroli.

    Zwraca listę nazw kontroli bez wzorca (dowód niepełny — raport nie może
    ich nazwać potwierdzonymi).
    """
    nazwy = [nazwa for nazwa, _plik, _test, _mutacja in checks]
    for nazwa in nazwy:
        if nazwy.count(nazwa) > 1:
            raise RuntimeError(f"Dwie kontrole o nazwie „{nazwa}” — raport nie wskaże, która padła.")
    for nazwa, wzorzec in oczekuj.items():
        if nazwa not in nazwy:
            raise RuntimeError(f"Wzorzec oczekiwanej przyczyny dla nieistniejącej kontroli „{nazwa}” (literówka?).")
        if not wzorzec.strip():
            raise RuntimeError(f"Pusty wzorzec oczekiwanej przyczyny dla „{nazwa}”.")
        try:
            re.compile(wzorzec)
        except re.error as blad:
            raise RuntimeError(f"Zły wzorzec oczekiwanej przyczyny dla „{nazwa}”: {blad}") from blad
    return [nazwa for nazwa in nazwy if nazwa not in oczekuj]


def jedna_kontrola(nazwa, plik, test, mutacja, oczekuj, oczekiwany, runner, root, backup):
    """Kopia → mutacja (md5 musi się zmienić) → werdykt → przywrócenie → zielony.

    `oczekuj=None` znaczy „brak wzorca”: wtedy oczekiwanym werdyktem jest
    BEZ_WZORCA (dowód niepełny), nigdy POTWIERDZONA.
    """
    path = root / plik
    subprocess.run(["cp", str(path), str(backup)], check=True)
    before = digest(path)
    try:
        path.write_text(mutacja(path.read_text()))
        changed = digest(path)
        if changed == before:
            raise RuntimeError(f"{nazwa}: mutacja nie zmieniła źródła.")
        print(f"{nazwa}: przed={before}, mutacja={changed}", flush=True)
        wynik = runner(test)
        ocena = werdykt(test, oczekuj, wynik) if oczekuj is not None else werdykt_bez_wzorca(wynik)
        if oczekuj is None:
            oczekiwany = BEZ_WZORCA
        print(f"WERDYKT {nazwa}: {ocena.werdykt} — {ocena.powod}", flush=True)
        if ocena.werdykt != oczekiwany:
            # Pełne wyjście tylko wtedy, gdy trzeba je czytać: oczekiwana
            # czerwień nie ma udawać w logu niewyjaśnionego błędu joba.
            print(wynik.wyjscie, flush=True)
            raise RuntimeError(f"{nazwa}: werdykt {ocena.werdykt}, oczekiwano {oczekiwany} — {ocena.powod}")
    finally:
        subprocess.run(["cp", str(backup), str(path)], check=True)
        restored = digest(path)
        print(f"{nazwa}: po przywróceniu={restored}", flush=True)
        if restored != before:
            raise RuntimeError(f"{nazwa}: przywrócone źródło różni się od oryginału.")
    sprawdz_zielony(test, "po przywróceniu", runner)


def przebieg(dodatnie, checks, oczekuj, mechanizmu=(), runner=uruchom_test, root=None, wymagaj_wzorca=False, wszystkie=None):
    """Cały przebieg: kontrole dodatnie, kontrole mechanizmu, kontrole negatywne.

    `checks` to krotki `(nazwa, plik, test, mutacja)`, `mechanizmu` — krotki
    `(nazwa, plik, test, mutacja, oczekuj)`, od których wymagamy ZLA_PRZYCZYNA.
    Kontrola bez wzorca przechodzi jako BEZ_WZORCA i trafia z nazwy do
    końcowego raportu: migracja wzorców jest etapowa, ale dowodem pełnym jest
    tylko POTWIERDZONA. Zwraca (potwierdzone, bez_wzorca).

    `wymagaj_wzorca=True` zamienia BEZ_WZORCA w odmowę PRZED pierwszym testem —
    przełącznik na moment, gdy wszystkie otwarte PR-y z nowymi wpisami dostaną wzorce.

    `wszystkie` to pełna lista `checks`, gdy `checks` jest tylko częścią CI
    (`--czesc N/M`): wzorce sprawdzamy względem całości (literówka w kluczu
    ma paść w KAŻDEJ części), a raport liczy tylko wpisy tej części.
    """
    root = root or Path(__file__).resolve().parent.parent
    sprawdz_wzorce(wszystkie if wszystkie is not None else checks, oczekuj)
    bez_wzorca = [nazwa for nazwa, _p, _t, _m in checks if nazwa not in oczekuj]
    if bez_wzorca and wymagaj_wzorca:
        raise RuntimeError("Kontrole bez wzorca oczekiwanej przyczyny (#1011) — dopisz wpis w "
                           "scripts/kontrole_oczekiwana_przyczyna.py: " + "; ".join(bez_wzorca))
    for test in dodatnie:
        sprawdz_zielony(test, "przed mutacją", runner)
    with tempfile.TemporaryDirectory(prefix="kuking-kontrola-") as directory:
        backup = Path(directory) / "oryginal"
        for nazwa, plik, test, mutacja, wzorzec in mechanizmu:
            jedna_kontrola(nazwa, plik, test, mutacja, wzorzec, ZLA_PRZYCZYNA, runner, root, backup)
        for nazwa, plik, test, mutacja in checks:
            jedna_kontrola(nazwa, plik, test, mutacja, oczekuj.get(nazwa), POTWIERDZONA, runner, root, backup)
    potwierdzone = len(checks) - len(bez_wzorca)
    print(f"{potwierdzone} z {len(checks)} kontroli negatywnych POTWIERDZONYCH oczekiwaną przyczyną; "
          f"{len(mechanizmu)} kontroli mechanizmu odrzuciło złą przyczynę; źródła przywrócone.", flush=True)
    if bez_wzorca:
        print(f"BEZ WZORCA (dowód niepełny, #1011): {len(bez_wzorca)} kontroli — " + "; ".join(bez_wzorca), flush=True)
    return potwierdzone, bez_wzorca
