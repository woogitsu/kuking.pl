# JIT na połączeniach aplikacji — #2291

## Kontrakt i granica dowodu

`config/database.php` przekazuje `server_options.jit = off` na każde nowe
połączenie PostgreSQL, także z `DB_URL`. Puste `DB_JIT` również daje `off`;
jawne `DB_JIT=on` włącza JIT dla tej sesji. Nie zmieniamy ustawień całego
serwera ani nie wykonujemy `ALTER DATABASE` lub `ALTER SYSTEM`.

`PolaczenieBazyMaWylaczonyJitTest` otwiera świeże połączenia po `DB::purge()`
i mierzy `SHOW jit`. Obejmuje połączenie domyślne, drugą nazwę oraz URL.
Przypadek z `jit=on` jest kontrolą dodatnią działania opcji; sam nie dowodzi,
że test wykryje regresję domyślnej konfiguracji.

## Wykonywana kontrola ujemna

Rejestr `scripts/kontrole-negatywne-alfa08.py` zmienia domyślne `off` na `on`
w źródle konfiguracji. Filtr wskazuje dokładnie:

```text
PolaczenieBazyMaWylaczonyJitTest::swieze_polaczenie_aplikacji_ma_jit_off
```

Ta metoda używa atrybutu `#[Test]`; jej nazwa nie ma prefiksu `test_`.
Oczekiwana porażka ma marker `JIT_2291_SWIEZA_SESJA_WYMAGA_OFF`. Istniejący
runner wymaga zielonego testu przed mutacją, właściwej przyczyny porażki,
dokładnego przywrócenia źródła i ponownego zielonego testu. Inna przyczyna
porażki lub brak wykonanych testów nie zaliczają kontroli.

Nową mutację należy odebrać z rzeczywistych logów CI na PostgreSQL 18.
Kontrola składni ani wcześniejsze zielone CI bez tej mutacji nie są dowodem
jej wykonania. Testy wymagają izolowanej bazy; nie uruchamiamy mutacji na
produkcji.

## Odbiór i wycofanie

Zielone CI i wdrożony commit dowodzą dostarczenia kodu. Pomiar produkcyjnego
`SHOW jit` na świeżej sesji aplikacji jest osobnym odbiorem, uwzględniającym
jej faktyczne `DB_JIT` i konfigurację połączenia. Nie deklarujemy go na
podstawie testów ani ustawienia serwera odczytanego poza aplikacją.

Ta zmiana dodaje wyłącznie dowód regresji, bez migracji i zmiany ustawienia
produkcyjnego. Wycofanie to cofnięcie wpisu kontroli, markera i tego dokumentu;
pozostawia istniejące domyślne `jit=off` w aplikacji.
