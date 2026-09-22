# Składniki, porcje i podpowiedzi tagów — 20 września 2026

Stanowisko: `gpt-skladniki`, gałąź `gpt/skladniki`.
Początek: `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`, czyste drzewo.
Runtime: `/home/mateusz/flota/gpt-skladniki-run`, PostgreSQL
`127.0.0.1:55439`, użytkownik `kuking`, własna baza `kuking_flota_gpt-skladniki`.
Argument skryptów to `gpt-skladniki`, ponieważ wskazane w poleceniu
`skladniki` kopiowałoby inne stanowisko. Skrypty wspólne nie zostały zmienione.

## Zmiany

- #878: właściciel wybrał w tej sesji wyłącznie tekst autora, bez dopisku.
  Widok zachowuje tekst, uwagę i grupę. Flaga i CHECK pozostają bez zmian.
  Dziennik decyzji przy D-033 i opis bazy zapisują zmianę kontraktu #44.
- #741: komentarze rozróżniają działające wyszukiwanie od planu skalowania V2.
  Pomoc „Bez ilości” mówi, co wpisać, bez obietnicy przeliczania.
  Sprawdzono ROADMAP, FEATURES i D-033. Nie zbudowano skalowania.
- #754: potwierdzenie pojawia się po obsłudze `input`; opóźnione `select`
  dla niezmienionej pozycji kursora nie kasuje komunikatu i nie otwiera listy.
  `input` nadal trafia do innych odbiorców. Test DOM w Chromium
  **jest w CI od commita `c7b0f498`** — wcześniejszy zapis w tym miejscu
  („Test DOM w Chromium jest w CI") był **nieścisły**: test istniał
  w repozytorium jako `scripts/tagi-potwierdzenie.test.mjs`, ale nie wołał go
  ani `ci.yml`, ani skrypt `build` — nie uruchamiało go NIC.

## Pomiary własne

Na niezmienionym kodzie aplikacji:

- Istniejący `SkladnikBezIlosciTest`: 5 PASS, 16 asercji. Stary test wymagał
  właśnie dopisku „do smaku”.
- Nowy kontrakt rzeczywistego HTTP i zapisu PostgreSQL: czerwony test pokazał
  „pieprz — do smaku”, „mleko ile weźmie — do smaku”,
  „olej do smażenia — do smaku — na patelnię” i „szczypta soli — do smaku”.
  „sól do smaku” oraz składnik bez flagi stanowią kontrole dodatnie.
- Test pomocy Livewire: czerwony na obietnicy przelicznika.
- Test DOM z rzeczywistym modułem JS: oba warianty, kliknięcie i Enter,
  czerwone. Po opóźnionym `select` lista ponownie się otwierała.

Po poprawkach testy celowane przeszły. Test DOM sprawdza tekst pola,
`role=status`, zamknięcie listy, brak wysłania formularza, zachowanie `input`,
brak dodatkowego wyszukiwania oraz dalsze pisanie.

## Ograniczenia i wycofanie

Nie badano wypowiedzi czytnika ekranu ani zrozumienia tekstów przez osoby
50+. Test DOM używa lokalnej odpowiedzi wyszukiwarki, nie produkcyjnego API.
Nie wykonywano zmian produkcji, pushów, PR-ów ani wiadomości zewnętrznych.
Bez migracji i bez zmian historycznych przepisów. Wycofanie zmian kodu
nie wymaga rollbacku bazy; przywróciłoby też opisane błędy prezentacji.

Źródła zgłoszeń przeczytano przez `gh issue view` wraz z komentarzami.
Powyższe wyniki są pomiarami własnymi; cudzych wyników ze zgłoszeń nie
przedstawiono jako wykonanych w tej sesji.

## #750 — pomiar i decyzja nadal otwarta

Własny pomiar na niezmienionych regułach porcji: rzeczywisty zapis Livewire
na PostgreSQL, następnie render formularza edycji tego samego rekordu.
Chromium zbadał pola z rzeczywiście wyrenderowanych formularzy (bez serwera
HTTP i bez wykonywania klienta Livewire): w obu `value=1.25`, `step=0.5`,
`validity.stepMismatch=true`, `checkValidity()=false`.

| Wejście Livewire | Przejście do kroku 2 | Wartość w bazie |
|---|---|---|
| puste | tak | NULL |
| 0.5 | tak | 0.5 |
| 1 | tak | 1 |
| 1.25 | tak | 1.25 |
| 1.5 | tak | 1.5 |
| 0.49 | nie | brak przepisu |
| 999 | tak | 999 |
| 999.5 | nie | brak przepisu |
| 1.255 | tak | 1.26 |

Pomiar potwierdza rozbieżność i ciche zaokrąglenie przy trzech miejscach.
Nie mierzono jeszcze pełnej macierzy żądań zwykłego formularza ani
rzeczywistego kliknięcia zapisu w przeglądarce. Nie utrwalono obecnego
zaokrąglenia testem regresyjnym jako docelowego zachowania.

Do wyboru właściciela pozostają:

1. **Setne (rekomendacja):** krok 0.01 w obu formularzach, walidacja
   najwyżej dwóch miejsc po przecinku w obu drogach. Zachowuje istniejące
   1.25; odrzuca 1.255 z instrukcją poprawy, bez cichego zaokrąglania.
2. **Połówki:** krok 0.5 i walidacja wielokrotności 0.5 w obu drogach.
   Istniejące 1.25 pozostaje w bazie, ale kolejna edycja wymaga ręcznej
   zmiany liczby. To koszt dla autora, którego nie wolno ukryć migracją.

Pytanie wysłano w sesji; otrzymana odpowiedź dotyczy wyłącznie #878.
Dlatego kontrakt i kod porcji pozostają na razie bez zmian.

## Weryfikacja końcowa

- Pełny zestaw z wykluczeniem wyłącznie `ProbaOdtworzeniaTest`:
  **4384 PASS, 83 489 asercji, 349,60 s**. To wynik PHPUnit z pliku
  `output/skladniki/pelne-testy.txt`. Pierwszy, częściowy start przerwano,
  ponieważ wykluczenie nieistniejącej grupy nie wyłączało tej klasy.
  Powtórzony przebieg użył filtra `^(?!.*ProbaOdtworzeniaTest)`.
  Po pełnym podsumowaniu pomocniczy wrapper zgłosił błąd odczytu: jego plik
  został poprawiony podczas działania. Nie był to błąd PHPUnit, ale kodu
  wyjścia wrappera nie przedstawiamy jako sukcesu.
- Następnie dodano drugi test pomocy (zwykły formularz). Oba testy pomocy
  przeszły w kolejnych kontrolach ujemnych; cały zestaw nie był powtarzany
  po tej wyłącznie testowej zmianie.
- `vendor/bin/pint`: **PASS, 1156 plików**, bez zmian formatowania.
- `npm run build`: **PASS**, 20 testów JS i 72 pary kontrastu PASS.
- Chromium: **2/2 PASS** (kliknięcie i Enter).
- Cztery kontrole ujemne przez `scripts/kontrola-ujemna.sh`: **PASS → FAIL
  z właściwej przyczyny → PASS**, po każdej porównane MD5 i mtime.
  Dotyczą dopisku #878, obietnicy #741, skasowania statusu przez `input`
  oraz ponownego otwarcia listy przez opóźnione `select` (#754).
  Pierwsza próba #878 nie przeszła kontroli po przywróceniu: pozostał
  skompilowany widok Blade. Powtórzenie czyściło cache widoków przed
  każdym etapem i przeszło cały cykl. Pliki źródłowe wróciły bez zmian.

Dowody lokalne: `output/skladniki/mutacja-878.json`, `mutacja-741.json`,
`mutacja-754.json`, `mutacja-754-select.json` oraz `porcje-pomiar.json`.
Katalog `output` jest ignorowany przez Git; raport powyżej jest trwałym
zapisem wyników do przekazania kolejce.

## Przekazanie do kolejki

Commity implementacji (lokalne, bez push i PR):

- `d9a4b290` — #878 i #741: tekst autora oraz prawdziwe opisy.
- `2a7fab48` — #754: trwałe potwierdzenie i test DOM. **Ten commit NIE dodał
  kroku w CI** — wbrew temu, co pisał tu wcześniejszy akapit. `git diff
  origin/main...2a7fab48 -- .github/workflows/` daje zero dodanych linii,
  a zadanie `assets` nie zyskało żadnego kroku. Zapis „krok w CI" był
  **twierdzeniem o skutku, którego nikt nie sprawdził**.
- `c7b0f498` — dopięcie tamtej obietnicy: test przeniesiony do
  `scripts/przegladarka/tagi-potwierdzenie.test.mjs` i wołany nazwanym krokiem
  „Regresja potwierdzenia wyboru tagu (DOM w Chromium)" w zadaniu `assets`,
  zaraz PO kroku instalującym Chromium.

### Dlaczego krok w `ci.yml`, a nie lista `build` w `package.json`

Dróg do CI są dwie i obie są zamkniętymi listami nazwanych plików. Ten test
musiał pójść drugą, bo potrzebuje Chromium, a `npm run build` biegnie również:

- w `Dockerfile` (etap `assets`, obraz `node:22-bookworm-slim`) — obraz
  produkcyjny bez przeglądarki; ten etap kopiuje zresztą pojedyncze pliki
  z `scripts/`, a nie cały katalog;
- w zadaniu `assets` **przed** krokiem instalującym Chromium.

Na liście `build` test byłby więc czerwony w obu tych miejscach — z powodu
niezwiązanego z testowanym zachowaniem. Krok w `ci.yml` jedzie tą samą drogą,
którą już jedzie `scripts/port-grupy.test.mjs`.

Test leży w podkatalogu `scripts/przegladarka/`, żeby strażnik z gałęzi
`naprawa/testy-js-wchodza-do-ci` (reguła: „każdy `*.test.mjs` z `scripts/`
jest na liście `build`") nie zapalił na pliku, którego ta reguła nie może
objąć. **To wymaga decyzji autora strażnika**: właściwym domknięciem jest
osobna reguła „każdy plik z `scripts/przegladarka/` jest wołany nazwanym
krokiem w `ci.yml`", nie wyjęcie katalogu spod kontroli na stałe.

### Pomiar tego kroku (własny)

`node --test scripts/przegladarka/tagi-potwierdzenie.test.mjs` na runtime WSL,
po `npx playwright install chromium`:

- **2 testy, 2 PASS, 0 FAIL**, 2,05 s (warianty: kliknięcie i Enter).
- Kontrola dodatnia: po podmianie w `resources/js/tagi-w-opisie.js` komunikatu
  „Tag jest w opisie. Możesz pisać dalej." na `ZEPSUTE` — **2 FAIL, 0 PASS**,
  `AssertionError ... actual: 'ZEPSUTE'`. Test potrafi zapalić.

Workflow parsuje się jako YAML (`yaml.safe_load`); nowy krok znajduje się
dokładnie raz i stoi w zadaniu `assets` po instalacji Chromium — to zdanie
jest **teraz** prawdziwe; w poprzedniej wersji raportu opisywało krok,
którego nie było. Nie uruchamiano GitHub Actions.
#750 nie jest zgłoszone jako naprawione. Decyzja o setnych lub połówkach
pozostaje jedyną brakującą decyzją produktową w tym pakiecie.
