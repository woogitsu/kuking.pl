# #581 — brakujące stany rozwinięte i walidacyjne

## Aktualizacja po scaleniu #640 — 17 września 2026

Pakiet z sekcji 1 poniżej został wykonany w PR #640, scalonym jako
`13712d303eab63ff594341fe2a01479be3538538`. Szczegółowy dowód znajduje się
w `PANEL_DETAILS_581.md`: 24 konfiguracje rozwiniętych elementów, sześć
przypadków prawdziwego zoomu 200%, ogląd zrzutów oraz kontrole ujemne źródeł.
CI PR 35208128945 przeszło wszystkie 12 zadań; odczytany artefakt panelu
zawiera 642 przypadki bez porażek (w tym wcześniejsze macierze i menu).
Zadanie panelu na main w CI 35210542806 również zakończyło się sukcesem.
Jego artefakt 10493055242 potwierdza 288 pustych i 312 pełnych konfiguracji,
24 przypadki details, sześć zoomu details oraz osiem menu i cztery zoomu menu.
Raporty nie zawierają porażek; sam agregat obejmuje tylko pierwsze 600,
dlatego pozostałe 42 odczytano z osobnych plików JSON.

Main CI 35210542806, Railway 6500620974 i Deploy 35212601635: success.
Produkcja potwierdzona w publicznej przeglądarce: Alfa 0.52 / 13712d3,
CSS app-CVK-IJbC.css, JS app-DNnuLVJi.js. Nie jest to odbiór wszystkich
zalogowanych stanów panelu. Dowód:
https://github.com/woogitsu/kuking.pl/pull/640#issuecomment-5713166578.

Nie należy ponownie
implementować sekcji 1 ani uznawać poniższych stanów walidacji za objęte
tymi 642 przypadkami. Następny zakres to rzeczywiste błędne POST formularzy
decyzji, przywracania i odwołań w izolowanej lokalnej bazie, zachowanie
wpisanych wartości oraz brak zmian danych i przenoszenia błędów między
wierszami. Pozostałe rodziny z sekcji 3 pozostają w kolejce.

Poniższy pierwotny opis zachowuje kontekst i selektory; jego sekcja 1 nie jest
już listą niewykonanych prac.

Odczyt Blade, kontrolerów i kontraktu panel-marki.mjs. Bez przeglądarki, buildów, PHP i DB. Miernik obejmuje początkowo widoczne kontrolki, nie otwiera details i blokuje POST. Zatem 600 konfiguracji nie dowodzi poniższych stanów. Numery rekordów należy pobierać z istniejącej pełnej fixture, nie wpisywać cudzych identyfikatorów.

## 1. Najkrótszy pakiet bez zapisu — priorytet wysoki

| Trasa | Rzeczywisty selektor / stan | Bezpieczna sekwencja | Fixture |
|---|---|---|---|
| `/admin/kolaz-powitalny` | `details.confirm > summary.confirm-summary`; po otwarciu `.confirm-body .confirm-question` i `button[type=submit]` | Tab → Enter na „Wyczyść wybór zdjęć”; odczyt pytania, Tab na przycisk potwierdzenia, widoczny fokus, Shift+Tab i Enter na summary aby zamknąć. Nie naciskać potwierdzenia. | Obecna wystarczy, także pusta. |
| `/admin/kuking-na-dzis` | `details.confirm`, ten sam komponent | Tak samo dla czyszczenia wyboru tablicy; przeczytać całe odmienne pytanie. Nie wysyłać DELETE. | Obecna wystarczy. |
| `/admin/wiadomosci/{wiadomosc}` | `main details.mt-5 > summary`; po otwarciu `details[open] a[href^="mailto:"]` | Tab → Enter na „Poczta nie działa albo trzeba wysłać załącznik”; odczyt całego akapitu i Tab do mailto. Nie aktywować mailto ani nie otwierać programu pocztowego. Zamknąć summary. | Pełna z adresem wystarczy. |
| Ta sama wiadomość | Ostatni `form.panel-formularza` z `[name=handler_note]` i końcowym `button[type=submit]` „Zapisz” | Naturalny Tab do ostatniego przycisku, pełny obrys i hit-test względem fixed Wygląd oraz dolnego powrotu. Następnie naturalne przewinięcie do końca tekstu/pomocy i sprawdzenie dostępności całego ostatniego akapitu. Bez submit. | Obecna wystarczy. |

