# Odbiór wdrożenia dalszych wyników wyszukiwarki — #568

Data odbioru: **19 września 2026**. Odbierany kod: `main` =
`8a2ecb2a91bec7f7fef0f40e9d1c59b8c4735257`, zawierający merge PR #592
(`2144a1143c51808cdc1639c984df99d5ad586fbc`). Ten dokument **nie zmienia
implementacji** — #592 domknięto w całości, tutaj jest wyłącznie pomiar.

Dokument implementacji: [`DALSZE_WYNIKI_568.md`](DALSZE_WYNIKI_568.md).
Tabela kryteriów z warstwą dowodu przy każdym:
[`evidence/odbior568/kryteria568.md`](evidence/odbior568/kryteria568.md).

## 1. Wersja na produkcji zgadza się z odbieranym kodem

Stopka `https://kuking.pl/` odczytana `curl`-em: `Alfa 0.67`,
`wydanie 19 września 2026, 12:30 · 8a2ecb2`. To **ten sam commit**, który
odbieramy, więc wszystkie wnioski z produkcji dotyczą właściwej wersji.
CI `35437520171` success, Deploy `35437623435` success dla tego SHA.

`app/Http/Controllers/SearchController.php` ma MD5
`4a0634d3409166be8ba47ea243befa84` — tę samą sumę, którą podaje raport
implementacji z 16 września. Plik nie zmienił się od scalenia #592.

## 2. Produkcja NIE MA zbioru ponad 200 wyników — i nie da się tego obejść

Odczyt produkcji (`evidence/odbior568/produkcja568.txt`, wyłącznie GET, bez
logowania, bez tworzenia treści):

- `sitemap.xml` ma **49 adresów**: 41 wpisów, 3 profile publiczne, reszta to
  strony statyczne,
- czternaście prób zapytań (`bigos`, `pierogi`, `zupa`, `ciasto`, `sos`,
  `kurczak`, `jajko`, `sernik`, `ka`, `ie`, `ni`, `za`, `pi`, `ma`) daje
  **najwyżej 1 przepis i 2 osoby**,
- **ani jedno zapytanie nie wyprodukowało odnośnika „Pokaż więcej”.**

Serwis jest po prostu młody. Dosłowne kryterium „przejście między pełnymi
zakresami ponad 200 wyników na rzeczywistych danych produkcyjnych” jest dziś
**niewykonalne bez wytworzenia setek sztucznych rekordów**, czego issue
zakazuje wprost. Ten odbiór tego nie robi i **nie udaje**, że to zmierzył.

## 3. Co JEDNAK zmierzono na produkcji

Że kod z #592 tam działa — bez dopisywania jednego wiersza danych.
Kod sprzed #592 nie znał parametrów `od_przepisu`/`od_osoby` i zignorowałby je.

| Żądanie (produkcja) | HTTP | Co wróciło |
|---|---|---|
| `/szukaj?q=ka&sekcja=przepisy&ile=200&od_przepisu=200` | 200 | „W tym zakresie nie ma już przepisów”, „Wróć do początku przepisów” |
| `/szukaj?q=ka&sekcja=ludzie&ile=200&od_osoby=200` | 200 | „W tym zakresie nie ma już osób”, „Wróć do początku osób” |
| kliknięty href powrotu → `…&od_przepisu=0&od_osoby=0` | 200 | „Znaleziono 1 przepis.”, odnośnik powrotu znika |
| `/szukaj?q=ka&sekcja=wszystko&ile=40` | 200 | „Znaleziono 1 przepis.”, „Znaleziono 2 osoby.” |

Czyli na produkcji potwierdzone są: **droga powrotu**, **komunikat pustego
dalszego zakresu**, **zachowanie zwykłego `ile<=200`** i **obecność samej
poprawki**. Nie są potwierdzone na produkcji: przejścia między pełnymi oknami
i niezależność przesunięć — bo nie ma na czym.

## 4. Scena lokalna z 450 wynikami

Kopia wykonawcza w WSL, PostgreSQL `127.0.0.1:55439`, osobne bazy
`kuking_odbior568_claude` (testy) i `kuking_568_browser_claude` (przeglądarka),
parametry połączenia jawnie przez zmienne środowiska. Dane demonstracyjne:
450 publicznych przepisów pasujących do jednej frazy. Zero danych produkcyjnych.

