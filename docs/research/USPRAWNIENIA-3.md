# USPRAWNIENIA-3 — trzeci audyt: białe plamy po USPRAWNIENIA-2

Zakres tego audytu jest zamknięty: sekcja **„F. Czego nie zdążyłem obejrzeć —
białe plamy"** z `docs/research/USPRAWNIENIA-2.md` (punkty 1–6), plus trzy
zadania przekrojowe zlecone wprost: Policy kontra trasy, `$fillable` w całym
`app/Models`, oraz zadania w kolejce i harmonogramie. Struktura i ton — jak
w `USPRAWNIENIA-2.md`.

## Co czytałem

- `AGENTS.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md` (przeglądowo),
  `docs/research/USPRAWNIENIA-2.md` w całości, `docs/UX_50_PLUS.md`,
  fragmenty `docs/design/DESIGN_SYSTEM.md` (typografia, BottomNav, kontrast).
- Całość `resources/views/components/recipe-wizard.blade.php` (1134 linii).
- `app/Jobs/GenerateUserExport.php`, `app/Domain/Users/Exports/*` (trzy klasy —
  zlecenie mówiło o czterech, w repozytorium są trzy: `CollectUserExportData`,
  `ExportFileNames`, `ExportPhotoPlan`).
- `resources/css/tokens.css` (całość), `resources/css/app.css` (fragmenty —
  każde użycie `var(--text-help)`), `resources/css/fonts.css` (całość).
- `docker/entrypoint.sh` (całość, 455 linii), `app/Http/Controllers/HealthController.php`,
  `.railway/railway.ts` (fragmenty: `startCommand`, `preDeployCommand`,
  `healthcheckPath` dla web/worker/scheduler), `docker/php.ini` (całość).
- `docs/infra/DEPLOYMENT_RUNBOOK.md` — tylko fragmenty o `preDeployCommand`
  i healthchecku. `docs/seo/`, `docs/brand/`, `docs/decyzje/` — **nie w całości**,
  patrz sekcja F.
- Każda Policy w `app/Policies/` kontra `routes/web.php` i kontrolery, które
  ich używają: `PostController`, `RecipeController`, `CookedEventController`,
  `CommentController`, `CollectionController`, `SocialController`,
  `TopicFollowController`, `AppealController`, `ReportController`,
  `DataSettingsController`, `ProfileController`, kontrolery w `Settings/`,
  `NotificationController`.
- Każdy `$fillable`/`$guarded` w `app/Models/*.php` (21 modeli), oraz każde
  wystąpienie `forceFill`, `unguarded`, `->fill(`.
- Oba joby w kolejce (`app/Jobs/*.php` — tylko dwa istnieją: `GenerateUserExport`,
  `ProcessUploadedImage`) i cały harmonogram w `routes/console.php`.

## Co uruchomiłem

- `composer install` — **nie powiódł się w pełni**: zależności produkcyjne
  (`require`) zainstalowały się (prawdopodobnie w tle, równolegle z inną sesją
  w tym samym katalogu roboczym — pięć agentów pracuje tu naraz), ale
  `require-dev` (w tym `phpunit/phpunit`) padało za każdym razem na etapie
  pobierania z GitHuba przez proxy tej sesji (`Could not authenticate against
  github.com` po nieudanym pobraniu dist-a). Próbowałem trzykrotnie w różnych
  momentach audytu — bez powodzenia. **`vendor/bin/phpunit` nie istnieje
  w tym środowisku**, więc `php artisan test` nie działa i nie uruchomiłem ani
  jednego testu z `tests/`.
- `php artisan migrate --force` na bazie `kuking_test` (na PostgreSQL **16.13**,
  nie 18 — środowisko w praktyce ma starszą wersję, niż zapowiadało zlecenie).
  Wszystkie 24 migracje przeszły bez błędu.
- Jednorazowy skrypt PHP (bootstrujący aplikację bez `phpunit`), odtwarzający
  ręcznie logikę `tests/Feature/UploadLimitsAgreementTest.php` — patrz E1.
  Plik: `/tmp/.../scratchpad/verify_a1.php` (nie trafia do repozytorium).
