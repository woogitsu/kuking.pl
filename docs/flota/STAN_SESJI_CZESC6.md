# Stan sesji, część 6 — panel moderacji i liczniki przepisu

Ten dokument jest wiernym zapisem treści dwóch raportów źródłowych. Liczby,
SHA i sformułowania przepisano dokładnie z plików źródłowych, bez interpretacji
za autora. Poniżej wskazano też, których miejsc/zakresów raporty NIE sprawdziły
lub czego świadomie nie zrobiono.

Źródła:
1. `C:\Users\matma\Documents\kuking-flota\gpt-panel-moderacji-marka\docs\design\PANEL_ODBIOR_FLOTY_581_2026_09_20.md`
2. `C:\Users\matma\Documents\kuking-flota\gpt-przepis-liczniki\docs\design\WERYFIKACJA_LICZNIKOW_I_LINKOW_666_667_2026_09_20.md`

Oba pliki istniały i zostały odczytane w całości.

---

## Raport 1: Panel moderacji #581 — ponowny odbiór floty

Stan źródeł: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź
`gpt/panel-moderacji-marka`, 20 września 2026.

### Dlaczego nie powstaje drugi port

Odczyt aktualnego issue #581 i kodu wykazał, że port kompozycji jest już
wdrożony w tej podstawie. Obecny zakres issue to odbiór pozostałych stanów.
Nowa rama jest w `resources/css/marka-panel.css`, importowanym przez
`app.css`; layout nadaje `data-marka-panel` i opakowuje treść klasą
`marka-panel-tresc`. Nawigacja używa `panel-menu-przelacznik` oraz
`panel-menu-tresc`; bez JavaScriptu lista pozostaje rozwinięta.