Chodzenie po **rzeczywistych odnośnikach wyjętych z HTML**, nie po ręcznie
złożonych adresach (`evidence/odbior568/pomiar568-okna.txt`):

| okno | `od_przepisu` | pokazano | zapytań SQL | komunikat |
|---|---|---|---|---|
| 1 | 0 | 200 | 8 | „Pokazujemy 200 przepisów. Jest ich więcej.” |
| 2 | 200 | 200 | 8 | „Pokazujemy przepisy 201–400.” |
| 3 | 400 | 50 | 8 | „Pokazujemy przepisy 401–450.” |

Okna są **rozłączne** (przecięcie zbiorów identyfikatorów puste po każdym
kroku), a ich suma to **dokładnie 450** identyfikatorów. Trzecie okno nie ma
już odnośnika dalej. Liczba zapytań SQL jest **stała na 8 niezależnie od
przesunięcia** — żadnego dodatkowego `COUNT`, żadnego N+1.

Ta sama ścieżka trzech okien przechodzi **samym `curl`-em**, czyli bez
JavaScriptu.

## 5. Granice

`evidence/odbior568/kryteria568.md` wiersze 8–11; test
`tests/Feature/GraniceOkienWyszukiwaniaTest.php`:

- **dokładnie 200 wyników** — `jestWiecej=false`, zero odnośników „Pokaż
  więcej przepisów”, komunikat „Znaleziono 200 przepisów.” Granica nie
  przecieka o jeden w żadną stronę,
- **201 wyników** — dalsze okno ma dokładnie jeden przepis i komunikat
  w liczbie pojedynczej „Pokazujemy przepis 201.”, nie „201–201”,
- **zero wyników** — pusty stan „Nic nie znaleźliśmy”, bez odnośników okien,
- **brak frazy z przesunięciem w adresie** — zwykły ekran startowy; strona
  NIE mówi „W tym zakresie nie ma już przepisów”,
- **fraza za krótka z przesunięciem** — komunikat o za krótkiej frazie,
  też bez komunikatu pustego zakresu,
- **przesunięcie z obcej sekcji** — `od_przepisu` w sekcji „Ludzie”
  i `od_osoby` w sekcji „Przepisy” są zerowane, bez odnośnika powrotu.

## 6. Przeglądarka: 320 px i tekst 140%

`evidence/odbior568/przegladarka568.json`. Rzeczywista aplikacja Laravel na
`127.0.0.1:8568` (`php artisan serve --no-reload`), scena z 450 przepisami.

Okno 201–400 zmierzone w **12 wariantach** (320/768/1440 px × jasny/ciemny ×
100%/140%): **przepełnienie poziome 0 px w każdym**, obliczony font 18 px
i 25,2 px zgodnie ze skalą.

Rzeczywistymi kliknięciami przy 320 px: okno 1 → 2 → 3, potem **Tab
z klawiatury na „Wróć do początku przepisów” i Enter** przy 320 px, motywie
ciemnym i tekście 140% — adres wraca do `od_przepisu=0`, lista znów ma 200
pozycji, odnośnik powrotu znika. Obejrzano zrzut: pierścień fokusu widoczny
i niezasłonięty, przycisk 296 × 91 px przy regule 48 px z `UX_50_PLUS.md`.

**Pułapka pomiarowa do zapamiętania:** odczyt stylu bezpośrednio po `Tab`
trafia w trwającą przejściówkę CSS i pokazuje `box-shadow` jako przezroczysty,
a `outline-style` tego przycisku to `none` — pierścień robi wyłącznie
`box-shadow`. Pomiar samego `outline` dałby tu fałszywy alarm.

Skala tekstu i motyw ustawiane były atrybutami dokumentu
(`data-text-scale`, `data-theme`). To **nie jest** dowód zapisu preferencji
przez formularz ani pomiar rzeczywistego zoomu przeglądarki 200%.

## 7. Bramki lokalne i kontrola ujemna nowego testu

Wszystko na PostgreSQL `127.0.0.1:55439`, w osobnej kopii wykonawczej
(`vendor` skopiowany, nie dowiązany; `composer dump-autoload --optimize`
policzył 10 042 klasy).