- Nie uruchomiłem serwera (`frankenphp`/`artisan serve`), więc nic z warstwy
  HTTP „na żywo" (w tym `/health`, cały kreator w przeglądarce, kontrast
  i zoom 200%) nie zostało zmierzone — tylko przeczytane.

## Spis ustaleń

| # | Tytuł | Priorytet | Sekcja |
|---|---|---|---|
| A1 | Nawigacja i pomocnicze teksty renderują się 16 px, poniżej własnego minimum 18 px | P0 | A |
| A2 | Kreator przepisu odrzuca zdjęcia 12–15 MB, mimo że produkt wszędzie indziej obiecuje limit 15 MB | P0 | A |
| B1 | `ProcessUploadedImage` nie ma `failed()` — zdjęcie może utknąć w `processing` na zawsze | P1 | B |
| B2 | Eksport RODO po cichu pomija zdjęcia, które w chwili eksportu jeszcze się przetwarzają | P1 | B |
| E1 | A1/A2/A3 z USPRAWNIENIA-2 — już naprawione, potwierdzone | — | E |
| E2 | C1 z USPRAWNIENIA-2 (walidacja kasuje wybór zdjęć) — już naprawione | — | E |
| E3 | Policy kontra trasy — bez luk IDOR | — | E |
| E4 | `$fillable` w całym `app/Models` — `status`/`role` użytkownika nietknięte | — | E |

---

## A. Zepsute teraz

### A1. Nawigacja i pomocnicze teksty renderują się 16 px, poniżej własnego minimum 18 px

**Dowód.** `resources/css/tokens.css:76`:

```css
--text-help: calc(1rem * var(--user-text-scale, 1));           /* 16px bazowo */
```

Nic w `tokens.css` ani w `app.css` nie zmienia rozmiaru bazowego `html`
(sprawdzone: `html { -webkit-text-size-adjust: 100%; }`, żadnego `font-size`).
Czyli przy domyślnej skali tekstu (100%, ustawienie, którego nikt nie ruszał)
`--text-help` = dokładnie **16 px**.

Tego tokenu używa m.in. `resources/views/components/layout.blade.php:322-335`
— **etykiety pięciu pozycji dolnej nawigacji**, jedynego opisu ikony w całym
serwisie na urządzeniu mobilnym:

```php
<a class="bottom-nav-item" href="{{ route('home') }}" ...>
    <x-ikona nazwa="home" class="bottom-nav-icon" :rozmiar="26" /> Start
</a>
```

`resources/css/app.css:222` (`.bottom-nav-item`): `font-size: var(--text-help);`.

Sam projekt ma na to jawną regułę — `docs/design/DESIGN_SYSTEM.md:152`:

> `--text-help` | 16px | Pomoc kontekstowa, znaczniki czasu — **tylko** tam,
> gdzie tekst główny obok jest ≥18px

„Start”, „Szukaj”, „Dodaj”, „Zeszyt”, „Profil” **nie są** pomocą kontekstową
obok większego tekstu — to jest CAŁA treść tego elementu interfejsu, jedyny
opis pięciu najważniejszych akcji produktu. Żadnego sąsiadującego tekstu 18 px
nie ma. `docs/UX_50_PLUS.md` nie przewiduje dla nawigacji żadnego wyjątku:
„body 18 px minimum”, bez zastrzeżenia „poza etykietami nawigacji”.

Ten sam token pod tym samym adresem trafia też do: `.field-help`, `.meta`
(72 użycia w widokach — daty, podpisy autora), `.badge`, `.choice-help`,
`.stat-label`, `.site-footer-inner`, `.kuking-board-subtitle`. Część z nich
(np. `.field-help` obok 18 px etykiety pola) mieści się w regule design
systemu; `.bottom-nav-item` — nie.

**Scenariusz porażki.** Zenon, 68 lat, dostał od córki telefon z Androidem.
Nie wie, że w ustawieniach jest suwak „Powiększ tekst” — nikt mu nie pokazał,
a ekran startowy nie prosi o to wprost. Otwiera Kuking, chce dodać zdjęcie
obiadu. Napis „Dodaj” pod plusem na dole ekranu jest wyraźnie mniejszy niż
tekst posta nad nim — dokładnie ten rodzaj drobnego druku, którego ten produkt
miał unikać z założenia. To nie jest awaria funkcji: Zenon w końcu trafi
palcem. Ale to jest dokładnie ten szczegół, który sprawia, że ktoś mówi
„to nie jest dla mnie” i zamyka aplikację — bez zgłaszania niczego, bo dla
niego to nie jest „błąd”, tylko „ten telefon/ta strona jest dla młodszych”.

