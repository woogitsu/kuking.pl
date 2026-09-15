# Rzeczywisty baseline obciążenia KuKing — load581

15.09.2026. **Wykonany pomiar lokalny**, nie test produkcji i nie porównanie języków. Źródło: wdrożony SHA `108bc93f809ff904baf694b04e96c956d17bdbd3` (Alfa 0.39). Po zakończeniu zatrzymano własny serwer; generator i worker zakończone. Host został zwolniony rootowi przed pisaniem tego raportu. Nie zmieniono aplikacji produkcyjnej, panel581, kanonicznego Git ani istniejących baz.

## Wynik

Dziesięć właściwych próbek obsłużyło **4596 poprawnych żądań użytkowych /5316 żądań HTTP**. Różnicę stanowią przekierowania zdjęć. Zero nieudanych kontroli treści/statusu, zero 429 i zero dropped iterations w tych próbkach. Rozgrzewki, diagnostyka, logowania i upload są liczone osobno.

| Właściwa próbka | Zadane żądania użytkowe/s | Poprawne | Osiągnięte/s | P50 | P95 | P99 |
|---|---:|---:|---:|---:|---:|---:|
| Publiczne strony i mały obraz |20|1201|20.01|20 ms|33 ms|36 ms|
| Zalogowany strumień, zeszyt, gotowanie |20|1201|20.00|38 ms|52 ms|55 ms|
| Cztery zakresy wyszukiwania |5|225|5.00|41 ms|54 ms|56 ms|

**To nie jest granica przepustowości.** Nie doprowadzano hosta do nasycenia; zakończono przy ustalonych stopniach. Z tych wyników nie wynika liczba ludzi, jaką obsłuży produkcja. Nie wynika też przewaga Go lub potrzeba zmiany frameworka. Szczegółowe wyniki wszystkich stopni i percentyle każdego endpointu: [TABELE.md](TABELE.md), dane [summary.json](summary.json).

## Środowisko i izolacja

- Host WSL2/Ubuntu DOM-NEW, Intel Core Ultra 7 270K Plus, 24 logiczne CPU; dostępne około31.2 GiB RAM. Szczegóły [environment.json](environment.json). W trakcie pomiarów root wstrzymał własne browser/build/PHP; nie wyłączano cudzych usług systemowych.
- Obraz `kuking:ci-108bc93f809ff904baf694b04e96c956d17bdbd3`, ID `sha256:31466a52a516249f3465c8cbea1c6e9750b734a17cba1d0afa4f39cd91aa4395`. Był już lokalnie, więc nie wykonywano builda.342 pliki PHP katalogu app porównano bajtowo z pobranym archiwum dokładnego SHA — zero różnic. To kontrola kodu app, nie deklaracja porównania wszystkich assetów obrazu. SHA256 archiwum: `03dc22f9d12e5a5ddd4bc16cfc2b876291b52313461474f97f213ef8a8e1d0a9`.
- Rzeczywista odpowiedź HTTP diagnostyki potwierdziła PHP 8.4.25, SAPI `frankenphp`, OPcache włączony. Standardowy Caddy/php_server, tryb klasyczny, cache konfiguracji/widoków z produkcyjnego entrypointu. **Nie używano artisan serve ani Octane.** Diagnostyczny plik został usunięty po pomiarze.
- Web: twardy limit2 CPU/2 GiB; k6: 1 CPU/512 MiB. Generator oficjalny Grafana k6 v2.2.0, digest `sha256:5221b620a4f874faff6e32ba597aa667c058391fe4898b1c6f6377f062c6cdec`. Nie używano Grafana Cloud; raportowanie użycia k6 wyłączone.
- Nowa `/home/mateusz/kuking-load581`, utworzona dopiero po sprawdzeniu nieistnienia. Nowa baza `kuking_load581_baseline`, właściciel kuking, jawne127.0.0.1: 55439, TimeZoneUTC. Sprawdzenie serwera poprzedzało utworzenie/migracje. PostgreSQL współdzieli host i nie otrzymał osobnego limituCPU/RAM.
- Świeży losowy APP_KEY; `runtime.env` i sesje tylko w prywatnej kopii, 0600. APP_ENV=local, APP_DEBUG=false, mail=array, lokalne dyski mediów; brak produkcyjnych sekretów/R2/OAuth/Turnstile. Sesje/cache/kolejka zachowały sterownik database. Brak Turnstile jest normalnym lokalnym ustawieniem kodu i **ogranicza zakres pomiaru logowania**; hasło, CSRF, policy i limitery tras pozostają rzeczywiste.

## Dane i ruch

