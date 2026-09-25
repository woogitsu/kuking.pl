# Pytania, powiadomienia i korespondencja — #834, #860, #847

Stanowisko: `gpt/pytania-widoki`, baza kodu `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`.
Pomiar własny: 20 września 2026. Wszystkie operacje na danych wyłącznie
w `kuking_flota_gpt-pytania-widoki`, PostgreSQL `127.0.0.1:55439`, rola `kuking`.
Nazwa argumentu skryptów to **gpt-pytania-widoki**: skrypt składa z niej ścieżkę
stanowiska; krótsze `pytania-widoki` z przekazanej instrukcji nie wskazuje tego katalogu.

## #834 — aktualny tytuł pytania w powiadomieniu

Lista pokazuje dodatkowy wiersz `Pytanie: „…”` przy odpowiedzi głównej oraz
odpowiedzi w rozmowie. Zachowuje fragment odpowiedzi i dotychczasowe przyciski.
Dwa jednakowe fragmenty odpowiedzi pod różnymi pytaniami mają rozróżnialny kontekst.

Przyjęty kontrakt implementacji: **tytuł aktualny**, odczytywany z pytania przy
wyświetleniu, bez utrwalania kolejnej kopii w JSON. Edycja zmienia kontekst również
starszych powiadomień. Powiązanie korzysta z istniejącego `comment_id`; nie wymaga
migracji ani ponownego tworzenia powiadomień. Dwa odczyty zbiorcze dla strony
zastępują zapytania na każdy wiersz. Liczba SQL dla strony z 2 i 20 pytaniami
jest taka sama (wykonany test, nie wniosek tylko z kodu).

Istniejący filtr dostępności wybiera powiadomienia przed wzbogaceniem; odczyt
kontekstu dodatkowo respektuje widoczność komentarza i pytania, blokady oraz
flagę funkcji. Nie tworzy nowego endpointu. Usunięte lub niedostępne pytanie nie
ujawnia tytułu. Rekord bez `comment_id` nadal jest pomijany przez istniejący filtr
powiadomień — pierwsza wersja próby błędnie oczekiwała jego widoczności; poprawiono
oczekiwanie po wykonanym pomiarze, bez osłabiania filtra. Zwykłe zdarzenia bez
kontekstu zachowują dotychczasowy tekst.

**`app/Models/Notification.php` nie został zmieniony.** Wzbogacanie jest w osobnej
klasie domenowej, wywołanej przez kontroler. Ochrona HTML pozostaje w Blade.

## #860 — Pomoc rozdziela tworzenie od edycji

Pomiar na niezmienionym kodzie potwierdził:

- Formularz nowego pytania uprzedza o publiczności i nie daje wyboru odbiorców.
- POST z `visibility=private` zapisuje pytanie **publiczne** (sprawdzono bazę).
- PUT właściciela zmienia widoczność na `private` albo `followers`.
- Po zapisie właściciel ma 200, gość i osoba nieobserwująca 403;
  obserwujący ma 200 tylko przy `followers`.
- Zwykły formularz wpisu nadal pokazuje wszystkie trzy warianty.

Błąd leżał w zbyt szerokim tekście Pomocy. Nowy akapit mówi o publicznym pytaniu
razem z opisem i zdjęciem, późniejszej edycji oraz zwykłym wpisie dla ograniczonego
grona od początku. Jest widoczny przy włączonym dziale. Nie zmieniono zasad
publikacji ani edycji.

Przypadki do przyszłego pilota #813: „Czy pytanie zobaczą tylko znajomi?”,
„Chcę zapytać prywatnie”, „Kto zobaczy zdjęcie przy pytaniu?”, „Jak ukryć już
opublikowane pytanie?”. Pierwsze trzy wymagają rozróżnienia publicznego tworzenia
od zwykłego wpisu; ostatni prowadzi do edycji. Nie uruchamiano modelu AI.

## #847 — wynik pomiaru i decyzja właściciela

To korespondencja z operatorem, nie odpowiedzi w Poradźcie. Bez zmiany zachowania.
Jednorazowy próbnik (`ContactRetentionProbeTest.php`, usunięty przy scaleniu
z main — nie był uruchamiany w CI, zależał od bazy stanowiska floty) zapisywał
obserwacje, nie dodawał asercji ustanawiającej regułę retencji. Sprawdzał
izolację bazy i wykonanie żądań. Poczta jest atrapą `Mail::fake()`; nie wysłano
żadnej wiadomości do ludzi ani usług.

Zegar: 20.09.2026 12:00 UTC. Zamknięcie: 20.09.2025 13:00 UTC. Nowa odpowiedź:
20.09.2026 12:00 UTC. Sprzątanie: 21.09.2026 12:00 UTC, karencja 12 miesięcy.

| Scenariusz | Po odpowiedzi | Następnego dnia |
|---|---|---|
| Stara zamknięta sprawa | `done`, poprzednie `handled_at` | Usunięta sprawa i świeża odpowiedź |
| Świadomie otwarta ponownie | `in_progress`, nowe `handled_at` | Sprawa i odpowiedź zostały |
| Otwarta i ponownie zamknięta | nowe zamknięcie | Sprawa i odpowiedź zostały |

Przed przesunięciem zegara: 0 kandydatów. Następnego dnia tryb na sucho:
1 kandydat, nadal 3 sprawy i 3 odpowiedzi. Wykonanie: 1 usunięta sprawa,
kaskada usunęła jej odpowiedź. Formularz odpowiedzi był dostępny również przy
zamkniętej sprawie. Odczyt kodu: ekran nie podaje przy nim terminu usunięcia.
Poprawiono jedynie przestarzały komentarz mówiący, że nic nie wskazuje na
`contact_messages`; opisuje teraz istniejącą kaskadę.