**Koszt.** S. Podnieść `.bottom-nav-item` (i ewentualnie `.stat-label`,
`.kuking-board-subtitle`, gdzie tekst też jest samodzielny, nie towarzyszący)
do `--text-body`. `.field-help`/`.meta`/`.badge`, które faktycznie stoją obok
tekstu ≥18 px, mogą zostać — to jest zgodne z własną regułą projektu.

**Czego się wyrzekamy.** Nawigacja dolna zajmie odrobinę więcej pionowego
miejsca (etykiety owijają się częściej przy długich nazwach — ale wszystkie
pięć to pojedyncze, krótkie słowa, więc w praktyce nie zawinie się wcale).
Zero utraty funkcji.

---

### A2. Kreator przepisu odrzuca zdjęcia 12–15 MB, mimo że produkt wszędzie indziej obiecuje limit 15 MB

**Dowód.** `config/kuking.php:44`: `'max_bytes' => (int) env('KUKING_MEDIA_MAX_BYTES', 15 * 1024 * 1024)`.
Ten limit (15 MB na zdjęcie) obowiązuje w `PostController`, `RecipeController`
(formularz jednej strony), `CookedEventController`, `ProfileSettingsController`
— wszędzie przez wspólną klasę `app/Support/LimityZdjec.php`, zbudowaną
**właśnie po to**, żeby ta sama liczba nie rozjechała się w kilku miejscach
(komentarz w tej klasie wprost odwołuje się do dawnego audytu A31 —
`post_max_size` kontra `config/kuking.php`, patrz też E1 niżej).

Ale kreator przepisu (`resources/views/components/recipe-wizard.blade.php:99,821`)
wgrywa zdjęcie przez Livewire (`use WithFileUploads;`, `wire:model="heroPhoto"`).
Plik z `<input type="file" wire:model="heroPhoto">` leci NATYCHMIAST po wyborze
— zanim jakikolwiek kod komponentu (`storePendingPhoto()`, `LimityZdjec`) go
zobaczy — na osobny, wewnętrzny endpoint Livewire, który waliduje go WEDŁUG
WŁASNEJ konfiguracji: `config/livewire.php:135`:

```php
'rules' => null, // Example: ['file', 'mimes:png,jpg'] | Default: ['required', 'file', 'max:12288'] (12MB)
```

`rules => null` znaczy: obowiązuje domyślna reguła pakietu, **`max:12288`
kilobajtów = dokładnie 12 MB**. Nic w repozytorium tego nie nadpisuje
(`grep -rn "temporary_file_upload\|12288" app/ config/` — jedyne trafienie to
sam plik konfiguracyjny). Żadnego nasłuchu na `livewire-upload-error` też nie
ma (`resources/js/app.js` — sprawdzone w całości, nic).

Czyli: zdjęcie o wadze 13 MB — dozwolone przez `config/kuking.php`,
zapowiedziane w formularzu wpisu jako „Największy plik: 15 MB”
(`pages/posts/create.blade.php:14`), zgodne z komentarzem w `docker/php.ini:52`
(„Zdjęcia z telefonu to realnie 3–12 MB. 24 MB daje zapas na HEIC z iPhone'a”,
a HEIC z nowszych iPhone'ów z sensorem 48 Mpx regularnie mieści się właśnie
w przedziale 12–20 MB) — zostaje odrzucone na wejściu do kreatora, **zanim
dotrze do jakiegokolwiek kodu Kuking**, więc żaden ze starannie napisanych
polskich komunikatów (`LimityZdjec::komunikatZaDuzyPlik()`) się nie uruchamia.
To jest dokładnie ta sama klasa błędu, co dawny audyt A31/C8 (`post_max_size`
kontra budżet aplikacji) — tylko w piątym miejscu, którego `LimityZdjec` nie
dotyka, bo żyje w zupełnie innym pliku konfiguracyjnym pakietu.

