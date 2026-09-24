# Panel tablicy dnia — pomiary zgłoszeń #863–#868

Data: 20 września 2026. Stanowisko: `gpt-tablica`, gałąź `gpt/tablica`.
Baza pracy: `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`.

## Pochodzenie pracy

Przejęto commit `a57d9219` — odtworzenie kolejności wyróżnień po pobraniu
modeli przez `whereIn` oraz trzy testy `KolejnoscWyroznienTablicyDniaTest`.
Nie uznano wcześniejszego pomiaru za dowód. Samodzielnie uruchomiono testy
na przejętym stanie (27 testów, 85 asercji) oraz kontrolę ujemną usuwającą
odtworzenie kolejności wyłącznie z izolowanej kopii wykonawczej: test oblał,
po przywróceniu przeszedł. Kod `DailyBoard.php` pozostaje dokładnie taki
jak w przejętym commicie; automatyczny dobór nie był zmieniany.

W tej sesji wykonano pozostałe poprawki, testy formularza i kart pytań,
kontrolę remisu `position`, sprawdzenie skrótu tablicy na stronie powitalnej,
dokumentację oraz poniższe pomiary. Nie przejęto cudzych wyników testów.

## Wynik funkcjonalny

| Zgłoszenie | Zachowanie sprawdzone samodzielnie |
| --- | --- |
| #864 | Formularz zawiera wybrane osoby i wpisy poza czterdziestką oraz wpis starszy niż siedem dni. Powtórny zapis zachowuje wybór i notatki; jawne odznaczenie usuwa wyróżnienie. Prywatna pozycja i jej notatka nie wyciekają, a odczyt panelu nie zmienia prywatności. |
| #866 | Kolejność ręczna przeżywa pobranie modeli, odfiltrowanie niedostępnych pozycji, automatyczne uzupełnienie i skrót strony powitalnej. Remis `position` rozstrzyga `daily_picks.id`. |
| #865 | Błąd walidacji odtwarza nowe zaznaczenia, tekst notatek, celowo puste pola i całkowicie odznaczoną grupę. Nieprawidłowy typ wejścia nie powoduje błędu renderowania, tekst jest kodowany przez Blade. |
| #863 | Komunikat nie obiecuje liczby widocznych kart. Zduplikowane UUID-y zapisują jeden wiersz; audyt rozróżnia liczbę przesłaną i zapisaną. Pusty wybór przywraca automat. |
| #867 | Z poziomu widocznego formularza można znaleźć autora spoza pierwszych 40 osób, zachowując wcześniejsze wybory i ich notatki. Samo wyszukanie nie zapisuje tablicy; zatwierdzenie zapisuje obie osoby. Niedostępnego konta nie można dopisać przez ręczne UUID. |
| #868 | Pytanie z samym tytułem i pytanie z dodatkowym opisem pokazują pełny tytuł, także w nazwie dostępnej linku, w doborze ręcznym i automatycznym oraz w panelu. Sekcja z pytaniem ma podpis „Dania i pytania”. Wyłączona flaga, prywatność i ukrycie nadal wykluczają pytanie. |

Decyzję dotyczącą #868 podjął właściciel w tej sesji: „Tak — pokaż tytuł
pytania i dostosuj podpis sekcji”. D-081 pozostaje bez zmian. Szczegóły
zachowania i wycofania: [opis panelu](../product/PANEL_TABLICY_DNIA.md).

## Testy i kontrole ujemne

Testy wykonywano na PostgreSQL `127.0.0.1:55439`, baza
`kuking_flota_gpt-tablica`, użytkownik `kuking`. Kopia wykonawcza:
`/home/mateusz/flota/gpt-tablica-run`. Po zmianach synchronizowano ją
skryptem floty z argumentem `gpt-tablica`.

- Przed poprawkami nowe regresje formularza i tytułu pytania oblały.
- Zestaw ukierunkowany: **47 testów, 251 asercji, wszystkie przeszły**.
- Pierwszy pełny przebieg: 4400 testów przeszło, jeden oblał, ponieważ
  dotychczasowy test wymagał niewidocznych podpisów notatek. Zastąpiono go
  sprawdzeniem widocznych, powiązanych podpisów nad rzeczywistymi polami.
- Drugi pełny przebieg: 4400 testów przeszło, jeden oblał na odnośniku
  do niniejszego, jeszcze niezapisanego raportu. Uzupełniono dokument.
- Końcowy pełny przebieg: **4401 testów przeszło, 83 633 asercje**, 346,08 s.
- Po ostatniej korekcie opisu panelu („jeden wpis od osoby”) ponowiono
  regresje obszaru i sprawdzenie dokumentacji: **50 testów, 300 asercji,
  wszystkie przeszły**.
- `ProbaOdtworzeniaTest` wyłączono zgodnie z poleceniem właściciela:
  korzysta ze współdzielonej bazy odtwarzania poza izolacją stanowiska.
