# Stan przygotowania i co jeszcze NIE jest zmierzone (#605)

Stan na 18.09.2026, 11:35 czasu lokalnego. Metoda: `METODA.md` w tym katalogu.

**Seria pomiarowa do nasycenia NIE ZOSTAŁA jeszcze zdjęta.** Powód jest
zapisany, bo bez niego ten dokument wyglądałby na niedokończony, a jest
świadomie wstrzymany: maszyna pomiarowa jest współdzielona i w oknie pracy nad
tym zadaniem chodziły na niej stale cudze procesy — self-hosted runner CI
(port 34555), pełne `php artisan test` w worktree Codeksa, pętla kopii
Subagenta A (MinIO + trzy kontenery PostgreSQL) i zestawy Playwrighta.
`load average` trzymał się między **9 a 18** przy 24 rdzeniach. Seria zdjęta
w takim otoczeniu mierzyłaby cudzy hałas, a nie aplikację, i nie dałoby się
później odróżnić jednego od drugiego. Okno ciszy potwierdza koordynator.

Wszystko poza samymi seriami jest gotowe i sprawdzone działaniem.

---

## 1. Co jest zbudowane

### 1.1 Zbiór danych — `dane.json`

Baza `kuking_b605_obciazenie` (127.0.0.1:55439, PostgreSQL 18.6). Zbudowany
w **347 sekund** przez `scripts/dane-obciazenia-605.php`:

| tabela | wierszy |
|---|---:|
| `users` (2000 autorów + 60 widzów) | 2 060 |
| `profiles` | 2 060 |
| `follows` | 62 420 |
| `posts` | 200 000 |
| `recipes` | 20 000 |
| `recipe_steps` | 90 000 |
| `recipe_ingredients` | 149 996 |
| `comments` | 200 000 |
| `cooked_events` | 25 000 |
| `collections` | 480 |
| `collection_items` (zapisy w zeszytach) | 15 456 |
| `post_tags` | 399 697 |
| `tags` (z `TagSeeder`) | 1 446 |
| `tag_follows` | 978 |
| `blocks` | 1 510 |

Rozkład jest **nierówny**, zgodnie z #605:

- 60 % wpisów napisało **20 autorów** z dwóch tysięcy, 40 % przepisów — **50**;
- **40 przepisów ma 300–600 komentarzy** (razem 17 640), pozostałe 182 360
  komentarzy rozkłada się cienko po wpisach i przepisach;
- konta widzów obserwują 10 / 25 / 50 / 100 / 250 / 500 / 800 / 1200 osób
  (pełna lista w `dane.json`);
- tagi po Zipfie — pierwsza dziesiątka zbiera większość z 399 697 przypięć;
- co dziesiąty zeszyt ma 250 pozycji, pozostałe po kilka;
- widoczność wpisów 80 / 15 / 5 % (publiczne / obserwujący / prywatne);
- blokady obejmują także konta widzów.

### 1.2 Zdjęcia — `zdjecia.json`, `rozgrzewka-mediow.txt`

Trzy prawdziwe pliki JPEG (`scripts/zdjecia-obciazenia-605.php`):

| plik | Mpx | jakość | rozmiar |
|---|---:|---:|---:|
| 12 Mpx (4000 × 3000) | 12,0 | 88 | **5,64 MB** |
| 24 Mpx (5657 × 4243) | 24,0 | 88 | **11,12 MB** |
| 48 Mpx (8000 × 6000) | 48,0 | 72 | **13,62 MB** |

Wgrane **ścieżką produktową** (`POST /dodaj/zdjecie`), po 8 z każdego rozmiaru
w rozgrzewce plus 8 z celowych sprawdzeń progu 25 Mpx — razem **32 wiersze
`media`**, wszystkie w statusie `ready`, **0 nieudanych zadań**,
55 adresów `/zdjecia/{uuid}/{wariant}` (thumb / feed / large) sprawdzonych
przez generator. Kontrolnie wariant `large` spod `/zdjecia/{uuid}/{wariant}`
oddaje **636 618 B `image/webp`**, czyli prawdziwy plik, a nie 404.

### 1.3 Sesje

60 kont widzów zalogowanych prawdziwym formularzem. Mediana logowania
**175 ms**, maksimum 205 ms. Cele odkryte z aplikacji: 2 557 przepisów,
811 wpisów, 100 tagów, 500 profili.

### 1.4 Przyrząd

- `scripts/dane-obciazenia-605.php` — zbiór danych,
- `scripts/zdjecia-obciazenia-605.php` — pliki zdjęć,
- `scripts/generator-obciazenia-605.mjs` — generator ruchu (Node 24, bez zależności),
- `scripts/probnik-obciazenia-605.sh` — próbnik CPU/RSS/DB/kolejki,
- `scripts/seria-obciazenia-605.sh` — jedna seria wraz z zapisem otoczenia.