**Scenariusz porażki.** Halina fotografuje świąteczny sernik nowym iPhone'em
— zdjęcie w HEIC waży 13,4 MB, mniej niż zapowiedziane 15 MB. W formularzu
jednej strony (`/dodaj/przepis/jedna-strona`) przeszłoby bez problemu. W trzy-
krokowym kreatorze, który jest domyślną drogą (link „Dodaj przepis” w `/dodaj`
prowadzi tu), pasek postępu wgrywania znika, a pole zdjęcia zostaje puste —
bez komunikatu, który cokolwiek tłumaczy po polsku, bo droga, którą ten błąd
przechodzi, nigdy nie mija warstwy Kuking. Halina próbuje jeszcze raz, z tym
samym skutkiem, i albo rezygnuje ze zdjęcia głównego, albo z kreatora
w ogóle (formularz jednej strony jest odnośnikiem na dole kroku 1, łatwym
do przeoczenia).

**Koszt.** S. `config/livewire.php`: `'rules' => ['required', 'file', 'image', 'max:' . (int) floor(config('kuking.media.max_bytes') / 1024)]`
— jedna linia, ta sama formuła co `LimityZdjec::maksKilobajtowDoWalidacji()`
(warto właśnie ją tam wywołać, nie przepisywać). Do tego test regresyjny na
wzór `UploadLimitsAgreementTest`, tym razem porównujący `config('kuking.media.max_bytes')`
z `config('livewire.temporary_file_upload.rules')`.

**Czego się wyrzekamy.** Nic — to czysta naprawa rozjazdu, bez kompromisu.

---

## B. Ryzyko przy pierwszych stu użytkownikach

### B1. `ProcessUploadedImage` nie ma `failed()` — zdjęcie może utknąć w `processing` na zawsze

**Dowód.** `app/Jobs/ProcessUploadedImage.php:34-148`. Job ma `$tries = 3`,
`$timeout = 120` i **żadnej metody `failed()`**. Jedyne miejsce, w którym
status zdjęcia wraca z `processing` do czegokolwiek innego, to `catch
(\Throwable $e)` wewnątrz `handle()` (linie 106-120).

Porównanie z bliźniaczym jobem `app/Jobs/GenerateUserExport.php:186-196`,
które MA `failed()` i tłumaczy dlaczego wprost w komentarzu (linie 51-52):

> „Rekord NIGDY nie zostaje w `processing` — pilnuje tego zarówno `catch`
> w `handle()`, jak i hook `failed()` (ten łapie także timeout, po którym
> nie ma już wyjątku do przechwycenia).”

Ten sam mechanizm ochronny, który autorzy świadomie dodali dla eksportu
danych, nie istnieje dla przetwarzania zdjęć. Gdy `ProcessUploadedImage`
przekroczy `$timeout = 120` s (dekodowanie zdjęcia blisko limitu
`max_megapixels = 50` pod obciążeniem workera, albo worker padnie/zostanie
zabity przez system w trakcie — worker ma limit pamięci `--memory=384`
z `docker/entrypoint.sh:339`, a dekodowanie dużego zdjęcia w GD, jak mówi
komentarz w `docker/php.ini:38`, jest właśnie tym, co tę pamięć zjada),
Laravel woła `failed()` na tym zadaniu (dokładnie tak, jak opisuje komentarz
w `GenerateUserExport` — to standardowe zachowanie kolejki przy timeout: proces
jest przerywany sygnałem w środku `handle()`, więc `catch` w `handle()` nigdy
się nie wykonuje, a jedyną szansą na sprzątnięcie jest hook `failed()`).
W `ProcessUploadedImage` tego hooka nie ma — więc `Media.status` zostaje
`processing` **bezterminowo**, niezależnie od tego, ile razy zadanie się
powtórzy (przy timeout żadna z trzech prób nie przechodzi przez `catch`).

Widok `resources/views/components/photo.blade.php:97-104` dla stanu innego
niż `ready`/`rejected` pokazuje: „Twoje zdjęcie się jeszcze przygotowuje.
Nic nie zginęło — odśwież stronę za chwilę, żeby je zobaczyć.” — komunikat
napisany z założeniem, że to stan przejściowy trwający sekundy, nie
nieskończoność.