- rodziny wyszukiwania (12 plików): **72 testy / 418 asercji PASS**,
- `DalszeWynikiWyszukiwaniaTest` sam: **10 testów / 90 asercji PASS**,
- nowy `GraniceOkienWyszukiwaniaTest`: **6 testów / 34 asercje PASS**,
- pełna suita na `8a2ecb2` z dołożonym nowym testem: **4286 testów,
  4285 PASS, 82 806 asercji, 368 s**. Jedyna porażka
  (`KonfiguracjaDyskowTest::test_kazdy_dysk_z_konfiguracji_da_sie_utworzyc`)
  to **brak `AWS_DEFAULT_REGION` w mojej kopii wykonawczej**, nie regresja:
  po ustawieniu tej zmiennej rodzina daje **6/6 PASS**. Kodu aplikacji
  nie zmieniano. (Wcześniejszy przebieg z `APP_URL=127.0.0.1` w `.env` dawał
  dodatkowo 2 porażki `ZaufaneHostyTest`; po usunięciu `APP_URL` rodzina
  daje **13/13 PASS** — też artefakt środowiska, nie kodu.)
- Pint i PHPStan na nowym pliku testu: bez uwag.

**Fizyczna kontrola ujemna** (`evidence/odbior568/kontrola-ujemna568.txt`).
Przed startem skrypt sprawdza `git status`, że oba mutowane pliki są
identyczne z HEAD — inaczej przerywa (pułapka 8 z `PULAPKI_TESTOW.md`).
Mutowany jest KOD APLIKACJI, nie test, a każda mutacja jest potwierdzona
`grep`-em, zanim cokolwiek się uruchomi:

| mutacja | grep potwierdził | wynik | przywrócenie |
|---|---|---|---|
| `jestWiecej` liczone z `>=` zamiast `>` | `count() >= $ile` w linii 152 | 1 failed / 5 passed, kod 1, porażka dokładnie na asercji granicy 200 | MD5 `4a0634d3409166be8ba47ea243befa84` = stan wyjściowy |
| zakres w widoku liczony bez przesunięcia | `Pokazujemy przepis {{ 1 }}` w linii 168 | 1 failed / 5 passed, kod 1, porażka na „Pokazujemy przepis 201.” | MD5 `d15ed216a63fa637ad383e11e6fe5cd4` = stan wyjściowy |

Kontrola dodatnia po przywróceniu obu plików: **16 testów / 124 asercje,
kod wyjścia 0**. Pliki przywraca `trap`, nie „potem”.

## 8. Czego ten odbiór NIE sprawdził

- przejścia między pełnymi oknami >200 **na danych produkcyjnych** — taki
  zbiór nie istnieje i nie wolno go wytwarzać,
- rzeczywistego zoomu przeglądarki 200% (mierzono skalę tekstu); zoom 200%
  ma osobny dowód z 16 września, zrobiony na gałęzi sprzed scalenia,
- zapisu preferencji wyglądu przez formularz i stanów zalogowanych na scenie
  z dużym zbiorem,
- czytnika ekranu, Windows High Contrast i fizycznego telefonu,
- zachowania przy zmianie danych **w trakcie** przeglądania okien — paginacja
  offsetowa nie daje niezmiennej migawki i to jest znana, zapisana własność,
  nie usterka tego pakietu.

## 9. Rekomendacja

**Zamknąć #568 jako completed.** Wszystkie siedem punktów z listy „Pozostał
tylko odbiór” jest pokrytych, a jedyne, czego nie da się pokryć na produkcji
(przejścia między pełnymi oknami >200), jest niemierzalne z powodu wielkości
serwisu, a nie z powodu kodu — i issue samo zabrania wytwarzania tam danych.

Co pozostaje **nieudowodnione mimo zamknięcia**, wprost:

1. Nikt nigdy nie zobaczył na produkcji przejścia z okna 1–200 do 201–400 na
   prawdziwych danych. Dowodem jest scena lokalna i CI, nie produkcja.
2. Nie zmierzono na produkcji niezależności przesunięć przepisów i osób ani
   zachowania filtrów/prywatności w dalszych oknach — z tego samego powodu.
3. Nie zmierzono rzeczywistego zoomu przeglądarki 200% na kodzie po scaleniu
   (istnieje dowód z 16 września, ale z gałęzi sprzed merge).

Jeśli to za mało, jedyną uczciwą alternatywą jest **nie zamykać i poczekać,
aż serwis urośnie do ponad 200 dopasowań dla jednej frazy** — dziś najszersze
zapytanie daje 1 przepis i 2 osoby, więc byłoby to czekanie na treść, nie na
kod. Zamknięcie z tą listą ograniczeń jest lepsze niż otwarte issue, którego
nikt nie umie domknąć.
