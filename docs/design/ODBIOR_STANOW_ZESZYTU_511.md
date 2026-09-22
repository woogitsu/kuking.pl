# Odbiór dodatkowych stanów zeszytu — #511

Data: 2026-09-13. Odbiór lokalny, uzupełniający wcześniejszy pomiar wypełnionego zeszytu. Bez zmian źródeł aplikacji i testów, bez operacji na produkcji.

## Środowisko i zakres

Kopia `/tmp/kuking-final-20260913`, dedykowana baza `kuking_port511`, PostgreSQL `127.0.0.1:55439`, lokalny serwer na porcie 8211. Odtworzono DemoSeeder oraz fixture kompozycji; dodatkowe dane i przyrząd utworzono poza repo w `/tmp/stany511-07zA9O`. Użyto lokalnego Chromium oraz rzeczywistych operacji klawiaturą.

**20 wariantów:** pięć stanów × szerokości 320/1440 × motyw jasny/ciemny. Na 320 ustawiono tekst 140%, na 1440 — 100%. Faktycznie obliczony rozmiar tekstu wynosił odpowiednio 25,2 px i 18 px, a kolor tekstu `rgb(21, 23, 20)` / `rgb(244, 245, 241)`.

| Stan | Dane i wykonana czynność | Wynik |
|---|---|---|
| Pusty indeks | Osobne aktywne konto `pusty511`, bez zeszytów | Uczciwy pusty stan, odnośnik szukania przepisów i możliwość założenia zeszytu; bez fikcyjnych zapisów |
| Otwarty formularz | Rozwinięcie „Załóż nowy zeszyt” | Widoczne pełne etykiety nazwy, opisu, prywatności i przycisku; formularz dostępny klawiaturą |
| Walidacja | Prawdziwy lokalny POST: nazwa zawierająca spację oraz opis | Serwer odrzucił nazwę; podsumowanie i błąd pola widoczne, opis zachowany, formularz pozostaje otwarty |
| Pusty szczegół | Własny „Pusty zeszyt pomiarowy” konta Ania | Pusty stan i dalsze akcje, bez udawanych przepisów; zachowana szyna innych zeszytów |
| Tylko wpisy | „Tylko zapisane wpisy” z jednym lokalnym publicznym wpisem bez zdjęcia | Prawdziwa treść wpisu i jego akcje; brak fikcyjnego zdjęcia i siatki przepisów |

We wszystkich 20 wariantach szerokość dokumentu nie przekracza szerokości okna. Po wszystkich niepoprawnych wysłaniach formularza niezależny odczyt bazy potwierdził **0 zeszytów konta `pusty511`**. Nie utworzono folderu przez badaną walidację.

## Klawiatura i ogląd

W każdym wariancie wykonano 22 rzeczywiste naciśnięcia Tab i zapisano osiągnięte elementy. To ograniczony przebieg formularzy oraz głównej treści, a nie deklaracja kompletnego audytu wszystkich kontrolek i rozwijanych menu.

Pomiar środka wspólnego prostokąta wielowierszowego odnośnika błędu na 320 px zgłosił podejrzenie niewidoczności. Osobna diagnoza jasnego motywu i tekstu 140% sprawdziła cztery rzeczywiste fragmenty `getClientRects`: środek każdego trafia w ten odnośnik i jest widoczny. Zrzut aktualnego fokusu potwierdza pełny tekst i widoczny obrys, poza nagłówkiem i dolną nawigacją. Nie potwierdzono błędu aplikacji. Dla ciemnego wariantu nie wykonano dodatkowej niezależnej analizy fragmentów; nie należy przedstawiać samego testu wspólnego prostokąta jako dowodu zasłonięcia.

Ręcznie obejrzano reprezentatywne zrzuty: walidacja 320 jasna, pusty szczegół 320 jasny, pusty indeks 1440 ciemny, tylko wpisy 320 ciemny, otwarty formularz 1440 jasny oraz osobny aktualny fokus błędu. Nie deklarujemy osobnego ręcznego oglądu każdej z 20 grafik. Widoczne teksty w obejrzanych stanach są pełne; brak potwierdzonego nowego błędu.

## Dowody

- `output/stany511/results.json`: 20 wyników, obliczone parametry, treści oraz przebiegi Tab.
- `output/stany511/{empty-index,form-open,validation,empty-detail,posts-only}-{320,1440}-{false,true}.png`: 20 zrzutów; `false` oznacza motyw jasny.
- `output/stany511/error-focus.json` oraz `error-focus.png`: niezależna diagnoza fragmentów i aktualnego fokusu błędu.
- `/tmp/stany511-07zA9O/count.php`: odczyt potwierdzający `EMPTY_USER_COLLECTIONS=0`.

Ten odbiór **nie obejmuje rzeczywistego zoomu przeglądarki** dla pustych stanów. Wcześniejszego wyniku 96 wariantów i testów rzeczywistego zoomu wypełnionego zeszytu nie rozszerzamy na te stany. Nie badano tu wysłania poprawnego formularza, publicznego widoku cudzej kolekcji ani wszystkich możliwych błędów serwera.

Własne przeglądarki i serwer zakończono. Źródeł nie mutowano, więc nie było przywracania plików ani kontroli MD5. Baza pozostaje dedykowaną bazą pomiarową z opisanymi fixture; przed ponownym pełnym portem należy odtworzyć jego fixture zeszytów.
