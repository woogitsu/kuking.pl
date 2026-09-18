# Paginacja komentarzy i wykonań — #652

Status: **scalona i wdrożona**. PR #654, merge
`84186922f2c9c5bb91d2fd9f352a86eb9b8cf935`. Baza prac:
`a51aaca932e5da08995a93a514890664a5eeb4ce` (integracja #651).

Zdanie „przygotowana poprawka, jeszcze bez PR i wdrożenia" stało tu do
18 września 2026 i było już nieprawdziwe — opisywało stan z chwili pisania
pliku, przed wysyłką. Sekcja „Dostarczenie i wycofanie" niżej ma aktualne
liczby.

## Problem i zmiana

Na stronie przepisu przejście z drugiej strony wykonań do kolejnych
komentarzy usuwało parametr `wykonania`, cofając galerię na stronę pierwszą.
Odwrotny kierunek działał dzięki `withQueryString()`, ale przenosił także
niepowiązane parametry adresu.

`PaginationLinks::preserveOtherPage()` dołącza wyłącznie rozwiązaną stronę
drugiej listy, gdy jest większa od jednego. Ogranicza numer jej własną
ostatnią stroną. Oba kontrolery — przepisu i zeszytu — korzystają z tej
samej reguły. Filtry widoczności, zapytania i dane pozostają bez zmian.

Helper zmienia odnośniki, nie bieżącą odpowiedź. Ręcznie wpisane dwa numery
poza zakresem nadal mogą dać puste listy bez przycisków; nie jest to naprawione
przez ten pakiet.

## Dowody lokalne

- Reprodukcja przed zmianą: 2 testy, 230 asercji, jedna oczekiwana porażka.
- Po zmianie, razem z regresją zeszytu: 15 testów, 1771 asercji PASS.
- Pint dla czterech zmienionych plików PASS; PHPStan bez błędów.
- Pięć fizycznych kontroli ujemnych wykryło usunięcie każdego wywołania
  w przepisie, użycie ostatniej zamiast bieżącej strony, limit cudzej listy
  oraz przywrócenie `withQueryString()`. Po przywróceniu kopii spoza repo
  MD5 i mtime zgodne; kontrola dodatnia ponownie PASS.
- Niezależny przegląd kodu nie znalazł blockerów. Reviewer nie powtarzał
  testów ani nie weryfikował niezależnie surowych logów.

## Przeglądarka

Izolowana baza `kuking_652_browser`, PostgreSQL na `127.0.0.1:55439`, UTC.
Fixture: 29 komentarzy i 41 wykonań. Nie zmieniano danych produkcyjnych.

Rzeczywiste kliknięcia w Chrome zachowują pozycję drugiej listy w obu
kierunkach. Wstecz przywraca obie strony. Przy 320 px i tekście 140%
przycisk komentarzy ma widoczny obrys fokusu 3 px; Enter przechodzi na
komentarze 25–29, zachowując wykonania 13–24.

Pomiar geometrii: 320, 360, 390, 414, 768 i 1440 px, oba motywy, tekst
100% i 140% — 24 kombinacje bez poziomego overflow. To nie oznacza
24 osobnych zrzutów ani powtórzenia wszystkich kliknięć w każdej konfiguracji.
Obejrzano zrzuty 320 i 1440 px w ciemnym motywie przy 140%.
Przyciski przy 320 px: 50,5 px wysokości dla 100%, 91 px dla 140%.

Rzeczywisty zoom 200%: 4/4 sceny PASS (oba motywy i oba kierunki).
Rozszerzenie Chrome ustawiło zoom, a `chrome.tabs.getZoom()` po nawigacji
potwierdziło wartość 2. Viewport zmienił się z 640×1800 na 320×900;
DPR wynosił 2, szerokość dokumentu 320. Nie zastępowano zoomu zmianą fontu
ani deviceScaleFactor. Kliknięcia zachowały oba parametry strony i pozycje
13 na obu listach. Agent obejrzał wszystkie cztery zrzuty: etykiety pełne,
przyciski odsłonięte, brak poziomego przepełnienia. Dowody lokalne:
[`evidence/pagination652/zoom/ODBIOR.md`](evidence/pagination652/zoom/ODBIOR.md)
i `report.json` w tym samym katalogu, wraz z czterema zrzutami.

Test na fizycznym telefonie
nie został wykonany. Fixture bez zdjęć służy sprawdzeniu paginacji,
nie odbiorowi całej kompozycji przepisu.

## Dostarczenie i wycofanie

Wersja Alfa 0.60 i changelog. Pakiet przeszedł hook, push i wymagane CI
(35277046687: 12/12 success, pełne PHP 3983 testy / 80649 asercji) i został
scalony jako `8418692`. Nie ma migracji. Wycofanie przez sprawdzony PR
cofający pakiet przywróci poprzednie budowanie odnośników, wraz z opisanym
błędem.

## Granica dowodu produkcyjnego — 18 września 2026

Odczyt produkcji `https://kuking.pl`, 13:10–13:24 UTC, wyłącznie HTTP GET.
Stopka: `Alfa 0.65 · wydanie 18 września 2026, 12:12 · 55877e2`. Porównanie
przez API GitHub: wdrożony `55877e2` jest 29 commitów przed `8418692`
i **0 wstecz** — naprawa jest na żywo. To dowód wersji, nie odbiór
interakcji.

Publicznie dostępne są trzy treści (`/szukaj?sekcja=przepisy`):

| treść | trasa | wykonania | komentarze |
| --- | --- | --- | --- |
| „Bigos z cukinii", slug bigos-z-cukinii | `/przepisy/{recipe}` | 0 — „Jeszcze nikt tego nie gotował" | 0 — „Jeszcze nikt tu nic nie napisał" |
| „Rolada kawowa", 01a0a6af-4aea-7230-9b41-8c6d4b694eee | `/wpisy/{post}` | sekcji brak (to wpis, nie przepis) | 0 |
| sałatka ziemniaczana, 01a08f53-75fc-714d-9828-af759b1024af | `/wpisy/{post}` | sekcji brak (to wpis, nie przepis) | 1 |

**Publiczny przepis jest dokładnie jeden.** „Rolada kawowa" to wpis, a nie
przepis — adres przepisu o slugu rolada-kawowa zwraca 404.

Rozmiar strony komentarzy to 12 (`config/kuking.php`,
`comments.page_size`), więc **żaden publiczny przepis nie wystawia nawet
jednego przycisku „Pokaż więcej"**, a scenariusz #652 wymaga dwóch
paginatorów naraz. Dołożenie komentarza albo wykonania wymagałoby zalogowania
się i zapisu na produkcji — czego w odbiorze nie robimy.

**Nie tworzono w tym celu komentarzy ani wykonań na produkcji.** To granica
dowodu, nie wynik pozytywny. Odbiór produkcyjny #652 pozostaje niewykonany
i tylko z tego powodu issue jest otwarte.

Pokrewny ekran zeszytu ma osobny raport
[`PAGINACJA_ZESZYTU_646.md`](PAGINACJA_ZESZYTU_646.md) — tam znajduje się
komplet dowodu lokalnego dla wspólnego `preserveOtherPage()` przy myszy,
dotyku i klawiaturze, wykonany na wdrożonym `55877e2`.
