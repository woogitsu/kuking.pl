## D-064 · HEIC zostaje odrzucany, z komunikatem mówiącym co zrobić — libheif do obrazu Dockera NIE wchodzi teraz

**Data:** 10 września 2026 · Issue #119 (F-01 audytu z 6 września 2026) · Status: **obowiązuje**

Pytanie z issue: dokładać `libheif` (przez Imagick) do obrazu Dockera i przyjmować
HEIC naprawdę, czy zostać przy dzisiejszym odrzuceniu? Odpowiedź: **zostać przy
odrzuceniu — ale odrzuceniu, które mówi CO ZROBIĆ**, plus jedna zmiana, która nie
czekała na tę decyzję: HEIC dostaje teraz **własny kod powodu** w sygnale
`photo_upload_failed`, żeby dało się w ogóle zmierzyć, ile to jest osób.

### 1. Stan faktyczny, sprawdzony w tej sesji, nie przepisany z audytu

Zmierzone bezpośrednio w kontenerze agenta (PHP 8.4.19), to samo, co audyt #119
znalazł 6 września — powtórzone tutaj, żeby nie polegać na cudzym pomiarze bez
sprawdzenia:

```
$ php -r 'var_dump(defined("IMAGETYPE_HEIC"), defined("IMAGETYPE_HEIF"));'
bool(false)
bool(false)

$ php -m | grep -i imagick
(pusto — rozszerzenie nie jest zainstalowane)

$ php -r 'print_r(gd_info());'
...
[JPEG Support] => 1  [PNG Support] => 1  [WebP Support] => 1  [BMP Support] => 1
[AVIF Support] => 1
(brak jakiegokolwiek wpisu HEIC/HEIF)
```

**Zastrzeżenie, wprost:** ten kontener NIE JEST obrazem produkcyjnym — to
środowisko agenta, nie `dunglas/frankenphp:1-php8.4-trixie` z `Dockerfile`.
W tej sesji nie było demona Dockera (`docker info` kończy się błędem połączenia
z `/var/run/docker.sock`), więc **obrazu produkcyjnego nie dało się tu zbudować
ani uruchomić** — to zostaje otwarte dla właściciela (§5). Wniosek o braku HEIC
nie zależy jednak od tego, który kontener się sprawdza: `Dockerfile` (etapy
`vendor` i `runtime`, linie 90–99 i 132–141) instaluje przez
`install-php-extensions` dokładnie: `pdo_pgsql pgsql intl gd zip exif pcntl
bcmath opcache` — **bez `imagick`**, i nie doinstalowuje `libheif` żadnym
`apt-get`. Skoro GD w tym samym PHP 8.4 (ten sam `dunglas/frankenphp` bazowy
obraz co etap `vendor`) nie zna HEIC, a obraz produkcyjny nie dokłada niczego,
co by to zmieniło, wniosek „produkcja też nie otwiera HEIC" nie wymaga
zbudowania obrazu, żeby być prawdziwym — wymaga tylko przeczytania, czego
`Dockerfile` NIE instaluje. Dokładny numeryczny pomiar (rozmiar warstwy, czas
dekodowania) to już inna sprawa — patrz §5.

**Co się dzieje dziś, krok po kroku, gdy ktoś wgra HEIC** (dwie niezależne
drogi, obie kończą się tym samym komunikatem):

1. Formularz (`PostController::store` i trzy pozostałe) waliduje polem
   `photos.*` regułą `App\Rules\ObslugiwaneZdjecie` (`app/Http/Controllers/
   PostController.php:125`). Reguła woła `RozpoznanieZdjecia::rozpoznaj()`
   (`app/Rules/ObslugiwaneZdjecie.php:86`).
2. `RozpoznanieZdjecia::rozpoznaj()` (`app/Support/RozpoznanieZdjecia.php:83`)
   wywołuje `getimagesize()`. Dla HEIC/HEIF to zawodzi (`bool(false)`) —
   PHP 8.4 nie ma stałej `IMAGETYPE_HEIC` ani `IMAGETYPE_HEIF`, więc nawet nie
   próbuje.