**Scenariusz porażki.** Basia wgrywa zdjęcie 45-megapikselowego zbliżenia
sernika (w granicach dozwolonych 50 Mpx) w środę wieczorem, gdy serwis akurat
przetwarza kilka innych zdjęć naraz. Dekodowanie i trzy warianty (thumb/feed/
large) w GD nie mieszczą się w 120 s. Zadanie ginie w trakcie, `Media` zostaje
na zawsze w `processing`. Basia wraca po godzinie, po dniu, po tygodniu —
wpis nadal mówi „zaraz się pojawi, odśwież za chwilę”. Nie ma żadnej ścieżki
w interfejsie, którą mogłaby to naprawić sama (stan `rejected` ma komunikat
z sugestią „usuń wpis i dodaj ponownie”; `processing` — nie, bo z założenia
miał być chwilowy).

**Koszt.** S. Dodać `failed(?Throwable $e): void` analogiczny do tego
w `GenerateUserExport`: znajdź `Media` po `$this->mediaId`, jeśli status nie
jest już `ready`, ustaw `rejected` z `metadata.failure_reason`. Test
regresyjny: `Queue::fake()`, ręczne wywołanie `->failed()` na instancji joba,
assercja na stanie `Media`.

**Czego się wyrzekamy.** Nic. To jest dokładnie ten sam wzorzec, który projekt
już raz uznał za konieczny — koszt jego przeoczenia w drugim miejscu jest
czystą stratą, nie kompromisem.

---

### B2. Eksport RODO po cichu pomija zdjęcia, które w chwili eksportu jeszcze się przetwarzają

**Dowód.** `app/Domain/Users/Exports/ExportPhotoPlan.php:38-41`:

```php
$photos = $user->media()
    ->where('status', Media::STATUS_READY)
    ->orderBy('created_at')
    ->orderBy('id')
    ->get();
```

Tylko zdjęcia w stanie `ready` w ogóle wchodzą do `$this->media`/`$this->names`
— czyli do paczki. `CollectUserExportData` i `GenerateUserExport` nie mają
własnej wiedzy o zdjęciach: proszą `ExportPhotoPlan::pathFor($mediaId)`, które
dla zdjęcia w stanie `pending`/`processing` **zwraca `null`** (nie ma go
w mapie `$this->names`) — a widoki (`resources/views/exports/recipe.blade.php:38,102`
i odpowiedniki dla wpisów/wykonań) po prostu pomijają `<img>`, gdy dostaną
`null`. Zero śladu w `dane.json`, w `CZYTAJ-TO-NAJPIERW.txt`, w `index.html`
— żadne z tych miejsc nie wspomina, że jakiekolwiek zdjęcie zostało pominięte.
`GenerateUserExport::addReadme()` i `addIndex()` liczą `$photos->count()`,
które **już wyklucza** nie-gotowe zdjęcia — więc liczba w paczce jest
wewnętrznie spójna, ale cicho niezgodna z tym, co człowiek naprawdę ma
na koncie.

To nie jest hipotetyczne dla „konta z tysiącem zdjęć” (punkt zlecenia):
im więcej zdjęć ktoś ma, tym częściej choć jedno z nich jest w trakcie
przetwarzania w danej chwili — a `GenerateUserExport` i `ProcessUploadedImage`
dzielą tę samą kolejkę `database` i te same nazwy kolejek
(`--queue=high,default,media,low` w `docker/entrypoint.sh:334`), więc
eksport dużego konta może faktycznie wystartować, zanim wszystkie świeżo
wgrane zdjęcia zdążą się przetworzyć.

**Scenariusz porażki.** Basia postanawia „zabrać swoje przepisy” przed
zamknięciem konta. Dzień wcześniej dogrywa zdjęcia do trzech przepisów, które
jeszcze nie miały zdjęć głównych, i od razu klika „Przygotuj paczkę z moimi
danymi”. Zdjęcia są jeszcze `pending`. Paczka przychodzi e-mailem, Basia
otwiera `index.html` — wygląda kompletnie, bo nic nie krzyczy „czegoś brakuje”.
Dopiero gdy usunie konto (30 dni później) i zajrzy do przepisu w archiwum,
odkryje brak zdjęcia, które na pewno wgrywała — a wtedy nie ma już jak go
odzyskać. RODO art. 15/20 obiecuje dostęp do WSZYSTKICH danych, nie do tych,
które akurat zdążyły się przetworzyć.

