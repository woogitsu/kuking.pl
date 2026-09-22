# Realistyczne obciążenie #605 — pomiar lokalny 20.09.2026

Nasycenie osiągnięto. W tej lokalnej rampie 15 żądań/s przeszło bez błędów,
a przy 30 żądaniach/s 72,1% żądań zakończyło się niepowodzeniem — przede
wszystkim timeoutem, bez 429. Pierwszym objawem jest zaleganie żądań HTTP
przy kosztownych odczytach SQL, nie narastanie kolejki zdjęć. Przy 60/s
kontener dociera następnie do limitu pamięci. Po największym napływie mały
ruch 5/s również nie działa; samoczynnego powrotu nie zaobserwowano przez
ponad 11 minut. Restart wyłącznie własnego kontenera przywrócił odpowiedź.

To ustala przedział degradacji tej konfiguracji i mieszanki, nie dokładną
maksymalną pojemność ani liczbę użytkowników produkcji. Potwierdzono duży
koszt JIT w rzeczywistym zapytaniu feedu. Benchmarki Octane i silników obrazów
nie są następnym uzasadnionym krokiem przed oceną kosztu SQL i zaległości HTTP.

## Zakres i pochodzenie dowodów

Wszystkie liczby z katalogu `2026-09-20-gpt` są pomiarami własnymi tej sesji.
Warunki zapisano przed rampą w `WARUNKI.md`. Wyniki wcześniejszych audytów
nie są wynikami tej próby. [pomiar cudzy: `docs/infra/POMIAR_FEEDU_585.md`]
wskazał koszt kompilacji JIT jako hipotezę; nie przenosimy tamtych czasów
na obecną bazę. [pomiar cudzy: `docs/infra/evidence/load581/`] to dawny
baseline, nie dowód pojemności obecnego portalu.

