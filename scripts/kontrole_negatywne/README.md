# Kontrole negatywne — jeden plik na kontrolę

Każdy plik `*.py` w tym katalogu, którego nazwa **nie** zaczyna się od `_`,
jest plikiem kontroli. Punkt wejścia `scripts/kontrole-negatywne-alfa08.py`
wykrywa je sam (glob + import), w kolejności nazw plików. Nie ma listy do
dopisywania, więc dwa PR-y z nowymi kontrolami nie wchodzą sobie w drogę.

- `_narzedzia.py` — `Kontrola`, `replace_once`, `run_test`, bramka lokalnego
  uruchomienia, kopia zapasowa, przywracanie i raport. **Nie dopisuj tu kontroli.**
- `kNN_<obszar>.py` — kontrole. Numer ustala tylko kolejność; ten sam numer
  w dwóch PR-ach nie jest konfliktem (różne nazwy plików), więc bierz
  następny wolny i się nie przejmuj.

## Kolejność w jednym przebiegu

1. Wszystkie `KONTROLE_DODATNIE` ze wszystkich plików, po kolei: test ma przejść
   na nietkniętym źródle.
2. Każda `Kontrola`: kopia pliku poza repo → mutacja (musi zmienić md5) → test
   ma **oblać z `FAILED`** → przywrócenie z kopii (także przy wyjątku) → md5 ma
   się zgadzać → test ma przejść.

Odmowa jeszcze przed pierwszym testem: pusty katalog, plik bez `KONTROLE`,
wpis, który nie jest `Kontrola(...)`, dwie kontrole o tej samej nazwie.

## Wzór pliku

```python
"""Co ten strażnik pilnuje i dlaczego tylko mutacja to dowodzi (#numer issue)."""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


PLIK = "resources/css/app.css"
TEST = "NazwaTwojegoTestuTest"   # klasa albo pojedyncza metoda test_…


def moja_mutacja(source):
    """KONTROLA DODATNIA: psuje dokładnie to, czego test pilnuje."""
    return replace_once(source, "co jest teraz", "co by to zepsuło")


KONTROLE_DODATNIE = [TEST]   # opcjonalne; test ma przejść przed mutacją

KONTROLE = [
    Kontrola("Krótka, niepowtarzalna nazwa", PLIK, TEST, moja_mutacja),
]
```

Zasady, których pilnuje `tests/Feature/StraznikTekstuMaKontroleDodatniaTest.php`:

- nazwa testu stoi w pliku kontroli jako **samodzielny napis w cudzysłowie**
  (`"NazwaTestu"`) — dopiero wtedy strażnik uznaje test czytający źródła za pokryty;
- nazwa kontroli to napis w pierwszym argumencie `Kontrola(...)` i jest unikalna
  w całym katalogu;
- nazwy strażnika `StraznikTekstuMaKontroleDodatniaTest` nie wpisuj w cudzysłowie
  poza `k05_straznik_tekstu.py` — tam stoi celowo raz, bo jest punktem mutacji
  jego własnej kontroli dodatniej.

## Sprawdzenie przed commitem

```sh
python3 scripts/kontrole-negatywne-alfa08.py --lista   # tryb suchy: bez testów, bez mutacji

DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_<stanowisko> \
KUKING_KONTROLE_LOKALNIE=1 python3 scripts/kontrole-negatywne-alfa08.py
```

Pełny przebieg pisze po źródłach i kasuje bazę testów, więc lokalnie wymaga
jawnej zgody i własnej bazy (nigdy portu 5432). W CI rusza sam.

## Gałąź sprzed podziału (dopisywała do starego pliku)

Scalenie main daje konflikt w `scripts/kontrole-negatywne-alfa08.py`.
Weź punkt wejścia z main (`git checkout origin/main -- scripts/kontrole-negatywne-alfa08.py`),
a każdy swój wpis — stałe, funkcję mutacji, krotkę z `checks` i `run_test(...)` —
przenieś do nowego pliku `kNN_<obszar>.py` według wzoru wyżej: krotka
`("nazwa", PLIK, TEST, mutacja)` staje się `Kontrola("nazwa", PLIK, TEST, mutacja)`,
a `run_test(TEST, True)` — pozycją w `KONTROLE_DODATNIE`. Pełne kroki:
`docs/flota/PRZENIESIENIE_PO_PODZIALE.md`.
