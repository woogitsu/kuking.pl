# Stan sesji, część 5 — dług weryfikacyjny, odtwarzanie bazy, monitoring

Raport zestawia wiernie treść trzech raportów źródłowych, bez interpretacji
i bez wniosków wykraczających poza to, co w nich zapisano. Liczby i SHA
przepisano dokładnie. Wszystkie trzy pliki źródłowe zostały odnalezione —
żadnego brakującego pliku nie zgłaszam.

Źródła:
1. `gpt-dlug-weryfikacyjny/docs/audits/WERYFIKACJA_713_492_2026_09_20.md`
2. `gpt-dr-baza/docs/infra/DR594_RUNBOOK_LOKALNY.md` oraz
   `gpt-dr-baza/docs/infra/evidence/dr594/RAPORT.md`
3. `gpt-monitoring/docs/infra/MONITORING_ODBIOR_2026_09_20.md`

---

## 1. Dług weryfikacyjny (#713, #492)

Stan na 20.09.2026, źródła aplikacji: SHA
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Gałąź `gpt/dlug-weryfikacyjny`.
Przed pomiarem drzewo było czyste. Nie zmieniono aplikacji, testów, schematu
ani konfiguracji wdrożenia.

Definicje z raportu: **własny pomiar** = wykonanie w tej sesji. **Odczyt
kodu** ≠ ogląd interfejsu. Wynik przejęty ma oznaczenie **[pomiar cudzy:
źródło]**. „Brak dowodu” nie oznacza usterki. Status GitHuba nie zastępuje
kryterium odbioru.

### Własne pomiary przed zmianą dokumentacji

- PostgreSQL `127.0.0.1:55439`, izolowana baza
  `kuking_flota_gpt-dlug-weryfikacyjny`, runtime
  `/home/mateusz/flota/gpt-dlug-weryfikacyjny-run`.
  Testy: **4393 passed, 83 692 asercje, 508,30 s, kod wyjścia 0**.
  Wyłączono wyłącznie `ProbaOdtworzeniaTest` na polecenie użytkownika
  (korzysta ze współdzielonej bazy źródłowej). Nie ma tu deklaracji pełnego
  hooka.
- `scripts/panel-komunikat.test.mjs`: **12/12**, zero błędów.
- `vendor/bin/pint`: **PASS, 1155 plików** w runtime tej samej aplikacji.
- Produkcja, GET `/health` i `/login`, **17:39 UTC**: oba HTTP 200, wersja
  `4c811cc7…`, zgodna z własnym `git ls-remote origin refs/heads/main`.
  Health nadal `degraded`, wyłącznie `kolejka: zadania_nieudane`. Login
  zawiera Turnstile, `connect-src` dopuszcza jego host. Bez logowania.
- Odczyt GitHub: zgłoszenia z komentarzami przez `gh issue view`, PR #707 i
  jego CI przez `gh pr view` / `gh run view` — to inspekcja **cudzego
  wykonania**, nie uruchomienie tamtego CI w tej sesji.
- Próba odczytu projektów konektorem Railway zwróciła `USER_NOT_LOGGED_IN`.
  Nie wyprowadzono z tego żadnego stanu bazy ani panelu.

### #713 — stan każdej pozycji ze stanem faktycznym i dowodem

**A. Dostęp**

- **A1 — failed_jobs**: częściowo zrobione wcześniej, opis „nie da się
  odczytać” niepełny. [pomiar cudzy: #599, komentarz 5732929669] — odczyt na
  sucho pokazał cztery `UstawienieNowegoHasla` z 9.09 (14:05/14:42/14:54/16:24
  UTC), jedno konto, wygasłe tokeny, zero usunięć i ponowień. Własny dzisiejszy
  health nadal zgłasza zaległości; `HealthController` liczy całą tabelę, sam
  health nie dowodzi, że dziś to dokładnie te cztery rekordy. Brakuje:
  aktualnego bezpiecznego odczytu liczby/klas/dat/kategorii przyczyny i
  kontrolowanego uporządkowania. D-183 już zabrania ponawiania wygasłego
  resetu — to nie jest nowa decyzja „retry czy nie”.
- **A2 — R2 #120/#617/#619**: niezweryfikowane w wymaganej warstwie. Migawki
  wszystkich trzech zgłoszeń nadal OPEN. **BRAK DOWODU** jurysdykcji R2,
  publiczności bucketów, tokenów, lifecycle, blokad. `LocationHint` nie
  dowodzi jurysdykcji UE. #617 zawiera wybór strategii retencji, nie tylko
  brak testu.
- **A3 — EmailLabs #204**: niepotwierdzone wyłączenie piksela u dostawcy.
  Odczyt `TransportEmailLabs` i narzędzi sprawdzania śledzenia potwierdza
  rozdzielenie śledzenia linków od otwarć. Lokalny nagłówek dot. linków nie
  dowodzi wyłączenia piksela. **BRAK DOWODU** potwierdzenia ustawienia u
  dostawcy i odbioru nowego listu w uprawnionym teście. Nie wysyłano wiadomości
  w tej sesji.

**B. Dane i obciążenie**

- **B1 — #666**: częściowo zweryfikowane. [pomiar cudzy: #666, macierz #517]
  — lokalne 96 konfiguracji, produkcyjne zero i jedno wykonanie. Dzisiejsze
  testy PHP nie są nowym pomiarem wizualnym. **BRAK DOWODU** produkcyjnych
  2/5/22 wykonań oraz „N z M odpowiedzi” z procentem na rzeczywistych danych.
- **B2 — #370**: dwie z trzech luk już domknięte. [pomiar cudzy: PR #721,
  `docs/design/STRONY_TAGOW_370.md`, `evidence/tags370/cta-results.json`,
  `stanC-results.json`] — 24/24 kliknięcia/dotknięcia prowadzą gościa na
  login, CTA 50,5–91 px; dostarczono brakujące zrzuty stanu C przy 100%.
  Brakuje produkcyjnego kolażu z co najmniej trzema różnymi autorami i
  związanej z nim grupy A.
- **B3 — #646/#652**: zrobione lokalnie i świadomie zamknięte bez
  produkcyjnego wariantu. [pomiar cudzy: oba zgłoszenia i
  `ODBIOR_DALSZYCH_WYNIKOW_568.md`] — 15 testów, 1771 asercji, trzy negatywy.
  Brakuje wyłącznie produkcyjnego ekranu z ponad 12 elementami w każdej z
  dwóch list, jeśli wymagany jest taki dodatkowy odbiór.
- **B4 — #605**: przyrząd zrobiony; pojemność niezmierzona. [pomiar cudzy:
  #605, `docs/infra/evidence/obciazenie605/PRZYGOTOWANIE.md`, `METODA.md`] —
  wcześniejsze serie/warunki nie dają przyjętego pomiaru rzeczywistego ruchu
  mieszanego. **BRAK DOWODU** przyjętej serii ze sprawdzoną topologią,
  obciążeniem gospodarza i kryteriami ważności. Zastrzeżenie wprost: **20
  req/s nie jest ustaloną pojemnością produkcji**.

