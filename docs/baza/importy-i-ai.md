# Importy przepisów i budżet AI

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### importy_przepisow
Jedno zlecenie odczytu przepisu (V2 — OCR zdjęcia kartki; później adres strony
i PDF), migracja `2026_09_26_100000_create_importy_przepisow_table`,
`docs/DECISIONS.md` **D-298** (architektura), **D-297** (limity), **D-296** (zgoda).

**Wiersz nie niesie treści przepisu.** Treść od pierwszej chwili stoi w zwykłym
szkicu (`recipes`, `status = draft`, `visibility = private`), a zdjęcie kartki
w `recipes.source_scan_media_id` — zapisane, ZANIM cokolwiek pójdzie do modelu.
Dlatego tabela celowo **nie wskazuje na `media`**: zdjęcie ma już wszystkie
ochrony skanu kartki (eksport, kasowanie z kontem, `DostepDoZdjecia`,
`KasujZdjecie`), a nowy rodzic zdjęcia trzeba by dopisać w pięciu miejscach.

| Kolumna | Znaczenie |
|---|---|
| `id` | `uuid`, `DEFAULT gen_random_uuid()`. Widoczny w adresie ekranu postępu `/import/{import}` — wejście i tak idzie przez właściciela (UUID ≠ autoryzacja). |
| `user_id` | `uuid NOT NULL`, FK `users` `ON DELETE CASCADE`. Anonimizacja konta (`EraseAccountData`) kasuje wiersze jawnie. |
| `recipe_id` | `uuid NULL`, FK `recipes` `ON DELETE SET NULL` — szkic, do którego trafia odczyt. |
| `zrodlo` | `zdjecie` \| `url` \| `pdf`. CHECK `importy_przepisow_zrodlo_check`. |
| `status` | `oczekuje` \| `w_toku` \| `gotowy` \| `nieudany` \| `wstrzymany_limitem`. CHECK. **Poza `$fillable`** (AGENTS.md §7). |
| `kod_bledu` | Zamknięta lista (CHECK `importy_przepisow_kod_bledu_check`): `limit_osoby`, `budzet_dzienny`, `budzet_miesieczny`, `brak_zgody`, `wylaczony`, `model_niedostepny`, `nieczytelne`, `odpowiedz_bledna`, `zdjecie_niedostepne`, `szkic_zmieniony`, `blad_wewnetrzny` oraz — od migracji `2026_09_29_120000_extend_importy_przepisow_kod_bledu_o_adres` (#28) — siedem powodów odmowy odczytu strony przy `zrodlo = 'url'`: `adres_nieprawidlowy`, `adres_niepubliczny`, `strona_niedostepna`, `za_duzo_przekierowan`, `za_duza_strona`, `za_dlugo`, `nie_strona` (te same napisy co `ImportOdrzucony::*`; zdanie dla człowieka wylicza z nich `KomunikatImportu`) oraz — od migracji `2026_09_29_150000…` (#28 etap 2) — siedem powodów odmowy odczytu pliku przy `zrodlo = 'pdf'`: `pdf_za_duzy`, `pdf_za_duzo_stron`, `pdf_uszkodzony`, `pdf_zaszyfrowany`, `pdf_bez_tekstu`, `pdf_brak_przepisu`, `narzedzie_pdf_niedostepne`. CHECK `importy_przepisow_kod_przy_bledzie_check`: kod jest **dokładnie** przy `nieudany`/`wstrzymany_limitem`. |
| `source_url` | `text NULL`, tylko przy `zrodlo = 'url'` (CHECK). Adres wpisany w formularzu importu z adresu — **wejście zadania `ImportujPrzepisZAdresu`** (worker odtwarza je stąd po restarcie; w zadaniu w kolejce adresu nie ma). Zerowany w każdym stanie końcowym (sukces, odmowa, `failed()`, `kuking:odzyskaj-importy`), więc nie zostaje dłużej, niż trwa zlecenie. Adres po przekierowaniach i bez śledzenia zostaje w `przepisy_z_importu.source_url` i `recipes.source_url`. |
| `plik_tymczasowy` | `text NULL`, tylko przy `zrodlo = 'pdf'` (CHECK `importy_przepisow_plik_check`; migracja `2026_09_29_150000_add_plik_tymczasowy_and_kody_pdf_to_importy_przepisow`, #28 etap 2). Ścieżka wysłanego PDF-a na prywatnym dysku importu (`kuking.import.pdf.dysk`, katalog `kuking.import.pdf.katalog` = `import-pdf-tmp/`) — **wejście zadania `ImportujPrzepisZPdf`** (worker czyta plik stąd, nie z `UploadedFile`). Plik jest kasowany w każdym stanie końcowym (sukces, odmowa, `failed()`, wyłączone źródło), przy usunięciu konta i po retencji; dopiero po skasowaniu ścieżka jest zerowana (dysk odmówił = ścieżka zostaje, ponawia `kuking:odzyskaj-importy`). Indeks częściowy `importy_przepisow_plik_idx (updated_at) WHERE plik_tymczasowy IS NOT NULL`. |
| `proby` | Liczba prób wywołania modelu (ponowienia przy 429/5xx/timeout). Zwiększana w tej samej transakcji co rezerwacja budżetu — numer próby jest częścią klucza `ai_rezerwacje (import_id, proba)`. |
| `koszt_mikrousd`, `tokeny_wejscia`, `tokeny_wyjscia` | Suma rozliczeń wszystkich prób (z `usage`, a bez niego cała rezerwacja). Dopisywana w tej samej transakcji co rozliczenie budżetu (`RozliczenieOdczytu`). Kwota ≥ 0 (CHECK `importy_przepisow_kwoty_check`). Rezerwacje **nie** stoją w tym wierszu — są w `ai_rezerwacje`. |
| `odpowiedz_modelu` | `jsonb NULL` — odpowiedź bez rozumowania, do diagnozy błędów odczytu. Może zawierać tekst z kartki → **30 dni**, potem `NULL`. Zapisana = etap „odczytano” zamknięty: ponowienie zadania dokańcza z niej, **bez drugiego płatnego żądania** (#1980). |
| `klucz_wyslania` | `uuid NULL`; `UNIQUE (user_id, klucz_wyslania) WHERE klucz_wyslania IS NOT NULL` — jedno wysłanie formularza = jedno zlecenie. |
| `rozpoczeto_at`, `zakonczono_at`, `created_at`, `updated_at` | `timestamptz`. |

Indeksy: `(user_id, created_at DESC)` — limit na osobę (5 dziennie / 30
miesięcznie liczone w strefie `Europe/Warsaw`, bez zleceń `wstrzymany_limitem`);
`(created_at)` — retencja; `(recipe_id) WHERE recipe_id IS NOT NULL` — bramka
publikacji szkicu z odczytu; `importy_przepisow_przejsciowe_idx (updated_at)
WHERE status IN ('oczekuje', 'w_toku')` — odzyskiwanie porzuconych zleceń.

**Zlecenie i zadanie razem albo wcale (#1977).** `ZlecImportPrzepisu` wysyła
`OdczytajPrzepis` wewnątrz transakcji zapisu zlecenia — kolejka jest bazodanowa,
na tym samym połączeniu, bez `after_commit`, więc wiersz w `jobs` zatwierdza się
razem ze zleceniem. Zlecenie `oczekuje`/`w_toku` bez zmiany od 120 minut (zadanie
zgubione inną drogą) `kuking:odzyskaj-importy` kończy jako `nieudany` /
`blad_wewnetrzny` — ekran pokazuje „Spróbuj jeszcze raz”.

**Ochrona ręcznej edycji szkicu OCR (#2520).** Świeżo utworzony przez odczyt
szkic ma `recipes.content_revision = 0`. Udany zapis w `PublishRecipe`, nawet
samej nazwy, opisu lub liczby porcji, podnosi licznik. `OdczytajPrzepis`
sprawdza zero przed płatnym żądaniem i ponownie na świeżym wierszu pod blokadą
przed wpisaniem wyniku. Wyższa rewizja kończy istniejącym kodem
`szkic_zmieniony`; zapisany tekst autora i zdjęcie zostają. `updated_at`
nie jest dowodem nietkniętej treści, bo zmieniają go także zapisy techniczne.
Ponowienie odczytu na ręcznie edytowanym szkicu jest odrzucane przed nowym
zleceniem. Nie ma nowej kolumny ani migracji; wycofanie kodu przywróciłoby
ryzyko nadpisania.

**Import z adresu w kolejce (#28).** Zlecenie z `zrodlo = 'url'` powstaje od razu
po wysłaniu formularza (`ZlecImportZAdresu`: blokada osoby, miejsce w
`proby_importu`, wiersz zlecenia z `source_url`, zadanie `ImportujPrzepisZAdresu`
— jedna transakcja), a `recipe_id` jest `NULL`, dopóki zadanie nie zapisze
szkicu; szkic i `gotowy` idą razem w jednej transakcji. Robots.txt i „brak
przepisu na stronie” kończą zlecenie jako `gotowy` ze szkicem z samym źródłem
(`przepisy_z_importu.droga = 'bez_tresci'`), każda inna odmowa — `nieudany`
z jednym z siedmiu kodów wyżej. Rezerwacja budżetu przy stronie bez danych
`Recipe` ma w `ai_rezerwacje.import_id` identyfikator wiersza `proby_importu`
(nie zlecenia), dlatego `failed()` zadania domyka księgę po nim.

**Import z PDF w kolejce (#28, etap 2).** Zlecenie z `zrodlo = 'pdf'` powstaje tak samo
(`ZlecImportZPdf`), z tą różnicą, że wejściem jest PLIK: kontrole tanie (rozmiar,
sygnatura `%PDF-`) idą w żądaniu WWW, plik trafia na prywatny dysk importu PRZED
transakcją (zapis do zdalnego bucketu nie trzyma blokady osoby), a jego ścieżka do
`plik_tymczasowy`. `pdfinfo`/`pdftotext`/`pdftoppm` i ewentualny odczyt skanu przez
model (tylko za zgodą z tego formularza; PDF z warstwą tekstu nie opuszcza serwisu)
robi zadanie `ImportujPrzepisZPdf` na kopii roboczej pobranej z dysku. **Retencja pliku
(#2051):** plik znika po sukcesie, po odmowie, w `failed()`, przy usunięciu konta
(`EraseAccountData`), a `kuking:odzyskaj-importy` (co kwadrans) kasuje (a) pliki zleceń
w stanie końcowym, które jeszcze go mają, (b) pliki zleceń starszych niż retencja
(`max(kuking.import.pdf.retencja_godzin, zlecenie_minut + 60 min)`) i (c) pliki
z katalogu importu, których nie wskazuje żaden wiersz, a które są starsze niż retencja
(zapis przyjęty przed powstaniem wiersza). Sprzątanie NIE dotyka `livewire-tmp/`,
`incoming/` ani publicznych wariantów — kasuje wyłącznie w katalogu importu.

**Retencja** (`kuking:sprzataj-importy`, codziennie 06:00): `odpowiedz_modelu`
→ `NULL` po 30 dniach, wiersz znika po 90. **Wyjątek:** wiersz, którego szkic
jest nadal szkicem, zostaje (bez surowej odpowiedzi), bo jest bramką publikacji
(„Odczytany tekst jest sprawdzony”) — znika najbliższym przebiegiem po publikacji
albo usunięciu szkicu.

**Eksport RODO:** sekcja `odczyty_przepisow` (źródło, stan, powód
niepowodzenia, szkic, daty) — bez surowej odpowiedzi; odczytany tekst jest
w sekcji `przepisy`, zdjęcie w `zdjecia`.

**Rollback migracji kodów adresu (#28):** `down()` nie odmawia, ale nie jest bezstratny —
zamienia siedem kodów odmowy strony na `blad_wewnetrzny` (status `nieudany` zostaje) i przywraca
wąską listę CHECK; ginie tylko dokładny powód nieudanej próby, nie treść ani limity
(`tests/Feature/CofniecieMigracjiKodowAdresuImportuTest.php`). Przed cofnięciem kodu wyłącz import
z adresu (`KUKING_IMPORT_URL=false`), a zadania czekające w kolejce dokończ albo poczekaj na
`kuking:odzyskaj-importy`.

**Rollback migracji pliku tymczasowego (#28 etap 2):** `down()` nie odmawia, ale nie jest bezstratny —
zamienia siedem kodów PDF na `blad_wewnetrzny` (status `nieudany` zostaje), usuwa kolumnę
`plik_tymczasowy` (z indeksem i CHECK) i przywraca listę kodów bez PDF; kody adresu zostają.
Pliki, które kolumna wskazywała, ZOSTAJĄ na dysku bez wiersza i po cofnięciu kodu nikt ich nie
posprząta — przed cofnięciem wyłącz import z PDF (`KUKING_IMPORT_PDF=false`), poczekaj na
dokończenie zadań `ImportujPrzepisZPdf` (albo na `kuking:odzyskaj-importy`) i w razie potrzeby
skasuj katalog `import-pdf-tmp/` na dysku importu (`tests/Feature/CofniecieMigracjiPlikuTymczasowegoImportuTest.php`).

**Rollback:** `php artisan migrate:rollback` kasuje tabelę bez odmowy. Dane są
pochodne (ślad zleceń bez treści i bez zdjęć); po ponownym `migrate` limity
na osobę liczą się od zera, co najwyżej pozwala komuś na kilka odczytów więcej
jednego dnia — dalej pod budżetem kwotowym z `ai_budzet_dzienny`. Szkice
z odczytu zostają zwykłymi szkicami (znika tylko bramka „Tekst sprawdzony”), więc
przed cofnięciem na produkcji wyłącz funkcję (`OPENAI_IMPORT_KEY=`).

### ai_budzet_dzienny
Ile pieniędzy na płatny model OpenAI poszło danego dnia (D-297), migracja
`2026_09_26_100100_create_ai_budzet_dzienny_table`. Jeden wiersz na dzień
kalendarzowy w strefie `Europe/Warsaw`.

| Kolumna | Znaczenie |
|---|---|
| `dzien` | `date PRIMARY KEY`. |
| `zarezerwowano_mikrousd` | Suma rezerwacji jeszcze nierozliczonych (1 USD = 1 000 000). |
| `wydano_mikrousd` | Suma rozliczonych kosztów z `usage`; brak `usage` = cała rezerwacja. |
| `liczba_wywolan` | Ile rezerwacji (wywołań) danego dnia. |
| `created_at`, `updated_at` | `timestamptz`. |

CHECK `ai_budzet_dzienny_kwoty_check`: wszystkie liczby ≥ 0.

**Rezerwacja bierze dwie blokady, zawsze w tej kolejności (#2013):** najpierw
blokadę **miesiąca** — `pg_advisory_xact_lock(20130, hashtext('YYYY-MM'))`,
zwalnianą z końcem transakcji — potem `SELECT … FOR UPDATE` na wierszu **dnia**.
Sam wiersz dnia szeregował tylko rezerwacje z tej samej daty, a limit miesięczny
jest wspólny: dwa odczyty z różnych dni tego samego miesiąca (tuż przed i tuż
po północy) blokowały różne wiersze, czytały tę samą sumę miesiąca i oba ją
przekraczały. Blokada miesiąca szereguje je wszystkie — drugi widzi rezerwację
pierwszego (`tests/Dwa/BudzetAiNaDwochPolaczeniachTest`). Rozliczenie
i zwolnienie rezerwacji blokady miesiąca **nie biorą** (suma miesiąca może
się przy nich tylko zmniejszyć albo zostać bez zmian), więc nie ma odwrotnej
kolejności blokad ani zakleszczenia.
Miesiąc = suma wierszy **całego miesiąca kalendarzowego** (od 1. dnia do
ostatniego, także dni po dniu rezerwacji): rezerwacja z późniejszej daty zużywa
ten sam limit. W cache'u tego nie trzymamy:
to są pieniądze, a licznik w cache'u znika przy restarcie (AGENTS.md §3 — bez Redisa).
Retencji brak: wiersz na dzień to kilkadziesiąt bajtów, a historia wydatków
jest potrzebna do rozliczeń.

**Rollback:** `down()` **odmawia**, gdy w bieżącym miesiącu są wydatki (D-088):
po ponownym `migrate` licznik zaczynałby od zera, a serwis mógłby wydać drugi
raz tyle samo. Najpierw wyłącz funkcję (`OPENAI_IMPORT_KEY=`); świadome
skasowanie: `KUKING_ROLLBACK_KASUJ_BUDZET_AI=true php artisan migrate:rollback`.
Ta sama migracja i ten sam `down()` kasują `ai_rezerwacje` (niżej) — otwarte
rezerwacje są już policzone w `zarezerwowano_mikrousd`, więc odmowa obejmuje
także je.

### ai_rezerwacje
Księga rezerwacji budżetu modelu — jeden wiersz na **jedną próbę płatnego
wywołania** (D-298 „maszyna stanów płatnego wywołania”, #1973, #1974). Ta sama
migracja co `ai_budzet_dzienny` (`2026_09_26_100100_create_ai_budzet_dzienny_table`).

| Kolumna | Znaczenie |
|---|---|
| `id` | `bigint` identity. |
| `import_id` | `uuid NOT NULL` — zlecenie z `importy_przepisow`. **Bez klucza obcego, świadomie:** usunięcie konta kasuje zlecenia, a otwarta rezerwacja musi przeżyć zlecenie, żeby sprzątanie ją domknęło (kaskada zostawiłaby kwotę w `zarezerwowano_mikrousd` na zawsze). Po skasowaniu zlecenia UUID nikogo nie wskazuje. |
| `proba` | `smallint NOT NULL`, ≥ 1 — numer próby zlecenia (`importy_przepisow.proby`). |
| `dzien` | `date NOT NULL`, FK `ai_budzet_dzienny(dzien)` `ON DELETE RESTRICT` — rozliczenie trafia do tego samego dnia, także po północy. |
| `mikrousd` | Kwota zarezerwowana (najgorszy przypadek z cennika), ≥ 0. |
| `stan` | `zarezerwowana` \| `wyslana` \| `rozliczona` \| `zwolniona` (CHECK `ai_rezerwacje_stan_check`). |
| `wydano_mikrousd` | Kwota wpisana w wydatki — **dokładnie** przy `rozliczona` (CHECK `ai_rezerwacje_wydano_check`). |
| `zamknieto_at` | `timestamptz` — **dokładnie** przy `rozliczona`/`zwolniona` (CHECK `ai_rezerwacje_zamkniecie_check`). |
| `created_at`, `updated_at` | `timestamptz`. |

Indeksy: `UNIQUE (import_id, proba)` (`ai_rezerwacje_import_proba_unique`) —
klucz idempotencji; `(created_at) WHERE stan IN ('zarezerwowana', 'wyslana')` —
sprzątanie po czasie. CHECK `ai_rezerwacje_kwoty_check`: `proba ≥ 1`, kwoty ≥ 0.

**Przejścia** — wyłącznie warunkowym `UPDATE … WHERE stan IN ('zarezerwowana', 'wyslana')`:

    zarezerwowana ──(przed żądaniem)──► wyslana ──(usage / brak usage)──► rozliczona
          └──(zgoda cofnięta, 4xx, porzucona niewysłana)──► zwolniona
    porzucona `wyslana` (failed(), następna próba, odzyskiwanie) ──► rozliczona całą kwotą

- Wiersz powstaje w **tej samej transakcji** co zwiększenie `zarezerwowano_mikrousd`
  (i co zwiększenie `importy_przepisow.proby`) — nie ma rezerwacji bez śladu (#1973).
- Drugie rozliczenie tej samej próby nie trafia w żaden wiersz, więc nie dotyka
  budżetu (#1974). Rozliczenie budżetu i zapis kosztu/odpowiedzi w zleceniu idą
  w jednej transakcji (`RozliczenieOdczytu`).
- Otwartą rezerwację domyka `OdczytajPrzepis::failed()`, początek następnej próby
  i `kuking:odzyskaj-importy` (co kwadrans, po 30 minutach).

**Retencja** (`kuking:sprzataj-importy`): wiersz **zamknięty** znika po 90 dniach;
otwartych retencja nie rusza. **Eksport RODO / kasowanie konta:** tabela nie ma
`user_id` i nie niesie treści — nie wchodzi do paczki; zlecenia znikają z kontem,
a osierocone wiersze księgi niczego nie wskazują.

**Rollback:** razem z `ai_budzet_dzienny` (wyżej) — ta sama odmowa.

### proby_importu

Wspólna księga limitu OCR, adresu i PDF (D-297/D-300). Migracja
`2026_09_28_080000_create_proby_importu_table`. Rezerwacja miejsca następuje
w transakcji pod blokadą doradczą osoby, przed pobraniem strony, odczytem
PDF albo wysłaniem OCR do modelu. `user_id` i `klucz_wyslania` tworzą unikalny
klucz powtórzenia; kolejny POST z tym kluczem nie pobiera źródła ponownie.
`zrodlo` przyjmuje `zdjecie`, `url` lub `pdf`; `status` — `w_toku`, `gotowy`
lub `nieudany`. Opcjonalne `import_id` łączy próbę z dotychczasowym zleceniem
OCR, a `recipe_id` z prywatnym szkicem. `zgoda_ai_at` zapisuje dowód zgody
udzielonej dla jednego importu strony bez JSON-LD albo skanowanego PDF;
wcześniejsza zgoda na kartkę nie upoważnia do wysłania tych źródeł. Stare OCR bez wpisu w księdze nadal
liczą się do limitu, lecz powiązane OCR liczy się tylko raz. Wiersze starsze
niż 90 dni (lub dłuższa skonfigurowana retencja zleceń) usuwa
`kuking:sprzataj-importy`; kasowanie konta usuwa je kaskadą. Rollback
odmawia usunięcia księgi, gdy są w niej próby z bieżącego miesiąca.

### przepisy_z_importu

Pochodzenie szkicu przepisu z importu — adres strony, plik PDF albo zdjęcie
(V2, **D-300**). Jeden wiersz na przepis, klucz główny `recipe_id`.
Migracja: `2026_09_26_100000_create_przepisy_z_importu_table`.

To **nie** jest dziennik zleceń importu (status, koszt, odpowiedź modelu —
ten należy do fundamentu importu i ma retencję 90 dni). Ten wiersz żyje tyle,
co przepis, bo pilnuje reguł przy publikacji (`App\Domain\Import\StrazImportu`,
wołana przez `PublishRecipe` przez kontrakt `StrazPochodzeniaPrzepisu`).

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `recipe_id` | uuid PK, FK `recipes` `ON DELETE CASCADE` | przepis (szkic) z importu |
| `user_id` | uuid, FK `users` `ON DELETE CASCADE` | kto importował; indeks `(user_id, created_at)` |
| `zrodlo` | text, CHECK `url` / `pdf` / `zdjecie` | skąd |
| `droga` | text, CHECK `json_ld` / `fragmenty` / `tekst_pdf` / `ocr` / `bez_tresci` | jak powstała treść: lokalnie z danych strukturalnych schema.org (JSON-LD, a gdy go brak — mikrodane; obie drogi zapisują `json_ld`), granice fragmentów od modelu, tekst PDF, OCR, albo szkic z samym źródłem (robots.txt zabrania / brak przepisu) |
| `source_url` | text NULL, CHECK: przy `zrodlo = url` niepusty i `^https?://`, ≤ 2000 znaków | adres po przekierowaniach, bez parametrów śledzących; ten sam trafia do `recipes.source_url` i jest tam zablokowany |
| `tekst_zrodla` | text NULL | kroki w brzmieniu ze strony — do ostrzeżenia „opis prawie taki sam jak na stronie" (`similarity()` z `pg_trgm`, próg `kuking.import.podobienstwo_ostrzezenie`); **czyszczony przy publikacji** |
| `sprawdzone_at` | timestamptz NULL | człowiek zaznaczył „Sprawdziłem odczytany tekst"; bez tego szkic się nie opublikuje |
| `pominiete` | jsonb NULL, bez CHECK — kształt pilnuje `PominieteWImporcie::zTablicy()` (migracja `2026_10_05_300000_add_pominiete_to_przepisy_z_importu`, **#2521**) | co import pominął albo uciął przez granice formularza (120 składników, 60 kroków, długości pól): `{"skladniki": 3, "kroki": 0, "obciete": ["krok:2", "tytul"]}`. **Tylko liczby i nazwy pól, bez treści** (polityka prywatności bez zmian). `NULL` = import kompletny. Czytane przy każdym otwarciu szkicu (baner „Ten import jest niepełny”, ekran postępu), a publikacja wymaga świadomego potwierdzenia. Szkic ze zdjęcia dostaje wiersz (`zrodlo = 'zdjecie'`, `droga = 'ocr'`) **tylko gdy coś pominięto** — taki wiersz nie wprowadza bramki „Sprawdziłem odczytany tekst” (zdjęcie ma własną: `BramkaPublikacjiOdczytu`). **Rollback odmawia** (D-088), gdy istnieje szkic z `pominiete` i bez `sprawdzone_at`. |
| `created_at`, `updated_at` | timestamptz | |

Model `App\Models\PrzepisZImportu` ma pusty `$fillable` — wiersz zapisuje
tylko `ZapiszSzkicZImportu` (`forceFill`), znacznik sprawdzenia —
`StrazImportu::poPublikacji()`.

**Eksport:** sekcja `importy_przepisow` w `dane.json` (bez `tekst_zrodla` —
to cudzy tekst, który i tak jest w szkicu). **Kasowanie:** kaskadą z przepisem
i kontem; przy wymazaniu konta (`EraseAccountData`, każdy zakres) wiersze są
usuwane jawnie, bo konta się nie kasuje, tylko anonimizuje.

**Rollback:** `down()` **odmawia** (D-088), gdy istnieje wiersz z pustym
`sprawdzone_at` — po cofnięciu i ponownym `migrate` taki szkic dałoby się
opublikować bez „Sprawdziłem". Na pustej tabeli i przy samych sprawdzonych
wierszach przechodzi. Test: `CofniecieMigracjiImportuTest` (odmowa i kontrola
dodatnia). Przed ręcznym zdjęciem tabeli: wyłącz import
(`KUKING_IMPORT_URL=false`, `KUKING_IMPORT_PDF=false`) i zachowaj kopię.

### wczytane_z_paczki

Ślad „ta treść przyszła z własnej paczki eksportu” — idempotencja wczytywania
(issue **#1985**, etap 2). Migracja `2026_09_29_140000_create_wczytane_z_paczki_table`.
Jeden wiersz na jedną wczytaną pozycję (przepis, wpis albo zeszyt). To skrót
i wskaźnik — **bez treści paczki**.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | uuid PK, `DEFAULT gen_random_uuid()` | |
| `user_id` | uuid NOT NULL, FK `users` `ON DELETE CASCADE` | kto wczytał; nie jest w `$fillable` (model ma pusty) |
| `rodzaj` | varchar(10), CHECK `wczytane_z_paczki_rodzaj_check` | `przepis` / `wpis` / `zeszyt` |
| `odcisk` | char(64), CHECK `wczytane_z_paczki_odcisk_check` (`^[0-9a-f]{64}$`) | SHA-256 z ujednoliconej treści pozycji, liczony przez `PodgladPaczkiEksportu` (ten sam co w podglądzie) |
| `recipe_id` / `post_id` / `collection_id` | uuid NULL, FK `ON DELETE CASCADE` | utworzona treść; CHECK `wczytane_z_paczki_cel_check`: dokładnie jedno pole, zgodne z `rodzaj` |
| `created_at` | timestamptz NOT NULL DEFAULT now() | |

Ograniczenia i indeksy: `UNIQUE (user_id, odcisk)` (`wczytane_z_paczki_user_odcisk_unique`) —
ta sama paczka wczytana drugi raz, także dwoma żądaniami naraz, niczego nie
dubluje; trzy indeksy częściowe po wskaźnikach (`..._recipe_idx`, `..._post_idx`,
`..._collection_idx`) dla kaskad.

**Skasowanie treści.** Twarde — zabiera ślad kaskadą. Miękkie (`deleted_at`) śladu
nie rusza, dlatego `WczytajPaczke` uznaje ślad za „żywy” tylko wtedy, gdy stoi za
nim nieskasowana treść; skasowany przepis albo wpis można wczytać z tej samej
paczki jeszcze raz (stary ślad jest wtedy zastępowany).

**Prywatność.** Wczytane treści są zawsze prywatne — przepis to szkic
(`visibility = private`, `publish: false` przez `PublishRecipe`), wpis ma
`visibility = private`, zeszyt `visibility = private` i `is_default = false`.
Widoczność i status są wpisane w `WczytajPaczke`, nie brane z paczki. Zdjęć
z paczki nie wczytujemy.

**Eksport:** wiersz jest `NIE_DOTYCZY` w `InwentarzDanychKonta` (znacznik techniczny;
treść jest w sekcjach `przepisy`, `wpisy`, `kolekcje`). **Wymazanie konta:**
`EraseAccountData` usuwa ślady jawnie po `user_id` i kasuje czekający w prywatnym
magazynie plik ZIP (`MagazynPaczek::zapomnijWszystkie`).

**Rollback:** `down()` **odmawia** (D-088), gdy w tabeli jest choć jeden wiersz —
przepisy, wpisy i zeszyty zostałyby, ale znikłaby pamięć o tym, co wczytano, więc
ponowne wczytanie starej paczki utworzyłoby duplikaty. Pusta tabela i
`migrate:refresh` w CI przechodzą bez pytania. Wymuszenie po kopii tabeli:
`KUKING_ROLLBACK_KASUJE_SLADY_IMPORTU=1`. Test: `WczytajPaczkeTest`
(`test_cofniecie_migracji_*`).
