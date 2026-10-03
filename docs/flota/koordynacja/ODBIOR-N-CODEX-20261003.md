# Odbiór lokalny paczki N — 3 października 2026

## Zakres i kolejność

Paczka N powstaje na własnej gałęzi `codex/paczka-n-20261003`. Paczka M (#2793) została jawnie przekazana w komentarzu 5963674285 i scalona do C jako `2e55128e96c1802fb454a8905cef8799cacb3f71` po terminalnie zielonym pełnym CI `37080657954`; CI push C `37082918564` również zakończyło się sukcesem. Przed wydaniem M wymagana jest korekta `3195e275325ee13f71b7577425204403dd2920a8`: runbook CSAM, widoczność starszej wersji (#2808), odmowa kopiowania importu (#2800) i świeży stan konta podczas importu (#2815). N ma zostać wydana dopiero po odebraniu M; przed ostatecznym PR-em wymagane jest scalenie świeżej bazy oraz pełne CI końcowego heada.

W lokalnym złożeniu są „Moje rozmowy” (#2432), zapamiętane gotowania (#2439), zakres zeszytów w „Co ugotuję” (#2591), dowód zabezpieczenia awatara i poprawna kolejność odtwarzania kopii (#2708), a także dwa projekty dokumentacyjne: dziennik decyzji CSAM i klucz dostępu (#2530). Projekty nie uruchamiają funkcji ani nowej infrastruktury. Kontrolę ujemną uprawnień dla #2784 właściciel zatwierdził osobno; została wykonana i odwrócona w izolowanej kopii.

## Wykonane kontrole

Na złożeniu `fb27b5c20ec1ec593fa512b4ba1841c2b9ed59a4`:

- świeży własny `vendor` z dokładnego `composer.lock`, PHP 8.4 i PostgreSQL 18.6, osobna baza UTF8;
- połączone testy zmienionych obszarów i strażników: 64 testy, 1859 asercji, bez porażek, błędów i pominięć;
- strażnicy CHANGELOG, nowości i tras pod Policy: 14 testów, 2348 asercji;
- pełny PHPStan: zero błędów;
- niezależny przegląd: prywatne trasy przed trasą z UUID, konta wyłącznie z sesji, brak przedłużenia retencji przez odczyt, poprawka CodeQL z C zachowana;
- rzeczywiste mutacje składników paczki oblały z własnych przyczyn, pliki przywrócono przed commitami.

To dowody lokalne dla wskazanego złożenia. Nie zastępują pełnej bramki końcowego heada, CI PR-a, CI push `main`, wdrożeń trzech usług ani produkcyjnego `/wydanie`. Próba zestawu Python na Windows wykazała jeden test zależny od separatora ścieżek; pełna bramka końcowa ma działać w izolowanej kopii Linux, bez zmiany testu ani obejścia haka.

## Dalsze poprawki i dowody

- `f010356fd1e51146174700490f52877296c81e61` (#2790): potwierdzenie udostępnienia jest przypięte do UUID odbiorcy, autora i przepisu. Zmiana nazwy nie przekazuje dostępu osobie, która zajęła dawną nazwę; sprawdzenie stanu i blokad powtarza się pod zamkiem. 37 testów HTTP / 290 asercji, kontrola ujemna z markerem `ODBIORCA_2790_NIE_PRZECHODZI_NA_NOWE_KONTO`, przywrócenie plików, Pint i analiza statyczna przeszły na izolowanej bazie PostgreSQL 18.
- `e3f1ed8326c87c12faf7ded5c2aef80911d14e0c`: oba nowe ekrany mają rzeczywiste własne dane w przyrządach dostępności i UX. Lokalnie 28 testów / 134 asercje oraz 4/4 pomiary przeglądarkowe przeszły. Przy 320 px i czcionce 100%/200% oba ekrany zwróciły HTTP 200, właściwą ścieżkę i treść bez poziomego przewijania. Usunięcie markerów dało porażkę, przywrócenie bajtów i czasu plików ponownie dało PASS. Pełny przyrząd dostępności zatrzymał się lokalnie na wcześniejszej fixture; nie jest oznaczony jako zaliczony.
- `5986968d56e2f1d73a9cdf4886d03c5d5390424e`: runbook wymaga odtworzenia CSAM przed każdym wymazaniem, a brak możliwości izolacji usług zatrzymuje procedurę. Dwa testy jednostkowe / 23 asercje oraz trzy fizyczne mutanty z własnymi markerami i przywróceniami przeszły. Nie wykonywano odtwarzania produkcji ani komend na danych użytkowników.
- Złożenie `67f230fae79b488ba2c8c5b7ad66497e3eef2049` zawiera wszystkie powyższe poprawki i świeżą C po M. Konflikty tras i rejestrów rozwiązano sumą obu stron; runbook jest dokładną wersją korekty M. Pełna końcowa bramka pozostaje do wykonania.

## Kontrola z 3 października — dalsze poprawki

- #2803 (`409f94f7b`): kursor „Moich rozmów” z niemożliwą datą nie powoduje błędu PostgreSQL. Poprawny dzień przestępny, dokładność czasu i remis UUID pozostają. 20 testów / 112 asercji, fizyczna kontrola `ROZMOWY_2803_NIEMOZLIWY_CZAS` oraz dokładne przywrócenie pliku przeszły.
- #2785 (`616383620`): eksport odbiorcy sprawdza bieżące prawo do udostępnionego przepisu. Rzeczywisty ZIP nie zawiera nowego tytułu ani autora po utracie dostępu. 62 testy / 428 asercji, kontrola `EKSPORT_2785_BIEZACY_TYTUL_BEZ_DOSTEPU`, przywrócenie i analiza statyczna przeszły.
- #2809 (`5fccf7db6`): idempotentne ponowienie przeniesienia nie ujawnia niedostępnego tytułu; zachowuje notatkę, datę i jedną pozycję. 21 testów / 152 asercje oraz strażnicy 114 / 2654 przeszli; pięć fizycznych porażek mutanta miało marker `PRZENIESIENIE_2809_TYTUL_POD_POLICY`, a przywrócony kod znów przeszedł.
- #2708 (`5806a4626`, `e19d7fa71`): instrukcja moderacji zgodna z rzeczywiście obsługiwanymi celami i aktualnym ekranem. 8 testów / 1625 asercji oraz dwie fizyczne kontrole przeszły. To nie dowód wykonania próby produkcyjnego R2/CDN.
- #2808 (`95ce27cf9`, korekta komunikatu `f7270e649`): sprawdzenie widoczności wersji pod istniejącymi blokadami. 15 testów / 108 asercji, rzeczywiste dwa połączenia 1 / 11 oraz fizyczny mutant przeszły. Komunikat odróżnia niedostępność od usunięcia wersji.
- #2800 (`c07605660`): import URL/PDF/zdjęcia jawnie odmawia kopii, która zgubiłaby bramki odczytu; zwykły szkic nadal działa. 16 testów / 116 asercji i fizyczny mutant, który rzeczywiście opublikował kopię bez potwierdzenia, przeszły. Brak migracji i wywołania AI.
- #2815 (`94a97c808`, w korekcie M jako `3bf472ca8`): każda pozycja paczki sprawdza świeże konto przed śladem i zapisem. 20 testów / 179 asercji oraz pięć przeplotów na dwóch połączeniach / 73 asercje przeszły. Mutant oblał wpis i zeszyt markerem `IMPORT_2815_SANKCJA_PRZED_ZAPISEM`; wcześniejsza poprawna pozycja zostaje.
- Test runbooku (`204ef0a66`): pilnuje obu par podgląd/wykonanie, nowej bazy i kolejności przed podpięciem usług. 1 test / 27 asercji oraz dwie fizyczne mutacje z odrębnymi markerami przeszły.
- Połączone złożenie N `fc5018124e806e7f0e004b0453fa6eecdc63c347`, przed dodaniem #2800 i #2815: 120 testów / 813 asercji i pełny PHPStan bez błędów. To dowód tego SHA, a nie późniejszego końcowego heada.
- Złożenie N `330849933ae3fff8bcdb092acfb85db070674168`, po włączeniu korekty M: 179 testów / 1273 asercje, bez porażek, błędów i pominięć oraz pełny PHPStan bez błędów. Preflight odczytowy sprawdził 509 kotwic i dwa mechanizmy; nie uruchamiał mutantów ani nie zmieniał plików. Zestaw nie zastępuje pełnej baterii, CI i odbioru produkcji.

Pierwszy normalny pre-push korekty M (`5986968d5`) odmówił wysłania: dwie nieznane klasy testowego generatora Livewire oraz 29 porażek pełnej baterii. Nie pominięto testów ani haka. Generator testowej ścieżki lokalnej otrzymał jawny typ (`1862cb8a0`, w M jako `99560b08e`), zgodny z niezmienioną implementacją Livewire; pełny PHPStan i oba testy endpointu przeszły. Odrębny klaster PostgreSQL 18 z `C.utf8` naprawił konfigurację małych polskich liter. Osobna próba tych samych testów dała 35 / 90 PASS seryjnie, lecz siedem porażek przy ParaTest; kolejna pełna bramka korzysta z domyślnego seryjnego trybu `check.sh`, bez `KUKING_TESTY_ROWNOLEGLE`. Nie zmienia to wymagania pełnego CI PR-a ani żadnej bramki wydania.

## Otwarte czynności po złożeniu

- ostrzeżenia parsera (#2546, #2548) dodane z commita `6b7a81f7d`: 56 testów / 345 asercji oraz dwa rzeczywiste mutanty u agenta; sprostowanie rejestru (#2708) dodane z `7d3cea3bc`: 9 testów / 53 asercje i rzeczywisty mutant granicy. Polityka i archiwum bez zmian. Po złożeniu wykonywana jest wspólna kontrola końcowego heada;
- wykonać zwykły push z niezmienionym hakiem w przygotowanym środowisku Linux;
- po odbiorze wydania M sprawdzić ponownie świeżą bazę i wymagane CI N;
- zachować osobno otwarte kryteria pilota 50+, przeglądu prawnego i kroków panelowych;
- oczekiwać potwierdzenia właściciela w #2025 w sprawie oczekiwania workera i harmonogramu na zielone CI.

## Odbiór z 3 października, 02:55 UTC

Złożenie `59e2141a1d411847c4d3002568f68e3233d4883e`: **337 testów / 4018 asercji PASS**, bez failures/errors/skips, pełny PHPStan 0. Po dołączeniu #2835 pełny PHPStan na `b98f5cfd7` także 0. Nie jest to pełna bramka końcowego heada ani odbiór produkcji.

- #2783 `5faeb5e16` i dowód `ce1c2ad52`: tożsamość A/B, również przy identycznych polach. 103 / 636 PASS i dwie fizyczne mutacje. Pomiar dwóch sesji zgodny z D-105 (`tests/Dwa`): 2 / 33 PASS i dwa mutanty właściwych blokad. Sprawdza timeout na SELECT FOR UPDATE i ponowienie po zatwierdzeniu, nie zwolnienie tej samej czekającej komendy.
- #2807 `e8bdbff09`: 11 / 76 PHP, 11 testów modułu i Chromium 1/1. Dodatkowy browser używa rzeczywistego HTML HTTP kernel Laravel podanego przez route.fulfill i produkcyjnego modułu, nie zdalnej produkcji. Fizyczne usunięcie UL oblało browser, restore bajtów/mtime dał PASS. W rejestrze CI jest osobna kontrola PHP; ręczna mutacja przeglądarkowa nie jest automatyczną kontrolą CI.
- #2811 `da6e892c7`, migracja `c054b5cc8`: klucze ponowień pozostają po usunięciu zdjęcia, siódme nowe wysłanie działa, wymazanie usuwa prywatną historię. Raport wykonawcy: 17 / 100 HTTP, 1 / 13 Dwa i dodatkowe 5 / 58 oraz właściwe mutanty. Root odtworzył naruszenie §6 (3 PASS / 1 FAIL CHECK), po korekcie 11 / 76 PASS. CHECK jest NOT VALID i osobno walidowany poza transakcją; przerwane DDL można ponowić. Fizyczne wyłączenie odmowy rollbacku: 1 FAIL `DOLACZENIE_2811_ROLLBACK_ZACHOWUJE_KLUCZE`, dokładny restore i 1 PASS. Wstępna próba kotwicy odmówiła przed mutacją i nie jest zaliczoną kontrolą.
- #2825 `3c1ce2b4c`: aktualne członkostwo i prawo odczytu pod blokadą. 32 / 194 PASS; pięć porażek właściwego markera po mutacji i przywrócenie. Niezależny review ACCEPT.
- #2791 `bc55a3113`: zawieszenie przepuszcza tylko cofnięcie własnego dostępu; nowy grant i cudzy przepis odmawiają. 33 / 242 PASS; fizycznie 2 FAIL → restore → 2 PASS właściwego markera. Niezależny review ACCEPT.
- #2827 `b85d89923`, fixture `2cda733ab`: zamknięcie details zatrzymuje mowę/timery/spóźnione wyniki bez utraty tekstu. 19 testów modułu; fizycznie 19 PASS → 1 FAIL właściwego markera → 19 PASS. Chromium 1/1 używa syntetycznego DOM i symulowanego SpeechRecognition, nie prawdziwego mikrofonu ani pełnego renderu Laravel. Charset fixture poprawiony po rzeczywistym błędzie kodowania.
- #2826 `ca542ce00`, followup `34c75cb36`: oba znaczniki sprawdzane atomowo przed DELETE. Odtworzono również A → identyczne B → awans B; stary formularz odmawia. 56 / 357 Feature, 4 / 55 Dwa w obu kolejnościach, dwie właściwe mutacje i restore.
- #2816 `1ca0fad9d`: Ułóż → Usuń → Odzyskaj zachowuje kolejność, dopiski, wydruk i eksport; starsze kopie pozostają zgodne. 24 / 120 PASS i dwie właściwe mutacje. Wymagany eager-load autora naprawia wyjątek rzeczywistego eksportu. Bez migracji/retencji/kosztu.
- #2835 `0ee38b303`: nowe zaproszenie po nazwie lub linkiem ponawia Policy na świeżym koncie w kolejności konta → zeszyt. 34 / 205 Feature, 4 / 54 Dwa w obu kolejnościach; mutant oblał dokładnie dwie kolejności sankcja-pierwsza, restore bajtów/mtime i 4 PASS. Istniejący odczyt zawieszonej osoby pozostaje.

Root `tests/skrypty/zakres.sh`: 43 przypadki tabeli, ścieżki bez listy, duży diff i kontrole ujemne wszystkich siedmiu wyjść PASS. Preflight kotwic jest wyłącznie odczytem. Pełne Windows check agentów nie są deklarowane jako zielone. Powyższe wyniki wykonawców odróżniono od pomiarów root i niezależnego review.

N ma dwie nowe migracje: `first_package_id` (#2783) i `photo_submission_keys` (#2811), z wąską odmową rollbacku chroniącą zachowaną tożsamość/historię. Nie cofać produkcyjnej bazy automatycznie. CSAM/passkey, produkcyjne R2/CDN, prawo i pilot 50+ nadal mają otwarte kryteria. Kolejne poprawki trafiają do osobnych gałęzi po zamrożeniu N.

## Odmowa normalnego pusha N — 3.10.2026, 03:40 UTC

Na dokładnym `c088b2208f629776dff203a05174151ade394a01` zwykły pre-push
zakończył się kodem 1: 13 954 testy PASS, 1 FAIL, 171 062 asercje.
Jedyny błąd `IndeksDokumentacjiBazyTest`: opis przepisów 46 237 bajtów,
powyżej istniejących 46 080. PHPStan i kontrola odwracalności migracji
PASS. Hook odmówił wysłania; N nie została wtedy opublikowana.

Korekta `64453edb3` przenosi bez zmiany treści sekcje recipe_versions,
recipe_shares i draft_restore_points do osobnego dokumentu i dopisuje
indeks. Nie podwyższa progu ani nie omija testu. Następnie do N przyjęto
odczyt JUnit i siedem regresji przyrządu z M #2844 (`8b3072928`) oraz krok
CI z `344f62310`. Kolejna pełna normalna bramka jest nadal wymagana.