3. **Zmiana z tego zgłoszenia:** zamiast wpadać do ogólnej gałęzi „plik
   nieczytelny", kod sprawdza `mime_content_type()` (magic bytes — nagłówek
   ISO BMFF `ftyp` z marką `heic`/`heif`/`mif1`, NIE rozszerzenie pliku i NIE
   `Content-Type` od przeglądarki) i przy HEIC/HEIF zwraca osobny kod
   `heic_unsupported` z komunikatem mówiącym co zrobić (§3).
4. Ten sam plik, wysłany OMIJAJĄC formularz, odbija się identycznie —
   `StoreUploadedImage::handle()` (`app/Domain/Media/Actions/
   StoreUploadedImage.php:90`) woła to samo `RozpoznanieZdjecia::rozpoznaj()`.
   To jest prawdziwa granica (AGENTS.md §7: nie ufamy niczemu od klienta) i
   trzyma niezależnie od formularza.
5. Człowiek widzi błąd PRZY POLU `photos.0`, z resztą błędów, bez utraty
   wpisanego tekstu (`old()`) — nigdy 500, nigdy pustą ramkę, nigdy zdjęcie
   w `pending`/`rejected` bez wyjaśnienia. Zdjęcie **nigdy nie trafia do
   `Media::create()`** — dociera do niego zero wierszy HEIC, więc worker
   (`ProcessUploadedImage`) nigdy nie widzi tego pliku i nie ma szans utknąć
   na jego dekodowaniu.

**Co NIE jest dziś prawdą** (i audyt też to mylił): to nie jest awaria w
środku potoku, workera ani „zdjęcie wisi w `processing`". Plik odpada na
SAMYM WEJŚCIU, zanim cokolwiek trafi do kolejki `media`. Konsekwencja: cały
rachunek kosztu pamięci/czasu workera z `docs/MEDIA_PIPELINE.md` (tabela
12/24/50 Mpx → 161/254/452 MB) dziś **nie dotyczy HEIC w ogóle** — dotyczyłby
dopiero, gdyby ta decyzja brzmiała „tak, dokładamy libheif" (§4).

### 2. Sprostowanie założenia z treści zadania: worker ma 1024 MB, nie 384 MB

Zadanie, z którego powstał ten wpis, zakładało limit workera `--memory=384`.
To nieprawda i warto to powiedzieć wprost, zamiast pisać rachunek kosztu pod
liczbę, która nie istnieje w tym repozytorium (AGENTS.md, sekcja o
sprostowaniu z 9 września: liczy się to, co jest w kodzie).

Sprawdzone:
- `.railway/railway.ts:768` — kontener serwisu `worker` ma
  `memoryBytes: 1024 * MB` (twardy limit Railway, OOM-kill powyżej).
- `docker/entrypoint.sh:369` — `queue:work --memory="${QUEUE_MEMORY:-700}"`:
  to jest MIĘKKI limit Laravela (kończy proces między jobami po przekroczeniu
  700 MB RSS), niezależny od twardego limitu kontenera.
- `docker/entrypoint.sh:363` — `memory_limit` PHP ustawiony na
  `${PHP_WORKER_MEMORY_LIMIT:-512M}` (`.railway/railway.ts:454`), ale
  `docs/MEDIA_PIPELINE.md` już ostrzega, że TA liczba nie widzi bufora GD:
  zmierzony szczyt RSS dla 50 Mpx to 452 MB przy liczniku PHP pokazującym
  28 MB. Realny sufit, o który trzeba się martwić przy większym pliku, to
  1024 MB kontenera, nie 384 i nie 512.

`--memory=384` nie pojawia się nigdzie w tym repozytorium (`grep -rn 384
docker/ .railway/` nic nie znajduje). Skąd wzięła się ta liczba w treści
zadania — nie wiadomo; mogła być pomyłką przy przepisywaniu z innego miejsca.
Rachunek w §4 liczy więc wobec PRAWDZIWYCH 1024 MB.

### 3. Co jest prawdą dla iPhone'a — sprawdzone, nie zgadywane