- Pint uruchomiono dla zmienionych plików PHP. Assety zbudowano z wersji
  zależności zgodnych z lockiem; po wykryciu starego Vite w cache wykonano
  `npm ci` w izolowanej kopii, bez zmiany manifestu ani locka.

Kontrole ujemne w kopii wykonawczej miały cykl: zielony test → celowa
mutacja → czerwony test z oczekiwaną przyczyną → odtworzenie bajtów i czasu
pliku → zielony test. Dla Blade przed testem czyszczono kompilowane widoki,
aby przywrócenie czasu pliku nie pozostawiało w cache celowo zepsutego widoku.

| Kontrola | Celowo przywrócona usterka | Wynik |
| --- | --- | --- |
| 864-wybor | Pominięcie wcześniej wybranych wpisów poza kandydatami | Potwierdzona |
| 865-formularz | Wyłączenie odtwarzania przesłanego formularza | Potwierdzona |
| 866 | Usunięcie odtworzenia kolejności z poprawki poprzednika | Potwierdzona |
| 866-wpisy | Usunięcie wyłącznie odtworzenia kolejności wpisów, z zachowaniem kolejności osób | Potwierdzona |
| 866-remis | Usunięcie rozstrzygnięcia remisu po identyfikatorze | Potwierdzona |
| 867-szukanie | Wyłączenie filtra wyszukiwania osób | Potwierdzona |
| 863-komunikat | Przywrócenie komunikatu utożsamiającego wybór z widoczną tablicą | Potwierdzona |
| 868-karta | Zastąpienie tytułu pytania opisem na publicznej karcie | Potwierdzona |
| 868-panel | Zastąpienie tytułu pytania opisem w panelu | Potwierdzona |

Surowe protokoły testów i mutacji znajdują się poza repozytorium,
w `C:\Users\matma\Documents\kuking-flota\gpt-tablica-evidence`.

## Przeglądarka — pomiar lokalny

Oddzielna instancja `http://127.0.0.1:18764`, kopia
`/home/mateusz/flota/gpt-tablica-ui`, osobna baza
`kuking_flota_gpt-tablica_ui` na tym samym porcie 55439. Fixture zawierała
45 autorów, starszy wybór poza czterdziestką i pytanie z samym tytułem.
Nie używano danych produkcyjnych. Logowanie obejmowało drugi składnik.

Samodzielnie wykonano w Chromium wybór osoby, wpisanie notatki, wyszukanie
autora spoza czterdziestki, wybór tego autora i pytania oraz zapis.
Po wyszukaniu i po zapisie wcześniejsze zaznaczenia i notatki pozostały.

- Szerokości 320, 360, 390, 414 i 1440 px: bez poziomego przewijania;
  tekst i pole 18 px, przycisk wyszukiwania 50,5 px wysokości.
- Rzeczywiste powiększenie przeglądarki 200%: osiem kombinacji szerokości
  okna 640/1440 px, skali tekstu serwisu 100/140% i jasnego/ciemnego motywu.
  Potwierdzono zoom 2 i viewport 320/720 px. Wszystkie kombinacje bez
  poziomego przewijania; pola 18/25,2 px, przyciski 50,5/59,5 px.
- Axe w głównej treści panelu przy 320 px: zero wykrytych naruszeń,
  15 sprawdzeń zaliczonych (reguły WCAG A/AA, w tym 2.2). To kontrola
  automatyczna tego ekranu, nie certyfikacja całego serwisu.

**Ograniczenie odtworzone także na bazie pracy:** dodatkowa próba samego
tekstu 200% przez zwiększenie bazowego rozmiaru fontu przy viewport 320 px
ujawniła logo w globalnym nagłówku wystające o około 19 px. Podmieniono
wyłącznie kontroler i widok panelu w kopii UI na wersje z `534e0a51`, uzyskano
ten sam wynik, następnie przywrócono pliki. Nie zmieniano globalnego
nagłówka w tym zadaniu. Ten przypadek nie jest tym samym pomiarem co
rzeczywisty zoom przeglądarki opisany wyżej.

## Granice wykonania

Nie testowano zalogowanej produkcji ani zdalnego CI. Nie wykonano push,
nie otwarto PR-a ani nowych zgłoszeń. Nie zmieniono schematu, D-081,
automatycznego sortowania tablicy ani repozytorium kanonicznego.
Nie przeprowadzono pełnego ręcznego audytu WCAG ani osobnego przebiegu
przeglądarki z wyłączonym JavaScriptem; ścieżki formularza są sprawdzone
przez żądania HTTP i nie wymagają skryptów.

Nie pozostała decyzja produktowa blokująca sześć objętych zgłoszeń.
Po pomiarach zatrzymano lokalny serwer i zamknięto przeglądarkę.
