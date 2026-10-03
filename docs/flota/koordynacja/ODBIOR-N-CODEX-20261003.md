# Odbiór lokalny paczki N — 3 października 2026

## Zakres i kolejność

Paczka N powstaje na własnej gałęzi `codex/paczka-n-20261003`. Paczka M (#2793) została jawnie przekazana w komentarzu 5963674285 i scalona do C jako `2e55128e96c1802fb454a8905cef8799cacb3f71` po terminalnie zielonym pełnym CI `37080657954`. Przed wydaniem M wymagany jest osobny PR korekty runbooku CSAM `5986968d56e2f1d73a9cdf4886d03c5d5390424e`. N ma zostać wydana dopiero po odebraniu M; przed ostatecznym PR-em wymagane jest scalenie świeżej bazy oraz pełne CI końcowego heada.

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

## Otwarte czynności

- ostrzeżenia parsera (#2546, #2548) dodane z commita `6b7a81f7d`: 56 testów / 345 asercji oraz dwa rzeczywiste mutanty u agenta; sprostowanie rejestru (#2708) dodane z `7d3cea3bc`: 9 testów / 53 asercje i rzeczywisty mutant granicy. Polityka i archiwum bez zmian. Po złożeniu wykonywana jest wspólna kontrola końcowego heada;
- wykonać zwykły push z niezmienionym hakiem w przygotowanym środowisku Linux;
- po odbiorze wydania M sprawdzić ponownie świeżą bazę i wymagane CI N;
- zachować osobno otwarte kryteria pilota 50+, przeglądu prawnego i kroków panelowych;
- oczekiwać potwierdzenia właściciela w #2025 w sprawie oczekiwania workera i harmonogramu na zielone CI.
