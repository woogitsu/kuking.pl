# Pomiar feedu #585

Baza kodu: `f77bd4d7f16e93469b9a731f80c41e6b151e7faf`. Pomiary lokalne: 16 września 2026. Status zweryfikowany 20 września 2026: pakiet scalono 16 września w PR #628 jako `e951554cfd3f86147b95fb60606912c1650493f8`; CI PR i main zakończyło się sukcesem. Issues #585 i #609 zamknięto 16 września. Decyzja #609 pozostawia `pluck` + `whereIn`, co potwierdza odczyt kodu na `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Nie zmieniono zapytań ani konfiguracji produkcji w ramach tego pakietu. Poniższe czasy są historycznym pomiarem z 16 września, nie nowym benchmarkiem.

## Wniosek

Nie ma podstaw do zastąpienia obecnego IN wariantem EXISTS lub JOIN. W badanych scenariuszach EXISTS był wolniejszy, a JOIN nie dawał spójnej przewagi. Na wzbogaconym zbiorze wykryto koszt kompilacji PostgreSQL JIT; to konkretna hipoteza przekazana przez decyzję #609 do walidacji w #605, nie dowód potrzeby zmiany architektury ani zgoda na globalne wyłączenie JIT.

## Środowisko i dane

Izolowany PostgreSQL 18.6, `127.0.0.1:55439`, baza `kuking_585_benchmark`, właściciel kuking, UTC. Host współdzielony: 24 CPU, load average 5.09/6.27/5.67 przed pierwszą serią. Wyniki nie określają pojemności produkcji.

Generatory w repo tworzą 2100 autorów, cztery konta obserwujące 10/100/500/2000 osób oraz 50000 wpisów z nierównym rozkładem autorów i remisami czasu publikacji. Wzbogacenie obejmuje 200 najnowszych wpisów: 800 komentarzy, 800 zapisów przez akcję domenową oraz 100 przepisów. Końcowy zbiór zawiera też 399 rekordów mediów i 100 zdjęć głównych przepisów. Media są wyłącznie metadanymi fabryk — bez plików zdjęć.

Cały zbiór wygenerowano od zera skryptami repo. Poprzednią bazę zachowano jako lokalne archiwum. Generator odmawia ponownego utworzenia danych; kontrola fixture odczytuje rzeczywiste liczniki bazy. Procedura: [URUCHOMIENIE_POMIARU_FEEDU_585.md](URUCHOMIENIE_POMIARU_FEEDU_585.md).

## Zgodność semantyczna

`PomiarFeeduGraniceTest`: 3 testy / 49 asercji na IN, EXISTS i JOIN. Jawne oczekiwane ID obejmują widoczność, statusy kont, blokady obu kierunków, niedostępne przepisy, niezerowe komentarze i zapisy, własny zapis, deduplikację zapisujących osób, kursor z remisami oraz pusty feed poza własnymi wpisami. Początkowy błąd fixture poprawiono przez forceFill/save chronionego statusu i odczyt stanu z bazy; oczekiwanych ID nie zmieniano.

Trzy fizyczne kontrole ujemne FollowingFeed wykryły usunięcie filtra aktywnych autorów, filtra widocznego przepisu i odwrócenie kolejności ID. Kopie poza repo, przywrócone MD5 i mtime, dodatni przebieg 3/49. Dowody: `evidence/feed585/negative585-matrix*`.

Runner porównuje konkretne uporządkowane wiersze, liczniki, media_ids, recipe_id, recipe_hero_id i kursory. Końcowy odbiór: 12/12 prób, success=true, restored=true, MD5 `5c3b9d2ae6b90b071997f9e3c60cc59f`. Dowód: `evidence/feed585/final585-runner.json`. Jedno powtórzenie służy odbiorowi zgodności, nie nowej analizie statystycznej.

## Wyniki SQL i aplikacji

