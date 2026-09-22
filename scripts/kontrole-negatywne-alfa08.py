#!/usr/bin/env python3
"""Kontrole regresji Alfa 0.8 i portu marki: w CI albo lokalnie na własnej bazie.

DLACZEGO TEN SKRYPT WOLNO URUCHOMIĆ LOKALNIE.
Do 20 września 2026 stał tu bezwarunkowy `if os.environ.get("CI") != "true"`.
Skutek był odwrotny do zamierzonego: stanowisko, które dopisywało nowy wpis do
`checks`, NIE MIAŁO JAK go sprawdzić przed commitem — a błąd w mutacji wywraca
cały krok CI, nie tylko nowy wpis. Racjonalną reakcją było nie dotykać tego pliku
i opisać kontrolę dodatnią słowami w commicie. Audyt z 20.09.2026 policzył skutek:
na 170 testów czytających źródła (strażników tekstu) wpisem w `checks` objęte
były TRZY. Blokada nie chroniła bazy — chroniła przed używaniem mechanizmu.

CO ZOSTAJE Z OCHRONY. Skrypt PISZE PO ŹRÓDŁACH i URUCHAMIA TESTY, które kasują
i odtwarzają bazę. Uruchomiony na cudzej albo współdzielonej bazie niszczy pracę
innych stanowisk. Dlatego lokalne uruchomienie wymaga JAWNEJ zgody
(`KUKING_KONTROLE_LOKALNIE=1`) i przechodzi przez kontrolę celu połączenia:
host musi być lokalny, port NIE MOŻE być domyślnym 5432 (tam stoi klaster
współdzielony), a nazwa bazy musi być nazwana i różna od `kuking`.
W CI nic się nie zmienia — tam gate przepuszcza jak dotąd.
"""

import hashlib
import os
from pathlib import Path
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parent.parent
os.chdir(ROOT)


def odmow(powod):
    raise SystemExit(
        "Kontrole negatywne nie ruszą: " + powod + "\n"
        "W CI uruchamiają się same. Lokalnie ustaw KUKING_KONTROLE_LOKALNIE=1\n"
        "i wskaż WŁASNĄ bazę stanowiska, na przykład:\n"
        "  DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_<stanowisko> \\\n"
        "  KUKING_KONTROLE_LOKALNIE=1 python3 scripts/kontrole-negatywne-alfa08.py"
    )


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

CONTROLLER = "app/Http/Controllers/CollectionController.php"
LAYOUT = "resources/views/components/layout.blade.php"
CSS = "resources/css/app.css"
COLLECTION_TEST = "WyborZeszytuMaWalidacjeTest"
COMPOSER_TEST = "KafelDodawaniaPrzyDuzymTekscieTest"

# Strażnik nowych strażników tekstu. Nazwa klasy stoi w tym pliku DOKŁADNIE RAZ,
# i to jest celowe: strażnik szuka w tym pliku swojej nazwy, więc ta linia
# jest jednocześnie jego pokryciem i punktem mutacji pierwszej kontroli dodatniej.
STRAZNIK_TEKSTU_TEST = "StraznikTekstuMaKontroleDodatniaTest"
STRAZNIK_SAM_SKRYPT = "scripts/kontrole-negatywne-alfa08.py"
STRAZNIK_PLIK_ODSTEPSTWA = "tests/Feature/PlikKontrolnyZOdstepstwemTest.php"

# Etap `assets` obrazu a lista plików podana do `node --test` (regresja #1085).
# Ten strażnik pilnuje własnej NIEPUSTOŚCI (`assertNotEmpty`), ale nic w nim
# nie dowodzi, że czytnik `COPY` z Dockerfile potrafi powiedzieć „nie
# kopiowany". Gdyby parser zaczął zwracać zbiór za szeroki, `czyKopiowany()`
# byłoby zawsze prawdziwe, a test świeciłby na zielono nad niczym — dokładnie
# ta klasa usterki, dla której powstał mechanizm kontroli dodatnich.
OBRAZ_ASSETOW = "Dockerfile"
OBRAZ_ASSETOW_TEST = "ObrazAssetowMaPlikiTestowTest"


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


def remove_notice(source):
    start = source.index("@if($collectionError)")
    end = source.index("@endif", start) + len("@endif")
    return source[:start] + source[end:]