Kod aplikacji pozostał na `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Zmiana `ec289cd8` dotyczy przyrządu: jawny korpus fotografii, kontrola SHA-256,
rotacja plików, osobny licznik każdego pliku i rozpoznawanie przekierowań.
Nie zmieniono zapytań, procesora zdjęć, schematu ani konfiguracji produkcji.

## Wyniki rampy

| Seria | Werdykt | Napływ/s | Poprawne / czas napływu | Nieudane / wysłane | p50 / p95 / p99 sukcesów [ms] | p95 z błędami [ms] |
|---|---|---:|---:|---:|---|---:|
| r005-p1 | SKAZONY | 5 | 5 | 0 / 600 | 50.7 / 1175.6 / 1437 | 1175.6 |
| r005-p2 | CZYSTY | 5 | 5 | 0 / 600 | 51 / 1021.1 / 1146.5 | 1021.1 |
| r015-p1 | CZYSTY | 15 | 15 | 0 / 1800 | 679.6 / 2750.5 / 3218.1 | 2750.5 |
| r030-p1 | CZYSTY | 30 | 8.37 | 2595 / 3600 | 9744.9 / 19641.6 / 33359 | 20001.2 |
| r060-p1 | CZYSTY | 60 | 4.53 | 6656 / 7200 | 9337.1 / 19554.4 / 56190.7 | 20001.6 |
| r120-p1 | CZYSTY | 120 | 0 | 14400 / 14400 | — / — / — | 20012.1 |
| r005-powrot | CZYSTY | 5 | 0 | 600 / 600 | — / — / — | 20001.5 |

| Seria | CPU kontenera [rdzenie, średnia] | Pamięć cgroup [MiB, maks. current] | Kolejka maks. / wiek [s] | Aktywne DB maks. / lock waits |
|---|---:|---:|---:|---:|
| r005-p1 | 0.202 | 534 | 3 / 3 | 4 / 0 |
| r005-p2 | 0.237 | 616 | 5 / 3 | 4 / 0 |
| r015-p1 | 0.457 | 682 | 5 / 2 | 4 / 0 |
| r030-p1 | 0.512 | 869 | 5 / 4 | 5 / 0 |
| r060-p1 | 0.529 | 1022 | 7 / 2 | 4 / 0 |
| r120-p1 | 0.499 | 1017 | 7 / 2 | 5 / 0 |
| r005-powrot | 0.411 | 953 | 3 / 2 | 4 / 0 |

Średnie CPU obejmują okno generatora wraz z domykaniem żądań. Pierwsza
próba 5/s jest skażona i pozostaje wyłącznie dowodem diagnostycznym.
Surowe wyniki, próbki, otoczenie i werdykty: `serie/`. Wszystkie przebiegi
miały zero błędów wewnętrznych przyrządu i zero porzuceń przez limit
3000 żądań w locie. Przy 30/s szczyt wyniósł 609, przy 60/s 1236, przy
120/s 2474. Zatem to nie limit generatora ustalił zaobserwowaną degradację.

## Przygotowanie i kontrola zachowania przed zmianą

Na nietkniętym drzewie wykonano `node scripts/przyrzad-605.test.mjs`:
21 kontroli PASS. Odczyt istniejącego generatora pokazał jeden stały JPEG
na uploadzie. Zdjęcia z `zdjecia-obciazenia-605.php` są syntetyczne, mimo
sformułowania „prawdziwe zdjęcia” w starej metodzie.

Nowy test korpusu uruchomiony PRZED poprawką oblał: serwer kontrolny dostał
`kuking-b605-12mpx.jpg`, zamiast trzech nazw z manifestu. Po poprawce przechodzi.
Dodatkowa kontrola fizyczna wyłączyła sprawdzanie redirectu w kopii wykonawczej:
PASS → FAIL na asercji „302 wracające do formularza” → PASS po przywróceniu.
Skrypt potwierdził przywrócenie MD5 i mtime. Uwaga: zapisany JSON
`kontrola-ujemna-redirect.json` powstał przed pułapką EXIT i jego pole
`przywrocenie` nie jest końcowym potwierdzeniem; końcowe potwierdzenie
odczytano z konsoli. Nie traktować tego pola jako pozytywnego dowodu.

## Dane i zdjęcia

Własna baza `kuking_b605_gpt_obciazenie`, PostgreSQL na `127.0.0.1:55439`.
Generator przygotował 200 000 wpisów, 20 000 przepisów, 200 000 komentarzy,
62 420 relacji obserwowania, 15 456 zapisów w zeszytach i 1 510 blokad.
Pełne liczniki: `dane.json`. Utworzenie zajęło 457,1 s. Zalogowano 24 konta
przez rzeczywisty formularz i sesje aplikacji.

Korpus obejmuje pięć pobranych fotografii z Wikimedia Commons, według EXIF
Nikon D750 oraz iPhone 15 Pro: 12,2–48,8 MP, 2,75–12,04 MB, pion i poziom.
To pliki źródłowe publikacji Commons, nie dowód braku wcześniejszej obróbki
przez autorów. Tematy fotografii są różne; nie jest to zbiór zdjęć potraw.
Dochodzi ucięcie do 300 bajtów, uszkodzony APP1/EXIF oraz poprawny EXIF
orientation=6, utworzone z rzeczywistej fotografii bez zmiany pikseli.
Źródła, autorzy, licencje i sumy: `korpus-zrodla.json`; dokładne odtworzenie:
`ODTWORZENIE_KORPUSU.md`.

Przed rampą przez istniejący upload przepuszczono wszystkie osiem plików.
Siedem zostało przyjętych i osiągnęło `ready`, ucięty plik odrzucono.
Po przetworzeniu kolejka i failed_jobs miały zero rekordów. Wariant feed
orientation=6 miał 720×960, a odpowiadający mu oryginał 960×720.
Sprawdzenie zapisanych WebP nie wykryło chunków EXIF/XMP. Nie jest to
pełny audyt wszystkich sposobów ukrywania metadanych.

Zbiór 200 tys. wpisów NIE oznacza 200 tys. fotografii. Manifest odczytu
zawierał 27 adresów wariantów z rozgrzewki, wielokrotnie odczytywanych.
To mierzy konkurencję uploadu i odczytu rozgrzanych mediów, nie pojemność
wielkiego zimnego magazynu. Korpus i prywatne ciasteczka nie są commitowane.

## Środowisko i ograniczenia

FrankenPHP Classic, PHP 8.4.25, cztery wątki, obraz wskazany w `WARUNKI.md`,
kontener `kuking-gpt-obciazenie-app`, topologia `all` (web i kolejka razem),
limit 2 CPU i 1 GiB, bez dodatkowego swapu. PostgreSQL jest poza tym limitem.
Nie jest to topologia po rozdzieleniu usług z #595.

Host WSL ma 24 CPU i pracuje współbieżnie dla innych zadań. Bramka nie
zatrzymuje cudzych procesów. Werdykt „CZYSTY” oznacza przejście wcześniej
ustalonego progu, nie wyłączne korzystanie z hosta. Pozostaje wspólny cache
procesora, pamięć, dysk i PostgreSQL. Próbnik nie odejmuje CPU backendów
własnej bazy od „obcego CPU”; przy dużym ruchu może odrzucić stopień również
z powodu pracy własnej bazy. Nie skorygowano progów po obejrzeniu wyników.

Ruch przechodzi przez lokalny HTTP i lokalny storage. Nie zmierzono R2,
podpisanych przekierowań do R2, Cloudflare cache hit, TLS ani sieci telefonu.
Mieszanka 14 scenariuszy i 1% uploadów pochodzi z istniejącej metody, nie
z telemetrii rzeczywistych osób 50+. Nie przeliczać RPS na „użytkowników online”.
Oczekiwane odrzucenie uszkodzonego pliku liczy się jako obsłużone żądanie,
nie jako publikacja. Liczby uploadów każdego pliku stoją w wynikach serii.
Dodatkowe granice istniejącego przyrządu:

- Baza ma konta obserwujące do 1200 osób, ale zalogowane konta 0–23 tej
  próby obejmują 10–500 obserwowanych; nie zmierzono sesji z 800/1200.
- Scenariusz `zal_feed_str2` wysyła `/home?page=2`. Feed używa kursora,
  więc ta nazwa nie dowodzi przejścia na drugą stronę. Wynik opisuje
  rzeczywiście wysłany adres; nie przypisujemy mu pokrycia paginacji.
- Pola `kontener_rss_mb` i `kontener_rss_szczyt_mb` przyrządu pochodzą
  z `memory.current` i `memory.peak` cgroup: obejmują również cache, nie są
  czystym RSS procesu. `memory.peak` jest szczytem od startu kontenera,
  a nie zerowanym maksimum każdego stopnia. Web i worker mają osobne odczyty.
- Przepustowość w pliku to liczba poprawnych żądań podzielona przez czas
  napływu (120 s), także gdy odpowiedź doszła podczas domykania.
  Nie jest to liczba zakończonych odpowiedzi podzielona przez całe okno
  napływu i domykania. Przy błędach podajemy też percentyle z błędami;
  percentyl samych sukcesów może ukryć najwolniejsze zerwane żądania.
- Domyślnie brak odpowiedzi przez 20 s lub całkowity czas 30 s kończy
  zwykłe żądanie. Upload ma 60/90 s. Po napływie generator czeka 30 s,
  potem anuluje resztę. Anulowanie u klienta nie dowodzi wycofania zapisu
  po stronie serwera; stan rekordów trzeba sprawdzić osobno.
## Błąd próbnika znaleziony podczas odczytu wyników

Pierwsza rampa używała przyrządu `ec289cd8`. Surowe próbki `r060-p1`
i `r005-powrot` zawierają ujemne `worker_rdzenie`: poprzednia implementacja
odejmowała liczniki różnych procesów po wymianie workera. Te średnie CPU
workera są nieważne i nie służą do wniosku o GD. Nie poprawiono historycznych
plików przez obcięcie liczb do zera. CPU całego kontenera pochodzi z osobnego
licznika cgroup; głębokość kolejki i wyniki HTTP również są niezależne.

Poprawka `e96b6b71` rozpoznaje zmianę PID, brak procesu i cofnięcie licznika.
Zapisuje `null`, dopóki nie ma pełnego okna tego samego procesu. Test najpierw
pokazał rzeczywiste -9,9 zamiast braku pomiaru; po poprawce przeszedł. Kontrola
dodatnia mierzy 1 rdzeń, ujemna wykrywa także zmianę PID z rosnącym licznikiem,
a kolejne okno sprawdza zapamiętanie nowego procesu. Fizyczna mutacja warunku
PID oblała właściwą asercję i przywróciła MD5/mtime. Pełne konsole obu kontroli
ujemnych są w dowodach; zawierają końcowe potwierdzenie przywrócenia.

Nie wykonano ponownie całej rampy po tej poprawce. Para JIT używa poprawionego
próbnika. Nie mieszamy ważnego CPU kontenera z nieważną średnią procesu.
## Powrót do normy i zapisane zdjęcia

Największy napływ skończył się około 18:20:05 UTC. Kontrolny powrót do 5/s
(`r005-powrot`) miał 600/600 niepowodzeń, przy czystym otoczeniu. Odczyty
`powrot-obserwacja.jsonl` pokazują dalszą pracę własnej bazy do 18:30:46 UTC.
O 18:31:33 UTC pojedynczy GET `/` nadal nie dostał żadnych bajtów przez 5 s.
To ponad 11 minut po dużym napływie, a ponad 6 minut po napływie kontrolnym.
Nie wyznaczono czasu pełnego samoczynnego opróżnienia zaległości: zakończono
obserwację i wykonano jawny restart własnego kontenera. Po nim GET `/`
wrócił jako HTTP 200 w 0,534 s. Dowód: `restart-po-rampie.txt`.

Nie było OOM kill ani samoczynnego restartu kontenera. Licznik cgroup
`memory.events max` urósł do 1520, a peak do 1024 MiB: limit pamięci był
osiągany. Nie utożsamiamy tego licznika z liczbą zabitych procesów.

O 18:26:42 UTC było 119 mediów `ready`, zero jobs i zero failed_jobs.
W serii 120/s zapisano już 15 wpisów ze zdjęciami, choć klient nie zaliczył
ani jednego sukcesu tej serii. To ważna różnica między timeoutem klienta
a brakiem zapisu na serwerze. Te wpisy są syntetyczne i pozostają w prywatnej
bazie pomiarowej. Pełne liczniki poszczególnych serii: `media-po-rampie.jsonl`.
To punkt w czasie podczas opróżniania zaległości, nie obietnica, że później
nie powstały kolejne rekordy.

Stopnie 60/120 i powrót do 5/s są dalszą częścią narastającej rampy.
Minuta obserwacji i bramka spokoju hosta NIE zapewniły opróżnienia żądań
HTTP z poprzedniego stopnia. Nie podajemy ich jako niezależnych pojemności
serwera startującego od zera. Pierwsza degradacja 30/s wystąpiła wcześniej,
po stopniu 15/s bez błędów.

## Diagnostyka kosztu SQL

Odczyt ustawień własnego połączenia potwierdził JIT on i próg 100000.
Próbka aktywności podczas zaległości 30/s (`aktywne-zapytania-r030.json`)
zawiera rzeczywiste SELECT-y feedu oraz wybór wpisów przez DISTINCT ON
autora. Nie odnotowano oczekiwania na blokady w próbkach rampy. Nie czytano
cudzych zapytań ani nie zmieniano wspólnego logowania PostgreSQL.

Istniejącym przyrządem `pomiar-feedu.php` odtworzono rzeczywisty FollowingFeed
dla konta z 10 obserwowanymi. Kopia wykonawcza ma wyłącznie adaptację ścieżek
bootstrapu i nazwy własnej bazy (`jit/adaptacja-przyrzadu.patch`).

| Sesyjne JIT | paginate [ms] | Suma czasów DB [ms] | Najwolniejszy SELECT [ms] | Kompilacja JIT w osobnym EXPLAIN [ms] | Execution Time EXPLAIN [ms] |
|---|---:|---:|---:|---:|---:|
| on | 438,49 | 428,61 | 423,33 | 322,93 | 392,17 |
| off | 84,76 | 76,36 | 73,03 | brak | 76,53 |
| on ponownie | 410,27 | 401,41 | 397,72 | 308,49 | 377,67 |

Zapytania, parametry, 15 wynikowych wierszy z licznikami i kursor były takie
same we wszystkich trzech próbach (`jit/jit-semantyka.json`). JIT odpowiada
za około 82% czasu wykonania tych planów on. EXPLAIN jest osobnym powtórnym
wykonaniem, nie składnikiem czasu zmierzonego wcześniejszego paginate.
Czas DB jest zmierzony przez Laravel, nie jest globalną metryką wszystkich
żądań rampy. Nie odtworzono osobno czasu każdego zapytania DISTINCT ON.

To własne potwierdzenie kosztu kompilacji, bez zmiany SQL i bez wyboru nowego
silnika. Nie dowodzi, że JIT jest jedynym kosztem ani że jego wyłączenie
rozwiązuje wszystkie problemy kolejki HTTP. Warunki późniejszej próby
mieszanej stoją w `WARUNKI_JIT.md`; jej wyniki należy oceniać z werdyktami.
## Mieszany ruch z JIT on/off

| Seria | Werdykt | Poprawne / wysłane | Poprawne / czas napływu | p95 sukcesów [ms] |
|---|---|---:|---:|---:|
| jit-on-r030 | SKAZONY | 508 / 3600 | 4.23 | 19349.1 |
| jit-off-r030 | SKAZONY | 1749 / 3600 | 14.57 | 17873 |
| jit-on-r030-p2 | CZYSTY | 398 / 3600 | 3.32 | 19792.6 |
| jit-off-r030-p2 | SKAZONY | 3599 / 3599 | 29.99 | 10914.5 |

**Nie uzyskano czystej pary. Nie wyznaczono wzrostu pojemności po wyłączeniu
JIT.** Pierwsze on/off mają odpowiednio 37,9% i 92,9% zakłóconych próbek.
Powtórka on przeszła (1,9%, najwyżej jedna próbka kolejno). Powtórka off
odpadła: 3/94 próbek = 3,2% i dwie kolejne. Próg pozostał taki jak przed
pomiarem, mimo atrakcyjnego wyniku 3599/3599 w ostatniej próbie.

Deterministyczne losowanie nie gwarantuje identycznej liczby wysłań przy
oknie ograniczonym zegarem: ostatnia próba nadała 3599 zamiast 3600 żądań.
Nie ukryto tej różnicy. Dane zapisów przyrastały między próbami; nie był to
zamrożony snapshot. Wniosku o przyspieszeniu konkretnego SQL z on/off/on
nie zamieniamy na współczynnik pojemności całego serwisu.

Kontener po parze odtworzono z oryginalnym środowiskiem bez PGOPTIONS.
Własne połączenie odczytało JIT on, GET `/` dał 200 w 0,649 s. Następnie
kontener zatrzymano (`exited`). Końcowo: 219 mediów ready, jobs=0,
failed_jobs=0. Dowody: `odbior-ustawien.json`, `odbior-mediow.jsonl`,
`odbior-kontenera.txt`. Baza i fotografie pozostały do odtworzenia pomiaru.

## Co sprawdzono, a czego nie wykonano

Własne kontrole kodu: 21 istniejących sprawdzeń przyrządu oraz nowe kontrole
korpusu i zmiany PID; fizyczne kontrole ujemne obu poprawek z przywróceniem
MD5/mtime; `bash -n` zmienionych skryptów; `vendor/bin/pint` — 1155 plików;
PostgreSQL: `MediaUploadTest`, `JednoDekodowanieZdjeciaTest`,
`UploadLimitsAgreementTest` — 10 testów, 353 asercje. Konsole: `kontrole/`.

Nie uruchomiono pełnego pakietu testów, pełnego `scripts/check.sh`, buildu
assetów ani CI: zakres zmian to przyrząd shell/Node i dowody, aplikacja oraz
assety nie zostały zmienione. Nie zgłaszamy zielonego pełnego pakietu.
`ProbaOdtworzeniaTest` nie był uruchamiany; nie przypisujemy mu wyniku ani
nie powołujemy się na historyczną porażkę jako sprawdzoną w tej sesji.

Nie wykonano benchmarków #612 ani #613. W pierwszej zmierzonej degradacji
problemem są kosztowne odczyty SQL i zalegające żądania. Nie ma podstaw, aby
na tym etapie płacić kosztem porównania lub wdrożenia Octane, Imagick czy
libvips. Nie stwierdzamy, że PHP lub GD nigdy nie będą ograniczeniem — po
zmianie kosztu SQL trzeba ponownie zmierzyć mieszankę. Nie weryfikowano
utrzymywanej integracji libvips i niczego takiego nie dodano; wymóg ze #613
pozostaje warunkiem rozpoczęcia ewentualnej oceny tego silnika.

Nie zmieniono ustawień produkcji, wspólnego klastra PostgreSQL ani schematu.
Nie wykonano push, PR, komentarza ani zamknięcia zgłoszeń. Nie kontaktowano
się z ludźmi ani nie zatrzymywano cudzych procesów. Raport nie zamyka części
#605 dotyczącej R2/Cloudflare i docelowej rozdzielonej topologii.

## Decyzje dla właściciela

| Wariant | Co daje | Koszt / warunek |
|---|---|---|
| Ocenić wyłączenie JIT tylko dla połączeń aplikacji | Usuwa zmierzony koszt kompilacji bez nowego silnika | Osobna zmiana konfiguracji z kontrolą pozostałych ciężkich zapytań, pomiarem mieszanym i prostym wycofaniem; ten raport sam nie zatwierdza produkcji |
| Ustalić maksymalny czas czekania i sposób odrzucania nadmiarowych żądań | Pozwala zaprojektować przeciążenie bez wielominutowego blokowania nowych wejść | Decyzja o akceptowalnym oczekiwaniu i komunikacie ponowienia; dodatkowy limit ma kompromis między odrzuceniem a długim czekaniem, nie wolno go arbitralnie zabetonować testem |
| Najpierw ponowić pomiar na docelowej topologii i odizolowanym hoście | Wiarygodniejsza pojemność i ocena kolejnego ograniczenia | Osobne środowisko/czas pomiaru oraz skonfigurowana bezpieczna ścieżka mediów; brak podstaw do liczby „użytkowników online” bez telemetrii |

Rekomendowana kolejność: koszt SQL i zachowanie kolejki HTTP, następnie
ponowne nasycenie w docelowych warunkach. Porównanie silników dopiero wtedy,
gdy odpowiada na zmierzone ograniczenie. Nie zmieniamy decyzji produktowych
ani konfiguracji produkcji asercją w teście przyrządu.
## Odtworzenie i stan pozostawiony lokalnie

Gałąź `gpt/obciazenie`; kod przyrządu: `ec289cd8` i `e96b6b71`.
Katalog roboczy Windows: `C:\Users\matma\Documents\kuking-flota\gpt-obciazenie`.
Kopia wykonawcza WSL: `/home/mateusz/flota/gpt-obciazenie-run`.
Prywatne fotografie, manifest sesji i środowisko:
`/home/mateusz/flota/gpt-obciazenie-private` (poza repozytorium).
Baza `kuking_b605_gpt_obciazenie` i wolumin mediów pozostają zachowane;
kontener `kuking-gpt-obciazenie-app` jest zatrzymany.

Przed ponowną próbą przeczytaj zasady floty i przygotuj runtime jej skryptem
z obowiązkowym MSYS_NO_PATHCONV=1. Użyj istniejących narzędzi #605, własnej
bazy na 127.0.0.1:55439 i osobnego katalogu wyników, aby nie nadpisać dowodów.
Rampa i parametry: `WARUNKI.md`; para JIT i seed: `WARUNKI_JIT.md`;
ponowne pozyskanie fotografii: `ODTWORZENIE_KORPUSU.md`. Sam manifest sesji
jest prywatny i nie powinien trafić do commita. Odnowienie sesji wykonuje
istniejący tryb `login` generatora.

Instrukcję odtworzenia korpusu wykonano na kopiach pobranych fotografii:
8/8 sum SHA-256 zgadza się (`odtworzenie-korpusu.json`). Pliki JSON/JSONL
sprawdzono parserem. Zapis PowerShell w UTF-16/BOM sprowadzono do UTF-8;
nie zmieniano wartości próbek. Zakończenia linii są LF; w konsolach usunięto
kody kolorów i końcowe spacje. Sumy końcowych artefaktów są w `SHA256SUMS.txt`.