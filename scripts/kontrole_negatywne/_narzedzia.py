"""Wspólne narzędzia kontroli negatywnych: bramka, mutacja, testy, przywracanie.

Tu NIE MA żadnej kontroli. Kontrole żyją po jednej na plik obok, w plikach
bez `_` na początku nazwy (patrz README.md). Ten moduł nie może zawierać
nazwy żadnego testu w cudzysłowie: `StraznikTekstuMaKontroleDodatniaTest`
czyta wszystkie pliki `*.py` tego katalogu i uznałby ją za pokrycie.

DLACZEGO TEN MECHANIZM WOLNO URUCHOMIĆ LOKALNIE.
Do 20 września 2026 stał tu bezwarunkowy `if os.environ.get("CI") != "true"`.
Skutek był odwrotny do zamierzonego: stanowisko, które dopisywało nową kontrolę,
NIE MIAŁO JAK jej sprawdzić przed commitem — a błąd w mutacji wywraca cały
krok CI, nie tylko nową kontrolę. Racjonalną reakcją było nie dotykać mechanizmu
i opisać kontrolę dodatnią słowami w commicie. Audyt z 20.09.2026 policzył skutek:
na 170 testów czytających źródła (strażników tekstu) kontrolą objęte były TRZY.
Blokada nie chroniła bazy — chroniła przed używaniem mechanizmu.

CO ZOSTAJE Z OCHRONY. Mechanizm PISZE PO ŹRÓDŁACH i URUCHAMIA TESTY, które kasują
i odtwarzają bazę. Uruchomiony na cudzej albo współdzielonej bazie niszczy pracę
innych stanowisk. Dlatego lokalne uruchomienie wymaga JAWNEJ zgody
(`KUKING_KONTROLE_LOKALNIE=1`) i przechodzi przez kontrolę celu połączenia:
host musi być lokalny, port NIE MOŻE być domyślnym 5432 (tam stoi klaster
współdzielony), a nazwa bazy musi być nazwana i różna od `kuking`.
W CI nic się nie zmienia — tam bramka przepuszcza jak dotąd.

DLACZEGO KATALOG, A NIE JEDEN PLIK (wrzesień 2026). Do rozbicia każdy PR
dopisywał stałe, funkcję mutacji, wpis w `checks` i `run_test(...)` w tych
samych czterech miejscach jednego pliku, więc każde scalenie dawało konflikt
we wszystkich otwartych PR-ach. Teraz nowa kontrola to JEDEN NOWY PLIK.

CZERWIEŃ Z WŁAŚCIWEGO POWODU (#1011, docs/PULAPKI_TESTOW.md §5b). Do 24 września
2026 kontrola zaliczała KAŻDĄ porażkę: niezerowy kod i słowo `FAILED` gdziekolwiek
w wyjściu. Błąd składni po mutacji, zerwane połączenie z bazą albo niezależna
asercja tej samej klasy dawały ten sam „dowód" co właściwa asercja. Dziś każda
kontrola ma `oczekuj` — wyrażenie regularne, do którego musi pasować KAŻDA
porażka — a wynik czytamy z raportu JUnit PHPUnita, nie z tekstu dla człowieka
(ten zmienia kształt, np. na JSON, gdy test woła agent). Werdykty:
  POTWIERDZONA   każda porażka to asercja (`<failure>`) właściwego testu
                 z komunikatem pasującym do `oczekuj`;
  BRAK_PORAZKI   test przeszedł mimo mutacji — strażnik nie strzeże;
  ZLA_PRZYCZYNA  wszystko inne: brak raportu (fatal, bootstrap), wyjątek
                 zamiast asercji (`<error>`), porażka innego testu, komunikat
                 spoza wzorca.
Każdy przebieg zaczyna się od kontroli dodatnich SAMEGO MECHANIZMU
(`KONTROLE_MECHANIZMU`): mutacja dająca fatal zamiast asercji ma dostać
ZLA_PRZYCZYNA, inaczej cały krok pada.
"""

import hashlib
import importlib.util
import os
from pathlib import Path
import re
import subprocess
import tempfile
from typing import Callable, NamedTuple, Optional
import xml.etree.ElementTree as ET


KATALOG = Path(__file__).resolve().parent
ROOT = KATALOG.parent.parent

POTWIERDZONA = "POTWIERDZONA"
BRAK_PORAZKI = "BRAK_PORAZKI"
ZLA_PRZYCZYNA = "ZLA_PRZYCZYNA"


class Kontrola(NamedTuple):
    """Jedna mutacja: `test` ma oblać po niej i przejść po przywróceniu `plik`.

    `oczekuj` to wyrażenie regularne (`re.search`, `re.DOTALL`) dopasowywane do
    komunikatu KAŻDEJ porażki — bez pierwszego wiersza z nazwą testu. Jest
    obowiązkowe — loader odmawia wpisu bez niego.
    """

    nazwa: str
    plik: str
    test: str
    mutacja: Callable[[str], str]
    oczekuj: str = ""


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