def bez_wpisu_dla_straznika(source):
    """KONTROLA DODATNIA 1: zabierz strażnikowi jego własny wpis w tym pliku.

    Strażnik szuka tu swojej nazwy klasy. Po podmianie nie znajdzie jej, uzna
    sam siebie za strażnika tekstu bez pokrycia i ma zapalić. Podmieniamy samą
    wartość stałej, nie wpis w `checks` — dzięki temu mutacja nie rusza tego,
    KTÓRY test zostanie uruchomiony (ten stoi już w pamięci procesu).
    """
    # Wzorzec SKŁADAMY ze stałej, nie wpisujemy go tu dosłownie. Dosłowny zapis
    # dawałby DRUGIE wystąpienie nazwy klasy w tym pliku, a wtedy `replace_once`
    # odmawia („nie znalazła dokładnie jednego miejsca mutacji") — i, co gorsza,
    # strażnik znajdowałby swoją nazwę także po mutacji, więc kontrola dodatnia
    # nigdy by nie zapaliła. Zmierzone przy pierwszym uruchomieniu, 20.09.2026.
    stara = 'STRAZNIK_TEKSTU_TEST = "' + STRAZNIK_TEKSTU_TEST + '"'

    return replace_once(source, stara, 'STRAZNIK_TEKSTU_TEST = "WpisZabranyPrzezKontroleDodatnia"')


def bez_znacznika_odstepstwa(source):
    """KONTROLA DODATNIA 2: zabierz plikowi kontrolnemu znacznik odstępstwa.

    Plik czyta źródło i asertuje na jego treści, a wpisu w `checks` nie ma.
    Bez znacznika zostaje strażnikiem tekstu bez pokrycia — strażnik ma zapalić
    z drugiej strony niż w kontroli 1.
    """
    start = source.index(" * @bez-kontroli-dodatniej")
    end = source.index("\n", start) + 1

    return source[:start] + source[end:]


def smaller_help(source):
    start = source.index(".composer-help {")
    end = source.index("}", start)
    block = replace_once(source[start:end], "var(--text-body)", "var(--text-help)")
    return source[:start] + block + source[end:]


def bez_kopii_testu_assetow(source):
    """KONTROLA DODATNIA: zabierz etapowi `assets` jeden z plików `node --test`.

    `package.json` nadal podaje `scripts/pwa-install.test.mjs` do `node --test`,
    więc po tej mutacji Dockerfile obiecuje mniej, niż wymaga budowanie. Test
    ma to zauważyć; Node sam by nie zauważył, bo brakujący plik pomija bez błędu.
    """
    return replace_once(
        source,
        "COPY scripts/pwa-install.test.mjs ./scripts/pwa-install.test.mjs\n",
        "",
    )


checks = [
    ("Format UUID", CONTROLLER, COLLECTION_TEST,
     lambda s: replace_once(s, "'bail', 'nullable', 'uuid',", "'bail', 'nullable',")),
    ("Własność zeszytu", CONTROLLER, COLLECTION_TEST,
     lambda s: replace_once(s, "Rule::exists('collections', 'id')->where('owner_id', $request->user()->getKey())", "Rule::exists('collections', 'id')")),
    ("Komunikat po powrocie", LAYOUT, COLLECTION_TEST, remove_notice),
    ("Podpis co najmniej 18 px", CSS, COMPOSER_TEST, smaller_help),
    ("Licznik w widocznym menu konta", LAYOUT, "test_wejscie_do_panelu_pokazuje_sume_kolejek",
     lambda s: replace_once(s, """<li><a href="{{ route('admin.reports') }}">Otwórz panel moderacji <x-licznik-kolejki :ile="$czekaWPanelu" /></a></li>""", """<li><a href="{{ route('admin.reports') }}">Otwórz panel moderacji</a></li>""")),
    ("Strażnik tekstu bez własnego wpisu", STRAZNIK_SAM_SKRYPT, STRAZNIK_TEKSTU_TEST,
     bez_wpisu_dla_straznika),
    ("Odstępstwo bez znacznika", STRAZNIK_PLIK_ODSTEPSTWA, STRAZNIK_TEKSTU_TEST,
     bez_znacznika_odstepstwa),
    ("Plik z node --test nieskopiowany do etapu assets", OBRAZ_ASSETOW, OBRAZ_ASSETOW_TEST,
     bez_kopii_testu_assetow),
]

run_test(COLLECTION_TEST, True)
run_test(COMPOSER_TEST, True)
run_test(STRAZNIK_TEKSTU_TEST, True)
run_test(OBRAZ_ASSETOW_TEST, True)
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