**Warianty dla właściciela — żaden nie został wdrożony:**

| Wariant | Koszt wdrożenia i skutki |
|---|---|
| Pokazać termin/usuwanie wraz ze sprawą i drogę ponownego otwarcia — rekomendowany | Mały/średni: wspólne wyliczanie terminu, tekst i test ekranu. Zachowuje możliwość dopisania końcowego wyjaśnienia bez automatycznej zmiany retencji. Operator musi świadomie otworzyć sprawę, jeśli obsługa wraca. |
| Zabronić odpowiedzi do czasu otwarcia | Średni: zgodna blokada w Policy, akcji, formularzu i testach. Czytelny kontrakt, dodatkowy krok przy każdym późnym dopisku. |
| Pozostawić zachowanie i tylko dopisać wyjaśnienie | Mały: tekst i test. Nie zmienia minimalizacji danych, ale świeży ślad nadal może zniknąć następnego dnia. |
| Automatycznie przesuwać retencję od odpowiedzi | Większy: nowa decyzja o okresie, dokumentacja prywatności, granice nieudanych wysyłek i testy. Każdy dopisek wydłuża przechowywanie całej sprawy; nie wdrażać jako przypadkowej poprawki UI. |

## Dowody i ograniczenia

- Nie zmieniono aplikacji przed pierwszym przebiegiem: 74 istniejące testy pytań,
  461 asercji, zielone.
- Nowe próby przed poprawką: 4 czerwone dla kontekstu powiadomień; dla Pomocy
  1 czerwony test tekstu i 1 zielony rzeczywistego zapisu widoczności.
- Po poprawce: 4 testy kontekstu (46 asercji) oraz 2 Pomocy (32 asercje), zielone.
- Zbudowano assety przez `npm run build`.
- Chromium: rzeczywisty HTML wyrenderowany żądaniem testowym i aktualny CSS,
  zapisany jako statyczna strona w runtime. 320 px: `scrollWidth=320`, tytuły
  mają 18 px, szerokość i `scrollWidth` tytułu po 194 px. Tekst przypominający
  HTML jest literalny, długi wyraz się zawija. Przy 640 px i **CSS zoom 200%**
  `scrollWidth=640`. Obejrzano oba zrzuty. To nie jest pomiar natywnego zoomu
  przeglądarki ani pełne E2E logowania i klikania. Statyczny render miał jeden
  błąd pobrania ikony z bazowego localhost:8000; CSS i treść były załadowane.
- Zrzuty lokalne: `output/playwright/powiadomienia-320.png` oraz
  `output/playwright/powiadomienia-zoom-css-200.png` (katalog wyłączony z Gita).
- Brak badania z osobami mniej pewnymi obsługi: kryterium użyteczności #834
  nie jest zaliczone samym testem HTML. Brak oglądu produkcji i wdrożenia.
- Bez zmian schematu. Wycofanie kodu: odwrócić lokalny commit; brak danych do
  migracji i brak zmian retencji do cofania.
- Bez pushowania, PR i zamykania zgłoszeń, zgodnie z zasadami floty.

## Odtworzenie

Z PowerShell: ustawić `MSYS_NO_PATHCONV=1`, następnie wywołać przez `wsl -d Ubuntu -- bash`
`_wspolne/przygotuj-runtime.sh gpt-pytania-widoki`, a później
`_wspolne/testuj.sh gpt-pytania-widoki --filter Question`.
Próbniki #847 i renderowania (`*ProbeTest.php`) usunięto przy scaleniu z main:
nie należały do zestawu CI, wpisywały na sztywno bazę stanowiska floty, a render
zapisywał plik do `public/`. Zachowanie tytułu pytania pilnuje
`tests/Feature/QuestionNotificationContextTest.php`; wyniki pomiaru #847 zostają
w tym raporcie.

## Końcowa kontrola lokalna

Szeroki przebieg: **4389 testów, 83 565 asercji, 352,38 s — PASS**.
Pominięto `ProbaOdtworzeniaTest` zgodnie z jawnym wyjątkiem instrukcji floty
(wspólna baza próby odtworzenia). Grupa `dwa-polaczenia` pozostała wyłączona
zgodnie z domyślną konfiguracją projektu; zmiana nie dotyczy współbieżności.
Przebieg wykonano przed końcowym formatowaniem oraz korektą komentarza #847;
po formatowaniu wykonano ponownie testy regresyjne obu poprawek.

`vendor/bin/pint` uruchomiono dla wszystkich 7 zmienionych/dodanych plików PHP.
Kontrole przez `scripts/kontrola-ujemna.sh`: **PASS → FAIL → PASS** dla #834
(odcięcie tytułów) i #860 (powrót zbyt szerokiej obietnicy). Obie potwierdziły
rzeczywistą mutację oraz przywrócenie MD5 i mtime; pliki JSON są obok raportu.
Pierwszy przebieg #860 wykrył czerwień, ale po przywróceniu korzystał jeszcze
ze skompilowanego widoku mutacji. Powtórzenie z `artisan view:clear` przed każdym
etapem zakończyło pełną kontrolę. Nie zaliczono niepełnego pierwszego przebiegu.

Po końcowym formatowaniu: **80 testów pytań, 539 asercji — PASS**;
Pint w trybie kontroli: **7 plików — PASS**. `git diff --check` bez uwag.
Uwaga do JSON przyrządu: pole `przywrocenie` zapisuje on przed końcowym `trap`
i zostawia wartość „nie wykonane”. Konsola obu zakończonych przebiegów po tym
zapisie potwierdziła porównanie MD5 i mtime; końcowe testy oraz porównanie plików
runtime ze stanowiskiem potwierdziły przywrócone źródło. Nie poprawiano ręcznie
wygenerowanego dowodu.
