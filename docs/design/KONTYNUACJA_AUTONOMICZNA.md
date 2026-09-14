# Kontynuacja autonomiczna KuKing

## Zatwierdzony sposób pracy

Użytkownik 14 września 2026 zatwierdził realizację planu marki i ulepszeń. Automatyzacja `kuking-kontynuacja-prac` jest aktywna: co godzinę wraca do tej samej rozmowy. Przed wznowieniem sprawdzać procesy, subagentów, zmiany lokalne i PR; nie dublować wykonania. Polecenie zatrzymania użytkownika ma pierwszeństwo.

Kolejność: publikowanie i przepisy → wyszukiwanie i zeszyty → konto i komunikaty → pozostałe ekrany → poczta → ulepszenia wynikające z potwierdzonych problemów. Zachować zakres MVP i decyzje. Nie rozpoczynać audytu od zera.

Pakiet kończy się regresją według AGENTS.md, oglądem, niezależnym review, zwykłym hookiem, wymaganym CI, scaleniem i potwierdzeniem wdrożenia. Macierz rozdziela kod, lokalny pomiar, ogląd i produkcję. Brak dostępu pozostaje ograniczeniem. Pełna marka nadal **CZĘŚCIOWO**.

## Aktualny punkt pracy

- Baza: main `3a1584764637406676ff78ff7afa4b09df5f34b9`, Alfa 0.28, wcześniej potwierdzona na produkcji. PR #543 i #544 zakończone.
- Gałąź: `test/492-publikowanie-gotowanie`, PR #546. Pierwszy zdalny head bd852701f6e5471fc6325c9b9e20b3f201b607c4 zawiera #545. Pakiet poszerzono lokalnie o #547 — nie scalać starszego head na podstawie jego CI.
- Macierz uzgodniona z późniejszymi odbiorami; niezależny audyt odczytowy zakończony. Lokalna poprawka #545 / Alfa 0.29 usuwa niezmierzoną obietnicę po pierwszym wpisie. Raport `PIERWSZY_WPIS_545.md`: 31/104, dwie fizyczne kontrole ujemne, 48 konfiguracji, niezależny review. Pierwszy push przeszedł hook; końcowy pakiet #547 wymaga nowego push i kontroli.
- Istnieje odbiór `ODBIOR_TRYBU_GOTOWANIA_2026_09_14.md`: 48 konfiguracji i rzeczywisty zoom na starszym SHA. Nie pomijać dowodu i nie przypisywać go automatycznie dzisiejszemu kodowi. Brakowało składników, zdjęć i wysłania Ugotowałem.
- Następnie dostarczyć #545 przez zwykły hook, CI i odbiór produkcji. Wykorzystać istniejący lokalny prywatny wpis do edycji; dalej pełna publikacja przepisu i Ugotowałem zgodnie z macierzą.

## Środowisko

Repo kanoniczne: `C:\Users\matma\Documents\Codex\kuking.pl`. Kopia wykonawcza WSL `/tmp/kuking-final-20260913`; przed pomiarem porównać źródła. PHP `/opt/kuking-php-8.4-avif/bin/php`; Chromium `/tmp/kuking431-browsers/chromium-1243/chrome-linux64/chrome`. PostgreSQL **55439**, nigdy współdzielony 5432. Nie kopiować `.git` z kopii wykonawczej do repo kanonicznego.

Nie uruchamiać pełnego PHP równolegle z pomiarem korzystającym ze wspólnych mediów. Istniejący serwer 8029 i lokalne fixture wymagają odczytu stanu przed użyciem. Sekrety i sesje pozostają poza dokumentacją.

Własny serwer publikacji 8033: baza `kuking_publikacja492`, PID launchera w `output/publikacja492/server.pid`. Sesja `/tmp/kuking-publikacja492-state.json` poza repo. Lokalny wpis `01a0a149-64b7-7292-a55a-ec5955ac6fa0` zachowany do odbioru edycji. Przed uruchomieniem sprawdzić proces; helper przygotowania ma historyczną pułapkę cache relacji profile i nie powinien być uruchamiany bez odczytu. Nie powtarzać skryptu publikującego, bo powstanie drugi wpis.
## Dalszy odbiór w tym pakiecie

Raporty ODBIOR_PUBLIKOWANIA_492.md i KOMUNIKAT_UGOTOWALEM_547.md zawierają 240 konfiguracji formularzy, 12 błędów, rzeczywiste publikacje i szkic. #547: 31/148, sześć negatywów, 48 konfiguracji instrukcji i osiem wyników zapisu/powtórzenia; review zaakceptowane. Nie mylić początkowych założeń automatu z usterką 429 — jej przyczyny nie potwierdzono. Końcowy długi zrzut działa przy viewport:null i rzeczywistym rozmiarze okna Chromium.

Lokalne przepisy: lokalna-zupa-odbioru-publikacji oraz lokalny-szkic-odbioru-publikacji (oba published/private, drugi ma składnik, zdjęcie kroku i minutę). Sześć lokalnych wykonań, zero własnych powiadomień; nie powtarzać bez sprawdzenia skryptów tworzących. Zostało odebrać rozszerzony tryb gotowania oraz Wyszło. Przed scaleniem sprawdzić końcowy SHA, wymagane CI i później Railway. Następnie uaktualnić niniejszy punkt oraz raporty produkcji.