def odmow(powod):
    raise SystemExit(
        "Kontrole negatywne nie ruszą: " + powod + "\n"
        "W CI uruchamiają się same. Lokalnie ustaw KUKING_KONTROLE_LOKALNIE=1\n"
        "i wskaż WŁASNĄ bazę stanowiska, na przykład:\n"
        "  DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_<stanowisko> \\\n"
        "  KUKING_KONTROLE_LOKALNIE=1 python3 scripts/kontrole-negatywne-alfa08.py"
    )


def bramka():
    if os.environ.get("CI") != "true":
        if os.environ.get("KUKING_KONTROLE_LOKALNIE") != "1":
            odmow("brak jawnej zgody na uruchomienie poza CI.")

        host = os.environ.get("DB_HOST", "")
        port = os.environ.get("DB_PORT", "")
        baza = os.environ.get("DB_DATABASE", "")

        if host not in ("127.0.0.1", "localhost"):
            odmow(f"DB_HOST={host!r} nie jest lokalny.")
        # 5432 to domyślny port klastra współdzielonego przez wszystkie stanowiska.
        # Test kasuje i odtwarza schemat, więc trafienie tam niszczy cudzą pracę.
        if port in ("", "5432"):
            odmow(f"DB_PORT={port!r} — podaj port własnego klastra, nigdy 5432.")
        if baza in ("", "kuking"):
            odmow(f"DB_DATABASE={baza!r} — podaj nazwaną bazę stanowiska.")

        print(f"Kontrole negatywne lokalnie: {host}:{port}/{baza}", flush=True)


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
            text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            timeout=180,
        )
        junit = raport.read_text() if raport.is_file() else None
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


def w_zakresie(porazka, test):
    """Czy porażka należy do testu z wpisu: metody `test_…` albo klasy."""
    if test.startswith("test_"):
        return porazka.metoda == test
    return porazka.klasa == test or porazka.klasa.endswith("\\" + test)


def _skrot(tekst, ile=160):
    jeden = " ".join(tekst.split())
    return jeden if len(jeden) <= ile else jeden[: ile - 1] + "…"


def werdykt(test, oczekuj, wynik):
    """Ocena przebiegu PO mutacji. Tylko POTWIERDZONA zalicza kontrolę."""
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
    if wyjatki:
        p = wyjatki[0]
        return Werdykt(ZLA_PRZYCZYNA, f"wyjątek zamiast asercji w {p.metoda}: {p.typ}: {_skrot(p.tresc)}")

    obce = [p for p in porazki if not w_zakresie(p, test)]
    if obce:
        p = obce[0]
        return Werdykt(ZLA_PRZYCZYNA, f"oblał inny test niż {test}: {p.klasa}::{p.metoda}")

    wzorzec = re.compile(oczekuj, re.DOTALL)
    niewyjasnione = [p for p in porazki if not wzorzec.search(p.tresc)]
    if niewyjasnione:
        p = niewyjasnione[0]
        return Werdykt(ZLA_PRZYCZYNA, f"komunikat {p.metoda} nie pasuje do /{oczekuj}/: {_skrot(p.tresc)}")

    if wynik.kod == 0:
        return Werdykt(ZLA_PRZYCZYNA, "raport ma porażki, a kod wyjścia 0")

    metody = sorted({p.metoda for p in porazki})
    return Werdykt(POTWIERDZONA, f"{len(porazki)} z {liczba} oblało z oczekiwanej przyczyny: " + ", ".join(metody))


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


def replace_once(source, old, new):
    if source.count(old) != 1:
        raise RuntimeError("Kontrola nie znalazła dokładnie jednego miejsca mutacji.")
    return source.replace(old, new, 1)


def pliki_kontroli():
    """Pliki kontroli w kolejności nazw. `_` na początku = nie kontrola."""
    return sorted(
        (p for p in KATALOG.glob("*.py") if not p.name.startswith("_")),
        key=lambda p: p.name,
    )


def zaladuj():
    """Zwraca (kontrole dodatnie, kontrole, kontrole mechanizmu), w kolejności nazw.

    Odmawia, ZANIM cokolwiek zmutuje: pusty katalog mierzyłby pustkę,
    a dwie kontrole o tej samej nazwie dają raport, z którego nie da się
    odczytać, która z nich padła.
    """
    dodatnie = []
    kontrole = []
    mechanizmu = []
    skad = {}

    pliki = pliki_kontroli()
    if not pliki:
        raise RuntimeError("Brak plików kontroli w " + str(KATALOG))

    for plik in pliki:
        spec = importlib.util.spec_from_file_location("kontrole_negatywne." + plik.stem, plik)
        modul = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(modul)

        wlasne = list(getattr(modul, "KONTROLE", []))
        wlasne_mechanizmu = list(getattr(modul, "KONTROLE_MECHANIZMU", []))
        if not wlasne and not wlasne_mechanizmu:
            raise RuntimeError(f"{plik.name}: brak listy KONTROLE — plik kontroli bez kontroli.")

        for kontrola in wlasne + wlasne_mechanizmu:
            if not isinstance(kontrola, Kontrola):
                raise RuntimeError(f"{plik.name}: wpis w KONTROLE nie jest Kontrola(...): {kontrola!r}")
            if kontrola.nazwa in skad:
                raise RuntimeError(
                    f"Dwie kontrole o nazwie {kontrola.nazwa!r}: {skad[kontrola.nazwa]} i {plik.name}."
                )
            if not kontrola.oczekuj:
                raise RuntimeError(
                    f"{plik.name}: kontrola {kontrola.nazwa!r} bez `oczekuj` — bez oczekiwanej "
                    "przyczyny nie odróżnimy dowodu od awarii (#1011)."
                )
            try:
                re.compile(kontrola.oczekuj)
            except re.error as blad:
                raise RuntimeError(f"{plik.name}: {kontrola.nazwa!r}: zły wzorzec `oczekuj`: {blad}") from blad
            skad[kontrola.nazwa] = plik.name

        kontrole.extend(wlasne)
        mechanizmu.extend(wlasne_mechanizmu)

        dodatnie.extend(getattr(modul, "KONTROLE_DODATNIE", []))

    return dodatnie, kontrole, mechanizmu


