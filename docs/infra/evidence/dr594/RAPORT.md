# Lokalna próba odtworzenia #594 / #193 — 20 IX 2026

**Wynik: zaliczona lokalna ścieżka zrzut → szyfrowanie → odtworzenie →
weryfikacja na 5 504 654 wierszach. Produkcyjna ścieżka pozostaje niezweryfikowana.**
Wszystkie liczby poniżej zmierzył wykonawca tego zadania; źródła cudzych
ustaleń są wskazane osobno. Nie zmieniono kodu aplikacji, migracji ani
istniejących skryptów kopii/restore. Dodano generator, przyrząd pomiarowy,
kontrole tego przyrządu, runbook i dowody.

Instrukcja wykonania i postępowania po awarii:
[DR594_RUNBOOK_LOKALNY.md](../../DR594_RUNBOOK_LOKALNY.md).

## Stan wyjściowy, zmierzony przed zmianami

- Gałąź `gpt/dr-baza`, czyste drzewo,
  `HEAD=4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
- Odczytano #594 i #193 przez `gh issue view --repo woogitsu/kuking.pl`;
  obydwa otwarte. Nie wysłano komentarzy ani nie zmieniono zgłoszeń.
- Istniejący `PetlaOdtworzeniaJestJednaKomendaTest`: **5/5, 18 asercji**.
- Utworzono własne źródło `kuking_flota_gpt_dr_baza_source`, właściciel
  `kuking`, potwierdzony serwer `127.0.0.1:55439`, PostgreSQL 18.6.
- Wszystkie migracje i `DatabaseSeeder` wykonane wyłącznie na tym źródle.
- Istniejący `scripts/proba-odtworzenia.sh --petla-lokalna` na nietkniętym
  kodzie: zrzut **332 845 B**, **4654 wiersze**, 50 tabel zgodnych,
  82 migracje, 0 czekających; `pg_restore` **1 s**. Nazwa celu:
  `proba_odtworzenia_gpt_dr_baza_baseline`; cel automatycznie usunięty.
- Działały 3 wymagane wyzwalacze, 91 CHECK, 26 UNIQUE, 71 FK i sondy
  zachowania. Nie znaleziono usterki wymagającej poprawki kodu restore.

To własny pomiar, a nie przejęcie wcześniejszej próby 192/202 wierszy.
Wcześniejsze próby opisane w głównej dokumentacji traktowano wyłącznie jako
**[pomiar cudzy: KOPIE_I_ODTWORZENIE.md §5.1]**.

## Wielkość i kształt zbioru

Generator `scripts/dr594-dane.sql` dołożył **5 500 000** wierszy do pełnego
schematu po migracjach i danych demonstracyjnych. Jedna transakcja,
bez wyłączania ograniczeń, indeksów, FK czy wyzwalaczy. Dane nie pochodzą
z produkcji. Generator odmawia na innej bazie/roli/hoście/porcie i przy
ponownym uruchomieniu — sprawdzono obie odmowy.

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

W bazie zostały również dane seedera: m.in. **2 ugotowania i 2 powiadomienia**
(seeder używa `RecordCookedEvent`), 41 wcześniejszych przepisów i słownik tagów.
Nie dodawano masowo ugotowań z pominięciem akcji domenowej. Ten obszar ma małą
liczność: próba nie jest benchmarkiem dużej tabeli `cooked_events`.

Zbiór ma realistyczne relacje i rozmiary tekstów, ale jest syntetyczny:
powtarzalne słownictwo dobrze się kompresuje, rozkład autorów jest regularny,
nie modeluje całej aktywności produkcyjnej. Część tabel jest pusta.
Metadane uploadów nie udają gotowych zdjęć; bajtów R2 ta próba nie obejmuje.
Nie należy skalować tych czasów liniowo do przyszłej produkcji.

## Czasy i rozmiary — własny pomiar

Przebieg rozpoczęty **2026-09-20 17:39:37 UTC** / 19:39:37 Europe/Warsaw.
Pełny odczyt: [receipt.json](receipt.json),
[zrzut i szyfrowanie](backup.txt), [odtworzenie i kontrole](restore.txt).

W tekstowych kopiach logów w Git usunięto kolory terminala i końcowe białe
znaki. Pełne logi pozostają w katalogu artefaktów poza repozytorium.

| Etap | Czas |
|---|---:|
| Sam `pg_dump` | **30,22 s** |
| Cały skrypt kopii, w tym zrzut, odczyt katalogu, szyfrowanie i `.meta` | 32,32 s |
| Odszyfrowanie, według zegara istniejącego skryptu | 6 s |
| Sam `pg_restore` | **56,94 s** (skrypt: 57 s) |
| Liczniki wszystkich tabel, migracje, bariery i sondy | **2,62 s** |
| Cały skrypt odtwarzania, z odszyfrowaniem i kontrolami | 66,73 s |
| Dodatkowe SHA-256 pełnej treści wszystkich tabel źródła i celu | **76,54 s** |
| Weryfikacja po restore łącznie (kontrole + treść) | **79,16 s** |
| Kopia + cały skrypt odtwarzania + pełne porównanie treści | **175,58 s ≈ 2 min 56 s** |

Ostatnia suma nie zawiera przygotowania danych ani dodatkowych kontroli
ujemnych, przeglądu schematu i odczytu przez Eloquent. Nie sumuj ponownie
wierszy „cały skrypt” z ich etapami składowymi.

| Wielkość | Bajty |
|---|---:|
| Baza źródłowa przed zrzutem (`pg_database_size`) | 2 747 324 095 |
| Archiwum `pg_dump --format=custom` | 426 731 512 |
| Szyfrogram CMS/RSA | 427 148 807 |

Przyrząd używa zegara monotonicznego i zapisuje czas otrzymania każdego
wiersza komunikatu; precyzja ułamków sekund nie jest gwarancją dokładności
narzędzia. Postgres/klienci 18.6, WSL2, Intel Core Ultra 7 270K Plus,
24 procesory logiczne, 31 GiB RAM widoczne w WSL. Dysk WSL miał około
382 GiB wolnego. Host współdzielony i obciążony: load 1 min **32,06 → 32,37**,
w tym zwykłe testy tej gałęzi na innej bazie. To jeden pomiar w zastanych
warunkach, nie pomiar izolowanej wydajności ani gwarancja czasu maksymalnego.

**RPO:** brak utraty wierszy w tym zamrożonym zbiorze, ale produkcyjnego RPO
nie zmierzono — nie ma tu harmonogramu ani odczytu wieku produkcyjnej kopii.
**RTO:** zmierzono lokalne etapy, nie produkcyjny czas odzyskania usługi.
Brakuje wykrycia awarii, dostępu operatora, pobrania offsite, odtworzenia
infrastruktury, kluczy aplikacji, mediów, kolejek i przełączenia ruchu.

## Co dokładnie zweryfikowano

1. Istniejące skrypty kopii i odtwarzania, bez podmieniania `pg_dump`,
   `pg_restore` lub ich wyniku. Klucz testowy RSA 3072, CMS; odszyfrowanie
   samym kluczem prywatnym, zgodność SHA-256 z `.meta`.
2. **50/50 tabel, 5 504 654 wiersze po obu stronach**, 82 migracje wykonane,
   0 czekających, 3 wymagane wyzwalacze, 91 CHECK, 26 UNIQUE, 71 FK.
3. Cztery niedozwolone zapisy odrzucone z właściwej przyczyny, dozwolony
   zapis przyjęty, 0 pozostawionych wierszy sondujących.
4. SHA-256 strumienia JSONB wszystkich kolumn i wierszy każdej tabeli,
   sortowanego ze stałą kolacją `C`; zgodność [źródła](source-manifest.json)
   i [celu](target-manifest.json). Są to dowody bez treści wierszy i danych kont.
5. Katalogi: po obu stronach 159 indeksów, w tym 25 częściowych,
   rozszerzenia `pg_trgm`, `pgcrypto`, `plpgsql`, `unaccent`, sesja UTC.
6. Porównanie schematu: **surowe teksty `pg_dump --schema-only` nie były
   identyczne**. PostgreSQL przepisał 37 literalnych rzutowań `varchar[]`
   na `text[]` z rzutowania całej tablicy na rzutowanie jej elementów.
   Po normalizacji wyłącznie tego zapisu i losowych tokenów `\restrict`
   skróty są identyczne. Wszystkie 37 par wyrażeń dodatkowo porównano
   w PostgreSQL przez `IS NOT DISTINCT FROM` — wszystkie równe.
   Nie usuwano definicji ograniczeń ani indeksów z porównania.
   [Pierwszy, nierówny wynik](schema.json), [wyjaśniony wynik](schema-comparison.json),
   [pełna różnica, z zachowanymi białymi znakami w JSON](schema-diff.json).
7. Odczyt aplikacyjny przez `php artisan tinker` na bazie odtworzonej:
   `Recipe::where('slug', 'dr594-zupa-1')->withCount(['ingredients','steps'])`
   zwrócił przepis „Zupa warzywna — próba 1”, **8 składników i 4 kroki**.
   To odczyt modelu i relacji, nie test interfejsu ani pełny odbiór aplikacji.

## Kontrole ujemne — wykonane, nie deklarowane

[controls.json](controls.json) zbiera kody oraz oczekiwane przyczyny:

- [brak jednego komentarza](negative-row.txt): najpierw zgodność, potem
  `DELETE 1`, kod **63**, dokładnie `comments: 2000060` wobec `2000061`,
  przywrócenie dokładnego wiersza i ponowna zgodność;
- podmiana tekstu jednego komentarza przy tej samej liczbie wierszy:
  manifest zmienia wyłącznie `comments`; po przywróceniu wszystkie 50
  manifestów identyczne z wynikiem sprzed mutacji;
- [uszkodzenie bajtu w środku szyfrogramu](corrupt-archive.txt): kod **44**,
  niewłaściwy SHA-256; baza `..._uszkodzony` **nie powstała**;
- [ponowne generowanie](generator-repeat.txt): kod **3**, odmowa przed zapisem;
- [generator skierowany na cel zamiast źródła](generator-wrong-target.txt):
  kod **3**, odmowa przed zapisem.

W trakcie przygotowania nowy przyrząd dwukrotnie zatrzymał się przed kopią:
porównywał tekst adresu `127.0.0.1/32` z `127.0.0.1` (poprawiono przez
`host(inet_server_addr())`), potem nie utworzył wymaganego katalogu kopii
(kod 21; dodano jawne utworzenie). To błędy nowego przyrządu, nie zastane
usterki odtwarzania. Udany pomiar wykonano po tych poprawkach.

## Testy, zakres i przekazanie

- `php artisan test` przez skrypt floty, filtr wykluczający tylko
  `ProbaOdtworzeniaTest`: **4393 passed, 83 692 asercje, 518,53 s**.
  [Podsumowanie testów](testy-podsumowanie.txt).
- `ProbaOdtworzeniaTest` pominięto zgodnie z instrukcją właściciela:
  używa współdzielonej bazy `kuking_zrodlo_proby_glowny`. Zamiast tego
  wykonano realny obieg i kontrole wyżej na własnych bazach.
- `vendor/bin/pint`: **PASS, 1155 plików**, [pint.txt](pint.txt).
- Składnia nowych skryptów, odmowy generatora po końcowej zmianie
  bezpiecznika oraz odmowa dla adresu/portu NULL:
  [final-checks.json](final-checks.json).
- Brak zmian schematu, więc nie potrzeba migracji ani zmiany `DATABASE.md`.
  Wycofanie zmiany: usunąć nowe narzędzia/dokumenty; nie cofać migracji.
  Lokalne dane i kopie usuwa się osobno po sprawdzeniu nazw, według runbooka.
- Sprzątnięto odtworzoną bazę po końcowym potwierdzeniu tożsamości.
  Potwierdzono brak celów: bazowego, odtworzonego i uszkodzonego.
  **Pozostawiono** syntetyczne źródło `kuking_flota_gpt_dr_baza_source`
  oraz katalog `/home/mateusz/flota/gpt-dr-baza-artifacts/20260920T173937Z`
  do powtórzenia pomiaru; [cleanup.json](cleanup.json).
  Katalog zawiera również testowy klucz prywatny — dopuszczalne tu tylko
  dlatego, że nie ma w nim danych produkcyjnych. To nie model składowania
  klucza produkcyjnego, który musi być niezależny od kopii (D-049).
- Nie wykonano push, PR, zmian produkcji, odczytów jej danych/paneli,
  wystawienia portów, wysyłki wiadomości ani uruchomienia `kopia-bazy`.
- **Decyzje właściciela:** miejsce offsite i jurysdykcja, operator/zastępca,
  dostęp i odzyskanie klucza, częstotliwość, retencja, alarm/reakcja,
  docelowe RTO/RPO, aktualny stan Volume Backups/PITR oraz autoryzowane
  środowisko prawdziwego produkcyjnego restore. Warianty i koszty operacyjne:
  [runbook §7](../../DR594_RUNBOOK_LOKALNY.md#7-decyzje-właściciela-i-warunki-odbioru-produkcji).

**Nie przejęto cudzych wyników jako własnych.** Stan paneli i wcześniejsze
deklaracje liczby kopii to **[pomiar cudzy: KOPIE_I_ODTWORZENIE.md §5.2–5.3]**,
nieweryfikowany w tym zadaniu. Bieżąca liczba produkcyjnych kopii jest nieznana.