[pomiar cudzy: aktualny opis https://github.com/woogitsu/kuking.pl/issues/581]
PR #587 przeniósł kompozycję, #637 dostarczył zwijane menu. Podane tam
wyniki CI i produkcji nie są pomiarami wykonanymi w tej sesji.

**Decyzje D-218 i D-220 nadal obowiązują. Nie zmieniono Policy, ról, tras,
kontrolerów, widoczności danych, schematu ani znaczenia decyzji moderacyjnych.**

→ Granica „port kompozycji, bez zmiany uprawnień, Policy ani zakresu tego, co
moderator widzi" — **dotrzymana zgodnie z tekstem raportu**: raport wprost
stwierdza brak zmian Policy, ról, tras, kontrolerów, widoczności danych,
schematu i znaczenia decyzji moderacyjnych.

### Środowisko i metoda własnego pomiaru

- Worktree: `C:\Users\matma\Documents\kuking-flota\gpt-panel-moderacji-marka`.
- Runtime przeglądarki: `/home/mateusz/flota/gpt-panel-moderacji-marka-run`.
- Pełne testy PHP: `/home/mateusz/flota/gpt-panel-moderacji-marka-php-run`,
  osobna kopia mediów i zależności, aby testy nie niszczyły fixture przeglądarki.
- PostgreSQL: **127.0.0.1:55439**, właściciel `kuking`.
- Testy PHP: baza `kuking_flota_gpt-panel-moderacji-marka`.
- Przeglądarka: nowa baza
  `kuking_flota_gpt_panel_moderacji_marka_browser_20260920`, strefa UTC.
- Własne kopie zależności, osobny APP_KEY, poczta `array`, lokalne media.
- Rzeczywiste logowanie fixture i kod TOTP; sesje oraz poświadczenia poza repo.
- Macierz używa istniejących mierników projektu, bez zmian ich asercji.
  Wyłącznie w runtime pięć plików oprzyrządowania otrzymało lokalną nazwę
  bazy zamiast historycznych nazw z listy dopuszczonych. Lokalny runner
  wykonał też etap walidacji normalnie uruchamiany tylko w CI. Port,
  kontrola środowiska i mailer pozostały wymagane. To adapter wykonawczy,
  nie zmiana aplikacji ani dowód wykonania GitHub Actions.

### Strażniki: zakres i granice

| Strażnik | Co obejmuje | Czego nie dowodzi |
|---|---|---|
| `PanelUzywaTypografiiMarkiTest` | Wprowadzenia Sygnałów i Tagów mają `text-lead`, a arkusze zawierają token | Wyliczonego fontu całego panelu |
| `PanelModeracjiMaCalaSzerokoscTest`, `PanelSzerokiTelefonTest` | Reguły ramy, brak prawej szyny, lokalne przewijanie tabeli, atrybuty HTML | Wyniku wszystkich nadpisań nowego arkusza |
| `OdstepMiedzyDrogamiWejsciaTest` | Skan wszystkich `resources/css/*.css`, w tym panelu; jawny, wąski wyjątek jego rytmu; kontrola liczby plików | Pełnej kaskady i geometrii przeglądarki |
| `MinimalnyRozmiarTekstuTest` | Wybrane selektory i tokeny w `app.css` i `tokens.css` | Ogólnego skanu wszystkich reguł `marka-panel.css` |
| `GlosMarkiOpisujeArkuszPrawdziwieTest` | Kontrakt dokumentów i reguł zapisu nazwy w `app.css` | Portu panelu; jego parser nie jest silnikiem kaskady |
| `panel-marki.mjs` | Puste/pełne ekrany, sześć szerokości, motywy, skala, dane, geometria, Tab i radio | Wszystkich błędnych POST i wszystkich zamkniętych sekcji |
| `panel-details.mjs`, `panel-details-zoom.mjs` | Otwarte potwierdzenia i pomoc wiadomości, obrys, cały tekst, prawdziwy zoom | Poprawnego zapisu decyzji i wysyłki wiadomości |
| `panel-validation.mjs` | Dziewięć błędnych POST, stare wartości, izolacja wierszy, niezmienność siedmiu tabel | Natywnej walidacji przeglądarki i kompletnego odbioru wszystkich formularzy |

Nie zmieniano `app.css`, więc nie korygowano liczby reguł pod parser
`GlosMarkiOpisujeArkuszPrawdziwieTest`. Test przeszedł na podstawie zadania.

### Wyniki

Wszystkie niżej opisane pomiary wykonano samodzielnie na kodzie podstawy,
przed zmianą aplikacji. Porównanie SHA-256 potwierdziło zgodność **597 plików**
`app`, `resources`, `routes` i `config` między worktree a runtime przeglądarki.
Nie powstała poprawka błędu, nowy strażnik ani nowa asercja produktowa.

#### Kompozycja i wymiary

**600/600 konfiguracji**: 288 pustych i 312 pełnych; szerokości
320/360/390/414/768/1440 px, dwa motywy, tekst 100/140%.
Zestaw obejmuje też ekran wymagający 2FA i widoki szczegółowe przewidziane
przez fixture. Klawiatura, radio, widoczność fokusu, minimalne cele
48 × 48 px i brak przepełnienia strony przeszły istniejący miernik.

Przykład zmierzony na pełnych Zgłoszeniach, jasny motyw:

| Szerokość | Skala tekstu | Tekst bazowy | Nawigacja | Treść | Odstęp |
|---|---|---|---|---|---|
| 320 px | 100% | 18 px | 296 px | 296 px | 24 px, układ pionowy |
| 320 px | 140% | 25,2 px | 296 px | 296 px | 24 px, układ pionowy |
| 1440 px | 100% | 18 px | 256 px | 1112 px | 24 px, dwie kolumny |
| 1440 px | 140% | 25,2 px | 358,39 px | 1009,61 px | 24 px, dwie kolumny |

→ Zmierzone konkretne wartości (jako dowód minimów UX 50+): tekst bazowy
18 px przy skali 100% (spełnia próg ≥ 18 px); minimalne cele dotyku
48 × 48 px przeszły istniejący miernik (spełnia próg ≥ 48 px). Rozmiary
przycisków w sekcji „Bez JavaScriptu" (patrz niżej) zmierzono osobno.

Nawigacja ma promień 26 px i powierzchnię `rgb(255, 255, 255)` w jasnym
motywie; pomiar sprawdza zgodność z tokenem także w ciemnym. Główna treść
zajmuje pozostałą szerokość, bez pustej prawej szyny. Brak odtworzonego
rozjazdu wymagającego nowego portu. Mobilną nawigację miernik otwiera przed
pomiarem wszystkich odsyłaczy — dlatego na części zrzutów jest rozwinięta.

#### Bez JavaScriptu

Dodatkowy odczyt **16/16 konfiguracji**: 320 px, oba motywy i obie skale,
kolaż, tablica na dziś, zgłoszenia i odwołania. Kontekst przeglądarki miał
wyłączone skrypty strony. Aparatura ustawiła tylko motyw i skalę oraz
odczytała geometrię. Żądania inne niż GET/HEAD były blokowane; licznik
prób mutacji wyniósł zero.

Potwierdzenia czyszczenia używają natywnego `details.confirm`: właściwy
przycisk jest ukryty przed rozwinięciem i dostępny po nim, także klawiaturą.
Formularze są serwerowe i mają CSRF. Nie wykonano czyszczenia ani skutecznej
decyzji. Pola miały minimum 18 px tekstu; przyciski w tej próbie minimum
50,5 px wysokości przy skali 100% oraz 59,5 px przy 140%. Cała strona
pozostawała szeroka na 320 px.

→ Zastrzeżenie autora wprost w tekście: „Sprawdzenie to nie dowodzi poprawnego
zapisu każdej operacji — ten zakres należy do testów domenowych i HTTP."

→ To jest dowód na spełnienie minimum UX 50+ „praca bez JavaScriptu przy
akcjach nieodwracalnych": potwierdzenia czyszczenia działają przez natywne
`details.confirm` bez JS, formularze są serwerowe z CSRF, a próba mutacji
przy wyłączonych skryptach wyniosła zero (żądania inne niż GET/HEAD były
blokowane).

#### Testy i pozostałe stany

- 24/24 rozwinięte sekcje: potwierdzenia kolażu i tablicy oraz pomoc pocztowa.
- 6/6 tych sekcji przy rzeczywistym zoomie 200%, skali tekstu 140%, CSS 320 px,
  DPR 2; 4/4 dodatkowe konfiguracje menu przy zoomie 200%.
- 8/8 dodatkowych stanów menu, w tym zachowanie bez skryptów.
- 9/9 błędnych POST: poprawne wartości i wybory zostają w aktywnym wierszu,
  błędy są przypisane do pól, linki podsumowania przenoszą fokus do nich,
  drugi wiersz nie przejmuje wpisanych danych. Siedem tabel domenowych
  pozostało niezmienionych. To walidacja serwera, nie test natywnego `required`.
- Łącznie 651 przypadków istniejącego zestawu przeglądarkowego oraz
  16 dodatkowych pomiarów bez skryptów, wszystkie zaliczone.
- Początkowy wybór testów panelu, moderacji, 2FA i strażników marki:
  **139 testów, 778 asercji**, 18,66 s.
- Szeroki zestaw PHP: **4393 testy, 83 692 asercje**, 466,41 s, bez porażek.
  Wyłączono `ProbaOdtworzeniaTest` zgodnie z podanym wyjątkiem wspólnej bazy.
  Nie uruchamiano grupy `dwa-polaczenia`, wyłączonej również w domyślnej
  konfiguracji projektu; ten odbiór nie zmienia operacji współbieżnych.
- `vendor/bin/pint --test` oraz końcowe `vendor/bin/pint`: **1155 plików**, bez uwag.
- `npm run build`: zaliczone, w tym 72 pary kontrastów i kontrole Node;
  wynikowe zasoby `app-C9S9kWiN.css` i `app-B4FFWDpj.js`.

Nie uruchamiano opcjonalnych mutacji `--negative-menu` ani
`--negative-details`: nie zmieniano aplikacji ani tych testów. Nie ogłasza
się więc świeżego wyniku ich kontroli ujemnych. Zasada czerwonego testu
przed poprawką nie została zastąpiona samym zielonym wynikiem — w tej sesji
nie ma poprawki, którą należałoby w ten sposób udowodnić.

#### Dowody

- Zapis pomiarów i sumy źródłowych raportów: `evidence/flota581-20260920/pomiary.json`.
- Pomiar bez skryptów: `evidence/flota581-20260920/bez-javascriptu.json`.
- Walidacja serwerowa: `evidence/flota581-20260920/walidacja.json`.
- Porównanie źródeł: `evidence/flota581-20260920/zgodnosc-zrodel.json`
  i sumy adaptera wykonawczego: `evidence/flota581-20260920/adapter.json`.
- Obejrzane obrazy: desktop (`evidence/flota581-20260920/zgloszenia-desktop.png`),
  potwierdzenie bez skryptów (`evidence/flota581-20260920/potwierdzenie-bez-skryptow.png`),
  formularz odwołania bez skryptów (`evidence/flota581-20260920/odwolanie-bez-skryptow.png`).
  Dwa ostatnie pokazują przewinięty fragment formularza, nie cały ekran.

Pełne lokalne wyniki i pomocnicze skrypty pomiaru pozostały odpowiednio
w `/home/mateusz/flota/gpt-panel-moderacji-marka-evidence` oraz ignorowanym
`output/panel581-20260920`. Do repo nie kopiowano sesji, tokenów ani fixture
z poświadczeniami. Pliki JSON w dowodach są skrótem wyników, nie nowym testem.

### Granice odbioru

Nie wykonano pushowania, PR-a, CI, wdrożenia ani zmian produkcyjnych.
Zalogowane stany produkcji nie są objęte własnym pomiarem. Nie wysyłano
wiadomości do ludzi ani instytucji. Lokalna walidacja nie zastępuje
produkcyjnego oglądu brakujących rzeczywistych spraw z opisu #581.

Nie pojawiło się nowe pytanie produktowe wymagające decyzji właściciela.
Do zamknięcia pozostałego zakresu #581 nadal potrzebny jest odbiór wskazanych
stanów na produkcji, gdy będą dostępne rzeczywiste sprawy. Ten raport go nie
zastępuje i nie oznacza zgłoszenia jako zamkniętego.

---

## Raport 2: Liczniki i cele odnośników — weryfikacja #666 i #667

Data: 20 września 2026. Stan wejściowy: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
czyste drzewo gałęzi `gpt/przepis-liczniki`. Praca lokalna, bez publikacji.

### Wynik rozpoznania

Obie pierwotne poprawki **są już w badanym drzewie**:

- `bdc56b8cf9b664eda104b628d85149b08d84d8d9` — jednostki wykonań i odpowiedzi (#671);
- `71549ebad6eab2d41c71073c2f93aed85419e69d` — nazwy i cele odnośników (#672).

Samodzielnie sprawdzono obecność obu commitów w historii, odczytano bieżące
zgłoszenia przez `gh issue view` i uruchomiono testy na źródłach wejściowych.
Otwarte issue nie oznacza tu brakującej implementacji.

[pomiar cudzy: komentarze właściciela w #666 i #667 oraz
LICZNIKI_WYKONAN_666.md i CELE_LINKOW_667.md] Wcześniejsze wdrożenia i odbiory
produkcyjne opisują działające poprawki. Pozostały reprezentatywne rzeczywiste
dane niezerowych liczników oraz zalogowany odbiór pustych zeszytów. W tej sesji
nie sprawdzano produkcji ani nie tworzono na niej danych.

### Co naprawdę jest liczone

**Kluczowe ustalenie #666: kod liczy ZDARZENIA (kolejne gotowania), nie unikalne
osoby** — z wyjątkiem miejsc opisanych niżej jako osobne przypadki (odpowiedzi
„would_make_again" liczą zdarzenia, nie unikalne osoby; eksport JSON liczy
wszystkie zdarzenia).

Poniższa tabela to pełna lista miejsc sprawdzonych w tej sesji, dokładnie
w formie z raportu źródłowego:

| Miejsce | Źródło liczby i podpis | Weryfikacja w tej sesji |
|---|---|---|
| Strona przepisu, znaczek i galeria | `paginate()->total()` po `widoczneDla(widz)`; „Ugotowane N ×” oraz odmienione „N wykonań” | Render Laravel, wielokrotne wykonania tej samej osoby, 0/1/2/5/12/22, paginacja i blokady |
| Odpowiedzi przy przepisie | Zdarzenia z niepustym `would_make_again`, osobno odpowiedzi `true`; nie unikalne osoby | 4 zdarzenia 2 osób, sprzeczne odpowiedzi: 2 z 4, 50%; brak odpowiedzi nie podnosi progu 3 |
| Dane strukturalne przepisu | `userInteractionCount` z tej samej liczby co galeria | Test renderu i prywatności |
| Karta wyniku wyszukiwania | `withCount(cookedEvents => widoczneDla(widz))`, „Ugotowane N ×” | Test zgodności z widokiem przepisu i filtrami widza |
| Karty na profilu i w zeszycie | Bez załadowanego `cooked_events_count` znaczek jest pomijany, nie pokazuje zmyślonego zera | Odczyt kontrolerów i `recipe-card.blade.php` |
| Licznik profilu | Zdarzenia właściciela z widocznych dla widza przepisów, „N razy „Ugotowałem”” | Testy zgodności z listą i odmiany podpisów |
| Powiadomienie | Pojedyncze zdarzenie, „imię — ugotowane z Twojego przepisu”; brak zbiorczego licznika osób | Odczyt widoku i test dwóch wykonań tej samej osoby → dwa powiadomienia |
| Indeks HTML eksportu | Liczba własnych zapisanych zdarzeń z `ugotowalem`, „N zapisanych wykonań” | Odczyt generatora i widoku; wykonana rodzina `DataExportTest` |
| Przepis w JSON eksportu | Wszystkie zdarzenia pod przepisem, bez filtrów galerii; dotąd błędny klucz `ile_razy_ugotowany_przez_innych` | Osobne odtworzenie i regresja opisane niżej |

→ Zgodnie z raportem, WSZYSTKIE dziewięć powyższych miejsc zostało sprawdzonych
w tej sesji (kolumna „Weryfikacja w tej sesji" jest wypełniona dla każdego
wiersza). Raport nie wymienia żadnych innych miejsc pokazywania licznika
wykonań poza tymi dziewięcioma — nie ma w tekście wzmianki o sprawdzeniu ani
pominięciu innych ekranów poza wymienionymi (strona przepisu, dane
strukturalne, karta wyszukiwania, karty profil/zeszyt, licznik profilu,
powiadomienie, indeks HTML eksportu, JSON eksportu).

Raport wprost zaznacza różnicę zakresów: „Profil, galeria i eksport mają różne
zakresy danych. Ich liczby nie muszą być równe; podpis musi jasno nazywać
zakres i jednostkę. Eksport własnych treści obejmuje także treści niewidoczne
w publicznych listach." — to jest stwierdzenie autora, że różne liczby w różnych
miejscach są zamierzone (różne zakresy), a nie niespójnością/pogorszeniem.
Raport NIE opisuje żadnego przypadku, w którym dwa miejsca pokazujące TEN SAM
zakres danych (np. dwa ekrany dla tej samej galerii) podawałyby sprzeczne
liczby po poprawce — jedyna naprawiona niespójność to błędna nazwa klucza
w eksporcie JSON (opisana niżej), a nie rozbieżność liczbowa między ekranami.

### Warianty produktu i koszt

1. **Zdarzenia („12 wykonań”).** Pokazują powroty do przepisu. Strona już
   potrzebuje tej liczby do paginacji; odczyt `total()` nie dodaje zapytania.
   Każda odpowiedź przy kolejnym gotowaniu zachowuje własne znaczenie.
2. **Osoby („8 osób”).** Pokazują zasięg wśród różnych kucharzy.
   `count(distinct user_id)` wymaga deduplikacji przy tych samych filtrach
   dostępu. Nie zastępuje liczby zdarzeń potrzebnej galerii. Najprostsza
   implementacja dodaje agregację; trzeba również wybrać regułę dla
   sprzecznych odpowiedzi jednej osoby.
3. **Obie liczby.** Więcej informacji, ale dłuższy podpis i dodatkowa agregacja
   osób. Bez decyzji właściciela nie dokładamy drugiej statystyki do interfejsu.

**Zakres #666 już rozstrzyga zachowanie zdarzeń i odpowiedzi; potwierdza to
komentarz właściciela w issue. Nie zmieniamy tej decyzji na `DISTINCT`.**

→ Decyzja „zdarzenia" vs „osoby" dla głównego licznika #666: raport stwierdza,
że jest to rozstrzygnięte przez **komentarz właściciela w issue #666**
(„potwierdza to komentarz właściciela w issue"), NIE decyzja podjęta samodzielnie
przez stanowisko w tej sesji. Stanowisko w tej sesji jedynie zweryfikowało
istniejącą implementację i nie zmieniło jej na `DISTINCT`.

### Samodzielny pomiar PostgreSQL

Własna baza `kuking_flota_gpt-przepis-liczniki`, właściciel `kuking`,
`127.0.0.1:55439` — parametry sprawdzone przed pomiarem. Syntetyczne zdarzenia
jednego przepisu, 8 aktywnych kucharzy, widz zalogowany, zakres modelu
`CookedEvent::where('recipe_id', ...)->widoczneDla(widz)`. Dla tego samego
zbioru porównano `count(*)` i `count(distinct cooked_events.user_id)`.
`EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`: 2 rozgrzewki i mediana kolejnych
5 wykonań, bez kosztu połączenia i renderu. Dane wycofano transakcją.

| Zdarzenia | Unikalne osoby | `count(*)`, ms | `count(distinct ...)`, ms |
|---|---|---|---|
| 12 | 8 | 0,044 | 0,050 |
| 1000 | 8 | 2,046 | 1,994 |
| 10000 | 8 | 13,483 | 11,465 |

Odczyty buforów współdzielonych były takie same dla obu wariantów:
38 / 3014 / 20135; zapisu plików tymczasowych nie było. Przy 10000 zdarzeń
zakresy próbek wyniosły 12,574–16,460 ms i 10,003–13,618 ms.

→ Zastrzeżenie granicy pomiaru wprost z tekstu: „To mała próbka na
współdzielonym hoście, z 8 powtarzającymi się kucharzami, a nie prognoza
wydajności produkcji. Nie wykazała spowolnienia `DISTINCT`. Koszt dodatkowego
zapytania o osoby pozostaje realny, bo liczba zdarzeń jest już potrzebna
paginatorowi. Wybór jednostki opiera się na znaczeniu produktu.”

### Dodatkowa rozbieżność w eksporcie i decyzja właściciela

`CollectUserExportData` liczy wszystkie zdarzenia, lecz stary klucz mówił
„przez innych”. Przypadek kontrolny: 1 wykonanie autora, 2 wykonania jednego
aktywnego kucharza i 2 wykonania kucharza zbanowanego. To **5 zdarzeń, 3 osoby,
4 cudze zdarzenia i 3 zdarzenia widoczne w galerii**.

Samodzielnie uruchomiony zbieracz eksportu przed poprawką zwrócił
`ile_razy_ugotowany_przez_innych = 5` dla tych danych. Transakcję wycofano.

**Właściciel w tej sesji wybrał: „Wszystkie wykonania, także własne i ukryte;
nazwij ten pełny zakres wprost.”** Dlatego nowy klucz to
`ile_razy_ugotowany_lacznie`, a `o_tym_pliku.zakres_licznika_wykonan_przepisu`
wyjaśnia, że liczba obejmuje własne i cudze wykonania, także niewidoczne
w galerii, i liczy każde kolejne gotowanie osobno. Zapytanie pozostaje takie samo.

→ To jest jedyna decyzja właściciela zapisana w tym raporcie i dotyczy
WYŁĄCZNIE nazwy/zakresu pola w eksporcie JSON (błędny klucz
`ile_razy_ugotowany_przez_innych` → poprawiony `ile_razy_ugotowany_lacznie`),
nie wyboru między „12 wykonań” a „8 osób” dla głównego licznika na
stronie/karcie/profilu — ten drugi wybór, jak opisano wyżej, opiera się na
wcześniejszym komentarzu właściciela w issue #666, a nie na nowej decyzji
podjętej w tej sesji.

To zmiana nazwy pola JSON, bez migracji bazy. Stary, mylący klucz nie jest
utrzymywany jako alias. Programy czytające przyszłe paczki muszą uwzględnić
nową nazwę; wcześniej pobrane archiwa pozostają bez zmian. Wyszukiwanie
w źródłach nie wykazało innych konsumentów starego klucza w aplikacji.
Własne wykonania w `ugotowalem` i indeks HTML eksportu zachowują swój zakres.

### Odnośniki #667

Samodzielny render HTTP/Blade potwierdził:

- Okruszek przepisu i `BreadcrumbList` nazywają cel „Świeżo z Kuking”
  i prowadzą do `/odkryj`.
- „Poszukaj przepisów” w obu pustych stanach zeszytu prowadzi do
  `/szukaj?sekcja=przepisy`.
- Wyszukiwarka bez frazy zachowuje zakres i prosi o wpisanie zapytania;
  nie przedstawia się jako katalog wszystkich przepisów.

Okruszek i akcja szukania **nie mają już wspólnego celu**. Dwa przyciski
szukania w dwóch pustych stanach mają ten sam cel zgodnie z tą samą czynnością.
Nie powstała nowa nazwa funkcji ani katalog przepisów.

### Wykonane kontrole

Wszystkie poniższe wyniki są własnymi pomiarami na PostgreSQL
`127.0.0.1:55439`, w bazie `kuking_flota_gpt-przepis-liczniki`:

| Moment | Kontrola | Wynik |
|---|---|---|
| Nietknięty kod wejściowy | 10 rodzin liczników, odnośników, profilu, powiadomień i eksportu | 86 testów, 495 asercji, PASS |
| Nietknięty kod wejściowy | Domyślny zestaw `php artisan test`, filtr `^(?!.*ProbaOdtworzeniaTest)` | 4393 testy, 83692 asercje, PASS, 522,65 s |
| Nowy test, stary kod eksportu | `DataExportTest::test_licznik_przepisu_w_paczce_nazywa_pelny_zakres_wykonan` | FAIL, 8 asercji; wykryty klucz `ile_razy_ugotowany_przez_innych` |
| Poprawiony eksport | `DataExportTest` | 26 testów, 153 asercje, PASS |
| Stan końcowy kodu | Te same 10 rodzin oraz filtr `Teksty` | 140 testów, 795 asercji, PASS |
| Stan końcowy kodu | `vendor/bin/pint` | PASS, 1155 plików |
| Stan końcowy zmian | `git diff --check` | PASS |

Rodziny pierwszego i końcowego przebiegu: `CookedCountsUnitsTest`,
`LinkDestinationLabelsTest`, `LicznikUgotowanZgadzaSieZListaTest`,
`PrzepisyLicznikiZgadzajaSieZGaleriaTest`, `KomuWyszloUkladTest`,
`WyszukiwarkaLiczyWykonaniaTakSamoJakPrzepisTest`,
`ProfilLicznikiTresciZgadzajaSieZListamiTest`, `NaglowekProfiluOdmieniaLicznikiTest`,
`UgotowalemZawszePowiadamiaAutoraTest`, `DataExportTest`.

**Pełnego zestawu nie powtarzano po wąskiej zmianie klucza i objaśnienia JSON**;
końcowy przebieg objął zmieniony eksport, wszystkie wskazane rodziny oraz
reguły tekstów. `ProbaOdtworzeniaTest` pominięto zgodnie z poleceniem ze względu
na wspólną bazę próby. Grupa `dwa-polaczenia` pozostaje wyłączona domyślnie
przez konfigurację projektu; nie uruchamiano jej osobno.

Przygotowanie runtime dwukrotnie zgłosiło `rsync: Cannot allocate memory`.
Każdą taką próbę powtórzono do kodu wyjścia 0 przed uruchomieniem testów.
Roboczy próbnik PHP początkowo trafił do skanu Pint; usunięto go po pomiarze,
ponownie zsynchronizowano runtime i dopiero wtedy uzyskano końcowy PASS.

### Wycofanie i granice

Wycofanie lokalnego commita przywraca poprzedni format przyszłych paczek;
nie wymaga operacji na bazie i nie przepisuje pobranych archiwów. Przywróci
jednak również błędne określenie „przez innych”, dlatego preferowana reakcja
na problem zgodności to poprawienie konsumenta JSON.

Nie wykonano push, PR, zamknięcia issues, zmian produkcji ani nowego odbioru
przeglądarkowego. Zmiana dotyczy danych JSON, bez zmiany HTML/CSS.
Nowej decyzji produktowej o licznikach nie potrzeba; właściciel rozstrzygnął
zakres eksportu. Otwarte pozostają wcześniej opisane odbiory produkcyjne.

---

## Podsumowanie wg pytań zleceniodawcy

### #666 — liczniki wykonań

- **Co kod naprawdę liczy:** zdarzenia (kolejne gotowania), nie unikalne osoby
  — z wyjątkami opisanymi w tabeli źródłowej (np. odpowiedzi „would_make_again”
  liczą zdarzenia z niepustą odpowiedzią, nie unikalne osoby; JSON eksportu
  liczy wszystkie zdarzenia bez filtrów galerii).
- **Miejsca sprawdzone w tej sesji (9, wszystkie z tabeli źródłowej):** strona
  przepisu (znaczek i galeria), odpowiedzi przy przepisie, dane strukturalne
  przepisu (`userInteractionCount`), karta wyniku wyszukiwania, karty na
  profilu i w zeszycie, licznik profilu, powiadomienie, indeks HTML eksportu,
  przepis w JSON eksportu.
- **Miejsca NIE wspomniane jako sprawdzone:** raport nie wymienia żadnych
  innych ekranów/miejsc poza tymi dziewięcioma; nie ma w tekście źródłowym
  wzmianki o pominięciu konkretnego, nazwanego miejsca.
- **Czy naprawiono jedno miejsce, zostawiając inne z inną liczbą (pogorszenie):**
  raport tego nie opisuje. Jedyna zidentyfikowana i naprawiona rozbieżność to
  błędna NAZWA pola w eksporcie JSON (`ile_razy_ugotowany_przez_innych` →
  `ile_razy_ugotowany_lacznie`), nie rozbieżność między dwoma miejscami
  pokazującymi ten sam zakres danych. Raport wprost zaznacza, że różne liczby
  w profilu/galerii/eksporcie są zamierzone, bo mają różne zakresy danych, i że
  „podpis musi jasno nazywać zakres i jednostkę” — to stwierdzenie autora
  raportu, przytoczone dosłownie z zastrzeżeniem, że nie jest to interpretacja
  zleceniobiorcy.
- **Czy wybór „12 wykonań” vs „8 osób” to decyzja właściciela czy stanowiska:**
  wg raportu to rozstrzygnięcie **zakresu #666, potwierdzone komentarzem
  właściciela w issue #666** (liczba zdarzeń, nie `DISTINCT`) — nie nowa
  decyzja podjęta samodzielnie przez stanowisko w tej sesji. Odrębna, osobna
  decyzja właściciela zapadła w tej sesji WYŁĄCZNIE co do nazwy/zakresu pola
  JSON eksportu (patrz wyżej).

### #581 — panel moderacji

- **Granica „port kompozycji, bez zmiany uprawnień/Policy/zakresu widoczności
  dla moderatora”:** wg raportu dotrzymana — port kompozycji był już wdrożony
  wcześniej (PR #587, #637); w tej sesji nie zmieniono Policy, ról, tras,
  kontrolerów, widoczności danych, schematu ani znaczenia decyzji
  moderacyjnych; obowiązują nadal decyzje D-218 i D-220.
- **Konkretne pomiary UX 50+:**
  - Tekst bazowy: 18 px przy skali 100%, 25,2 px przy skali 140% (spełnia próg ≥18 px).
  - Cele dotyku: minimalne 48 × 48 px przeszły istniejący miernik (spełnia próg ≥48 px);
    w scenariuszu bez JavaScriptu przyciski miały minimum 50,5 px wysokości
    przy skali 100% i 59,5 px przy 140%.
  - Szerokości testowane: 320/360/390/414/768/1440 px, dwa motywy, tekst 100/140%.
  - Nawigacja: promień 26 px, powierzchnia `rgb(255, 255, 255)` w jasnym motywie.
- **Praca bez JavaScriptu przy akcjach nieodwracalnych:** potwierdzenia
  czyszczenia używają natywnego `details.confirm` (bez JS); formularze są
  serwerowe z CSRF; przy wyłączonych skryptach licznik prób mutacji wyniósł
  zero; żądania inne niż GET/HEAD były blokowane. Zastrzeżenie autora: „Sprawdzenie
  to nie dowodzi poprawnego zapisu każdej operacji — ten zakres należy do
  testów domenowych i HTTP.”
- **Brak hover jako jedynej drogi:** raport nie opisuje osobnego testu hover;
  wymienione strażniki (`panel-marki.mjs` itd.) obejmują klawiaturę, radio i Tab,
  ale hover jako wyłączna droga nie jest w tekście źródłowym wprost adresowany
  — brak wzmianki oznacza, że tej sesji raport tego nie potwierdza ani nie
  wyklucza.

## Zastrzeżenia granic pomiaru i rzeczy świadomie niezrobione (obie sesje łącznie)

- Nie wykonano push, PR, CI, wdrożenia ani zmian produkcyjnych w żadnej z sesji.
- #581: nie uruchomiono opcjonalnych mutacji `--negative-menu` ani
  `--negative-details`; nie wysyłano wiadomości do ludzi/instytucji; lokalna
  walidacja nie zastępuje produkcyjnego oglądu rzeczywistych spraw z opisu #581;
  pozostały odbiór wskazanych stanów na produkcji jest nadal potrzebny.
- #581: adapter wykonawczy (lokalne nazwy baz w pięciu plikach oprzyrządowania,
  etap walidacji z CI uruchomiony lokalnie) opisany wprost jako „adapter
  wykonawczy, nie zmiana aplikacji ani dowód wykonania GitHub Actions”.
- #581: nie uruchamiano grupy testów `dwa-polaczenia` (domyślnie wyłączona);
  wyłączono `ProbaOdtworzeniaTest` zgodnie z podanym wyjątkiem wspólnej bazy.
- #666/#667: nie sprawdzano produkcji ani nie tworzono na niej danych w tej
  sesji („[pomiar cudzy]” dla wcześniejszych wdrożeń i odbiorów produkcyjnych).
- #666/#667: pomiar wydajności PostgreSQL to „mała próbka na współdzielonym
  hoście, z 8 powtarzającymi się kucharzami, a nie prognoza wydajności
  produkcji”.
- #666/#667: pełnego zestawu testów nie powtórzono po wąskiej zmianie klucza
  i objaśnienia JSON w eksporcie; `ProbaOdtworzeniaTest` pominięto; grupa
  `dwa-polaczenia` nie była uruchamiana osobno.
- #666/#667: nie wykonano push, PR, zamknięcia issues, zmian produkcji ani
  nowego odbioru przeglądarkowego.

## SHA i identyfikatory przepisane dokładnie

- #581: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/panel-moderacji-marka`.
- #666/#667: stan wejściowy `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
  gałąź `gpt/przepis-liczniki`.
- Poprawki już obecne w drzewie #666/#667: `bdc56b8cf9b664eda104b628d85149b08d84d8d9`
  (#671), `71549ebad6eab2d41c71073c2f93aed85419e69d` (#672).

## Brakujące pliki

Żaden z dwóch wskazanych plików źródłowych nie był brakujący — oba istniały
pod podanymi ścieżkami i zostały odczytane w całości.