`confirm-button.blade.php` zawiera rzeczywisty formularz POST z metodą DELETE, nie okno JS. Samo otwarcie details niczego nie kasuje. Tag promotions „Zdejmij z promowanych” NIE jest details — nie dopisywać fikcyjnego stanu potwierdzenia do audytu (`tag-promotions.blade.php:78`).

Obejrzany wcześniej `zoom-wiadomosc-pelny-dark-main.png` pokazuje fixed Wygląd/powrót nakładające się na dół tekstu. To kadr początku main po przewinięciu do h1, nie dowód niemożności doczytania końca. Powyższy test końca dokumentu ma rozstrzygnąć realną dostępność; nie wydawać PASS na podstawie samego środka wysokiej karty. Minimum: 320px, tekst140%, oba motywy i prawdziwy zoom200%; dodatkowo desktop kontrolny.

## 2. Pola warunkowe i alternatywne kompozycje

- **Zgłoszenia** (`reports.blade.php:135–350`): nierozpatrzone mają formularz decyzji; rozpatrzone mają badge/decyzję i tylko gdy `$przywracalne[id]` jest prawdziwe — osobny formularz przywrócenia. GET `/admin/zgloszenia?status=resolved` musi zawierać lokalną schowaną treść i rozpatrzone zgłoszenie. Obecna fixture pojedynczego otwartego zgłoszenia nie dowodzi tego wariantu. Potrzebny odrębny kontrolowany rekord, bez ukrywania/odblokowywania rzeczywistych osób w trakcie oglądu.
- **Nie ma dynamicznego ukrywania terminu zawieszenia.** `reports.blade.php` jawnie wyjaśnia, że `[name=suspend_days_custom]` pozostaje widoczne przy wszystkich radiach `[name=action]` i `[name=suspend_days]`. To dodatkowy stan wyboru i walidacji, a nie brak odbioru ujawnianego pola. Sprawdzić strzałki, wpisanie własnej liczby oraz brak samoczynnego submit; nie wykonywać poprawnej decyzji moderacyjnej.
- **Odwołania** (`appeals.blade.php:148`): formularz z `[name=outcome]` i `[name=decision_note]` tylko dla rozpatrywanego odwołania i uprawnionej roli; zakończone `?status=upheld` / `?status=overturned` pokazują inną treść decyzji. Potrzebne lokalne zakończone rekordy, obecna otwarta fixture nie wystarcza.
- **Wiadomość** (`wiadomosc.blade.php:96–211`): z adresem ma `.panel-formularza`, textarea odpowiedzi i awaryjne details. Bez adresu ma `.sekcja-strony` oraz notice „Nie ma jak odpisać tej osobie” zamiast formularza. Historia `.wizard-row` ma trzy różne kompozycje: wysłana, nieudana (z komunikatem błędu), wynik nieustalony. Pełna fixture pokazuje tylko swoje przygotowane statusy; pozostałe wymagają jawnych lokalnych rekordów. Nie uzyskiwać ich przez realne wysyłanie poczty lub pozorowanie awarii dostawcy.
- **Konta**: lista filtrów/daty i dane profilu są stale widoczne; natywna ikona daty to osobny fragment przejścia Tab, nie aplikacyjne menu details. Odbiór dat i tabeli ma własne wcześniejsze błędy/regresje. Status konta i historia moderacji na `/admin/uzytkownicy/{user}` zmieniają treść, ale nie ujawniają dodatkowego formularza zarządzania rolą.