Zadanie wprost każe to sprawdzić, a nie zgadywać. Dwa źródła: oficjalna pomoc
Apple (`support.apple.com/en-us/116944`, „Using HEIF or HEVC media on Apple
devices") i — tam, gdzie Apple milczy — zgodne relacje z wielu niezależnych
wątków Apple Community.

**Sprawdzone i prawdziwe:**
- „Ustawienia → Aparat → Formaty → Najbardziej zgodny" to **dosłowna** ścieżka
  menu z dokumentacji Apple: *„Open Settings, then tap Camera. Tap Formats,
  then tap Most Compatible."* Efekt: *„All new photos and videos will now use
  JPEG or H.264 format."* Dotyczy PRZYSZŁYCH zdjęć.
- Apple wprost: *„If sharing this media using other methods, such as AirDrop,
  Messages, or email, and the receiving device doesn't support the newer
  media formats, the media might automatically be shared in a more
  compatible format, such as JPEG or H.264."* Zgodnie z wieloma niezależnymi
  wątkami Apple Community, w praktyce udostępnienie przez Mail konwertuje
  HEIC do JPEG jako zachowanie domyślne (stąd wątki „jak to WYŁĄCZYĆ", nie
  „jak to włączyć") — to jest droga dla zdjęcia, które JUŻ leży w telefonie.

**Sprawdzone i USUNIĘTE z komunikatu** (wcześniejsza wersja to zgadywała):
poprzedni tekst radził też „otwórz w Zdjęciach i użyj «Duplikuj», żeby dostać
wersję JPG". Fałsz: wykrywanie duplikatów w Photos porównuje pliki po
FORMACIE, nie po treści — co oznacza, że funkcja Duplikuj tworzy drugą kopię
W TYM SAMYM formacie (HEIC), nie konwertuje niczego. `app/Support/
RozpoznanieZdjecia.php` (`komunikatHeic()`) ma teraz tylko dwie rady, obie
sprawdzone: zmianę ustawienia na przyszłość i wysyłkę e-mailem dla zdjęcia,
które już jest zrobione.

**Czego NIE sprawdzono i co zostaje otwarte** (kryterium akceptacji #119,
niewykonalne z tego repozytorium): pomiar na prawdziwym urządzeniu — czy
Safari na iOS naprawdę wysyła JPEG zamiast HEIC, gdy formularz (przez
`LimityZdjec::atrybutAccept()`) nie deklaruje `image/heic` w `accept`. Są na
to poszlaki z dokumentacji dla programistów (starsze Safari konwertowały
zgodnie z `accept`; Safari 17+ ma zgłoszony wyjątek od tego zachowania w
niektórych warunkach), ale żadna z nich nie zastępuje testu na fizycznym
iPhonie — patrz §5.

### 4. Rachunek kosztu dołożenia libheif (przez Imagick) — dlaczego NIE teraz

**Rozmiar obrazu.** Zmierzone jako przybliżenie z metadanych pakietów Debian/
Ubuntu (`apt-cache show`, `Installed-Size` — NIE zbudowany obraz produkcyjny,
bo nie ma tu demona Dockera; realne bajty w warstwie `dunglas/frankenphp:
1-php8.4-trixie` mogą się różnić i wymagają prawdziwego builda przed decyzją
ostateczną):

| Pakiet | Installed-Size |
|---|---:|
| `libheif1` | 803 KB |
| `libde265-0` (dekoder HEVC — format większości zdjęć iPhone) | 375 KB |
| `libheif-plugin-libde265` | 41 KB |
| `libmagickcore-6.q16` (silnik ImageMagick) | 6 648 KB |
| `libmagickwand-6.q16` | 1 352 KB |
| rozszerzenie `imagick` (`.so`) | 18 KB |
| **Razem (bez `libaom`/AVIF — patrz niżej)** | **≈ 9,2 MB nieskompresowane** |

Świadomie POMINIĘTE: `libheif-plugin-aomdec` + `libaom3` (dekoder AV1, **5,3 MB
samo `libaom3`**) — obsługuje HEIF-w-AV1, rzadki wariant. AVIF (to samo
kodowanie AV1, inny kontener) GD **już** dekoduje natywnie (`gd_info()` →
`AVIF Support => 1`), więc dokładanie drugiej drogi do tego samego kodeka nie
ma uzasadnienia. Rzeczywisty dodatek do warstwy obrazu to więc rząd
**kilku–dziesięciu MB nieskompresowanych**, prawdopodobnie mniej po kompresji
warstwy Docker — ale to jest SZACUNEK, nie pomiar na tym `Dockerfile`.

**Czas builda.** Dodatkowy `apt-get install` w DWÓCH etapach (`vendor` i
`runtime` — `Dockerfile` instaluje rozszerzenia PHP w obu, linie 90 i 132, z
komentarzem „te same rozszerzenia co w etapie vendor, trzymaj listy
zsynchronizowane"): rząd dodatkowych 10–30 sekund na etap przy zimnym cache
warstwy `apt`, niezmierzone dokładnie tutaj.

**Pamięć/czas dekodowania HEIC — TO JEST GŁÓWNA NIEWIADOMA, nie rozmiar.**
Kryterium akceptacji #119 wprost tego wymaga i NIE DA SIĘ tego zmierzyć bez
`libheif`+`imagick` w środowisku (nie są tu zainstalowane). Software'owy
dekoder HEVC (`libde265`) jest z natury cięższy niż dekodowanie JPEG baseline
przez GD — o ile cięższy, dla zdjęcia 48 Mpx (główny sensor iPhone 14 Pro i
nowszych), NIE JEST zmierzone ani w tym repozytorium, ani w tej sesji. Zanim
ta decyzja mogłaby brzmieć „tak", ten pomiar musi istnieć — patrz próg
rewizji niżej.

**Drugi sterownik obrazu do utrzymania.** `intervention/image` w wersji
`3.11.8` (`composer.lock`) ma DWA sterowniki: `ImageManager::gd()` (używany
dziś, `app/Jobs/ProcessUploadedImage.php:119`) i `ImageManager::imagick()`.
Orientacja EXIF w tym repo jest naprawiona SPECYFICZNIE pod zachowanie GD
(`ProcessUploadedImage.php:91–118`: `autoOrientation: false` naprawia
PODWÓJNY obrót, bo dekoder GD Interventionu czyta EXIF sam) — przejście na
Imagick jako główny sterownik oznaczałoby ponowne sprawdzenie tej samej
klasy błędu dla innego dekodera, nie przepisanie jednej linijki. Węższa,
bezpieczniejsza architektura, GDYBY ta decyzja kiedyś brzmiała „tak": użyć
Imagicka WYŁĄCZNIE jako wąski adapter „bajty HEIC wchodzą → bajty JPEG
wychodzą", wołany tylko dla plików rozpoznanych jako HEIC, PRZED
`ImageManager::gd()` — reszta potoku (warianty, orientacja, testy) zostaje
nietknięta. To nie jest dzisiejsza implementacja, to zapisany kierunek na
wypadek rewizji.

**Nowa powierzchnia CVE.** `libheif` miał w swojej historii zgłoszenia CVE
(dekodery formatów obrazu/wideo są klasycznym źródłem przepełnień bufora —
ta sama rodzina ryzyka co libwebp, libjpeg). Dodanie go to zobowiązanie do
pilnowania łatek w kontenerze, który dziś ma zamkniętą, przewidywalną listę
rozszerzeń (`Dockerfile`, komentarz przy etapie `vendor`: „DETERMINISTYCZNY
zestaw rozszerzeń PHP").

### 5. Alternatywy rozważone i odrzucone (albo odłożone)

| Opcja | Werdykt |
|---|---|
| **Biblioteka PHP bez zależności systemowych** | Nie istnieje sensowna. Dekodowanie HEVC to dekodowanie wideo — nie ma czystego PHP-owego dekodera, z tego samego powodu, dla którego nie ma czystego PHP-owego dekodera H.264. Każda opcja i tak schodzi do biblioteki C (libheif) przez rozszerzenie. |
| **Odrzucenie z dobrym komunikatem** | **To jest dzisiejsza decyzja** — patrz §1 i §3. Jedyna opcja bez kosztu infrastruktury, bez nowego kodeka do utrzymania, i już zaimplementowana. |
| **Konwersja po stronie przeglądarki (JS, dozwolone na newralgicznych ścieżkach — D-053)** | **Najbardziej obiecujący NASTĘPNY krok, świadomie NIE w tym zgłoszeniu.** `heic2any`/`libheif.js` (WASM) mogłyby dekodować HEIC na telefonie użytkownika i wysłać JPEG — zero kosztu pamięci/CPU workera, zero zmiany obrazu Dockera. Cena: waga paczki JS (WASM dekodera HEVC to rząd setek KB), czas CPU na telefonie (który już raz zdekodował to zdjęcie robiąc je — więc sprzętowo go stać), i **realna pułapka projektowa**: żeby okno wyboru pliku w ogóle POKAZAŁO pliki HEIC do wybrania, atrybut `accept` musiałby je wymieniać — a to jest DOKŁADNIE to ustawienie, które dziś (za sprawą jego BRAKU) może już włączać darmową konwersję Safari opisaną w §3. Ta praca wymaga więc jednocześnie: sprawdzenia na prawdziwym urządzeniu (§3, nadal otwarte) I świadomego zaprojektowania koegzystencji z zachowaniem Safari, żeby nie wyłączyć jednej sieci bezpieczeństwa, dokładając drugą. Nie robimy tego przy okazji tego zgłoszenia (AGENTS.md §3: nie dokładamy rzeczy bez zmierzonej potrzeby, a próg z §6 jeszcze nie jest zmierzony). |
| **libheif + Imagick w obrazie Dockera** | Odłożone — patrz §4. Nie „nigdy", tylko „nie bez pomiaru z §6". |
| **vips** (`libvips`) | Odrzucone bez dalszej analizy: `intervention/image` 3.x nie ma sterownika vips (tylko `gd` i `imagick`) — wymagałoby albo czekania na wsparcie biblioteki, albo pisania własnej integracji. Nieproporcjonalne do problemu. |

### 6. Próg, przy którym ta decyzja wraca na stół

Ta decyzja NIE jest „nigdy" — jest „nie bez tych trzech rzeczy naraz":

1. **Dane z produkcji**, nie przeczucie: `SELECT count(*) FROM product_signals
   WHERE signal_name = 'photo_upload_failed' AND properties->>'reason' =
   'heic_unsupported' AND occurred_at > now() - interval '30 days'` (zapytanie
   działa od tego wpisu — patrz `docs/research/ANALITYKA_STAN_WDROZENIA.md`
   §2.3) pokazujące, że odrzucenie HEIC jest **regularną**, a nie brzegową,
   przyczyną nieudanej publikacji głównej akcji produktu.
2. **Pomiar na prawdziwym iPhonie** (kryterium akceptacji #119, nadal
   niewykonane) — bo jeśli Safari i tak konwertuje większość ruchu do JPEG
   przy wysyłce (§3), sygnał z punktu 1 może zostać mały sam z siebie, a
   dokładanie libheif rozwiązywałoby problem, który już zniknął.
3. Jeśli 1 i 2 pokażą realną skalę: **najpierw** spróbować konwersji po
   stronie przeglądarki (§5) — dopiero jej niewystarczalność (np. przeglądarki
   bez WASM wśród realnego ruchu, awaria dekodowania w praktyce) uzasadnia
   dokładanie zależności systemowej do obrazu produkcyjnego.

### Co zaimplementowane w tym zgłoszeniu (bez zmiany obrazu Dockera)

- `App\Support\RozpoznanieZdjecia`: HEIC/HEIF dostaje własny kod powodu
  `heic_unsupported` (było: dzielony z każdym innym nieczytelnym plikiem pod
  `not_an_image`) — bez tego punkt 1 z §6 nie dałby się w ogóle policzyć.
  Rozpoznanie dalej po magic bytes (`mime_content_type()`), NIE po
  rozszerzeniu ani nagłówku od przeglądarki.
- Komunikat dla człowieka poprawiony do dwóch sprawdzonych rad zamiast dwóch,
  z których jedna była zgadywana i fałszywa (§3).
- Testy: `tests/Feature/ObiecujemyTylkoFormatyKtoreUmiemyTest.php` — nowy
  test przez PRAWDZIWY formularz (`posts.store`), z prawdziwymi magic bytes
  HEIC (pudełko `ftyp`/`heic`, nie plik `.heic` z bajtami JPEG — dokładnie
  pułapka, przed którą ostrzegało zadanie), sprawdzający błąd przy polu,
  zachowanie wpisanego tekstu, zero wierszy `media`, i kod powodu w sygnale.
- Dokumentacja: ten wpis, `docs/research/ANALITYKA_STAN_WDROZENIA.md` §2.3,
  `docs/MEDIA_PIPELINE.md`, `config/kuking.php` (komentarz przy
  `accepted_mime_types`).

### Aktualizacja 20 września 2026 — obietnica bez pokrycia poprawiona (#119 follow-up)

Ta decyzja **nie jest otwierana na nowo**: HEIC nadal jest odrzucany, `libheif`
nadal nie wchodzi do obrazu Dockera. Poprawiono wyłącznie TEKST komunikatu
z §3 pkt 2, po pomiarze stanowiska `gpt/heic-format`
(`docs/research/heic-119/RAPORT.md`).

Znaleziony błąd: komunikat obiecywał **bezwarunkowo**, że wysłanie HEIC do
siebie e-mailem da JPG („wyślij najpierw do siebie e-mailem — przyjdzie jako
JPG"). Apple (support.apple.com/pl-pl/116944) opisuje to jako zależne od
sposobu udostępniania i możliwości odbiorcy — „może" zostać wysłane w formacie
zgodnym, nie „zostanie". Naprawiono `App\Support\RozpoznanieZdjecia::komunikatHeic()`:
wynik dla TEGO zdjęcia nazwany jako niepewny („telefon czasem sam zamienia
je wtedy na JPG, ale zależy to od modelu telefonu"), z prostą alternatywą
(wybrać inne, gotowe zdjęcie), i osobno, jasno opisane ustawienie na
PRZYSZŁOŚĆ, które nie przerabia zdjęcia już zrobionego. Nie zastąpiono jednej
niepewnej obietnicy inną równie pewną — żadna sprawdzona na 100% droga
konwersji ISTNIEJĄCEGO pliku nie jest znana (patrz RAPORT.md §5: Mail,
„Duplikuj" i zewnętrzny konwerter odradzane jako pewniki).

Drugi błąd, drobniejszy: polska pomoc Apple podaje etykietę „Najbardziej
zgodne" (rodzaj nijaki), a komunikat (i ten wpis w §3 pkt 1 wyżej) miał
błędną odmianę „Najbardziej zgodny". Poprawiono w obu miejscach.

Test regresyjny (RED przed poprawką, GREEN po):
`tests/Feature/ObiecujemyTylkoFormatyKtoreUmiemyTest.php::test_komunikat_heic_nie_obiecuje_bezwarunkowo_konwersji_mailem`.

### Co CZEKA na właściciela (opisane, nie wykonane)

- **Pomiar na prawdziwym iPhonie** (§3, §6 pkt 2) — nie do wykonania z tego
  środowiska (brak fizycznego urządzenia i brak Safari zza proxy sesji —
  AGENTS.md, sekcja o przeglądarce w kontenerze agenta).
- **Build i pomiar realnego rozmiaru/czasu obrazu Dockera z `libheif`+
  `imagick`**, GDYBY próg z §6 kiedyś został przekroczony — wymaga demona
  Dockera (niedostępny w tej sesji: `docker info` nie łączy się z
  `/var/run/docker.sock`) i **jawnej zgody właściciela na zmianę
  `Dockerfile`** (dotyka wdrożenia produkcji — poza mandatem tego zgłoszenia).
- **Odczyt `product_signals` po 30 dniach** od wdrożenia tej zmiany, żeby
  ocenić próg z §6 pkt 1 na prawdziwych danych zamiast zera.

**Zmiana wymaga:** trzech rzeczy z §6 naraz — danych z produkcji pokazujących
realną skalę, pomiaru na prawdziwym iPhonie, i próby konwersji po stronie
przeglądarki jako tańszego pierwszego kroku. Samo „iPhone jest popularny"
(prawdziwe, ale znane już w dniu, gdy HEIC zdjęto z listy formatów) tego progu
nie przekracza.

**Pliki:** `app/Support/RozpoznanieZdjecia.php` ·
`app/Support/WynikRozpoznania.php` · `app/Domain/Analytics/ZapiszSygnal.php` ·
`tests/Feature/ObiecujemyTylkoFormatyKtoreUmiemyTest.php` ·
`docs/research/ANALITYKA_STAN_WDROZENIA.md` · `docs/MEDIA_PIPELINE.md` ·
`config/kuking.php` · `Dockerfile` (przeczytane, NIE zmienione) ·
`.railway/railway.ts` · `docker/entrypoint.sh`