Pierwsza seria: publiczne wpisy, jeszcze bez późniejszego wzbogacenia. 36 prób: trzy powtórzenia × trzy warianty × cztery konta. Kolejność wariantów odwrócono w drugim powtórzeniu. Mediany paginate w ms, z Eloquent i eager-load, bez HTTP:

| Obserwowani | IN | EXISTS | JOIN |
|---|---:|---:|---:|
| 10 | 19.05 | 57.96 | 22.37 |
| 100 | 25.76 | 56.19 | 27.51 |
| 500 | 25.84 | 52.88 | 28.54 |
| 2000 | 31.64 | 63.44 | 31.45 |

Dowód: `scale585-results.json`. EXPLAIN ANALYZE BUFFERS drugiego powtórzenia: główny SELECT przy 10 obserwowanych miał Execution Time 6.899/28.922/11.362 ms, przy 2000: 19.742/43.368/25.814 ms. Typ węzła planu sam nie rozstrzyga o poprawce.

Po dodaniu komentarzy, zapisów i przepisów wykonano kolejne serie po 36 prób. Po ANALYZE mediany IN wyniosły 46.97/50.81/323.40/332.24 ms; przy sesyjnym `PGOPTIONS=-c jit=off`: 18.37/25.60/27.26/35.49 ms. Plany wolnych prób wskazywały 261–272 ms kompilacji JIT. Sekwencja niezależnych procesów on/off/on dla 500 obserwowanych: 327.99/27.86/325.01 ms. Progi JIT: 100000/500000/500000. Nowe połączenie nadal odczytywało jit=on; nie wykonano ALTER SYSTEM.

Dowody: `scale585-enriched-results.json`, `scale585-enriched-analyzed-results.json`, `scale585-enriched-nojit-results.json`, `settings585.json`. Te serie poprzedzają dodanie mediów. Końcowego odbioru relacji nie przedstawiamy jako powtórzenia tych pomiarów szybkości.

## PDO, hydratacja, DiscoverFeed i HTTP

Skrypt zapisuje SQL, bindings, czas Laravel, osobny replay PDO prepare/execute/fetchAll oraz EXPLAIN ANALYZE BUFFERS. Replay następuje po paginate na rozgrzanej bazie; jego odjęcie od paginate nie mierzy Eloquent.

Post::hydrate mierzy osobno surowe wiersze, z kontrolą ID i kolejności. Osiem próbek Following/Discover dla czterech kont zwróciło po 15 wpisów i hydratację 16 wierszy paginatora: 0.16–0.30 ms. To nie obejmuje późniejszych castów widoku, relacji ani Blade. Dowód: `hydration585-baseline.json`. Dla obu feedów sprawdzono też pierwszą stronę, następną i powrót z rozmiarem 2; strony nie pokrywały się, powrót odtworzył pierwszą.

Pierwszy kernel HTTP z wcześniej uwierzytelnionym guardem zwracał 200, 27–28 zapytań i 81.59/96.75/94.32/101.09 ms. Brak manifestu Vite początkowo powodował 500; wykonano build i 72 kontrole kontrastu, bez ukrywania błędu zmianą aplikacji. Dowód: `kernel585-results.json`.

Osobny rzeczywisty HTTP: lokalny serwer developerski 127.0.0.1:8585, APP_ENV=local, sesje plikowe, prawdziwe logowanie GET/POST z CSRF i cookies. Po pierwszym pominiętym żądaniu po trzy próbki na konto. Mediany total: 312.24/316.40/565.89/578.50 ms. Dowód: `http585-results.json`. Inny etap zbioru niż kernel; nie porównywać tych liczb jako narzutu transportu. Brak TLS, assetów, współbieżności i serwera produkcyjnego. Zmieniono hasła wyłącznie czterech kont syntetycznych; nie twierdzimy, że przywrócono poprzednie hashe. Serwer zatrzymano, port odrzucał połączenie, tymczasowy plik hasła usunięto, cookies były tylko w pamięci.

