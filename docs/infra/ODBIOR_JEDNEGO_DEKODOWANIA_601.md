# Odbiór #601 — jedno dekodowanie zdjęcia (wdrożone w #625)

Data odbioru: **19 września 2026**. Baza: `origin/main` `e306842c`.
Odbierana zmiana: merge **`8b4528a049e9100ccfb9c45877c58f8843c2eedd`** (PR #625),
16 września 2026, 18:33 +02:00.

**Werdykt: NIE ZAMYKAĆ.** Jedyne kryterium, które zostało w #601 po scaleniu
#625 — „rzeczywisty odbiór przetwarzania zdjęcia na produkcji” — jest nadal
niespełnione, a ten odbiór podaje na to **datę**, nie tylko brak dowodu:
najnowsze zdjęcie widoczne publicznie na produkcji powstało **2026-09-15
20:08:20 UTC**, czyli ponad dobę PRZED wdrożeniem tej zmiany. Nowa ścieżka
kodu przez trzy doby na produkcji **nie przetworzyła ani jednego zdjęcia**,
które dałoby się zobaczyć z zewnątrz.

Ten odbiór był prowadzony w trybie **tylko do odczytu na produkcji**: bez
uploadu, bez sztucznego zadania, bez logowania na cudze konto.

---

## 1. Co dokładnie scalił #625

Jedna zmiana w `app/Jobs/ProcessUploadedImage.php` (11 linii: +8/−3), plus
regresja i dowody. Przed pętlą wariantów stoi teraz jedno dekodowanie
i jedna orientacja oryginału, a każdy wariant dostaje **osobną ramkę
Intervention nad tą samą bitmapą GD** (`$manager->read($sourceImage->core()->native())`),
a nie osobne dekodowanie bajtów i nie pomniejszanie poprzedniej miniatury.

Pilnuje tego `tests/Feature/JednoDekodowanieZdjeciaTest.php`: licznik
prawdziwych wywołań `imagecreatefromstring` w przestrzeni nazw dekodera
Intervention (`tests/Support/LicznikDekodowanGd.php`), 16 przypadków
JPEG/PNG/WebP/AVIF × dwa rozmiary × dwie orientacje, 48 porównań wariantów
**bajt w bajt** z algorytmem sprzed zmiany. Test ma własną kontrolę dodatnią
(baseline musi dać 3 dekodowania) i chodzi w osobnym procesie.

---

## 2. Tabela kryteriów odbioru

Kryteria wzięte wprost z listy „Testy” i „Definicja gotowości” w #601.

| # | Kryterium | Stan | Warstwa dowodu |
|---|-----------|------|----------------|
| 1 | Job wykonuje **jedno** `read()` binarnego oryginału | **spełnione** | kod + test lokalny (licznik GD 3→1) + kontrola ujemna A + CI 35122714122 |
| 2 | `thumb/feed/large` mają poprawne wymiary i orientację | **spełnione** | test lokalny: 16 przypadków, orientacje 1 i 6, 48 porównań wymiarów |
| 3 | Brak widocznej regresji jakości wobec baseline | **spełnione mocniej, niż wymagano** — nie „brak widocznej”, lecz **identyczność bajtowa** | test lokalny (`===` na bajtach WebP) + kontrola ujemna B |
| 4 | Peak RSS nie pogarsza się w sposób nieakceptowalny | **częściowo** | pomiar lokalny z #625 (24 MP ok. +6 %, 12 MP spadek, 48 MP bez zmiany); **brak pomiaru produkcyjnego** |
| 5 | Błąd jednego wariantu nie zostawia niespójnego stanu **bez obsługi** | **było niespełnione — naprawione w tym pakiecie** | kod + nowa regresja + dwie kontrole ujemne (patrz §4) |
| 6 | Zmierzony wynik przed/po jest zapisany | **spełnione** | `docs/infra/evidence/media601/`, `JEDNO_DEKODOWANIE_601.md` |
| 7 | Zmiana jest wdrożona na produkcję | **spełnione** | `8b4528a0` jest przodkiem `e306842c`; Railway deployment 6485687626 success; live deployment `609569cc` (commit `ab91185c`) zawiera ten merge |
| 8 | **Nowa ścieżka wykonała się na produkcji choć raz** | **NIESPEŁNIONE** | ogląd produkcji: 60 unikalnych zdjęć z 48 adresów publicznych, najnowsze `01a0a6af-4ae1-…` = **2026-09-15T20:08:20Z**, czyli sprzed wdrożenia |
| 9 | Stany `media.status` i obecność wariantów na produkcji | **NIESPRAWDZONE** | do produkcyjnego Postgresa nie ma dostępu z zewnątrz (potwierdza to komentarz w `SprawdzKolejke.php`), a konektor Railway zwraca wartości zmiennych zredagowane |
| 10 | Czas i pamięć **zadania** na produkcji | **NIESPRAWDZONE** | metryki Railway są zbiorcze dla kontenera `all` (web + worker + scheduler) |
| 11 | Brak śladów regresji w dziennikach produkcji | **słabo spełnione** | brak `Nie udało się przetworzyć zdjęcia` i `Zdjęcie bez wygenerowanych wariantów` w oknie żywego deploymentu — ale okno to kilkadziesiąt minut, a starsze deploymenty mają dzienniki usunięte |

### Liczby z oglądu produkcji (19.09.2026, tylko odczyt)

- `GET /health` → `degraded`; `media: ok`, `database: ok`, `migrations: ok`,
  **`kolejka: ok=false, error=zadania_nieudane`** — w `failed_jobs` leżą
  **4 nieudane zadania**. Klasy tych zadań **nie da się ustalić z zewnątrz**,
  więc **nie przypisujemy ich do `ProcessUploadedImage`** ani do #601.
- Ruch HTTP za 24 h: 2028 żądań, **0 odpowiedzi 5xx**.
- Metryki kontenera (7 dni): peak RSS **555,7 MB** przy limicie **1000 MB**,
  peak CPU 0,16 rdzenia. Worker chodzi z `--memory=700`
  (`docker/entrypoint.sh`), więc pomiary z #625 mieszczą się pod progiem.
- Warianty istniejącego zdjęcia serwują się poprawnie: `thumb` 320×240
  (9114 B), `podglad` 640×480 (26 624 B), `feed` 960×720 (45 966 B),
  `large` 1600×1200 (87 962 B) — wszystkie `image/webp`, HTTP 200.
- `srcset` z trzema pozycjami zamiast czterech **nie jest usterką**: komponent
  `photo.blade.php` deduplikuje kandydatów po prawdziwej szerokości (audyt T30).

### „Zdjęcie bez wygenerowanych wariantów” — sygnał, nie stan normalny

To `Log::warning` z `Media::url()`: pada dokładnie wtedy, gdy widok prosi
o adres zdjęcia, które **nie ma żadnego wariantu**. Produkcja ma
`LOG_LEVEL=warning` (`.railway/railway.ts`), więc ten komunikat **przeszedłby**
do dzienników Railway — jego brak coś znaczy. W dziennikach żywego
deploymentu go nie ma; to samo ustalenie zapisano już przy progu 25 Mpx
w `PRZEKAZANIE_PRAC_OPERACYJNYCH_2026_09_18.md`. Granica: okno retencji
obejmuje tylko bieżący deployment, a ruch w tym czasie był znikomy.

---

## 3. Czym to sprawdzono lokalnie

Worktree `/home/mateusz/kuking-601-odbior-claude` z `origin/main`, **kopia**
`vendor` (nie dowiązanie — przy symlinku `JednoDekodowanieZdjeciaTest` oblewa
przez autoloader i wygląda jak usterka pakietu), `composer dump-autoload
--optimize`, PHP 8.4.24 z `AVIF Support = true`, baza `kuking_601_odbior`
na `127.0.0.1:55439`.

- `JednoDekodowanieZdjeciaTest`: **1 test / 325 asercji, PASS** — ta sama
  liczba co w raporcie #625.
- Rodziny zdjęciowe (`Zdjec|Media|Upload|Orientacj|Wariant|Srcset|Oryginal|Budzet|Przygotowywanie`):
  **495 testów / 3688 asercji, PASS**.
- Pełny zestaw po naprawie z §4: **4298 testów / 82 960 asercji, PASS**
  (365,9 s). Pint: PASS na 1140 plikach. PHPStan: `No errors`.
  Przebieg: `/home/mateusz/dowody601-kontrole.log`.

### Kontrole ujemne dla samej zmiany z #625 — fizyczne, nie opisowe

Obie na **prawdziwym pliku joba**, z kopią poza drzewem roboczym, z dowodem
`grep`-em i MD5, że mutacja naprawdę weszła.

| Kontrola | Mutacja | Dowód wejścia | Wynik |
|---|---|---|---|
| A — powrót do trzech dekodowań | `read($original)` w pętli | `grep -c sourceImage` → **0**; MD5 `4ccbd8f7…` → `6071c310…`; `git diff --stat` 2+/5− | **FAIL** (`Failed asserting that 3 is identical to 1`), exit 1 |
| B — wspólne, mutowane źródło (skalowanie z poprzedniej miniatury) | `$image = $sourceImage;` | `grep` pokazuje podmienioną linię 153; `grep -c 'core()->native()'` → **0**; MD5 → `21e9b97a…` | **FAIL** (`feed: zmienione piksele lub kodowanie`), exit 1 |

Po każdej kontroli plik przywrócono z kopii: **MD5 z powrotem `4ccbd8f7790ee994f71c05c410e5d504`**,
`git status` czysty, test znowu 1 / 325 PASS.

Surowe przebiegi: `/home/mateusz/dowody601-negatyw.log`,
`/home/mateusz/dowody601-negatyw2.log` (poza repozytorium).

---

## 4. Usterka znaleziona przy odbiorze i naprawiona w tym pakiecie

**Kryterium 5 z #601 nie było spełnione.** `ProcessUploadedImage` wysyła
warianty do publicznego bucketu jeden po drugim, ale `metadata.variants`
zapisuje dopiero po ostatnim z nich, razem ze statusem `ready`.
`KasujZdjecie::skasujPliki()` chodzi **wyłącznie** po `metadata.variants` —
bo to jedyne miejsce, z którego zna nazwę pliku.

Między pierwszym `put()` a końcem zadania plik wariantu leży więc
w publicznym buckecie, a w bazie **nie ma pod niego żadnego klucza**.
Zadanie, które w tym oknie padnie na dobre, zostawia go tam na zawsze: nie
kasuje go ani usunięcie wpisu, ani decyzja moderacyjna, ani **wymazanie
konta (RODO)**, ani `kuking:sprzataj-osierocone-zdjecia`. To jest dokładnie
ta klasa sieroty, przed którą ostrzega komentarz przy
`Media::kluczPublicznegoWariantu`.

Ścieżka nie jest hipotetyczna: `$timeout = 120` ubija proces **sygnałem
w środku pętli**, więc `catch` w `handle()` się nie wykonuje (to samo
uzasadnienie, dla którego istnieje hook `failed()`), a dekodowanie zdjęcia
50 Mpx i trzy warianty w GD to realnie ten kawałek serwisu, który potrafi
się w limit czasu nie zmieścić.

**Naprawa, wąska:** klucze wariantów są policzalne z góry
(`Media::kluczPublicznegoWariantu` liczy je z `object_key` i nazwy), więc job
zapisuje całą listę **jednym `update()` przed pętlą**, pod osobnym kluczem
`Media::METADANE_WARIANTY_W_TRAKCIE`, a `KasujZdjecie` ją sprząta. Po sukcesie
lista znika — dwa źródła prawdy o tym samym pliku rozjechałyby się przy
pierwszej zmianie listy wariantów. Klucz, pod którym plik nigdy nie powstał,
jest no-opem, bo `skasujZDysku()` pyta `exists()`.

**Osobny klucz, nie `variants`**, i to jest świadomy wybór:
`Media::wariantDoSerwowania()` czyta `variants`, więc dopisanie tam kluczy
przed czasem pokazałoby zdjęcie w połowie przetwarzania pod nazwą wariantu,
którego plik może jeszcze nie istnieć. Ścieżka szczęśliwa nie zmienia się
ani o bajt — pilnuje tego niezmieniony `JednoDekodowanieZdjeciaTest`.

Regresja: `tests/Feature/PrzerwanePrzetwarzanieNieZostawiaSierotyTest.php`
(2 testy / 13 asercji). Ma własną **kontrolę dodatnią**: bez niej test
przechodziłby również wtedy, gdy zadanie padło przed pierwszym `put()`,
czyli gdy sieroty w ogóle nie było.

### Kontrole ujemne naprawy — fizyczne

| Kontrola | Mutacja | Dowód wejścia | Wynik |
|---|---|---|---|
| C1 — job przestaje zapisywać klucze | usunięty `update()` przed pętlą | `grep -c 'METADANE_WARIANTY_W_TRAKCIE => $kluczeWTrakcie'` → **0**; MD5 `ebb79774…` → `cdb0f58b…` | **FAIL** na asercji „Klucze wariantów w trakcie nie zapisały się wcale” |
| C2 — `KasujZdjecie` przestaje sprzątać | pętla po pustej tablicy | `grep -c 'metadata[Media::METADANE_WARIANTY_W_TRAKCIE]'` → **0**, linia 265 to `foreach ([] as $klucz)`; MD5 `9e828202…` → `54d62ada…` | **FAIL** na „Sierota została w publicznym buckecie mimo skasowania zdjęcia” |

C2 jest pouczające osobno: `skasujPliki()` zwróciło przy mutacji **`true`** —
czyli bez tej pętli serwis melduje udane skasowanie, a plik zostaje.

Po obu kontrolach MD5 obu plików wróciły do `ebb79774f22d5f89b6731d7c0b6182d6`
i `9e82820238e0abbc8c867fa9adb63c74`. Przebieg:
`/home/mateusz/dowody601-negatyw-sierota.log`.

---

## 5. Czego NIE sprawdzono

1. **Rzeczywistego zadania na produkcji** — i to jest jedyne kryterium
   blokujące zamknięcie. Wymaga uploadu albo sztucznego zadania, czyli
   zapisu na produkcji; ten odbiór miał mandat wyłącznie do odczytu.
2. **Stanów `media.status` i `metadata.variants` w produkcyjnej bazie** —
   Postgres produkcyjny nie jest osiągalny z zewnątrz, a konektor Railway
   oddaje nazwy zmiennych bez wartości.
3. **Czasu i pamięci samego zadania na produkcji** — metryki Railway są
   zbiorcze dla kontenera `all`. Peak 555,7 MB nie daje się przypisać
   zadaniu obrazu ani odróżnić od web/schedulera.
4. **Czterech nieudanych zadań w `failed_jobs`** — klasy nie da się ustalić
   z zewnątrz. Mogą nie mieć z #601 nic wspólnego; **nie wolno ich temu
   pakietowi ani przypisać, ani odpisać**.
5. **Ścieżki R2/CDN i workera kolejki** — wszystkie pomiary #625 i ten odbiór
   chodziły na dyskach lokalnych i przez bezpośrednie `handle()`, nie przez
   `queue:work` i nie przez R2.
6. **Wejścia z profilem ICC/CMYK oraz zdjęć > 25 Mpx w regresji** — regresja
   bierze małe obrazy generowane w GD; fotografie 12/24/48 MP zostały
   zmierzone jednorazowo w #625, poza zestawem CI.
7. **Równoległych workerów** — #625 mierzył dwie pary równoległych procesów
   `handle()`, nie workerów kolejki dzielących pamięć kontenera.
8. **Zachowania AVIF na produkcji** — `image/avif` jest na liście
   akceptowanych formatów, a `ObiecujemyTylkoFormatyKtoreUmiemyTest` pilnuje
   zgodności listy z `gd_info()` **w środowisku, w którym akurat chodzi**.
   Że produkcyjny GD ma `AVIF Support`, wynika tu tylko z `Dockerfile`
   (`install-php-extensions gd`) — **nie zostało to odczytane z działającej
   produkcji**.

## 6. Obserwacja poboczna (poza #601)

`SprawdzKolejke::zapiszWDzienniku()` pisze `Log::info('kuking:sprawdz-kolejke', …)`
i jego komentarz nazywa tę linię „jedynym dostępnym szeregiem czasowym
kolejki” — 96 linii na dobę. Produkcja ma jednak `LOG_LEVEL=warning`, więc
kanał `stderr` **odcina poziom `info`** i ta linia do dzienników Railway nie
dociera wcale. Sprawdzone: przebieg `kuking:sprawdz-kolejke` z 2026-09-19
13:00:33 UTC widać w dzienniku wyłącznie jako wiersz schedulera, bez żadnych
liczb. Obiecany szereg czasowy nie istnieje. To nie jest usterka #601 i nie
zostało tu naprawione.

Drugie drobne: docblock `ProcessUploadedImage::failed()` mówi o workerze
z `--memory=384`, a `docker/entrypoint.sh` podnosi tę wartość do `700` wraz
z wyjaśnieniem, że 384 stało poniżej normalnego kosztu jednego zadania.
Komentarz jest nieaktualny.

---

## Rollback

Naprawa z §4 nie dotyka migracji, kluczy magazynu ani danych. Cofnięcie to
odwrócenie zmian w `ProcessUploadedImage`, `Media` i `KasujZdjecie`; wiersze,
które zdążyły dostać `metadata.warianty_w_trakcie`, zostaną z nadmiarowym
kluczem, którego nikt już nie czyta — bez wpływu na widoki i na kasowanie.