**C. Człowiek, urządzenie, pełna ścieżka**

- **C1 — #569**: zamknięte świadomie, odsłuch niewykonany. Własny odczyt
  `resources/views/pages/recipes/cooking.blade.php`: `role="timer"`,
  `aria-live="off"`, bez zalecanej nazwy „Pozostały czas”. **BRAK DOWODU**
  odsłuchu NVDA/VoiceOver podczas odliczania i pracy w krokach.
- **C2 — telefon/Safari/iOS**: brak dowodu urządzeń pozostaje; zdanie
  „wszystko przez Chromium” jest fałszywym uogólnieniem. [pomiar cudzy:
  `ODBIOR_EKSPORTU_I_DRUKU_492.md`] — eksport badano także w Firefox 155,
  obok Chromium 151. **BRAK DOWODU** fizycznego telefonu, Safari/iOS.
- **C3 — #678**: przeglądarkowy ekran/druk wykonany, urządzenia nie. #678
  scalony PR. Ekrany, PDF i kontrole ujemne mają dowody. Test ZIP w PHP
  sprawdza archiwum programowo. **BRAK DOWODU** fizycznego wydruku i
  natywnego rozpakowania na systemach docelowych.
- **C4 — #697**: poprawka wdrożona, pełne logowanie nadal nieudowodnione.
  Issue CLOSED, commit `85ef2c15`. Własny GET potwierdza CSP, widget i 200.
  [pomiar cudzy: komentarz 5749355904] wprost wyklucza logowanie na konto.
  `PolitykaCspDopuszczaPowrotTurnstileTest` pilnuje CSP, nie całej sesji
  użytkownika. **BRAK DOWODU** rzeczywistego logowania hasłem przez
  Turnstile do sesji.

**D. Pozostałe pomiary**

- **D1 — przyczyna w komunikacie panelu**: zrobione. Odczyt
  `scripts/panel-validation.mjs:151`, `panel-komunikat.mjs`, własne 12/12
  testów. PR #723 i #730 obsługują gołe kody i `P581_…` ze spacją, bez
  danych JSON. Przyczyna przerywanych awarii samego pomiaru pozostaje osobna
  i nieustalona — nie przypisano jej obciążeniu bez reprodukcji.
