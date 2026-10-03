# #2852 — techniczny odbiór książki zeszytu w Chromium i PDF A4

## Wynik i granica odbioru

**PASS lokalnego odbioru HTML/HTTP, przeglądarki i PDF.** Naprawa #2852
z `7039a8503` jest już w O. Ten commit dodaje wyłącznie niniejszy zapis
brakującego odbioru technicznego; nie zmienia kodu ani testów produktu.
Issue [#2852](https://github.com/woogitsu/kuking.pl/issues/2852) pozostaje
**OPEN do odbioru wdrożenia produkcyjnego**. Nie wykonano pusha, PR, CI,
scalenia ani operacji na produkcji.

Podstawa: świeża treść issue, D-301
(`docs/decyzje/D-301-moja-wersja-przepis-na-podstawie-cudzego.md`),
`ODBIOR-Q-CODEX-20261003.md` oraz `ODBIOR-N-U-CODEX-20261003.md`.
Q dokumentuje wcześniejsze **57 testów / 418 asercji PASS**, ograniczoną
liczbę zapytań przy 11 oryginałach i właściwą fizyczną kontrolę
`PODPIS_2852_WIDOCZNY` z dokładnym przywróceniem. W tym odbiorze nie
powtarzano tych testów, mutantów ani pełnej baterii; ich wynik jest
dziedziczony, a poniższe pomiary wykonano na nowo.

## Izolacja i dokładne źródło

- Gałąź `codex/2852-odbior-pdf`, baza źródłowa O
  **`91e58bd07b719c27f12b7973c526f906cbefd2db`**.
- Worktree: `C:\Users\matma\.codex\worktrees\2852-odbior-pdf\Portale`.
- Własny runtime normalhp:
  `/home/codex-admin/kuking-koordynacja-20261003-codex/repo-2852-pdf`.
- Przed `createdb` sprawdzono nieistnienie bazy, rolę/właściciela
  `kuking_pg18_owner`, host `127.0.0.1`, port **55488** i
  `server_version_num=180006` (**PostgreSQL 18.6**). Utworzono wyłącznie
  `kuking_test_2852_odbior_pdf`, UTF8, `template0`, `C.utf8`.
- `APP_BASE_PATH` wskazuje własny runtime, zależności są fizycznymi kopiami
  zgodnymi z oboma lockami; zwykły `composer install` i końcowy
  `npm run build:assets` zakończyły się kodem 0. Własny klucz wygenerowano
  wyłącznie w nowej instancji, sesje mają własny magazyn plikowy.
- Własne media: rzeczywisty testowy obraz WebP i fizyczne warianty w
  `repo-2852-pdf/storage/app/public`; bez połączenia z produkcyjnym R2.
- Rzeczywisty serwer Laravel `http://localhost:2852`, formularz logowania
  i chroniona trasa `/zeszyt/{uuid}/do-druku`. Żadnego atrapowego HTML,
  przechwycenia odpowiedzi ani obejścia Policy/CSRF.
- Istniejący silnik: **Chrome for Testing 153.0.8010.12**,
  `/opt/woogitsu/playwright-browsers/chromium-1243/chrome-linux64/chrome`.
  Jeden browser naraz, bez instalacji. Workflow korzysta z istniejącego
  Playwright i zasad `scripts/wydruk-przepisu.mjs`.
- Końcowy odczyt `source-state.json`: **6977/6977 plików archiwum O**
  zgodnych bajtowo z runtime. Własny serwer został zatrzymany. Runtime,
  bazy i procesy innych prac nie były zmieniane.

## Rzeczywiste pomiary

**8/8 wariantów HTTP 200 i kontroli DOM/print PASS**:
cały zeszyt albo wybór × zdjęcia włączone/wyłączone × notatki wyłącznie
na jawne życzenie/wygaszone domyślnie. Całość zawiera 8 przepisów;
wybór zawiera 3, a pozostałe 5 rzeczywiście nie trafia do artykułów.

| Fixture oryginału | Wynik HTML i papieru/PDF |
|---|---|
| Widoczny publiczny | `Na podstawie przepisu: „Żurek oryginalny Jurka” · Jurek Testowy`; tytuł i konto są tekstem, a odnośnik prowadzi do właściwego oryginału. |
| Ukryty, usunięty miękko, tylko dla obserwujących, blokada w obie strony, usunięty trwale | Dokładny neutralny podpis D-301. Tytuł, nazwa konta, slug i URL każdego niedostępnego oryginału nie występują w odpowiedzi HTML, jej atrybutach, tekście PDF ani adnotacjach URI. |
| Twarde usunięcie | `forked_from_id=NULL`, `forked_at` zachowane; neutralny podpis pozostaje. |
| Zwykły przepis rodzinny | Bez etykiety Mojej wersji; pozostają autor, `od babci Zosi`, rok 1974, historia, składnik, krok i własny adres przepisu. |

Na właściwym `@media print` podpis ma **21,3333 px (16 pt)**,
dodatnią szerokość/wysokość i widoczny tekst. Warianty ze zdjęciami
mają dwa rzeczywiście załadowane obrazy (`decode()` i `naturalWidth>0`);
warianty bez zdjęć nie mają ich elementów. Warianty bez notatek nie
zawierają dopisków nawet w źródle HTML; jawne notatki są kompletne.

## Cztery faktyczne PDF i ogląd wszystkich stron

Chromium wygenerował prawdziwe pliki przez `page.pdf`, A4 z właściwymi
regułami CSS druku. Każda strona ma MediaBox około **594,96 × 841,92 pt**.
Poppler 26.01.0 wyodrębnił tekst i wyrenderował strony do PNG (110 DPI).
Adnotacje URI sprawdzono osobno przez pypdf 6.10.0.

| PDF | Strony | SHA-256 |
|---|---:|---|
| `whole-no-photos-no-notes.pdf` | 10 | `490c34445624f0b9fa754f1e9247ade48f23091bb96a484bff7df92df61137de` |
| `whole-photos-notes.pdf` | 10 | `171054ed33ef754e0aa6de8c3ce02368f3d2a15d95a6f2a092dd7ab015c73420` |
| `selected-no-photos-no-notes.pdf` | 5 | `8fee9fbc77bb85419800d9491929492af5e10aba7965454c72de820da7c1188f` |
| `selected-photos-notes.pdf` | 5 | `c435fbc3e752f6f7d36519eb6e0cff9ee8e1a9f0a019d9c9d879fabdbe520708` |

**Obejrzano 30/30 stron** poprzez 10 arkuszy kontaktowych z pełnymi
stronami (`view_image`, oryginalna rozdzielczość). Ogląd obejmował
okładkę, spis treści i wszystkie przepisy we wszystkich czterech PDF.
Podpis przed tytułem wersji jest czytelny także po zawinięciu nazwy
konta do drugiego wiersza. Nie zaobserwowano obcięcia, nakładania ani
zagubionych polskich znaków. Dla niedostępnych oryginałów widać neutralne
zdanie; zwykły rodzinny przepis zachowuje metryczkę bez podpisu wersji.
Ekstrakcja tekstu nie zastępuje tego oglądu.

## Surowe dowody i odtwarzalność

Wszystkie artefakty pozostają poza repo:

- Lokalnie: `C:\Users\matma\.codex\worktrees\2852-odbior-pdf\evidence`.
- Runtime/raw: `/home/codex-admin/kuking-koordynacja-20261003-codex/evidence-2852-pdf`.
- `browser-result.json`: właściwe URL, HTTP, liczby artykułów/podpisów,
  zdjęć/notatek, wymiary i tekst print, SHA odpowiedzi oraz zapisanych HTML.
- 8 HTML (jedynie tokeny CSRF zredagowane), 8 zrzutów przeglądarki,
  4 PDF, `*.txt`, `*.pdfinfo.txt`, 30 PNG w `rendered/`, 10 arkuszy,
  `pdf-inspection.json` z oddzielnym werdyktem tekstu/URI i oglądu.
- `environment.json`, `fixture.json`, `source-state.json`, logi instalacji,
  budowy, migracji i własnego serwera; komplet użytych helperów.
- `manifest.json`: rozmiar, SHA-256 i mtime każdego z **99 artefaktów**;
  SHA manifestu **`0184a88e7f4617c32ab41aba87c61173817d36d83a2667a5e10b25ba83b9f076`**.
  Obejmuje także porównanie dziewięciu istotnych źródeł local/runtime.

## Uczciwie odnotowane przygotowanie i ograniczenia

Pierwsza własna kopia `node_modules` dereferencjowała `.bin/vite`, przez co
pierwszy build nie znalazł właściwego CLI. Przywrócono tylko względne
dowiązania binarek wewnątrz własnych pakietów; końcowy build to kod 0.
Pierwsza fixture odczytała z modelu fabryki cache pustej relacji profilu;
odświeżono model i zdjęto wyłącznie jej pojedynczy wiersz z własnej pustej
bazy. Te próby są błędami przygotowania, nie wynikami testu produktu.

Wstępny pomiar obrazu zakończył się przed załadowaniem `loading=lazy`
poza ekranem. Zachowano go w `history-lazy-image-preflight`; finalny
helper rzeczywiście przewija do obrazu i czeka na dekodowanie.
Po tej próbie `artisan serve` pozostawił własny potomny serwer: odczytano
jego PID/router/cwd i zatrzymano tylko ten proces. Finalny helper uruchamia
bezpośrednio istniejący router Laravel i zamyka własny proces.

pypdf w ekstrakcji tekstu niepoprawnie wstawiał spacje przy polskich
znakach. Tekst sprawdzono rzeczywistą ekstrakcją Popplera, a pypdf
pozostał czytnikiem MediaBox i URI. Poppler podczas renderowania wypisał
ostrzeżenia `Bad bounding box in Type 3 glyph`; kod wyjścia był 0,
30 stron rzeczywiście powstało i zostało obejrzanych bez braków znaków.

Ten odbiór potwierdza lokalne HTML i Chromium PDF wskazanego O, nie
wydruk na fizycznej drukarce ani produkcyjne wdrożenie. Nadal obowiązują
pełne bramki wydania i produkcyjny receipt N–U; statusu issue nie zmieniono.

Refs #2852.
