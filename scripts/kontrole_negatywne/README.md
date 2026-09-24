# Kontrole negatywne — jeden plik na kontrolę

Każdy plik `*.py` w tym katalogu, którego nazwa **nie** zaczyna się od `_`,
jest plikiem kontroli. Punkt wejścia `scripts/kontrole-negatywne-alfa08.py`
wykrywa je sam (glob + import), w kolejności nazw plików. Nie ma listy do
dopisywania, więc dwa PR-y z nowymi kontrolami nie wchodzą sobie w drogę.

- `_narzedzia.py` — `Kontrola`, `replace_once`, werdykt z raportu JUnit, bramka
  lokalnego uruchomienia, kopia zapasowa, przywracanie i raport. **Nie dopisuj tu kontroli.**
- `k00_mechanizm_przyczyny.py` — `KONTROLE_MECHANIZMU`: mutacje, które dają
  czerwień z NIEWŁAŚCIWEGO powodu (fatal, obcy komunikat) i mają zostać odrzucone.
- `kNN_<obszar>.py` — kontrole. Numer ustala tylko kolejność; ten sam numer
  w dwóch PR-ach nie jest konfliktem (różne nazwy plików), więc bierz
  następny wolny i się nie przejmuj.

## Kolejność w jednym przebiegu

1. Wszystkie `KONTROLE_DODATNIE` ze wszystkich plików, po kolei: test ma przejść
   na nietkniętym źródle.
2. Każda z `KONTROLE_MECHANIZMU`: jak niżej, ale werdykt ma być `ZLA_PRZYCZYNA`.
   Inaczej mechanizm zaliczyłby fatal jako dowód i cały krok pada.
3. Każda `Kontrola`: kopia pliku poza repo → mutacja (musi zmienić md5) → test
   → **werdykt `POTWIERDZONA`** → przywrócenie z kopii (także przy wyjątku) →
   md5 ma się zgadzać → test ma przejść.

## Werdykt: czerwień z właściwego powodu (#1011)

Niezerowy kod i słowo `FAILED` **nie wystarczają** (docs/PULAPKI_TESTOW.md §5b).
Test biegnie z `--log-junit`, a werdykt czyta raport, nie wyjście dla człowieka:

| Werdykt | Kiedy |
|---|---|
| `POTWIERDZONA` | każda porażka to asercja (`<failure>`) testu z wpisu, a jej komunikat pasuje do `oczekuj` |
| `BRAK_PORAZKI` | test przeszedł mimo mutacji — strażnik nie strzeże |
| `ZLA_PRZYCZYNA` | brak raportu (fatal, bootstrap, baza), wyjątek zamiast asercji (`<error>`), porażka innego testu, komunikat spoza wzorca |

`oczekuj` jest **obowiązkowe**: wyrażenie regularne (`re.search`, `re.DOTALL`)
dopasowywane do KAŻDEJ porażki, bez pierwszego wiersza z nazwą testu — więc
sama nazwa testu niczego nie „wyjaśnia". Weź zdanie z komunikatu asercji,
a jeśli asercja nie ma własnego, końcówkę `Failed asserting that … contains "…"`.
Log pokazuje jedną linię `WERDYKT <nazwa>: …` na mutację; pełne wyjście PHPUnita
tylko przy werdykcie innym niż oczekiwany.

Odmowa jeszcze przed pierwszym testem: pusty katalog, plik bez `KONTROLE`,
wpis, który nie jest `Kontrola(...)`, dwie kontrole o tej samej nazwie,
kontrola bez `oczekuj` albo z wzorcem, który się nie kompiluje.

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
    Kontrola("Krótka, niepowtarzalna nazwa", PLIK, TEST, moja_mutacja,
             oczekuj=r"zdanie z komunikatu asercji, które ta mutacja wywołuje"),
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
python3 tests/skrypty/kontrole-negatywne-przyczyna.py  # test samego werdyktu, bez bazy

DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_<stanowisko> \
KUKING_KONTROLE_LOKALNIE=1 python3 scripts/kontrole-negatywne-alfa08.py
```

Pełny przebieg pisze po źródłach i kasuje bazę testów, więc lokalnie wymaga
jawnej zgody i własnej bazy (nigdy portu 5432). W CI rusza sam.