Sprawdzone działaniem: wszystkie 14 scenariuszy mieszanki oddają oczekiwane
kody (200 dla odczytów, 302 dla zapisów), próbnik zapisuje pełny komplet pól,
kontener startuje z obrazu produkcyjnego tego commita.

`vendor/bin/pint` na plikach PHP tego zadania: czysto. `php artisan test`
na bazie `kuking_b605_testy` z `PGTZ=UTC`: **4076 testów, 81 419 asercji,
wszystkie zielone** (385 s).

---

## 2. Co już widać, a czego jeszcze NIE WOLNO nazwać wynikiem

### 2.1 Koszt ścieżki wgrania rośnie NIEMONOTONICZNIE z rozmiarem zdjęcia

`rozgrzewka-mediow.txt`, 24 kolejne wgrania, odstęp 5 s, kolejka pusta między nimi.

**Każda liczba w tej tabeli jest zdjęta na GŁOŚNEJ MASZYNIE** (`load average`
12–15 od cudzych procesów) i **nie jest wynikiem pomiarowym**. Żadnej z nich
nie wolno cytować jako „tyle kosztuje wgranie zdjęcia”. Nośna jest tu wyłącznie
**relacja między wierszami** — i to dlatego, że powtarza się osiem razy z rzędu
w każdym wierszu, bez ani jednego wyjątku, a taki podział nie bierze się
z hałasu.

| rozmiar | czas `POST /dodaj/zdjecie`, 8 wgrań — **GŁOŚNA MASZYNA, NIE WYNIK, NIE CYTOWAĆ** |
|---|---|
| 12 Mpx | 228 · 258 · 264 · 264 · 265 · 278 · 281 · 327 ms (nie wynik) |
| 24 Mpx | 447 · 452 · 455 · 456 · 462 · 470 · 486 · 503 ms (nie wynik) |
| **48 Mpx** | **71 · 75 · 78 · 82 · 83 · 89 · 89 · 93 ms** (nie wynik) |

Największe zdjęcie jest na ścieżce żądania **pięć razy TAŃSZE** niż średnie.
To nie jest artefakt pomiaru — to wynika wprost z konfiguracji:
`kuking.media.podglad.max_megapixels` **= 25**, a `PodgladOdRazu` robi
synchroniczny wariant 640 px tylko poniżej tego progu (issue #430,
`docs/MEDIA_PIPELINE.md`). Zdjęcie 48 Mpx próg przekracza, więc **całą pracę
oddaje kolejce** i żądanie kończy się od razu.

**Sprawdzone celowo, nie domysłem** — pełny zapis w `prog-25mpx-a-podglad.md`.
To sprawdzenie nie jest pomiarem czasu, więc hałas na maszynie go nie dotyczy:

- **poziom danych** — ani jedno wgranie 48 Mpx nie ma
  `metadata.variants.podglad`; wszystkie wgrania 12 i 24 Mpx mają;
- **poziom strony** — przy 24 Mpx świeże zdjęcie jest na stronie wpisu już
  w pierwszym renderze (jako `podglad`); przy 48 Mpx **identyfikatora świeżego
  zdjęcia nie ma w HTML-u wcale**, aż kolejka skończy pracę.

Czyli powyżej 25 Mpx naprawa z #430 przestaje działać: autor publikuje wpis
i przez czas przetwarzania **nie widzi swojego zdjęcia**. Dopowiedzenie, którego
nie wolno pominąć: w dzienniku aplikacji **nie ma** ostrzeżenia „Zdjęcie bez
wygenerowanych wariantów”, więc `Media::url()` nie jest w ogóle wołany — widok
pomija zdjęcie warunkiem `isReady()` (komentarz AUDYT A3
w `resources/views/components/layout.blade.php`). Autor nie dostaje więc ani
zastępczego znaku marki, ani komunikatu — po prostu zdjęcia nie ma.

Czego to jeszcze nie mówi: **ile trwa to okno pod obciążeniem**. To należy do
serii. Rzecz jest zauważona przy okazji i **nie została zgłoszona do #430 ani
#605** — to decyzja do podjęcia razem z pomiarem opóźnienia kolejki.

### 2.2 Kolejność kosztu tras na realistycznym zbiorze

Źródło: `kontrola-przyrzadu.json` — **kontrola działania przyrządu, NIE wynik
pomiarowy**. 5 żądań/s przez 30 s (149 żądań), zdjęta 18.09 ok. 11:30 przy
`load average` 12–15 pochodzącym od CUDZYCH procesów na tej maszynie:
self-hosted runner CI, pełne `php artisan test` w worktree Codeksa, pętla kopii
Subagenta A, zestawy Playwrighta.

**Czytelniku: żadnej liczby z poniższej tabeli nie wolno zacytować, wstawić do
issue ani porównać z load581.** Kolumna „p50 (NIE WYNIK)” jest tu po to i tylko
po to, żeby pokazać **kolejność** tras — który ekran jest tani, a który drogi.
Wartości bezwzględne zawierają cudzy hałas i przy 149 próbkach mają przedział
ufności, którego nikt nie policzył. Tabela w prawej kolumnie zestawia je
z load581 **wyłącznie dla skali zjawiska**, a nie jako pomiar „przed/po”:
tamte liczby powstały innym przyrządem, na innym (małym) zbiorze i przy innym
limicie kontenera.

