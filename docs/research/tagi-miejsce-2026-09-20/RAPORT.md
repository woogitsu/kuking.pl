# Tagi jako miejsce — pomiar i pierwszy zakres, 20 września 2026

## Stan początkowy

Gałąź `gpt/tagi-miejsce`, czyste drzewo przy
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Zgłoszenia #647, #369, #370 i #681
odczytano wraz z komentarzami przez `gh issue view --repo woogitsu/kuking.pl`.

**Pomiar własny przed zmianami:** filtr `Tag|Tagi|Podpowiedzi|QuestionForm|PostForm`
na PostgreSQL: 280 testów, 50 122 asercje, PASS (33,81 s). Wynik zapisany
w historii narzędzi sesji; nie zachowano osobnego surowego pliku tego przebiegu.
Podpowiedzi po `#`, rzeczywiste liczniki, statystyki, kolaż, akcje i fotograficzny
katalog A–Z już istniały. Nie wdrażamy ich drugi raz.

Odczytano z gałęzi `gpt/tagi-filtr` raport `docs/research/TAGI_FILTR_2026-09-20.md`
i dowody w `docs/research/tagi-filtr-2026-09-20/`. **[pomiar cudzy: ten raport]**
opisuje filtr, porcję 20 i doładowanie obserwowanych tagów bez JavaScriptu.
Tych plików ani ekranu obserwowanych tagów nie zmieniono; gałęzi nie scalano.

## #647 — wykonany wąski zakres

Ręcznie przypięte tagi, także wielowyrazowe, przeniesiono bezpośrednio pod opis
w tworzeniu i edycji wpisu oraz pytania. Wcześniej lista była oddzielona
kolejnymi polami. Zachowano limity 5/3, usuwanie zwykłym POST, odtwarzanie
wpisanej treści i zamkniętą domyślnie wyszukiwarkę zapasową. Podsumowanie
„Dodaj tag bezpośrednio” ma krótszą nazwę; komunikaty awarii/braku podpowiedzi
wskazują dokładnie tę drogę. Nie zmieniono mechanizmu liczenia ani parsera.

Dowody własne:

- `647-czerwien.txt`: nowy `TagiPrzyOpisieTest` przed poprawką — 4 porażki,
  16 asercji, lista poza obszarem opisu. Test czyta rzeczywiste cztery formularze.
- `647-zielen.txt`: po poprawce — 66 testów, 373 asercje, PASS.
- `pelne-testy.txt`: 4397 testów, 83 736 asercji, PASS, 509,76 s.
  Pominięto wyłącznie `ProbaOdtworzeniaTest`, zgodnie z ostrzeżeniem o wspólnej
  bazie źródłowej. Nie zgłaszamy wykonania testu pominiętego.
- `647-przegladarka.txt`: klawiatura (strzałka/Enter) i kliknięcie wybierają
  podpowiedź bez wysłania formularza. 24 układy: szerokości 320, 360, 390,
  414, 768, 1440, dwa motywy, tekst 100/140%. Brak przewijania w bok,
  lista mieści się w oknie, opcje >=48 px, tekst pola >=18 px.
- `647-bez-js.txt`: w edycji bez JavaScriptu usunięcie, wyszukanie i dodanie
  wielowyrazowego tagu zachowuje zmieniony opis; przycisk usuwania 73 px.
- Obejrzano zrzuty małego ekranu z podpowiedzią i formularza bez skryptu.
- `npm run build`: PASS (72 pary kontrastu, 20 testów JS, build Vite).
  To kontrola istniejącego zestawu, nie nowy audyt wszystkich kontrastów.

Testy: osobna baza `kuking_flota_gpt-tagi-miejsce`. Przeglądarka:
`kuking_flota_gpt_tagi_miejsce_a11y`, właściciel `kuking`, host `127.0.0.1`,
port **55439**. Runtime `/home/mateusz/flota/gpt-tagi-miejsce-run`, aplikacja
lokalna na porcie 8647. `fixture.php` odmawia innej bazy i niepustych danych.
Zdjęcia pomiarowe są syntetyczne, nie są treścią do publikacji.

## #369 — pomiar i decyzja właściciela

`369-pomiar.txt`: anonimowy odbiorca bez JavaScriptu mógł odczytać autorów
z publicznych kart i paginacji:

| Osoby w fixture | Rozpoznawalni autorzy publicznych kart | Strony | Statystyka |
|---|---|---|---|
| 2 | 2 | 1 | zaproszenie zamiast liczby |
| 3 | 3 | 1 | 5 zdjęć od 3 osób |
| 5 | 5 | 1 | 5 zdjęć od 5 osób |
| 10 | 10 | 1 | 10 zdjęć od 10 osób |
| 42 | 42 | 3 | 42 zdjęcia od 42 osób |

Sam próg nie anonimizuje autorów, których publiczne wpisy nadal pokazujemy.
Nie udajemy, że ten eksperyment wyznaczył uniwersalną bezpieczną liczbę.
Po otrzymaniu wyniku właściciel jawnie wybrał: **zachowaj 5 zdjęć / 3 osoby
wyłącznie jako próg prezentacji publicznej aktywności, bez obietnicy anonimowości**.
Zapisano to w `docs/DECISIONS.md`. Kod i dotychczasowe testy progów pozostają;
nie dodano testu rzekomej anonimowości.

## #370 i #681 — istniejące zachowanie, bez przebudowy

`370-681-przegladarka.txt`: lokalnie, jako gość bez JavaScriptu, 16 układów
na stronie tagu i w katalogu (320/390/768/1440 px, tekst 100/140%), bez
przewijania w bok. Kolaż i fotograficzne karty obecne w DOM. Kliknięcie
kafla otwiera tag, „Dodaj wpis z tym tagiem” prowadzi gościa do logowania.
Testy istniejących mechanizmów objęto pomiarem bazowym i pełnym przebiegiem.
Nie nazywamy liczby elementów `img` dowodem jakości prawdziwych fotografii.

## Granice, wycofanie i dalszy odbiór

Nie wykonano push, PR, wdrożenia, zmian schematu, danych produkcji ani wysyłki
wiadomości. Nie przeprowadzono odbioru na rzeczywistym publicznym zbiorze,
telefonie z klawiaturą ekranową/IME ani rzeczywistego zoomu przeglądarki 200%.
Skala tekstu 140% nie zastępuje takiego zoomu. Nie zamykano zgłoszeń na GitHubie.
Prace nad szeroką przebudową #370/#681 nie są uzasadnione wynikiem tego pomiaru;
pozostaje odbiór produkcyjny i ewentualny kolejny, konkretnie wskazany zakres.
Historyczne uwagi z dyskusji nie są automatycznie uznane za usterki dzisiejszego kodu.

Decyzja wymagana do tego zakresu (#369) została podjęta. Dalsze rozszerzenie
prywatności publicznego autorstwa byłoby oddzielną decyzją produktową.
Wycofanie formularzy: revert lokalnego commita; brak migracji i zmiany danych.

Kontrola końcowa: `vendor/bin/pint` PASS (1156 plików runtime i osobno fixture),
`koncowe-testy.txt` PASS (17 testów, 191 asercji) po usunięciu nadmiarowych
pustych wierszy w widokach. Surowe wyniki tekstowe mają ujednolicone kodowanie
UTF-8 i usunięte końcowe spacje. Zrzuty: `647-popup-320-dark140.png` oraz
`647-nojs-320.png` (długi zrzut zawiera stałą dolną nawigację).