def lista():
    """Tryb suchy: co zostałoby uruchomione, bez testów i bez pisania po źródłach."""
    dodatnie, kontrole, mechanizmu = zaladuj()
    for test in dodatnie:
        print(f"dodatnia\t{test}\tTrue")
    for kontrola in mechanizmu:
        print(f"mechanizmu\t{kontrola.nazwa}\t{kontrola.plik}\t{kontrola.test}\t{ZLA_PRZYCZYNA}")
    for kontrola in kontrole:
        print(f"negatywna\t{kontrola.nazwa}\t{kontrola.plik}\t{kontrola.test}\t/{kontrola.oczekuj}/")
    print(f"{len(dodatnie)} kontroli dodatnich, {len(mechanizmu)} kontroli mechanizmu, "
          f"{len(kontrole)} kontroli negatywnych.")


def jedna_kontrola(kontrola, oczekiwany, runner, root, backup):
    """Kopia → mutacja (md5 musi się zmienić) → werdykt → przywrócenie → zielony."""
    path = root / kontrola.plik
    subprocess.run(["cp", str(path), str(backup)], check=True)
    before = digest(path)
    try:
        path.write_text(kontrola.mutacja(path.read_text()))
        changed = digest(path)
        if changed == before:
            raise RuntimeError(f"{kontrola.nazwa}: mutacja nie zmieniła źródła.")
        print(f"{kontrola.nazwa}: przed={before}, mutacja={changed}", flush=True)
        wynik = runner(kontrola.test)
        ocena = werdykt(kontrola.test, kontrola.oczekuj, wynik)
        print(f"WERDYKT {kontrola.nazwa}: {ocena.werdykt} — {ocena.powod}", flush=True)
        if ocena.werdykt != oczekiwany:
            # Pełne wyjście tylko wtedy, gdy trzeba je czytać: oczekiwana
            # czerwień nie ma udawać w logu niewyjaśnionego błędu joba.
            print(wynik.wyjscie, flush=True)
            raise RuntimeError(
                f"{kontrola.nazwa}: werdykt {ocena.werdykt}, oczekiwano {oczekiwany} — {ocena.powod}"
            )
    finally:
        subprocess.run(["cp", str(backup), str(path)], check=True)
        restored = digest(path)
        print(f"{kontrola.nazwa}: po przywróceniu={restored}", flush=True)
        if restored != before:
            raise RuntimeError(f"{kontrola.nazwa}: przywrócone źródło różni się od oryginału.")
    sprawdz_zielony(kontrola.test, "po przywróceniu", runner)


def przebieg(dodatnie, checks, mechanizmu=(), runner=uruchom_test, root=ROOT):
    """Cały przebieg bez bramki i bez ładowania — osobno, żeby dało się go testować."""
    for test in dodatnie:
        sprawdz_zielony(test, "przed mutacją", runner)
    with tempfile.TemporaryDirectory(prefix="kuking-kontrola-") as directory:
        backup = Path(directory) / "oryginal"
        for kontrola in mechanizmu:
            jedna_kontrola(kontrola, ZLA_PRZYCZYNA, runner, root, backup)
        for kontrola in checks:
            jedna_kontrola(kontrola, POTWIERDZONA, runner, root, backup)
    # Liczebnik bierzemy z `len(checks)`, nie z tekstu. Wcześniej stało tu wpisane
    # słowo „Pięć": po dodaniu szóstego wpisu CI nadal wypisywałoby „Pięć", a to
    # jedyne miejsce, z którego człowiek czyta wynik tego kroku.
    print(f"{len(checks)} kontroli negatywnych wykryło regresje z oczekiwanej przyczyny; "
          f"{len(mechanizmu)} kontroli mechanizmu odrzuciło złą przyczynę; źródła przywrócone.")


def uruchom():
    os.chdir(ROOT)
    bramka()
    dodatnie, checks, mechanizmu = zaladuj()
    przebieg(dodatnie, checks, mechanizmu)