**Koszt.** M. Najprostsza naprawa: w `requestExport()`
(`DataSettingsController.php`) sprawdzić, czy użytkownik ma media
w stanie `pending`/`processing` i albo poczekać (opóźnić dispatch), albo —
taniej — dodać w `CZYTAJ-TO-NAJPIERW.txt` i `index.html` jawne zdanie: „N zdjęć
nie zmieściło się w tej paczce, bo wciąż się przygotowywały. Poproś o nową
paczkę za kilka minut.” z liczbą policzoną jako różnica
`$user->media()->count()` i `$photos->count()`.

**Czego się wyrzekamy.** Wariant „poczekaj” komplikuje `GenerateUserExport`
(trzeba by odraczać albo sprawdzać stan przed startem, nie tylko przy starcie).
Wariant „powiedz wprost” jest tańszy i zgodny z resztą produktu (przyznanie
w interfejsie, że coś dzieje się w tle — dokładnie ten sam wybór, co
w naprawie dawnego audytu A2).

---

## C. Warto zrobić

Bez nowych ustaleń w tej kategorii z mojego zakresu — to, co znalazłem poza
A/B, albo już jest rozwiązane (sekcja E), albo jest zbyt drobne / zbyt
niepewne bez uruchomienia przeglądarki, żeby zasługiwało na osobne issue
(patrz sekcja F, np. zachowanie Livewire przy zerwanym połączeniu w kroku 2).

## D. Świadomie odkładamy

Bez nowych ustaleń. Nie natrafiłem w swoim zakresie na nic, co wymagałoby
świadomej decyzji „nie teraz” — a wymyślanie takiej decyzji na siłę byłoby
gorsze niż przyznanie, że jej nie mam.

## E. Sprawdzone, nie potwierdziło się

### E1. A1, A2, A3 z `USPRAWNIENIA-2.md` — wszystkie trzy już naprawione

Zlecenie prosiło o potwierdzenie tych trzech ustaleń na uruchomionej
aplikacji. Nie uruchomiłem serwera ani `phpunit` (patrz nota na początku), ale
kod jednoznacznie pokazuje naprawę wszystkich trzech, z bezpośrednimi
odniesieniami do numeru audytu w komentarzach:

- **A1 (Page Expired przy kilku zdjęciach).** `docker/php.ini:71`:
  `post_max_size=112M` (nie 28M jak w audycie), z komentarzem wprost cytującym
  problem i testem `tests/Feature/UploadLimitsAgreementTest.php`, który pilnuje
  zgodności na przyszłość. **Zmierzone**: napisałem jednorazowy skrypt PHP
  (bootstrujący `bootstrap/app.php` bez `phpunit`), odtwarzający dokładnie
  matematykę tego testu:

  ```
  post_max_size=117440512
  upload_max_filesize=25165824
  max_file_uploads=12
  max_bytes=15728640 max_per_post=6 budzet=94371840
  budzet+64KB <= post_max_size? TAK
  budzet <= 90% post_max_size? TAK
  max_bytes <= upload_max_filesize? TAK
  max_file_uploads >= max_per_post? TAK
  ```

  Wszystkie cztery warunki testu przechodzą naprawdę, nie tylko w teorii.

- **A2 (zdjęcie niewidoczne po publikacji, bez komunikatu).**
  `resources/views/components/photo.blade.php:45-107` ma teraz gałąź `@elseif($media)`
  z jawnym komentarzem „AUDYT A2” i dwoma stanami (`processing`/`rejected`),
  każdy z osobnym, polskim tekstem zależnym od tego, czy patrzy właściciel.
  `grep -rn "rejected" resources/views/` (z audytu-2 dający zero trafień) teraz
  zwraca dokładnie ten kod. Nie uruchomiłem `PrzygotowywanieZdjeciaWpisuTest`
  (nazwanego w komentarzu) — potwierdzone czytaniem kodu, nie wykonaniem testu.

- **A3 (SVG w karcie Open Graph).** `resources/views/components/layout.blade.php:73`:
  `$ogImage = ($image !== null && $image->isReady()) ? $image->url('large') : null;`
  — dokładnie ten warunek `isReady()`, którego audyt A3 się domagał, z komentarzem
  cytującym audyt wprost i nazwą testu `KartaDoUdostepnianiaTest`. Nie
  uruchomiłem tego testu — potwierdzone czytaniem kodu.