Przed serią GET: 100 syntetycznych kont, 1000 opublikowanych wpisów, 300 publicznych przepisów i1 prywatny, 900 składników, 300 kroków, 200 komentarzy, 60 gotowych mediów, 20 prywatnych zeszytów. Każde z20 kont pomiarowych obserwuje10 autorów; każdy zeszyt zawiera30 przepisów i30 wpisów. Zbiór przekracza oba paginatory. Jedna rzeczywista blokada oraz prywatny przepis służą kontroli ochrony. To mały, regularny zbiór syntetyczny: nie modeluje wieloletniego rozkładu danych ani wszystkich typów treści; nie przygotowano osobnego strumienia obserwowanych tagów czy zdarzeń Ugotowałem.

20 sesji uzyskano przez rzeczywisty GET login + POST hasła/CSRF + końcowy GET po przekierowaniu. Cztery logowania na minutę, bez czyszczenia/wyłączania limitera. Mediana211 ms, minimum205 ms, maksimum345 ms; [login-results.json](login-results.json). Czas obejmuje POST i końcowy feed, nie czas człowieka wpisującego hasło. Logowania są poza czasem właściwych próbek. Jedna sesja przypisana do danego VU; 20 przygotowanych VU nie oznacza20 stale aktywnych równoległych ludzi.

- Publiczne: landing, rzeczywisty przepis, odkryty src obrazu przez /zdjecia/{media}/large i jego podpisane przekierowanie. Obraz to **2356B, 960×640, syntetyczny WebP**, więc ten wariant nie reprezentuje transferu zdjęcia z telefonu.
- Zalogowane: /home, faktyczny następny href z kursorem, /zeszyt, własny /zeszyt/{id}?page=2&wpisy=2, /przepisy/pierogi-load581-0/gotuj. Każdy VU wybiera własny zeszyt.
- Wyszukiwanie: fraza z dopasowaniami, wszystko; przepisy z ile200; ludzie; szybkie. Wyszukiwarka pozostaje pg_trgm+unaccent. Limit200 i istniejący problem#568 nie zostały zmienione.

Publiczne/zalogowane: 30 s rozgrzewki, następnie1/5/10/20 żądań użytkowych/s przez60 s, 30 s cooldown pomiędzy. Wyszukiwanie: 1 i5/s przez45 s, 61 s odstępu; osobne serie, nie jednoczesna mieszanka wszystkich rodzin. Open-model constant-arrival-rate, 20 preallocated/maxVU. Kolejność endpointów cykliczna; nie jest to rozkład ruchu zaobserwowany na produkcji.

Progi zatrzymania: nieudane żądania>5% lub p95 poprawnych odpowiedzi>5 s po20 s obserwacji, webRAM>90% limitu lub dostępny RAM host<4 GiB. Odpowiedzi429 liczone osobno i nigdy jako sukces przepustowości. Żaden z progów nie został przekroczony we właściwych próbkach.

## Zasoby, baza i cache

| Seria | Maksimum CPU web w próbkach | Maksimum RAM web | Maks. połączeń własnejDB | Oczekujący na lock | Kolejka/failed |
|---|---:|---:|---:|---:|---:|
| Publiczne |28.46% jednegoCPU|około96 MiB|2|0|0/0|
| Zalogowane |42.46% jednegoCPU|około97 MiB|2|0|0/0|
| Wyszukiwanie |13.95% jednegoCPU|około97.5 MiB|2|0|0/0|

DockerCPU 100% oznacza jeden rdzeń, nie100% dwurdzeniowego limitu. To maksimum **próbek co około4 s**, nie gwarantowany pik chwilowy. Minimum wolnej pamięci hosta około27 GiB. PomiaryDB obejmują też jedno krótkie połączenie kolektora. Brak deadlocków i przyrostu temp_bytes. BlokiDB: publiczne 6195886 trafień, zalogowane 10872216, search 1842411, przyrost odczytów z dysku0. To współdzielony buffer cache PostgreSQL dla własnejDB po przygotowaniu danych; nie jest to trafienie cache Laravel ani dowód bezkosztowegoSQL. Nie resetowano globalnych statystyk/cache. Nie przypisano CPU/RAM całego współdzielonego procesu PostgreSQL jednej bazie. Nie mierzono osobno czasu SQL/profilu frameworka.

HTTP diagnostyka OPcache przed próbami: aktywny, 903 skrypty, 31536 trafień/904 nietrafienia,około97.2% od uruchomienia (w tym przygotowanie sesji). Późniejsze restarty cold/warm resetują te liczniki — nie wyliczać jednego hitratio całego eksperymentu z końcowego snapshotu. Cache hit/miss Laravel nie ma tu osobnego pomiaru; nie zastępuj go hitratio OPcache/PostgreSQL.

## Upload i worker — osobny mały test, nie stress kolejki

Pięć prawdziwych POST multipart do /dodaj/zdjecie z hasłową sesją,CSRF,visibility=private; odstęp2 s. Każdy JPEG: 1920×1080, 1570060B, deterministyczny syntetyczny szum, jakość85. To obciąża prawdziwy dekoder/koder, lecz nie odwzorowuje naturalnych zdjęć, EXIF ani maksymalnej rozdzielczości telefonu.

