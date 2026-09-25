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
"""

import hashlib
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
from typing import Callable, NamedTuple


KATALOG = Path(__file__).resolve().parent
ROOT = KATALOG.parent.parent


class Kontrola(NamedTuple):
    """Jedna mutacja: `test` ma oblać po niej i przejść po przywróceniu `plik`."""

    nazwa: str
    plik: str
    test: str
    mutacja: Callable[[str], str]


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


def run_test(name, expected_success):
    result = subprocess.run(
        ["php", "artisan", "test", "--filter=" + name, "--no-ansi"],
        text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        timeout=180,
    )
    print(result.stdout, flush=True)
    if (result.returncode == 0) != expected_success:
        raise RuntimeError("Nieoczekiwany wynik testu: " + name)
    if not expected_success and "FAILED" not in result.stdout:
        raise RuntimeError("Brak dowodu niezaliczonej asercji; sama awaria procesu nie wystarczy.")


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
    """Zwraca (kontrole dodatnie, kontrole) ze wszystkich plików, w kolejności nazw.

    Odmawia, ZANIM cokolwiek zmutuje: pusty katalog mierzyłby pustkę,
    a dwie kontrole o tej samej nazwie dają raport, z którego nie da się
    odczytać, która z nich padła.
    """
    dodatnie = []
    kontrole = []
    skad = {}

    pliki = pliki_kontroli()
    if not pliki:
        raise RuntimeError("Brak plików kontroli w " + str(KATALOG))

    for plik in pliki:
        spec = importlib.util.spec_from_file_location("kontrole_negatywne." + plik.stem, plik)
        modul = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(modul)

        wlasne = list(getattr(modul, "KONTROLE", []))
        if not wlasne:
            raise RuntimeError(f"{plik.name}: brak listy KONTROLE — plik kontroli bez kontroli.")

        for kontrola in wlasne:
            if not isinstance(kontrola, Kontrola):
                raise RuntimeError(f"{plik.name}: wpis w KONTROLE nie jest Kontrola(...): {kontrola!r}")
            if kontrola.nazwa in skad:
                raise RuntimeError(
                    f"Dwie kontrole o nazwie {kontrola.nazwa!r}: {skad[kontrola.nazwa]} i {plik.name}."
                )
            skad[kontrola.nazwa] = plik.name
            kontrole.append(kontrola)

        dodatnie.extend(getattr(modul, "KONTROLE_DODATNIE", []))

    return dodatnie, kontrole


def lista():
    """Tryb suchy: co zostałoby uruchomione, bez testów i bez pisania po źródłach.

    Kotwice mutacji sprawdza w pamięci (`preflight`), więc `scripts/check.sh`
    łapie kotwicę rozjechaną z kodem, zanim zrobi to CI.
    """
    dodatnie, kontrole = zaladuj()
    preflight(kontrole)
    for test in dodatnie:
        print(f"dodatnia\t{test}\tTrue")
    for kontrola in kontrole:
        print(f"negatywna\t{kontrola.nazwa}\t{kontrola.plik}\t{kontrola.test}\tFalse")
    print(f"{len(dodatnie)} kontroli dodatnich, {len(kontrole)} kontroli negatywnych.")


def preflight(checks):
    """Każda mutacja próbna W PAMIĘCI, zanim ruszy jakikolwiek test.

    Po PR #1721 kotwica eksportu przestała pasować, a krok padał dopiero po
    kilku minutach, anonimowym „nie znalazła dokładnie jednego miejsca” — bez
    nazwy kontroli. Czytanie w logu ~500 linii oczekiwanych porażek (np.
    „Format UUID” celowo daje 500 w WyborZeszytuMaWalidacjeTest) wyglądało jak
    regresja w kodzie. Tu nic nie jest zapisywane na dysk; błąd mówi, KTÓRA
    kontrola i w jakim pliku.
    """
    for label, filename, _test, mutate in checks:
        try:
            source = (ROOT / filename).read_text()
            if mutate(source) == source:
                raise RuntimeError("Mutacja nie zmieniła źródła.")
        except Exception as error:
            raise RuntimeError(f"Kontrola „{label}” ({filename}) nie pasuje do kodu: {error}") from error


def uruchom():
    os.chdir(ROOT)
    bramka()
    dodatnie, checks = zaladuj()
    preflight(checks)

    for test in dodatnie:
        run_test(test, True)
    with tempfile.TemporaryDirectory(prefix="kuking-kontrola-") as directory:
        backup = Path(directory) / "oryginal"
        for label, filename, test, mutate in checks:
            path = ROOT / filename
            subprocess.run(["cp", str(path), str(backup)], check=True)
            before = digest(path)
            try:
                path.write_text(mutate(path.read_text()))
                changed = digest(path)
                if changed == before:
                    raise RuntimeError("Mutacja nie zmieniła źródła.")
                print(f"{label}: przed={before}, mutacja={changed}", flush=True)
                run_test(test, False)
            finally:
                subprocess.run(["cp", str(backup), str(path)], check=True)
                restored = digest(path)
                print(f"{label}: po przywróceniu={restored}", flush=True)
                if restored != before:
                    raise RuntimeError("Przywrócone źródło różni się od oryginału.")
            run_test(test, True)
    # Liczebnik bierzemy z `len(checks)`, nie z tekstu. Wcześniej stało tu wpisane
    # słowo „Pięć": po dodaniu szóstego wpisu CI nadal wypisywałoby „Pięć", a to
    # jedyne miejsce, z którego człowiek czyta wynik tego kroku.
    print(f"{len(checks)} kontroli negatywnych wykryło regresje; źródła przywrócone.")
