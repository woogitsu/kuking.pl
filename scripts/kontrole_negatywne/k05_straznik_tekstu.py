"""Strażnik nowych strażników tekstu i jego dwie kontrole dodatnie.

Nazwa klasy strażnika stoi w CAŁYM katalogu `scripts/kontrole_negatywne/`
DOKŁADNIE RAZ — w stałej niżej — i to jest celowe: strażnik szuka swojej nazwy
we wszystkich plikach `*.py` tego katalogu, więc ta linia jest jednocześnie
jego pokryciem i punktem mutacji pierwszej kontroli dodatniej. Dlatego obie
kontrole ze strażnikiem stoją w tym jednym pliku: druga kopia nazwy w innym
pliku sprawiłaby, że po mutacji strażnik nadal znajdowałby pokrycie.
"""

from pathlib import Path

from kontrole_negatywne._narzedzia import ROOT, Kontrola, replace_once


STRAZNIK_TEKSTU_TEST = "StraznikTekstuMaKontroleDodatniaTest"
STRAZNIK_SAM_PLIK = Path(__file__).resolve().relative_to(ROOT).as_posix()
STRAZNIK_PLIK_ODSTEPSTWA = "tests/Feature/PlikKontrolnyZOdstepstwemTest.php"


def bez_wpisu_dla_straznika(source):
    """KONTROLA DODATNIA 1: zabierz strażnikowi jego własny wpis w tym pliku.

    Strażnik szuka tu swojej nazwy klasy. Po podmianie nie znajdzie jej, uzna
    sam siebie za strażnika tekstu bez pokrycia i ma zapalić. Podmieniamy samą
    wartość stałej, nie wpis w `KONTROLE` — dzięki temu mutacja nie rusza tego,
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

    Plik czyta źródło i asertuje na jego treści, a kontroli w tym katalogu nie ma.
    Bez znacznika zostaje strażnikiem tekstu bez pokrycia — strażnik ma zapalić
    z drugiej strony niż w kontroli 1.
    """
    start = source.index(" * @bez-kontroli-dodatniej")
    end = source.index("\n", start) + 1

    return source[:start] + source[end:]


KONTROLE_DODATNIE = [STRAZNIK_TEKSTU_TEST]

KONTROLE = [
    Kontrola("Strażnik tekstu bez własnego wpisu", STRAZNIK_SAM_PLIK, STRAZNIK_TEKSTU_TEST,
             bez_wpisu_dla_straznika,
             # Sama nazwa klasy byłaby drugim wystąpieniem w tym pliku — składamy.
             oczekuj=r"Bez pokrycia:.*" + STRAZNIK_TEKSTU_TEST + r"\.php — brak kontroli"),
    Kontrola("Odstępstwo bez znacznika", STRAZNIK_PLIK_ODSTEPSTWA, STRAZNIK_TEKSTU_TEST,
             bez_znacznika_odstepstwa,
             oczekuj=r"Bez pokrycia:.*PlikKontrolnyZOdstepstwemTest\.php — brak kontroli"),
]