Wszystkie POST zwróciły właściwe302 do nowego wpisu; czasy78–130 ms. Worker rzeczywistego database queue działał jako www-data przez maks45 s **w tym samym kontenerze**, dzieląc limit2 CPU/2 GiB z web. To wariant wspólnego budżetu podobny do roli all, nie niezależny pomiar skalowania osobnego workera. Log [worker.txt](worker.txt) dokumentuje ProcessUploadedImage oraz analizę treści. Wszystkie5 obrazów ready; kolejka 0/failed_jobs0. Metadaneupdated_at-created_at mają rozdzielczość sekund: cztery1 s i jeden0 s; 0 nie oznacza natychmiastowego przetwarzania. CPU web+worker maksimum próbkowane62.1% jednegoCPU,RAMokoło165 MiB, maksymalnie2 zadania widoczne w próbce. [upload-final.json](upload-final.json), [upload-results.json](upload-results.json).

Końcowy GET prywatnego wariantu: właściciel200 i rzeczywisty obraz; drugie konto/gość404. Wcześniejszy smoke prywatnego przepisu: właściciel200, inni403 bez prywatnego znacznika; cudzy zeszyt odmowa. Nie omijano autoryzacji.

## Cold/warm i ograniczenia wyroczni

Po serii zrestartowano wyłącznie własny kontener przed każdą parą. Pierwszy/drugi GET: landing361/73 ms, przepis285/57 ms, zalogowany feed364/84 ms. To **cold procesu PHP/OPcache, warm PostgreSQL i cache database**, nie zimna infrastruktura/CDN. Każda para ma tylko dwie obserwacje, więc nie podajemy percentyli cold ani nie utożsamiamy drugiego GET z ustabilizowaną rozgrzaną serią. [cold-warm.json](cold-warm.json).

Właściwy harness v1 ma SHA256 `f5cc6370f4d03f775dd364fe30e007df10d3482171efa2c3376fcb722c9d6ada`; snapshot [baseline-v1.k6.js](baseline-v1.k6.js), powiązanie z każdą próbą w *-runs.json. Kontroluje status+marker HTML; dla obrazu każda iteracja kontroluje końcowe200. Osobny smoke identycznego URL potwierdził Content-Type=image/webp, 2356B i magicRIFF/WEBP przed końcem serii i ponownie po niej. **Typ nie był asercją każdej iteracji k6.** Nie zmieniano harnessu w trakcie właściwych serii.

Pierwsza rozgrzewka przygotowawcza została przerwana przez próg błędów, ponieważ miernik traktował standardowe302 zdjęcia jako błąd. Po rozpoznaniu prawdziwej ścieżki dopuszczono przekierowania wyłącznie dla obrazu i zmierzono cały łańcuch; ponowiono rozgrzewkę oraz całą właściwą serię. Pierwszy wynik pozostaje osobno jako public-warmup-redirect-diagnostic; nie zalicza się do4596 sukcesów. Początkowe404 syntetycznych mediów wynikało z katalogu700 utworzonego przez seederroot; przed load skorygowano wyłącznie własny katalog do właściciela www-data. Nie była to diagnoza usterki produkcji.

Nie zmierzono CDN/TLS/internetu telefonu, rzeczywistegoR2, dużego zbioru, długiego soak, nasycenia, pełnego miksu równoczesnych uploadów i czytania, wykonań Livewire ani wszystkich policy. Krótkie próby i małe liczby próbek perendpoint ograniczają interpretację p99. Statyczne assety strony nie były automatycznie pobierane przez k6 jak w przeglądarce. Lokalne cache i dysk zaniżają koszty wobec części środowisk sieciowych.

## Co porównać dalej

Nie znaleziono w tym zakresie dowodu, że framework jest ograniczeniem wymagającym przepisania. Następny pomiar powinien zwiększyć rozmiar i różnorodność danych, ustalić rzeczywisty miks i dopiero potem mierzyć nasycenie oraz profilSQL/CPU. Jeśli porównujemy technologię, potrzebne trzy równoważne warianty: **A obecnyLaravel; B Laravel+Dragonfly dla tego samego cache; C Go+GORM+ten samDragonfly/schemat/uprawnienia**. B oddziela zysk cache od języka. Nie wolno przypisać Go przewagi uzyskanej tylko przez dołożenie cache. Nie zaimplementowano tutaj Go,Dragonfly ani FTS.

Oficjalne podstawy przyrządu: [k6 constant arrival rate](https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/constant-arrival-rate/), [metryki k6](https://grafana.com/docs/k6/latest/using-k6/metrics/reference/). Skrypty i odkażone wyniki są w tym katalogu; hasła,sesje i środowisko pozostały wyłącznie w prywatnym /home/mateusz/kuking-load581. Baza i zatrzymany własny kontener pozostają do reprodukcji; nie kasowano żadnych zastanych danych.