### E2. C1 z `USPRAWNIENIA-2.md` — walidacja już nie kasuje wyboru zdjęć

`app/Http/Controllers/PostController.php:44-76` wgrywa zdjęcia PRZED resztą
walidacji, z komentarzem cytującym dokładnie scenariusz z audytu-2 („Basia
wybierała trzy zdjęcia, pisała długi tekst... i traciła WYBÓR Z GALERII
TELEFONU”) i mechanizmem `media_ids` w ukrytych polach + `zebranZdjecia()`,
które sprawdza własność zdjęcia (`->where('owner_id', $user->getKey())`)
i to, czy nie jest już przypięte (`->whereDoesntHave('posts')`) — czyli
naprawa jest zrobiona bez otwierania nowego IDOR-a. Nie uruchomiłem testu
(nazwy nie znalazłem w treści kontrolera) — potwierdzone czytaniem kodu.

### E3. Policy kontra trasy — bez luk IDOR w moim zakresie

Sprawdziłem każdą trasę w `routes/web.php`, która ładuje model przez route
model binding albo przez identyfikator z pola formularza
(`media_ids`, `collection_id`, `parent_id`), kontra kontroler, który jej
używa: `PostController`, `RecipeController`, `CookedEventController`,
`CommentController`, `CollectionController`, `SocialController`,
`ProfileController`, `AppealController`, `ReportController`,
`DataSettingsController`, kontrolery w `Settings/`. Każde wejście na cudzą
treść (`show`, `update`, `destroy`, `comment`) woła `$this->authorize(...)`
albo `Gate::authorize(...)` z właściwą Policy, **przed** dotknięciem danych.
Szczególnie: `PostController::zebranZdjecia()` sprawdza własność
`media_ids` z formularza (nie tylko z URL), `CollectionController::saveRecipe()`
sprawdza `collection_id` przez `$request->user()->collections()->findOrFail()`
(zapytanie już zawężone do właściciela), a kreator przepisu
(`recipe-wizard.blade.php:125-126,420-438`) woła `Gate::authorize('update', $recipe)`
**przy KAŻDYM zapisie**, nie tylko przy wejściu do komponentu — co jest ważne,
bo Livewire trzyma stan komponentu między żądaniami i naiwna implementacja
sprawdzałaby autoryzację tylko raz. Nie znalazłem żadnej trasy z modelem
ładowanym po UUID/slug bez następującego po nim `authorize`/`can`.

### E4. `$fillable` w całym `app/Models` — `status`/`role` użytkownika nietknięte

`app/Models/User.php:51-58`: `fillable` zawiera wyłącznie `email`, `password`,
`locale`, `text_scale`, `wants_weekly_digest`, `age_confirmed_at`. Żadne pole
`status` ani `role`. Wszystkie zmiany stanu konta idą przez nazwane metody
z `forceFill` (`suspend()`, `ban()`, `markForDeletion()`, `promoteTo()` —
`User.php:480-585`), zgodnie z regułą z AGENTS.md §7. `promoteTo()` (jedyne
miejsce zmieniające `role`) nie jest wołane z żadnego kontrolera ani trasy
(`grep -rln "promoteTo" app/ routes/` — tylko definicja) — nie jest dziś
w ogóle eksponowane przez HTTP.

`status` występuje w `$fillable` siedmiu **innych** modeli (`Post`, `Recipe`,
`Comment`, `Media`, `DataExport`, `Report`, `Appeal`) — to są pola statusu
TREŚCI, nie użytkownika, i AGENTS.md §7 dotyczy wprost tylko konta. Każde
z tych `update()`/`create()` wywołań, które sprawdziłem, idzie przez akcję
domenową albo kontroler z wcześniejszym `authorize()`, nie przez surowe dane
z żądania.

---

## F. Czego nie zdążyłem obejrzeć — białe plamy

Uczciwie, żeby było wiadomo, gdzie ten dokument milczy.