| scenariusz | p50 — **NIE WYNIK, głośna maszyna, nie cytować** | load581: inny przyrząd, mały zbiór — **nie odejmować** |
|---|---:|---:|
| `/home?page=2` (feed obserwowanych, str. 2) | ~1 164 ms (nie wynik) | 38 ms |
| `/home` (feed obserwowanych) | ~1 039 ms (nie wynik) | 46 ms |
| `/szukaj?q=…` | ~557 ms (nie wynik) | 41 ms |
| `/odkryj` z sesją | ~540 ms (nie wynik) | — |
| `/` (anonimowo) | ~440 ms (nie wynik) | 31 ms |
| `/@profil` | ~88 ms (nie wynik) | — |
| `/tag/{tag}` | ~80 ms (nie wynik) | — |
| `/przepisy/{slug}` | ~48 ms (nie wynik) | 20 ms |
| `/zdjecia/{uuid}/{wariant}` | ~47 ms (nie wynik) | 19 ms |
| `/wpisy/{uuid}` | ~41 ms (nie wynik) | — |
| `POST komentarz` | ~39 ms (nie wynik) | — |

Hipoteza do sprawdzenia serią: **na realistycznym zbiorze rozjazd między
najtańszą a najdroższą trasą jest rzędu dwudziestopięciokrotnego**, a cała droga
„wejście przez feed” kosztuje rząd wielkości więcej niż strona przepisu czy
oddanie zdjęcia. W load581 tego nie było widać, bo tamten zbiór był mały
i równomierny. Do potwierdzenia w oknie ciszy — łącznie z rozbiciem na to, ile
z tego czasu to baza, a ile renderowanie.

Koszt samego generatora w tej kontroli: **0,03 rdzenia**, 80 MB RSS. Ta akurat
liczba jest wiarygodna niezależnie od hałasu, bo pochodzi z `process.cpuUsage()`
własnego procesu, a nie z zegara ściennego.

---

## 3. Co pozostaje NIEZMIERZONE i dlaczego

| co | dlaczego |
|---|---|
| **Ścieżka dostarczania mediów przez R2 / Cloudflare** | Panel Cloudflare jest za ścianą logowania, haseł nie wpisujemy; R2 nie jest dostępne z tej sesji. Dysk lokalny **nie jest** zamiennikiem CDN-a — nie ma cache'u brzegowego, signed redirect ani opóźnienia sieci. Wiersz `zdjecie` opisuje **wyłącznie origin**. Osobny pomiar origin vs cache Cloudflare należy do #597. |
| **Punkt nasycenia, charakter degradacji i powrót do normy** | Serie wstrzymane do okna ciszy — patrz nagłówek. |
| **Szczyt RSS przy przetwarzaniu 12 / 24 / 48 Mpx** (weryfikacja tabeli 161 / 254 / 452 MB z `docs/MEDIA_PIPELINE.md`) | Wymaga próbkowania co 200 ms przy pojedynczych wgraniach i cichej maszyny. Przyrząd jest gotowy (`--rozmiar` w generatorze, `INTERWAL` w próbniku), pomiar — nie. |
| **Pojemność z jednego adresu IP** | Z jednego adresu wcześniej niż aplikacja zadziałają limity `search` (60/min) i `zdjecie` (600/min). To osobne pytanie. |
| **Zachowanie z limitem CPU/RAM na bazie** | PostgreSQL współdzieli host bez osobnego limitu — tak samo jak w load581. Baza ma tu więcej mocy względem aplikacji niż na produkcji, więc wnioski o bazie są zawężone, nie poszerzone. |
| **Sprzęt produkcyjny** | Host to WSL2 na Intel Core Ultra 7 270K Plus. Rdzeń tego procesora nie jest rdzeniem maszyny Railwaya. |

---

## 4. Plan serii do wykonania w oknie ciszy

Szeregowo, po 180 s każda, z 30 s przerwy i 60 s próbkowania po zdjęciu
obciążenia (powrót do normy):

`5 → 10 → 20 → 35 → 50 → 75 → 110 → 160` żądań/s,
a po przekroczeniu punktu degradacji — powtórzenie stopnia sprzed nasycenia,
żeby sprawdzić, czy system wraca do tych samych liczb.

Osobno, przy próbkowaniu co 200 ms: po trzy wgrania 12, 24 i 48 Mpx
pojedynczo, dla weryfikacji tabeli pamięci z `docs/MEDIA_PIPELINE.md`.

Przy każdej serii zapisywane jest `uptime` i lista ciężkich procesów
(`otoczenie-<seria>.txt`) — bez tego nikt nie odróżni degradacji aplikacji
od cudzego hałasu.