Na pozostałych admin Blade nie znaleziono własnego menu details ani x-show/x-if. Konto i Wygląd są wspólnymi komponentami layoutu z osobnym istniejącym odbiorem; nie należy przedstawiać ich jako nowego panelowego formularza.

## 3. Walidacja — priorytet po details i końcu wiadomości

Wszystkie poniższe stany wymagają realnej odpowiedzi walidacji serwera. Samo `required` przeglądarki nie jest dowodem `.error-summary`. Błędne POST tylko na odizolowanej lokalnej fixture, z prawdziwym CSRF, mailer array i kontrolą liczby danych przed/po; zapis poprawionej decyzji/wysyłka nie są potrzebne do odbioru kompozycji. Jeżeli natywna walidacja blokuje wysyłkę, jawnie odróżnić jej test od kontrolowanego HTTP testu serwera — nie ukrywać manipulacji harnessu.

| Trasa / formularz | Pole i minimalny stan błędu | Czego brak w obecnym pomiarze |
|---|---|---|
| `/admin/zgloszenia`, formularz decyzji | `[name=action]`, `reason_code`, `suspend_days`, `suspend_days_custom`; własny termin bez poprawnej liczby, brak wymaganej podstawy | Własne `.error-summary` z tekstami, błędy wyłącznie właściwego `_wiersz`, zachowanie starego wyboru. Potrzebne dwa zgłoszenia do dowodu izolacji wierszy. |
| Formularz przywrócenia zgłoszenia | `reason_code`, `user_message` | Alternatywny formularz i jego błędy; dodatkowy rekord opisany wyżej. |
| `/admin/odwolania` | `decision_note` krótsze niż10 znaków, `outcome` | `.error-summary`, błąd pola i zachowanie wybranego radia; dwa odwołania do izolacji `_wiersz`. |
| `/admin/sygnaly` | `note` 2001 znaków | Błąd lokalnej notatki i zachowanie właściwej grupy autora; bez wykonywania poprawnego odrzucenia sygnałów. |
| `/admin/bez-odpowiedzi?typ=wpisy` | `body` puste lub4001 znaków | Podsumowanie i błąd odpowiedzi właściwego `_wiersz`; bez publikacji komentarza. Zakładki przepisy/ugotowane nie mają tego formularza. |
| `/admin/wiadomosci/{id}`, odpowiedź | `odpowiedz` puste lub5001 znaków | `.error-summary`, zachowanie tekstu, brak utworzonej/wysłanej odpowiedzi. |
| Ta sama wiadomość, stan/notatka | `handler_note` 2001 znaków | Błąd notatki i zachowanie wyboru `status`; nie testować zmianą stanu prawidłowego rekordu. |
| `/admin/tagi-promowane` dodawanie | `nazwa_tagu` nieistniejący tag | Realny komunikat bez tworzenia tagu. Obecna baza wystarczy. |
| Aktualizacja promowanego tagu | `note` 201 znaków | Błąd istniejącego wiersza. Obecna pełna fixture wystarczy. |
| `/admin/kolaz-powitalny` | 5 poprawnych wybranych UUID zdjęć, limit4 | Podsumowanie po przekroczeniu limitu; obecne4zdjęcia nie wystarczają do naturalnego wyboru5. Potrzebny dodatkowy lokalny kandydat. |
| `/admin/kuking-na-dzis` | 7 kandydatów jednego typu lub notatka301 znaków | Podsumowanie. Przy maxlength300 potrzebny jawny kontrolowany payload do sprawdzenia serwera; naturalne7wyborów wymagają rozszerzenia fixture. |

Po uzyskaniu rzeczywistego błędu: odczyt całego podsumowania, Tab po linkach jeśli istnieją, fokus błędnego pola, korekta bez poprawnego submit i porównanie braku przelania do innych wierszy. Puste/full GET nie zastępują tych dowodów. Niniejszy dokument jest kolejką odbioru, nie deklaracją wykonania wymienionych akcji.