- **D2 — variables_order**: skutek naprawiony; przyczyna źródłowa
  nieustalona. [pomiar cudzy: #713, PR #703] — `--no-reload` uniezależnia
  start serwera od wariantu środowiska. **BRAK DOWODU** winy `setup-php`;
  nie blokuje zamknięcia poprawki skutku.
- **D3 — regresja #707 i hook**: zrobione później. PR MERGED, head
  `ef2afc51c195cf70843e1d94e9b3ec88ec7c16d5`, merge
  `8695e90b966076d323824a7dcbd625b656c6a319`. Własna inspekcja [cudzego CI
  35435955028] znalazła wiersz „#684: podpowiedź ustępuje wskaźnikowi w 8/8
  konfiguracjach” w jobie rodzin ekranów. [pomiar cudzy: aktualizacja #713]:
  pełny hook dwa razy, 4280 testów / 82 773 asercje — to deklaracja autora
  komentarza, bez ponownego uruchomienia historycznego SHA tutaj. Własne 4393
  testy nie zostały nazwane hookiem.
- **D4 — lead**: wdrożone, lokalny odbiór częściowo udokumentowany,
  produkcyjny ogląd niepotwierdzony. [pomiar cudzy:
  `POZOSTALE_LUKI_492.md`]: 36 konfiguracji, 22/30,8 px. Dzisiejszy SHA
  produkcji zawiera PR #716, więc „czeka na wdrożenie” jest już nieprawdą.
  Opis zoomu wymaga sprostowania (patrz decyzja D-205 niżej). **BRAK
  DOWODU** oglądu właściwych stanów po wdrożeniu i niezależnego dowodu
  prawdziwego zoomu.
- **D5 — #692 / PR #712**: kod i regresja są; oglądu nowych bloków nie
  wykazano. `EksportMowiOZdjeciachKtoreNieWejdaNigdyTest` buduje rzeczywisty
  ZIP; metoda od linii 269 sprawdza formy dla 1/3/5/12. Testy przechodzą w
  bieżącym zestawie. **BRAK DOWODU** ekranu/drukowania/kontrastu/320 px/
  zoomu200 dla tych bloków i liczebników setkowych. Starszy odbiór #678 nie
  obejmuje automatycznie późniejszego kodu.
- **D6 — #711**: scalone i wdrożone, ograniczenie dwóch układów nadal
  aktualne. Migawka #711 MERGED; `layout.blade.php` nadaje atrybut także
  zalogowanemu. [pomiar cudzy: `PASEK_PRZEWIJANIE.md`]: lokalne scenariusze,
  otwarte menu, negatyw przywracający `@guest`. Własne testy obejmują
  `PasekChowaSieTakzePoZalogowaniuTest`. **BRAK DOWODU** oglądu
  chowania/odkrywania paska w gotowaniu i panelu moderacji oraz stanów
  produkcyjnych.
- **D7 — trzecia para list**: przegląd wykonany; nie potwierdzono następnego
  konfliktu. Własny skan paginatorów i odczyt `CollectionController`,
  `RecipeController`, `ProfileController`, `FeedController`,
  `AdminBezOdpowiedziController`, `SearchController`. Wyszukiwanie ma dwie
  listy poza paginatorami Laravela: wspólne rozszerzanie `ile` do 200, potem
  osobne `od_przepisu`/`od_osoby`. `DalszeWynikiWyszukiwaniaTest` i
  `GraniceOkienWyszukiwaniaTest` przechodzą. Nie wykazano usterki do
  naprawy; wspólne rozszerzanie to opisany kontrakt, nie nowa decyzja.
- **D8a — #193/#594**: przygotowanie i lokalne odtworzenie są, produkcyjny
  odbiór niepotwierdzony. [pomiar cudzy: oba zgłoszenia]: ćwiczenie MinIO z
  202 wierszami nie jest produkcyjnym backupem. **BRAK DOWODU** produkcyjnego
  zrzutu i odtworzenia w odizolowanym celu.
- **D8b — #595**: kod IaC nie potwierdza zastosowania. [pomiar cudzy: #595]:
  plan/staging/apply nadal nieodebrane. **BRAK DOWODU** kolejnych faz na
  rzeczywistym środowisku. Zgodność SHA aplikacji nie dowodzi konfiguracji
  usług Railway.
- **D8c — #598**: przyrząd i pojedyncze odczyty są; szczyt niezmierzony.
  [pomiar cudzy: `WERYFIKACJA_BUDZETU_POLACZEN_598.md`]: konfiguracja kanału
  naprawiona, 16 jest budżetem obliczonym z topologii, nie pomiarem szczytu.
  Raport sam wyklucza pomiar szczytu w §5. **BRAK DOWODU** szeregu odczytów
  podczas normalnego ruchu i wdrożenia.
- **D8d — #599**: alarm z odbiorcą nadal bez końcowego potwierdzenia. Kod
  handlera/webhooka i health istnieje. [pomiar cudzy: #599]: wcześniejsze
  braki konfiguracji; dziś nie odczytano panelu. **BRAK DOWODU** odbioru
  rzeczywistego alarmu po drugiej stronie oraz odczytu health po
  uporządkowaniu kolejki. Wysłanie alarmu do człowieka nie jest objęte
  zgodą w tej sesji.

### #492 — tylko pozostałe luki (nie nowa kolejka implementacji)

Indeks braków odbioru do istniejących issues/raportów. **Pełny port
pozostaje CZĘŚCIOWO**: nie ma podstaw ani do zamknięcia całości, ani do
ponowienia zamkniętych pakietów.

| Obszar | Wyłącznie pozostały zakres |
|---|---|
| Panel #581 | Produkcyjne pełne zgłoszenia, nowe wyzwanie 2FA i pozostałe warianty wymagane przez issue; szczególne układy paska — D6. |
| Tagi #370/#681 | #370: produkcyjny kolaż ≥3 autorów; #681: fotograficzny wariant katalogu na rzeczywistym publicznym zdjęciu produkcyjnym oraz brakujące uzupełnienie raportu. |
| Liczby i zeszyty #666/#667 | Warianty liczb z B1; dwa puste stany zeszytu na zalogowanym koncie produkcyjnym. |
| Eksport | D5: nowe bloki #692; C3: fizyczny druk i natywne rozpakowanie. |
| Logowanie i OAuth | C4 pełne hasło; rzeczywisty zewnętrzny dostawca i końcowy POST OAuth; zakresy zoomu i poczty wyłączone w raportach. |
| Formularze i odzyskiwanie | Nowe uploady, maksymalne dane, pełny fokus i pozostałe nieodebrane kombinacje błędów; prawdziwy zoom przy PUT po 429. |
| Komuś wyszło / Podziękuj | Zdjęcia wykonania, rzeczywisty zoom200, wysłanie mobilne i odbiór produkcyjny. |
| Profile i relacje | Tylko kombinacje/urządzenia poza udokumentowanymi odbiorami, fizyczny dotyk i ewentualne nowe odtwarzalne różnice. |
| Wygląd, nagłówek, nakładki #684 | Wąskie pozostałe stany z audytu dostępności, fizyczny telefon, dwa układy D6. |
| PWA/offline #278 | Kwalifikujące się konto, zakończona instalacja natywna Android/iOS; niepokryte zachowania offline/systemów. |
| Poczta, dostępność i człowiek | Realne programy pocztowe, dostarczalność i A3; odsłuch C1, urządzenia C2, badania użytkowników #15. |
| Publiczne i zalogowane stany poza powyższym | Niepokryte kombinacje filtrów, pustych danych, typów powiadomień i komunikatów muszą mieć przypisany scenariusz, jeśli mają wejść do końcowej deklaracji pełnego portu. To nie jest pełny świeży ogląd wszystkich ekranów. |

Nie reaktywowano #456, #485, #548, #549, #561, #638 ani lokalnie zamkniętych
#646/#652/#569. Nie powtarzano odebranych nakładek, relacji, formularzy PUT,
ciemnego logowania linkiem ani starego eksportu. Nie wysłano aktualizacji do
issues — użytkownik zlecił lokalny produkt i zabronił wiadomości.

### Osobno: decyzje i rozjazdy dowodów (cicho odwrócone przez kod / rozjazdy)

Raport wprost zastrzega: **nie potwierdzono nowego cichego odwrócenia
decyzji przez kod.** Poniższe są rozjazdami rejestru/raportu z dowodem, nie
listą nowych regresji. Nie zastępują przeglądu wszystkich decyzji z PR #787.

1. **D-183 / A1**: `docs/DECISIONS.md:14017` już rozstrzyga wygasłe resety;
   `app/Console/Commands/MartweZadania.php` realizuje ograniczoną komendę.
   Sugestia A1 „potem decyzja, czy odtwarzać” nie powinna ponownie otwierać
   retry dla opisanych wygasłych tokenów. Dzisiejsze rekordy wymagają jednak
   odczytu, a ich usunięcie — osobnej zgody operacyjnej.
2. **D-205 — wiarygodność przyrządu / D4**: `POZOSTALE_LUKI_492.md:44`
   nazywa `deviceScaleFactor: 2` rzeczywistym zoomem200. Sam DPR tego nie
   dowodzi. W śledzonym drzewie brak wskazanego `pomiar-d4.mjs` i odczytu
   poziomu zoomu; 36 konfiguracji pozostaje przejętą deklaracją o
   ograniczonej odtwarzalności. Raport nie stwierdza, że zoom nie odbył
   się — stwierdza brak wystarczającego dowodu.
3. **D-217 — preferencja wyglądu / #370**: komentarz 5742351788 mówi „motyw
   ciemny ustawiany przez DOM”. Tymczasem `cta-stanC.mjs:19–46` wybiera
   motyw i skalę rzeczywistym formularzem, zapisuje, ponownie otwiera stronę
   i odczytuje atrybuty; `stanC-results.json` zawiera te potwierdzenia. Są
   sprzeczne opisy tej samej metody.
4. **D-205 — zakres mierzonej strony / #370**: `cta-stanC.mjs:100–112`
   mierzy `overflow` **po przejściu na login**. Wynik CTA nadal dowodzi
   rozmiaru przycisku, trafienia i nawigacji. Nie dowodzi braku overflow
   źródłowej strony tagu.
5. **#598 — liczba obliczona nazwana pomiarem**:
   `docs/infra/WERYFIKACJA_BUDZETU_POLACZEN_598.md:219` pisze „zmierzonego
   szczytu (16)”, ale linie 202–203 wyprowadzają go z topologii, a 246–247
   wykluczają serię i pomiar szczytu wdrożenia. To sprzeczność wewnętrzna
   raportu; brak podstaw do zmiany progów kodu.
6. **D-206 — odbiór działającej aplikacji / C4 i D6**: zamknięte #697,
   scalone #711 i zgodny SHA produkcji nie są dowodami pełnych interakcji.
   Dług zachowano, nie rozszerzono asercji na nieprzebyte ścieżki.

### Decyzje właściciela i następny odbiór (z raportu #713/#492)

- **#617**: osobny bucket kopii z blokadą/retencją 30 dni albo jawne
  przyjęcie ryzyka przy prostszym układzie. Pierwszy wariant kosztuje
  dodatkową konfigurację, przechowywanie i ćwiczenie odtworzenia; drugi
  pozostawia ryzyko utraty/usunięcia kopii. Brak danych do uczciwej wyceny
  kwotowej.
- **D-221 / Poradźcie**: utrzymać wyłączenie i odbierać lokalnie albo osobno
  zatwierdzić udostępnienie produktu.
- **C1**: nazwać timer „Pozostały czas” albo pozostawić bieżący wzorzec do
  odsłuchu.
- **D2 i dalszy odbiór marki**: można przyjąć jawnie resztkowy brak
  diagnozy środowiska i ograniczenia urządzeń albo przeznaczyć osobny
  czas/dostęp na ich zamknięcie.

Odczyt paneli, E2E hasła, seria obciążeniowa, drukarka, natywne urządzenia,
czytnik ekranu i klienci poczty to konkretne następne pomiary — nie
wykonano ich tutaj. Nie usuwano failed_jobs, nie wysyłano listów/webhooków,
nie zmieniano produkcji. Nie wykonano pushu ani PR-a.

---

## 2. Odtwarzanie bazy (#594 / #193)

Stan instrukcji: 20 września 2026. Zakres: **lokalne dane syntetyczne**.
To uzupełnienie głównej procedury kopii (`KOPIE_I_ODTWORZENIE.md`), nie
zgoda na produkcyjny restore.

### Stanowisko próby

| Element | Wartość |
|---|---|
| Worktree Windows | `C:\Users\matma\Documents\kuking-flota\gpt-dr-baza` |
| Gałąź / baza kodu | `gpt/dr-baza` / `4c811cc7bff365fb8f86d87eabac93b7738a45cd` |
| Runtime WSL Ubuntu | `/home/mateusz/flota/gpt-dr-baza-run` |
| PostgreSQL | `127.0.0.1:55439`, rola `kuking`, wersja 18.6 |
| Źródło syntetyczne | `kuking_flota_gpt_dr_baza_source` |
| Cel | `proba_odtworzenia_gpt_dr_baza_<czas UTC>` |
| Baza zwykłych testów | `kuking_flota_gpt-dr-baza` — inna niż źródło i cel próby |

### Stan wyjściowy (przed zmianami)

- Gałąź `gpt/dr-baza`, czyste drzewo, `HEAD=4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
- Odczytano #594 i #193 przez `gh issue view` — obydwa otwarte. Nie wysłano
  komentarzy ani nie zmieniono zgłoszeń.
- Istniejący `PetlaOdtworzeniaJestJednaKomendaTest`: **5/5, 18 asercji**.
- Istniejący `scripts/proba-odtworzenia.sh --petla-lokalna` na nietkniętym
  kodzie: zrzut **332 845 B**, **4654 wiersze**, 50 tabel zgodnych, 82
  migracje, 0 czekających; `pg_restore` **1 s**. Cel
  `proba_odtworzenia_gpt_dr_baza_baseline` automatycznie usunięty.
- Działały 3 wymagane wyzwalacze, 91 CHECK, 26 UNIQUE, 71 FK i sondy
  zachowania. Nie znaleziono usterki wymagającej poprawki kodu restore.
- To własny pomiar, nie przejęcie wcześniejszej próby 192/202 wierszy —
  wcześniejsze próby to **[pomiar cudzy: KOPIE_I_ODTWORZENIE.md §5.1]**.

### Wielkość i kształt zbioru pomiarowego

Generator `scripts/dr594-dane.sql` dołożył **5 500 000** wierszy do pełnego
schematu po migracjach i danych demonstracyjnych. Jedna transakcja, bez
wyłączania ograniczeń, indeksów, FK, wyzwalaczy. Dane nie pochodzą z
produkcji.

| Tabela / obszar | Wiersze dodane |
|---|---:|
| Konta | 50 000 |
| Profile | 50 000 |
| Przepisy | 100 000 |
| Składniki przepisów | 800 000 |
| Kroki przepisów | 400 000 |
| Wersje przepisów z JSONB składników i kroków | 100 000 |
| Wpisy z różnymi widocznościami i długościami tekstu | 1 000 000 |
| Komentarze powiązane z wpisami | 2 000 000 |
| Obserwowania | 500 000 |
| Metadane uploadów w stanie `pending` | 250 000 |
| Powiązania wpisów z mediami | 250 000 |

W bazie zostały też dane seedera: m.in. **2 ugotowania i 2 powiadomienia**
(seeder używa `RecordCookedEvent`), 41 wcześniejszych przepisów i słownik
tagów. Ten obszar ma małą liczność — próba nie jest benchmarkiem dużej
tabeli `cooked_events`. Zbiór jest syntetyczny: powtarzalne słownictwo
dobrze się kompresuje, rozkład autorów jest regularny, nie modeluje całej
aktywności produkcyjnej. Część tabel jest pusta. Metadane uploadów nie
udają gotowych zdjęć; bajtów R2 ta próba nie obejmuje. Zastrzeżenie wprost:
**nie należy skalować tych czasów liniowo do przyszłej produkcji.**

### LICZBY CZASU — własny pomiar

Przebieg rozpoczęty **2026-09-20 17:39:37 UTC** / 19:39:37 Europe/Warsaw.

| Etap | Czas |
|---|---:|
| Sam `pg_dump` | **30,22 s** |
| Cały skrypt kopii (zrzut, odczyt katalogu, szyfrowanie, `.meta`) | 32,32 s |
| Odszyfrowanie, według zegara istniejącego skryptu | 6 s |
| Sam `pg_restore` | **56,94 s** (skrypt: 57 s) |
| Liczniki wszystkich tabel, migracje, bariery i sondy | **2,62 s** |
| Cały skrypt odtwarzania, z odszyfrowaniem i kontrolami | 66,73 s |
| Dodatkowe SHA-256 pełnej treści wszystkich tabel źródła i celu | **76,54 s** |
| Weryfikacja po restore łącznie (kontrole + treść) | **79,16 s** |
| Kopia + cały skrypt odtwarzania + pełne porównanie treści | **175,58 s ≈ 2 min 56 s** |

Zastrzeżenie wprost: ostatnia suma **nie zawiera** przygotowania danych ani
dodatkowych kontroli ujemnych, przeglądu schematu i odczytu przez Eloquent.
Nie sumować ponownie wierszy „cały skrypt” z ich etapami składowymi.

| Wielkość | Bajty |
|---|---:|
| Baza źródłowa przed zrzutem (`pg_database_size`) | 2 747 324 095 |
| Archiwum `pg_dump --format=custom` | 426 731 512 |
| Szyfrogram CMS/RSA | 427 148 807 |

Zmierzono na: PostgreSQL/klienci 18.6, WSL2, Intel Core Ultra 7 270K Plus,
24 procesory logiczne, 31 GiB RAM widoczne w WSL, ok. 382 GiB wolnego dysku
WSL. Host współdzielony i obciążony: load 1 min **32,06 → 32,37**, w tym
zwykłe testy tej gałęzi na innej bazie. Zastrzeżenie wprost: **to jeden
pomiar w zastanych warunkach, nie pomiar izolowanej wydajności ani gwarancja
czasu maksymalnego.** Przyrząd używa zegara monotonicznego; precyzja
ułamków sekund nie jest gwarancją dokładności narzędzia.

**RPO:** brak utraty wierszy w tym zamrożonym zbiorze, ale **produkcyjnego
RPO nie zmierzono** — nie ma tu harmonogramu ani odczytu wieku produkcyjnej
kopii.
**RTO:** zmierzono lokalne etapy, **nie** produkcyjny czas odzyskania
usługi. Brakuje wykrycia awarii, dostępu operatora, pobrania offsite,
odtworzenia infrastruktury, kluczy aplikacji, mediów, kolejek i
przełączenia ruchu.

### Co dokładnie zweryfikowano

1. Istniejące skrypty kopii i odtwarzania, bez podmieniania `pg_dump`,
   `pg_restore` lub ich wyniku. Klucz testowy RSA 3072, CMS; odszyfrowanie
   samym kluczem prywatnym, zgodność SHA-256 z `.meta`.
2. **50/50 tabel, 5 504 654 wiersze po obu stronach**, 82 migracje
   wykonane, 0 czekających, 3 wymagane wyzwalacze, 91 CHECK, 26 UNIQUE,
   71 FK.
3. Cztery niedozwolone zapisy odrzucone z właściwej przyczyny, dozwolony
   zapis przyjęty, 0 pozostawionych wierszy sondujących.
4. SHA-256 strumienia JSONB wszystkich kolumn i wierszy każdej tabeli,
   sortowanego ze stałą kolacją `C`; zgodność źródła i celu.
5. Katalogi: po obu stronach 159 indeksów, w tym 25 częściowych,
   rozszerzenia `pg_trgm`, `pgcrypto`, `plpgsql`, `unaccent`, sesja UTC.
6. Porównanie schematu: surowe teksty `pg_dump --schema-only` **nie były
   identyczne**. PostgreSQL przepisał 37 literalnych rzutowań `varchar[]`
   na `text[]` (rzutowanie całej tablicy → rzutowanie jej elementów). Po
   normalizacji wyłącznie tego zapisu i losowych tokenów `\restrict` skróty
   są identyczne. Wszystkie 37 par wyrażeń dodatkowo porównano w PostgreSQL
   przez `IS NOT DISTINCT FROM` — wszystkie równe. Nie usuwano definicji
   ograniczeń ani indeksów z porównania.
7. Odczyt aplikacyjny przez `php artisan tinker` na bazie odtworzonej:
   `Recipe::where('slug', 'dr594-zupa-1')->withCount(['ingredients','steps'])`
   zwrócił przepis „Zupa warzywna — próba 1”, **8 składników i 4 kroki**.
   To odczyt modelu i relacji, nie test interfejsu ani pełny odbiór
   aplikacji.

### Kontrole ujemne — wykonane, nie deklarowane

- Brak jednego komentarza: najpierw zgodność, potem `DELETE 1`, kod **63**,
  dokładnie `comments: 2000060` wobec `2000061`, przywrócenie dokładnego
  wiersza i ponowna zgodność.
- Podmiana tekstu jednego komentarza przy tej samej liczbie wierszy:
  manifest zmienia wyłącznie `comments`; po przywróceniu wszystkie 50
  manifestów identyczne z wynikiem sprzed mutacji.
- Uszkodzenie bajtu w środku szyfrogramu: kod **44**, niewłaściwy SHA-256;
  baza `..._uszkodzony` **nie powstała**.
- Ponowne generowanie: kod **3**, odmowa przed zapisem.
- Generator skierowany na cel zamiast źródła: kod **3**, odmowa przed
  zapisem.

Nowy przyrząd dwukrotnie zatrzymał się przed kopią podczas przygotowania
(porównanie tekstu adresu `127.0.0.1/32` z `127.0.0.1`, brak katalogu kopii
kod 21) — to błędy nowego przyrządu, nie zastane usterki odtwarzania. Udany
pomiar wykonano po tych poprawkach.

### Testy i przekazanie

- `php artisan test` (filtr wykluczający `ProbaOdtworzeniaTest`): **4393
  passed, 83 692 asercje, 518,53 s**.
- `vendor/bin/pint`: **PASS, 1155 plików**.
- Brak zmian schematu → nie potrzeba migracji ani zmiany `DATABASE.md`.
- Sprzątnięto odtworzoną bazę po końcowym potwierdzeniu tożsamości.
  **Pozostawiono** syntetyczne źródło `kuking_flota_gpt_dr_baza_source` oraz
  katalog artefaktów `/home/mateusz/flota/gpt-dr-baza-artifacts/20260920T173937Z`
  do powtórzenia pomiaru — katalog zawiera też testowy klucz prywatny
  (dopuszczalne tylko dlatego, że nie ma w nim danych produkcyjnych; to nie
  model składowania klucza produkcyjnego, który musi być niezależny od
  kopii — D-049).
- Nie wykonano push, PR, zmian produkcji, odczytów jej danych/paneli,
  wystawienia portów, wysyłki wiadomości ani uruchomienia `kopia-bazy`.

### Co runbook wymaga OD WŁAŚCICIELA (otwarte decyzje)

Raport zastrzega wprost: stan produkcji pozostaje niezweryfikowany; odczyty
panelu z 17 IX i dokumentacji z 18 IX to **[pomiar cudzy:
KOPIE_I_ODTWORZENIE.md §5.2–5.3]**, nie sprawdzenie wykonane w tej sesji.

| Decyzja / czynność właściciela | Warianty i koszt operacyjny |
|---|---|
| Miejsce offsite | Osobny prywatny bucket R2 zgodnie z D-043/D-049; ustalić konto, region/jurysdykcję i niezależność dostępu od Railway. Dodatkowa kopia u drugiego dostawcy zwiększa niezależność, ale wymaga drugiego utrzymania i rachunku. |
| Dostęp | Wskazać operatora i zastępcę; token zapisu tylko dla kopii, odczytu dla czujki. Klucz prywatny w dwóch niezależnych miejscach, poza Railway. Przećwiczyć odzyskanie bez dostępu do konta Railway. |
| Częstotliwość / RPO | Kod przewiduje `17 2 * * *` UTC. Codziennie: do doby zapisów między udanymi kopiami; częściej: niższy możliwy RPO, więcej odczytów, transferu i operacji. Awaria harmonogramu wydłuża RPO — sam cron nie daje gwarancji. |
| Retencja | Kod przewiduje 30 dni i minimum 7 kopii. Dłuższa retencja = dłuższe okno odzyskania, ale więcej przechowywania i kosztu. Te wartości są stanem kodu, nie dowodem ustawienia produkcji. |
| Alarm i odpowiedzialność | Ustalić odbiorcę i czas reakcji; osobno alarm nieudanego przebiegu oraz czujkę braku świeżej kopii. Kontrolowany test alarmu wymaga zgody na wysłanie wiadomości. |
| Railway Volume Backups / PITR | Sprawdzić aktualny panel, dostępność i koszt na konkretnym planie. Nie przyjmować historycznego zdania „tylko Pro” jako aktualnego faktu. |
| RTO i budżet | Określić dopuszczalny przestój oraz zasoby celu. Lokalny czas `pg_restore` nie obejmuje wykrycia awarii, odzyskania dostępu/kluczy, pobrania offsite, uruchomienia infrastruktury ani przełączenia ruchu. |
| Produkcyjna próba | Wskazać izolowane, autoryzowane środowisko poza tą maszyną; odtworzyć prawdziwą kopię bez publicznego portu DB. Najpierw plan #595/staging, następnie zatwierdzone uruchomienie `kopia-bazy`. |

Zastrzeżenie z runbooka: odbiór produkcyjny wymaga daty, identyfikatora i
wieku rzeczywistej kopii, czasu każdego etapu, liczników, migracji,
sprawdzenia danych/relacji, próby aplikacji bez wychodzącej poczty i zadań
oraz dowodu kolejnego przebiegu automatycznego. **Wartości RTO/RPO mają
zostać zaakceptowane przez właściciela, nie przez test.**

---

## 3. Monitoring i budżet połączeń (#598 / #599)

Data: 20.09.2026. Gałąź `gpt/monitoring`, baza kodu
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Raport zastrzega wprost: **to
jest pomiar lokalny i instrukcja wdrożenia, nie odbiór produkcji.**

### #598: liczby zmierzone samodzielnie

Środowisko: PHP 8.4.24, PostgreSQL 18.6, `127.0.0.1:55439`, baza
`kuking_flota_gpt-monitoring`, użytkownik `kuking`. Sesje, cache i kolejka:
`database`. Dane: 200 publicznych wpisów, 40 przepisów, 12 lokalnych JPEG
2400×1600 (3,84 Mpx). Nie wysłano poczty.

Próbnik co ok. 5 ms, każde zapytanie w osobnej transakcji; liczy wyłącznie
`client backend` własnej bazy, bez swojego PID. Inne stanowiska nie wchodzą
do wyniku, ale limit klastra jest wspólny dla wszystkich baz.

| Uruchomiony scenariusz | Szczyt zajętych | Szczyt aktywnych | Szczyt idle | Dowód pracy |
|---|---:|---:|---:|---|
| Spoczynek przed próbą | 0 | 0 | 0 | 90 próbek |
| Kontrola dynamiczna: 6 procesów uruchomionych już po starcie próbnika | 6 | 6 | 1 | 468 próbek, każdy proces zakończony poprawnie |
| Jeden proces obsługi HTTP, 60 żądań | **1** | 1 | 1 | 20 × `/odkryj`, 20 × `/szukaj?q=pierogi`, 20 × rzeczywisty przepis; wszystkie HTTP 200 i niepuste HTML |
| Jeden `queue:work`, kolejka `media` | **1** | 1 | 1 | 12 rzeczywistych `ProcessUploadedImage`, 12 zdjęć `ready`, 0 jobs, 0 failed_jobs |
| Jeden `schedule:run`, 10 przebiegów | **1** | 1 | 1 | ustawiona minuta :25, uruchamiane czujka i liczniki, 424 próbki |
| `migrate --force` bez oczekujących migracji | **1** | 1 | 1 | pomiar samego sprawdzenia migracji, nie koszt dowolnej przyszłej migracji |
| Spoczynek po próbie | 0 | 0 | 0 | 113 próbek |

Zastrzeżenie: maksima aktywnych i idle występują w różnych chwilach, nie
należy ich sumować. Połączenie zajęte obejmuje oba stany.

Lokalny limit: `max_connections=100`, rezerwa superusera 3, rezerwa zwykła
0, czyli **97 miejsc poza rezerwami**. Sprostowanie w raporcie: wcześniejsze
stwierdzenie „nie połączy się nikt, łącznie z administratorem” było zbyt
szerokie — rezerwa może umożliwić wejście administratorowi nawet po
wyczerpaniu miejsc zwykłych użytkowników.

Żądania lokalne: p50 **162 ms**, p95 **245 ms**, p99 **265 ms**
(nearest-rank, 60 próbek). Zastrzeżenie: to pomocniczy pomiar małego zbioru
na współdzielonej maszynie, nie SLO produkcji ani test maksymalnej
przepustowości. Serwer to `php -S`, **nie FrankenPHP**; zmierzono jeden
slot wykonania PHP. Nie zmierzono produkcyjnych wątków, TLS, Cloudflare,
zdalnego R2 ani logowania.

### Ile replik mieści się w puli — LICZBA Z #598 (obliczenie, nie pomiar)

[pomiar cudzy: komentarze #598 z 17.09 i
`WERYFIKACJA_BUDZETU_POLACZEN_598.md` §1a z 19.09] Produkcja miała limit
500, rezerwy 3 + 0 i FrankenPHP `max_threads=4`. Raport zastrzega: **nie
odczytano produkcji w tej sesji; liczby mogą być nieaktualne.** Jeden
wielowątkowy proces FrankenPHP nie jest jednym slotem PHP: dla czterech
równoległych wątków przyjęto cztery połączenia na replikę web.

Wzory (R replik web, Q procesów kolejki, S procesów harmonogramu):

```
zwykły szczyt       = 4R + Q + S
budżet wdrożeniowy  = 2 × (4R + Q + S) + 1 migracja + 3 administracja
```

Mnożnik 2 to konserwatywne założenie (stary + nowy komplet podczas
wdrożenia), **nie zmierzony szczyt wdrożenia**.

| Topologia po rozdzieleniu ról | R / Q / S | Zwykły szczyt | Budżet wdrożeniowy | Z 497 miejsc |
|---|---|---:|---:|---:|
| Podstawowa #595 | 1 / 1 / 1 | 6 | **16** | 3,2% |
| Druga replika web | 2 / 1 / 1 | 10 | **24** | 4,8% |
| Osobny dodatkowy media worker | 1 / 2 / 1 | 7 | **18** | 3,6% |
| Obie zmiany | 2 / 2 / 1 | 11 | **26** | 5,2% |

**Sprostowanie „ponad stu replik”** (dokładnie z raportu): przy Q=S=1 wzór
to `8R+8`. W 497 mieści się najwyżej **61 replik web** (budżet 496), a w
lokalnych 97 — **11** (96). Obie liczby oznaczają niemal całkowite
wyczerpanie puli, więc **nie są bezpiecznym limitem skalowania** — nie
uwzględniają RAM/CPU, limitów planu Railway ani zajętości przez inne
aplikacje.

Przy obecnym ostrzeżeniu 50 planowany budżet powinien być **poniżej 50**:
mieszczą się maksymalnie **5 replik web** (48), a szósta daje 56 i wymaga
nowego pomiaru oraz przeglądu progów. Przy dodatkowym workerze Q=2 pięć
daje 50, więc bez zmiany progu mieszczą się **4**.

### Próg i czas wykrycia

Skonfigurowane progi produkcyjne **50 / 125**, budżet **16**: 50 jest ponad
trzykrotnością budżetu 16, a 125 to ok. 25% historycznych 497 miejsc.
Dodatkowy web i worker dają 26, więc zostaje 24 miejsc przed pierwszym
alarmem i 471 do granicy puli.

Zastrzeżenie ograniczenia pomiaru: obecna czujka połączeń chodzi **raz na
godzinę**, kolejki co **15 minut**. Próg nie gwarantuje reakcji przed
wyczerpaniem przy szybkim wzroście. Zaległość 600 s może zostać zauważona
dopiero niemal 25 minut po powstaniu zadania. Zalecenie (rekomendacja do
wdrożenia, harmonogram niezmieniony w tym pakiecie): pomiar obu co minutę,
połączenia co 1 s podczas okna wdrożenia.

Na lokalnym klastrze 100 próg 125 zostaje w kodzie obcięty do 97 — alarm
krytyczny przychodzi dopiero przy pełnej puli zwykłych użytkowników.
Zastrzeżenie: **nie kopiować 50/125 do mniejszego planu**. Dla samodzielnej
instancji 100 z budżetem 16 proponowane wartości: 25/50.

### Co naprawiono i co naprawdę odebrano

Przed poprawką dwa testy `AlarmPrzyAwariiCacheTest` kończyły się
`QueryException` przy `Cache::get`, przed wysyłką (prawdziwy PostgreSQL,
nieistniejąca tabela cache w izolowanej bazie). `AlarmMemory` toleruje
teraz niedostępny odczyt/zapis/usunięcie pamięci wyciszania; obie istniejące
klasy alarmu nadal sprawdzają rzeczywiste potwierdzenie HTTP 2xx. Bez nowej
tabeli, pakietu, migracji lub zmiany progów.

**KONTROLA DODATNIA transportu — WYKONANA:** 16 przypadków, dokładnie 12
odebranych wiadomości przez prawdziwy serwer HTTP `127.0.0.1:8599`, bez
`Http::fake`. Próby połączeń zaniżają progi tylko w pamięci lokalnego
procesu do 1; liczba zajętych pochodzi z rzeczywistego `pg_stat_activity`.

| Sygnał / próba | Wynik |
|---|---|
| `kuking:sprawdz-alarm` | 1 wiadomość próbna |
| Przekroczony próg ostrzegawczy / krytyczny | po 1 wiadomości; eskalacja natychmiast |
| Powtórzone ostrzeżenie | 0 dodatkowych wiadomości |
| Powrót połączeń do normy | 1 odwołanie |
| Gotowe zadanie media czeka 900 s przy progu 600 | 1 wiadomość |
| Powtórzona zaległość | 0 dodatkowych wiadomości |
| Świeży failed job | 1 wiadomość |
| Rezerwacja starsza niż próg | 1 wiadomość |
| Powrót do normy po każdym stanie kolejki | po 1 odwołaniu |
| Brak dostępu do bazy i cache jednocześnie | po 1 wiadomości z obu komend |
| Spokojne stany początkowe | cisza |

Zastrzeżenie: odbiornik HTTP nie dowodzi odbioru przez człowieka, działania
Discorda ani TLS.

Weryfikacja poprawki: **4399 testów, 83 710 asercji, wszystkie zaliczone**
w 506 s na PostgreSQL (pominięto wyłącznie `ProbaOdtworzeniaTest`). Zestaw
alarmów: 103 testy, 453 asercje. Pint: 6 plików; PHPStan: trzy klasy
alarmów i nowy test, bez błędów. Dowód kontroli ujemnej: celowa mutacja
powoduje błąd `CACHE_BLOKUJE_ALARM`; po odtworzeniu test przechodzi.
Niezależne porównanie MD5 pliku w worktree i runtime dało
`d349de59801d55e09b64299e93902b2e`, zgodne z `md5_przed`. Pole
`przywrocenie` istniejącego skryptu pozostaje historycznie „nie wykonane”,
bo zapis JSON poprzedza końcowy trap.

Powtórzenie końcowego przyrządu ponownie dało szczyty **1/1/1**, kontrolę
dynamiczną **6** i **12** odebranych alarmów.

### Konkretny odbiorca i postępowanie po alarmie — propozycja do zatwierdzenia (NIE zrealizowane)

Proponowany kanał: prywatny Discord `#kuking-alarmy`, powiadomienia na
telefonie właściciela. Adres webhooka ze zakończeniem `/slack` jako
`LOG_BLAD_WEBHOOK_URL`, osobny kanał staging. Właściciel wskazuje osobę
zastępującą przy braku reakcji w 10 minut. Zastrzeżenie wprost: **nie
zakładano serwera, nie tworzono webhooka i nikomu nic nie wysłano.**

Odbiór wymaga, aby wskazana osoba odczytała znacznik czasu próbnej
wiadomości z telefonu z zablokowanym ekranem; bez tego kontakt ma status
„niepotwierdzony”, nawet przy HTTP 200. Nie zakładano, że proponowane w
starym runbooku `alerty@kuking.pl` istnieje.

| Alarm | Pierwsza reakcja człowieka |
|---|---|
| Połączenia ≥50 | Wstrzymaj skalowanie; `kuking:budzet-polaczen --bez-alarmu`, sprawdź liczbę replik i trwający deploy; porównaj aktywne/idle/idle-in-transaction. Nie zabijaj wszystkich sesji. |
| Połączenia ≥125 albo brak bazy | Sprawdź stan usługi PostgreSQL i ostatnie wdrożenie; ogranicz nowe procesy do poprzedniej topologii. Użyj rezerwowego dostępu administracyjnego. |
| Kolejka | `kuking:sprawdz-kolejke --bez-alarmu`, stan workera i logi, następnie `kuking:martwe-zadania`. Nie wykonuj zbiorowego retry starych resetów haseł. |
| Uptime | Sprawdź domenę z innej sieci oraz stan Railway/Cloudflare; porównaj `/` i `/health`; rozważ powrót do poprzedniej wersji. |
| Pamięć / CPU / restart | Ustal usługę i replikę, sprawdź OOM oraz ostatni job; nie dokładaj procesów przed sprawdzeniem budżetu bazy. |
| Koszt | Sprawdź usługę powodującą wzrost, preview i transfer; usuń przyczynę lub jawnie zaakceptuj nowy budżet. |

### Wdrożenie i koszt — propozycje, bez wykonania zmian w panelach

1. Kanał aplikacji: wdrożyć kod przez kolejkę floty, ustawić sekret,
   restart, `php artisan kuking:sprawdz-alarm`.
2. Uptime poza Railway: proponowany Better Stack Free — 10 monitorów, 10
   heartbeatów, sprawdzanie co 3 min, **0 USD/mies.** Darmowy interwał
   **nie spełnia alarmu w 2 minuty**; jeśli wymóg, płatny wariant 60 s po
   potwierdzeniu ceny w panelu.
3. Dashboard Railway: Observability, „Start with a simple dashboard”.
   Alerty zasobowe wymagają Pro: **Pro min. 20 USD/mies., obejmuje 20 USD
   zużycia** — to nie dodatkowe 20 USD do każdego serwisu. Nie sprawdzono
   obecnego planu.
4. Koszt: Workspace Usage → Set Usage Limits → Custom email alert.
   Propozycja: 80% zatwierdzonego budżetu B (np. 40 USD przy B=50 USD).
   Hard limit wyłącza usługi, więc nie zastępuje ostrzeżenia.
5. Częstość: minutowy pomiar czujek przed wzrostem — 2880 linii/dobę zamiast
   120.

### #599 — próby alertów zewnętrznych, bramka wdrożenia — WERDYKT O KONTROLACH DODATNICH

Raport definiuje wymóg: każda próba ma mieć zdrowy stan → przekroczenie →
odebraną wiadomość ze znacznikiem czasu → powrót → odwołanie. Brak
wiadomości to porażka.

| Alert | Proponowany próg początkowy | Kontrola dodatnia na stagingu / w panelu | Stan tutaj |
|---|---|---|---|
| Dostępność `/` i `/health` | 2 kolejne nieudane próby; Free: do ok. 6 min + timeout, bez gwarancji 2 min | Osobny monitor stagingu: zdrowe 200, kontrolowane 503, potem 200; potwierdzenie obu wiadomości przez osobę dyżurną | **niewykonana — brak konta/zgody na uruchomienie usługi** |
| CPU | >80% limitu przez 10 min | Zaniżyć próg tylko monitora stagingu poniżej zmierzonego zużycia, poczekać pełne okno, odebrać alarm, przywrócić | **niewykonana** |
| RAM | >80% limitu przez 5 min; OOM natychmiast | Jak CPU; osobno kontrolowany restart stagingu | **niewykonana** |
| Dysk DB | >80% zajętości | Tymczasowy próg poniżej bieżącego zużycia stagingu | **niewykonana** |
| Nieudane wdrożenie / crash | zdarzenie platformy | Nieudany deploy osobnego stagingu; odbiór powiadomienia platformy | **niewykonana** |
| Koszt | 80% B | Funkcja testowa dostawcy lub uzgodniony tymczasowy soft limit poniżej bieżącego zużycia | **niewykonana** |
| p95/p99 HTTP | p95 >1 s lub p99 >2 s przez 10 min, ≥100 żądań/okno | Kontrolowane opóźnienie stagingu | **niewykonana; próg do kalibracji** |

**WERDYK Z RAPORTU:** dla WSZYSTKICH siedmiu alertów zewnętrznych z tej
tabeli (Alert §7) kontrola dodatnia jest **BRAK / niewykonana** — żadna
próba przekroczenia progu z odebraną wiadomością nie została
przeprowadzona w tej sesji. Jedyna wykonana i udokumentowana kontrola
dodatnia w całym pakiecie dotyczy transportu wewnętrznego alarmu (§4,
16 przypadków / 12 odebranych wiadomości opisane wyżej) — nie dotyczy
alertów zewnętrznych z §7.

### Pozostały zakres #599 — jawne luki

Railway mierzy zasoby, a nie automatycznie p50/p95/p99 czy error rate
aplikacji.

| Brak | Wariant minimalny / koszt pracy i ograniczenie |
|---|---|
| Percentyle HTTP, 4xx/5xx, RPS, concurrency, klasy feed/search/recipe/media | Instrumentacja z nazwą trasy, czasem, kodem i znacznikiem repliki; bez URL, query string, IP i użytkownika. Potrzebny osobny pakiet implementacji i pomiar narzutu. Nie instalowano SDK. |
| DB time / p95 zapytań | Mierzyć czas bez SQL/bindings. `pg_stat_statements` daje agregaty, nie p95 sam z siebie. Nie włączano. |
| Lock waits / deadlocks / IOPS | Liczbowe próbki `pg_stat_activity`/`pg_stat_database`, osobno źródło I/O hosta. |
| Kolejki oddzielnie / throughput | Obecna czujka agreguje wszystkie kolejki i nie liczy wykonanych jobów. |
| Cloudflare/R2 | Osobne dashboardy cache hit, origin requests, transferu i błędów. Nie wykonywano odczytu konta. |
| Brak schedulera | Czujka uruchamiana przez martwy scheduler nie wykryje własnego zatrzymania. Potrzebny heartbeat do niezależnego monitora. |

Koszt lokalnego pakietu: zero nowych usług i zależności. Nie wyceniono
pracy nad pełnym APM ani przyszłego transferu bez pomiaru ruchu. Decyzje
właściciela: odbiorca i zastępstwo, akceptowalny czas reakcji, budżet B
oraz wybór wariantu metryk.

Nie wykonano push, PR, wdrożenia, zmian kont dostawców, wysyłek do ludzi,
pomiaru produkcji ani odczytu jej sekretów. **#598 pozostaje bez aktualnego
produkcyjnego peaku i #599 bez odebranych alertów zewnętrznych.**

---

## Podsumowanie do meldunku

- Wszystkie trzy pliki źródłowe odnalezione, brak brakujących plików do
  zgłoszenia.
- Dług #713/#492: 19 pozycji A1–D8 (D8 z czterema podzakresami) przepisane
  ze stanem faktycznym i dowodem; pozycje z jawnym BRAK DOWODU wypisane
  wyżej (m.in. A2, A3 częściowo, B1, B4, C1, C2, C3, C4, D4, D5, D6, D8a,
  D8b, D8c, D8d). Sześć rozjazdów decyzji/dowodów (D-183, D-205 ×2, D-217,
  #598, D-206) wypisanych osobno — raport zastrzega, że nie potwierdzono
  nowego cichego odwrócenia decyzji przez kod.
- Odtwarzanie #594: kluczowa suma **175,58 s ≈ 2 min 56 s** (kopia + cały
  skrypt odtwarzania + pełne porównanie treści) na zbiorze **5 504 654
  wierszy**; RTO/RPO produkcyjne niezmierzone; runbook wymaga od właściciela
  decyzji o miejscu offsite, dostępie, częstotliwości/RPO, retencji,
  alarmie, stanie Volume Backups/PITR, RTO i budżecie oraz autoryzowanym
  środowisku próby produkcyjnej.
- Monitoring #598: obliczony (nie zmierzony) budżet wdrożeniowy **16** dla
  topologii podstawowej #595 (R=1,Q=1,S=1); wzór `budżet = 2×(4R+Q+S)+1+3`;
  przy Q=S=1 wzór uproszczony `8R+8`; limity puli 497/97 miejsc.
  Kontrola dodatnia WYKONANA tylko dla transportu wewnętrznego (12/16
  odebranych); dla WSZYSTKICH siedmiu alertów zewnętrznych z §7 kontrola
  dodatnia jest BRAK (niewykonana).