1. **Żaden test z `tests/` nie został uruchomiony.** `composer install` nie
   dociągnął `phpunit` w tym środowisku (błąd uwierzytelnienia wobec GitHuba
   przy pobieraniu paczek `require-dev`, powtórzony trzykrotnie, w różnym
   odstępie czasu). Wszystko, co w sekcji E nazywam „potwierdzonym czytaniem
   kodu”, jest właśnie tym — lekturą, nie wykonaniem. Wyjątkiem jest A1/E1,
   które policzyłem osobnym skryptem PHP bez `phpunit`.

2. **PostgreSQL w tym środowisku to 16.13, nie 18**, wbrew zapowiedzi zlecenia.
   Migracje przeszły bez błędu na tej wersji, ale nie sprawdzałem, czy któraś
   z funkcji specyficznych dla 18 (jeśli jakaś jest używana) zachowuje się
   identycznie.

3. **Nic z warstwy HTTP na żywo.** Nie uruchomiłem `frankenphp`/`artisan serve`,
   więc `/health`, cały kreator przepisu w przeglądarce (w tym dokładne
   zachowanie przy zerwanym połączeniu w połowie kroku 2 — czy Livewire
   pokazuje jakikolwiek komunikat, czy po prostu nic), kontrast kolorów
   (ufam wyliczeniom już zapisanym w komentarzach `tokens.css`, nie
   przeliczyłem ich ponownie) i użyteczność przy 320 px / 200% powiększenia
   — wszystko to jest oceną z lektury kodu i CSS, nie pomiarem. To pokrywa się
   ze świadomie zostawionym zakresem issue #26 (automaty axe-core/Lighthouse).

4. **Zachowanie Livewire przy zerwanym połączeniu w kroku 2 kreatora** —
   dokładnie punkt z zamówienia, którego nie potwierdziłem. Kod
   (`saveDraft()`, `updated()` z debounce 3000 ms) wygląda rozsądnie na
   papierze: nieudany zapis ustawia `saveState = 'error'` z polskim
   komunikatem. Ale to zakłada, że żądanie Livewire w ogóle dotarło do
   serwera i wrócił z niego wyjątek. Co dokładnie widzi człowiek, gdy
   żądanie ginie w sieci (bez odpowiedzi w ogóle) — nie sprawdziłem i nie
   znalazłem w kodzie żadnej obsługi tego konkretnego przypadku (Livewire ma
   własne zachowanie „offline”, którego Kuking nigdzie nie nadpisuje niestandardowym
   komunikatem). To jest kandydat na kolejny biały punkt, nie ustalenie —
   nie mam dowodu, że jest źle, tylko brak dowodu, że jest dobrze.

5. **`docs/seo/`, `docs/brand/`, `docs/infra/`, `docs/decyzje/`** — razem
   ok. 6000 linii, przeczytałem z nich w całości tylko fragmenty
   `docs/infra/DEPLOYMENT_RUNBOOK.md` dotyczące `preDeployCommand`
   i healthchecku (bo to bezpośrednio dotyczyło mojego zakresu — Dockerfile
   i wdrożenie). Reszta — nieprzeczytana w całości. Możliwe, że część
   propozycji z sekcji C dawnych audytów jest tam rozstrzygnięta w drugą
   stronę; nie sprawdziłem tego punkt po punkcie.

6. **`Dockerfile` (281 linii) i `docker/Caddyfile`** — nieprzeczytane w
   całości. Skupiłem się na `docker/entrypoint.sh` (cały, 455 linii) i na
   `.railway/railway.ts` we fragmentach dotyczących ról web/worker/scheduler,
   `preDeployCommand` i healthchecku — bo to jest dokładnie to, co poprzedni
   audyt zostawił otwarte („ten kontener raz już położył produkcję”), i to,
   co sprawdziłem, wygląda dojrzale: nadzorca procesu z rosnącym backoff,
   rozróżnienie „zakończył się planowo” od „padł”, obsługa woluminu
   montowanego jako `root`. Nie znalazłem tam nic nowego do zgłoszenia, ale
   nie czytałem samego `Dockerfile` (etapy budowania obrazu, wersje
   zależności systemowych) linia po linii.

7. **Kontrolery `ModerationController`, `AdminAppealController`,
   `BezOdpowiedziController`, `DailyBoardController`** — sprawdziłem tylko,
   że każdy woła `$this->authorize('moderate', User::class)` (E3), nie
   czytałem ich logiki biznesowej w szczegółach.