## Odtwarzalność i kontrole narzędzi

Runner przechowuje manifest źródeł, fixture, ustawień i wyników, sprawdza rzeczywiście wykonany hash wariantu oraz liczbę obserwowanych. Patche są wyłącznie artefaktami pomiarowymi. Przywraca plik i mtime z kopii poza repo także po błędzie. flock odrzuca drugi runner w tej samej kopii; nie chroni przed obcym procesem ignorującym blokadę.

Wykonane kontrole:

- odmowa generatora dla istniejącego zbioru, złej nazwy bazy i APP_ENV=production (wyłącznie lokalne połączenie): `guard585-generator.json`;
- odmowa powtórnego wzbogacenia bez zmiany liczników: `enrichment585-guard.json`;
- zmiana znacznika jednego komentarza dawała 799 i kod 1; dokładny rekord przywrócony, dodatni odczyt 800: `fixture585-negative.json`;
- fizyczne pominięcie hydratacji w prawdziwym skrypcie dawało kod 1; MD5/mtime przywrócone i dodatnia próba: `hydration585-guard.json`;
- złe konto, drugi runner oraz fizycznie podmienione following_count wykryte: `runner585-guards.json`, `runner585-physical-negative.json`;
- proces błędu bez stdout/stderr ujawnił błędny test pustego komunikatu; poprawiono na failure is not None. Powtórzenie dało kod 1, success=false i restored=true: `runner585-error-fixed-receipt.json`;
- dodatnie generowanie od zera i odbiór: `fresh585-*.json`; końcowe media i porównanie: `media585-created.json`, `final585-runner.json`;
- Pint --test oraz PHPStan przeszły dla pięciu skryptów PHP pakietu i PomiarFeeduGraniceTest.

Wszystkie wymienione artefakty są w `evidence/feed585/`. Starsze odbiory pozostają dowodami historycznymi, nie dowodem wykonania późniejszych wersji skryptu. Kontrola liczników nie jest fingerprintem całej zawartości bazy.

## Dostarczenie i ograniczenia

Odbiór dostarczenia sprawdzono 20.09.2026 przez historię gita i GitHub: [PR #628](https://github.com/woogitsu/kuking.pl/pull/628) ma status MERGED (16.09.2026, 15:46:51 UTC), 12 zakończonych sukcesem kontroli CI oraz trzy pominięte zadania ręcznego preview. [CI main 35117632571](https://github.com/woogitsu/kuking.pl/actions/runs/35117632571) ma wynik success dla SHA scalenia. Opis PR potwierdza pełny hook zakończony kodem 0 [pomiar cudzy: opis PR #628]; nie uruchomiono go ponownie w tej korekcie. Zgłoszenia [#585](https://github.com/woogitsu/kuking.pl/issues/585) i [#609](https://github.com/woogitsu/kuking.pl/issues/609) są zamknięte; #609 zapisuje decyzję pozostawienia IN, dynamicznego licznika komentarzy oraz przeniesienia dalszej walidacji JIT do #605.

Brak pomiaru produkcji, ruchu mieszanego, nasycenia i transferu zdjęć. Syntetyczny zbiór i współdzielony host ograniczają wnioskowanie. Pomiary nie dowodzą potrzeby Go, Redis/Dragonfly ani przepisywania feedu. Brak przewagi kandydatów jest prawidłowym wynikiem benchmarku.

Końcowe niezależne review narzędzi: brak nowych blokerów poprawności i odtwarzalności; recenzent sprawdził 12 prób, liczniki oraz zgodność hashów sześciu skryptów z final585-runner.json. To historyczny odbiór opisany w pakiecie #628 [pomiar cudzy: opis PR #628]. Nieaktualny status dostarczenia skorygowano 20.09.2026 na podstawie źródeł powyżej; wniosek benchmarku pozostaje bez zmian.
