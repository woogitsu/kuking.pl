# USPRAWNIENIA.md — co jest zepsute, czego brakuje i czego NIE robić

> Research: 6 września 2026. Gałąź bazowa: `main` @ `3b11e67`.
> Wszystko sprawdzone w kodzie, w bazie albo w przeglądarce. Każde znalezisko ma plik i linię.
> Rzeczy niepotwierdzone są oznaczone wprost.

**Warunki pomiaru.** Worktree z dowiązanym `vendor`, PostgreSQL 16 lokalnie, osobna baza
`kuking_badanie` (żeby nie deptać po innych agentach), `php artisan serve`, Chromium 1194
przez Playwrighta, axe-core z `node_modules`. Pomiary skalowania robione na 405 kontach
i 995 wpisach dosypanych fabrykami. Zestaw testów przechodzi w całości:
**369 testów, 1457 asercji, 38 s, zero błędów.** To jest ważny kontekst dla całego dokumentu:
**żadne z poniższych znalezisk nie jest łapane przez obecne testy.**

> **Uwaga o `scripts/dostepnosc.mjs`.** Zlecenie zakładało, że ten skrypt jest w repozytorium.
> Na `main` go nie ma — żyje w commitach `f51245d` i `9731c03`, które nie zostały jeszcze
> scalone. Dlatego przejście dostępności zrobiłem od zera, własnym skryptem z axe-core.
> Jeśli tamta gałąź wejdzie, warto porównać wyniki: u mnie axe **nie** wyszedł na zero
> (patrz Z-19).

---

## 1. Dziesięć rekomendacji, uszeregowanych

Kolejność: (szkoda dla użytkownika 50+) × (jak tanio to naprawić). Nie kolejność „ważności tematu”.

---

### R-1. Listy z systemu przychodzą po angielsku · **S** · P0

**Co.** Napisać własne klasy powiadomień (`ResetPassword`, `VerifyEmail`) po polsku
albo dodać `lang/pl/passwords.php` + `resources/views/vendor/notifications/email.blade.php`.
Docelowo: własny szablon w stylu `resources/views/mail/data-export-ready.blade.php`, który już istnieje i jest po polsku.

**Dlaczego to boli.** Sprawdziłem to na żywo. Po kliknięciu „Nie pamiętam hasła” polska strona
mówi: *„wysłaliśmy na niego wiadomość z linkiem do ustawienia nowego hasła”*. Do skrzynki
przychodzi:

```
Subject: Reset your password
# Hello!
You are receiving this email because we received a password reset request for your account.
[ Reset Password ]
Regards,
```

Rejestracja wysyła `Subject: Verify your email address` / *„Please click the button below…”*.
Dowód: `storage/logs/laravel.log` po wywołaniu obu ścieżek na `MAIL_MAILER=log`.

Przyczyna: nie ma katalogu `lang/`, nie ma nadpisanych klas powiadomień, a
`PasswordResetController.php:47` woła `Password::sendResetLink()` i `RegisterController.php:123`
odpala `event(new Registered($user))` — jedno i drugie idzie do domyślnych, angielskich
powiadomień Laravela.

Dla naszej grupy to nie jest usterka kosmetyczna. Osoba, która zapomniała hasła, dostaje
list po angielsku od nieznanego nadawcy z przyciskiem „Reset Password”. Połowa uzna to za
próbę oszustwa i skasuje — bo dokładnie tak wyglądają maile phishingowe, przed którymi
ostrzegają ją w telewizji. **Odzyskiwanie hasła to jedyna droga powrotu dla kogoś, kto
wypadł z konta.** Dziś ta droga jest zamknięta.

**Jeśli tego nie zrobimy.** Każde zgubione hasło = trwale utracone konto. Przy zamkniętej
alfie na 20 osób jedna taka sytuacja to 5% bazy.

---

### R-2. Główna pętla produktu nie wychodzi poza serwis · **M** · P0

**Co.** (a) E-mail „Ktoś ugotował z Twojego przepisu” wysyłany razem z powiadomieniem
in-app. (b) Faktyczne cotygodniowe podsumowanie dla osób, które je zaznaczyły.

**Dlaczego to boli.** Dwie rzeczy, które razem tworzą dziurę:

1. `RecordCookedEvent.php:87-98` tworzy wyłącznie wiersz w `notifications`. `app/Mail/` zawiera
   **jeden** mailable — `DataExportReady.php`. Autor przepisu dowie się, że ktoś u siebie
   ugotował jego pierogi, **tylko jeśli sam wejdzie na stronę i zauważy plakietkę w belce.**
   `docs/product/RETENTION_LOOPS.md` §1 nazywa to pętlą numer jeden i najsilniejszym powodem
   powrotu. Dziś ta pętla wymaga, żeby człowiek wrócił *zanim* dostanie powód do powrotu.
2. `resources/views/pages/settings/privacy.blade.php:9-10` obiecuje wprost:
   *„Chcę raz w tygodniu dostawać e-mail z Kuking — Krótkie podsumowanie: kto ugotował
   z Twoich przepisów… Jeden e-mail tygodniowo, nigdy więcej.”*
   `wants_weekly_digest` jest zapisywane (`PrivacySettingsController.php:28`) i… to wszystko.
   W `routes/console.php` są dokładnie dwa zadania: sprzątanie eksportów i zdejmowanie kar.
   **Nie ma nadawcy digestu.** Checkbox jest obietnicą bez pokrycia.

Weekly Active Cooks to metryka północna. Bez kanału, który dociera do człowieka poza
serwisem, WAC zależy wyłącznie od tego, czy ktoś sam sobie przypomni.

**Jeśli tego nie zrobimy.** Zamknięta alfa będzie działać, bo w niej powiadomienia
zastępuje gospodarz dzwoniący do 20 osób. Przy 200 osobach przestanie — i nie będzie
wiadomo, czy produkt nie działa, czy tylko nikt nie wie, że coś się wydarzyło.

---

### R-3. Belka górna wychodzi poza ekran telefonu — na każdej stronie · **S** · P0

**Co.** `.topbar-inner` ma się zawijać albo skracać etykiety poniżej ~480 px.

**Dlaczego to boli.** Zmierzone Chromium przy `viewport 360×740` (typowy Android):

| stan | `scrollWidth` | `clientWidth` | co wystaje |
|---|---|---|---|
| gość, każda strona | **386** | 360 | przycisk „Załóż konto” (300…386) |
| zalogowany, każda strona | **493** | 360 | „Powiadomienia” + „Dodaj” (406…493) |
| 320 px CSS (WCAG 1.4.10) | **386** | 320 | jw. |

Zrzut ekranu strony powitalnej: przycisk „Załóż konto” jest ucięty w połowie słowa. Dla
zalogowanego przycisk „Dodaj” w belce **leży całkowicie poza ekranem**, a cała strona
przewija się w bok.

`AGENTS.md` §5 wymaga: *„przy 200% powiększenia i przy szerokości 320 px strona pozostaje
używalna”*. WCAG 2.2 AA, kryterium 1.4.10 (Reflow), zabrania przewijania poziomego
przy 320 px CSS. Oba złamane, wszędzie.

Ciekawe jest to, **czego automat nie złapał**: axe-core nie zgłasza tu nic — reflow jest
regułą, której axe nie testuje. `UkladGosciaTest` pilnuje siatki na desktopie, nie belki
na telefonie.

Winny kod: `resources/views/components/layout.blade.php:117-145` (flex bez `flex-wrap`)
i `resources/css/app.css:88-96`.

**Jeśli tego nie zrobimy.** Pierwsze wrażenie z produktu „wyjątkowo czytelnego”
to ucięty przycisk i strona, która ucieka w bok pod palcem. To jest dokładnie ten sygnał
„coś tu jest zepsute, chyba ja to zepsułam”, którego cała reszta projektu unika.

---

### R-4. Strony błędów są po angielsku, a wygaśnięcie sesji kasuje wpisany tekst · **S/M** · P0

**Co.** Katalog `resources/views/errors/` (404, 403, 419, 429, 500, 503) w layoucie Kuking,
po polsku, z drogą powrotu. Plus obsługa `TokenMismatchException` w `bootstrap/app.php`:
`back()->withInput()` z komunikatem, zamiast ekranu błędu.

**Dlaczego to boli.** `bootstrap/app.php:58-65` zawiera komentarz:

```php
// Gdy sesja wygaśnie w trakcie wypełniania formularza, użytkownik
// wraca na tę samą stronę z wpisanymi danymi, a nie na ekran błędu.
$middleware->validateCsrfTokens(except: ['_csp']);
```

**Kod nie robi tego, co mówi komentarz.** `validateCsrfTokens(except: [...])` wyłącza
sprawdzanie CSRF dla `_csp` i nic poza tym. Sprawdziłem: POST z nieprawidłowym tokenem
zwraca **419 z tytułem `Page Expired`** — domyślną, angielską stronę Laravela. Wpisane
dane przepadają. W repozytorium nie ma ani jednej wzmianki o `TokenMismatchException`.

Analogicznie 404: `<title>Not Found</title>`, treść „Not Found”, brak belki Kuking, brak
linku do strony głównej. Katalogu `resources/views/errors/` nie ma w ogóle.

To łamie dwie twarde reguły naraz (`AGENTS.md` §5): *„błędy po polsku, mówiące co zrobić”*
oraz *„poprawnie wpisane dane nigdy nie znikają”*. I trafia dokładnie w ten scenariusz,
który u osoby 65+ jest normą, a nie wyjątkiem: zaczęła pisać przepis, odeszła do garnka,
wróciła po godzinie, kliknęła „Opublikuj”.

**Jeśli tego nie zrobimy.** Najbardziej pracochłonna treść w serwisie (przepis pisany
ręcznie) ginie w najbardziej frustrujący sposób, z komunikatem po angielsku.
Kreator Livewire ma autosave — droga bez JavaScriptu (`/dodaj/przepis/jedna-strona`) nie ma.

---

### R-5. Nie ma przycisku „Wyloguj”, a Ustawienia są nieosiągalne z telefonu · **S** · P0

**Co.** Dodać „Wyloguj” i wejście do Ustawień w miejscu widocznym na telefonie.

**Dlaczego to boli.** Trasa `POST /logout` istnieje (`routes/web.php:110-112`), middleware
świadomie ją przepuszcza dla kont zawieszonych (`EnsureAccountIsActive.php:51`, komentarz:
*„`logout` musi zostać, bo to jedyne wyjście”*) — a **w `resources/views/` nie ma ani
jednego odwołania do `route('logout')`**. Sprawdzone porównaniem 74 nazw tras z ich
użyciami w widokach. Słowo „Wyloguj” nie pada nigdzie.

Osoba 50+ często korzysta ze wspólnego komputera (dzieci, wnuki, biblioteka, UTW).
`SESSION_LIFETIME` to 7 dni. Nie da się wyjść z własnego konta inaczej niż przez
czyszczenie ciasteczek.

Drugie piętro tego samego problemu: `resources/css/app.css:172` gasi `.side-nav` poniżej
64 rem. Na telefonie zostaje belka (Powiadomienia, Dodaj — a „Dodaj” i tak jest poza
ekranem, patrz R-3) i dolna nawigacja: Start, Szukaj, Dodaj, Zeszyt, Profil. **Ustawień
tam nie ma.** Jedyne drogi do „Rozmiaru tekstu” — flagowej funkcji dla tej grupy — to
Profil → „Zmień swój profil” (to inna podstrona ustawień, bez linku dalej) albo stopka →
Pomoc → link w zdaniu. Tak samo nieosiągalne z telefonu: „Świeżo z Kuking”, prywatność,
lista zablokowanych, eksport danych, usunięcie konta, a dla moderatora — cała moderacja.

Nie ma też ekranu-koncentratora „Ustawienia”: cztery podstrony linkują do siebie tylko
częściowo (`settings/privacy.blade.php:43` → dane; reszta to ślepe zaułki).

**Jeśli tego nie zrobimy.** Ustawienie większego tekstu, czyli rzecz, po której ten produkt
się poznaje, jest praktycznie nie do znalezienia na urządzeniu, na którym ta grupa
najczęściej czyta. A prawo do wylogowania jest tematem, który wraca w każdym teście
z użytkownikami.

---

### R-6. Na profilu widnieje przycisk „pagination.previous” · **S** · P1

**Co.** `lang/pl/pagination.php` + własny widok paginacji (albo `x-show-more` wszędzie).

**Dlaczego to boli.** Siedem miejsc używa `$paginator->links()` z domyślnym widokiem
Laravela: `pages/profile/show.blade.php:128,141,156`, `pages/notifications.blade.php:98`,
`pages/collections/show.blade.php:18`, `pages/profile/connections.blade.php:70`,
`pages/admin/reports.blade.php:99`.

Sprawdzone na żywo (`/@basia`, 21 wpisów, 360 px). Renderuje się:

- **„pagination.previous” i „pagination.next”** jako napisy na przyciskach — bo nie ma
  `lang/pl/pagination.php`, a `APP_FALLBACK_LOCALE=pl`, więc Laravel oddaje surowy klucz;
- „Showing 13 to 24 of 100 results”, `aria-label="Pagination Navigation"`,
  `aria-label="Go to page 3"` — po angielsku;
- klasy `py-2 text-sm text-gray-700 bg-white border-gray-300 inline-flex sm:hidden`
  **nie istnieją w zbudowanym CSS** (sprawdzone w `public/build/assets/app-*.css`), bo
  Tailwind 4 nie skanuje `vendor/` (jest w `.gitignore`). Efekt: przyciski mają 30 px
  wysokości zamiast wymaganych 48, `sm:hidden` nie działa, a strzałki „poprzednia/następna”
  w wariancie desktopowym są **samym SVG bez tekstu** — czyli „ikona jako jedyny opis”,
  wprost zabroniona w `AGENTS.md` §5.

`AGENTS.md` mówi też, że paginacja to przycisk „Pokaż więcej”. Komponent `x-show-more`
istnieje i jest poprawny — używają go tylko feedy kursorowe.

To jest najbardziej upokarzający pojedynczy defekt w całym serwisie: **na dole własnego
archiwum człowiek widzi napis `pagination.previous`.**

---

### R-7. Nie da się wydrukować przepisu · **S** · P1

**Co.** Arkusz `@media print` + przycisk `[ Wydrukuj ]` na stronie przepisu.

**Dlaczego to boli.** W `resources/css/` **nie ma ani jednej reguły `@media print`**
(sprawdzone grepem po `app.css`, `tokens.css`, `fonts.css`). Wydruk strony przepisu
wypluje belkę, nawigację boczną, przyklejoną dolną nawigację, komentarze, przyciski
„Zgłoś” i „Usuń ten przepis”.

Jednocześnie:

- `docs/product/SOUL.md:201` ma to jako pozycję **MVP**, koszt S, z uzasadnieniem
  *„Osoby 50+ gotują z wydruku albo z tabletu opartego o cukiernicę”*;
- `docs/product/SOUL.md:269` wpisuje to na listę „soul-pack MVP” pod numerem 12,
  z konsekwencją: *„…ignorujemy sposób, w jaki 50+ naprawdę gotuje”*;
- eksport danych **sam to przyznaje**: `resources/views/exports/posts.blade.php:6` —
  *„przepis czyta się przy garnku i drukuje pojedynczo”*. Czyli wersja do druku istnieje,
  ale wyłącznie w paczce ZIP, do której trzeba dojść przez ustawienia, poczekać na maila
  i rozpakować archiwum;
- Kwestia Smaku — serwis, którego ta grupa realnie używa — ma ikonę drukarki nad każdym
  przepisem ([Kwestia Smaku, blog](https://www.kwestiasmaku.com/blog-kulinarny/drukowanie-przepisow-i-zamieszczanie-waszych-zdjec)).

**Jeśli tego nie zrobimy.** Zostawiamy najtańszą możliwą przewagę na stole i wysyłamy
sygnał, że nie rozumiemy, jak ta grupa gotuje.

---

### R-8. Na wpis nie da się zareagować inaczej niż pisząc komentarz · **M** · P1

**Co.** Jedna nazwana, tania reakcja pod wpisem — `docs/product/SOUL.md:138-139` projektuje
ją jako **„Ładne!”**, z licznikiem widocznym **tylko dla autora** („7 osób doceniło”),
niewidocznym publicznie.

**Dlaczego to boli.** W bazie nie ma żadnej tabeli reakcji (sprawdzone `\dt` — 33 tabele,
żadnej). `post-card.blade.php:42-49` daje pod wpisem dokładnie dwa przyciski: „Napisz
komentarz” i „Zgłoś”. Dla wpisu — czyli dla **głównego typu treści produktu, tego z hasła
„zdjęcie + kilka słów”** — jedyną formą odpowiedzi jest napisanie zdania.

`docs/product/RETENTION_LOOPS.md` mówi wprost: post bez reakcji w pierwszej dobie to
utracony użytkownik 50+. A próg napisania komentarza jest u tej grupy nieporównanie wyższy
niż próg kliknięcia — bo komentarz trzeba wymyślić, a potem się pod nim podpisać.

To nie jest wracanie do zamkniętej decyzji. `AGENTS.md` §12 zakazuje **„liczników lajków
wyeksponowanych w interfejsie”**, a nie samej reakcji. Projekt z `SOUL.md` (nazwa czynności
zamiast serduszka, licznik prywatny) jest zgodny z tym zakazem i był zaplanowany do MVP.

**Jeśli tego nie zrobimy.** Wpisy będą wisieć bez żadnego odzewu, a `COLD_START.md`
zakłada, że odzew ma zapewnić gospodarz ręcznie. To skaluje się do ~50 osób, nie do 200.

---

### R-9. Nie ma jak przeglądać przepisów, a wpisu nie da się poprawić · **M** · P1

**Co.** (a) Lista przepisów pod `/przepisy` (chronologicznie, jak `/odkryj`).
(b) Edycja wpisu — trasa, formularz, test.

**Dlaczego to boli.**

*Przepisy.* W `routes/web.php` jest `GET /przepisy/{recipe}` i **nie ma indeksu**.
`/odkryj` pokazuje wyłącznie wpisy (`DiscoverFeed` operuje na `Post`). Pusta wyszukiwarka
mówi tylko „Wpisz coś w pole powyżej” (`pages/search.blade.php:24`). Efekt: **do przepisu
można dojść wyłącznie przez wyszukiwarkę (trzeba wiedzieć, czego się szuka), przez profil
autora albo przez wpis z podpiętym przepisem.** Główna akcja produktu — „Ugotowałem” —
zaczyna się od znalezienia cudzego przepisu, a lejek do niej nie istnieje.
Kod sam się w tym gubi: `pages/recipes/show.blade.php:66` buduje okruszek nawigacyjny
schema.org o nazwie **„Przepisy”** wskazujący na `route('discover')`, czyli na listę wpisów.
To nieprawda podana do Google.

*Wpisy.* Brak tras `posts.edit` / `posts.update` (sprawdzone `route:list`). W widoku wpisu
jest tylko „Usuń ten wpis” (`pages/posts/show.blade.php:19-21`). Tymczasem
`docs/FEATURES.md` wymienia w MVP „Wpis: … edycja”, a `docs/ROADMAP.md` §3 ma „edit”.
Literówka w opisie obiadu jest dziś naprawialna tylko przez skasowanie wpisu i wgranie
zdjęcia od nowa — czyli utratę daty w archiwum i wszystkich komentarzy.

**Jeśli tego nie zrobimy.** Przepisy będą istniały głównie dla Google, a nie dla ludzi
w serwisie. A „nie da się poprawić literówki” to najczęstsza skarga w testach z tą grupą
i jedna z niewielu, które ludzie zapamiętują jako „ten serwis mnie ośmieszył”.

---

### R-10. Onboarding pyta o zainteresowania i wyrzuca odpowiedź do kosza · **S** · P2

**Co.** Albo wykorzystać zainteresowania (choćby do sortowania propozycji), albo usunąć
obietnicę z tekstu, albo usunąć krok. Dziś jest najgorszy z możliwych wariantów: pytamy
i kłamiemy.

**Dlaczego to boli.** `pages/onboarding/interests.blade.php:13` obiecuje:
*„Zaznacz, co Cię interesuje — **podpowiemy Ci ludzi, którzy gotują podobnie**”*.

`OnboardingController.php:62-67` chowa odpowiedzi do sesji. `people()` woła
`DailyBoard::peopleToFollow()`, które **w ogóle nie zna zainteresowań** — sortuje po dacie
ostatniej publikacji (`DailyBoard.php:127-132`). `done()` (linia 107) kasuje klucz z sesji.
Zaznaczenie „Kiszonki” i zaznaczenie „Ciasta” dają identyczną listę, tak samo jak
niezaznaczenie niczego.

Do tego onboarding kosztuje trzy pełne ekrany między rejestracją a pierwszym zdjęciem.
Policzone przejście od strony powitalnej do opublikowanego zdjęcia: **7 akcji na 5 kolejnych
stronach** (Załóż konto → formularz → Dalej → Dalej → Dodaj pierwsze zdjęcie → wybór pliku
→ Opublikuj), plus 3 dotknięcia w wyborze pliku na Androidzie. Cel produktowy to poniżej
60 sekund; pierwsze zdjęcie mieści się w tym tylko przy sprawnym pisaniu.
Dla wracającego użytkownika jest już dobrze: **3 kliknięcia + wybór pliku**
(dolna nawigacja „Dodaj” → „Zdjęcie i kilka słów” → plik → „Opublikuj”).

**Jeśli tego nie zrobimy.** Pierwsza rzecz, jaką produkt mówi nowej osobie, jest nieprawdą.
Ta grupa tego nie weryfikuje świadomie, ale odczuwa: „coś tu nie działa tak, jak pisze”.

---

## 2. Znaleziska w kodzie

Numeracja `Z-`. Wszystko sprawdzone, nie wywnioskowane.

### 2.1. Kod obiecuje coś, czego nie robi

| # | Miejsce | Co jest napisane | Co robi kod |
|---|---|---|---|
| **Z-1** | `bootstrap/app.php:58-60` | „Gdy sesja wygaśnie w trakcie wypełniania formularza, użytkownik wraca na tę samą stronę z wpisanymi danymi, a nie na ekran błędu.” | `validateCsrfTokens(except: ['_csp'])` wyłącza CSRF dla jednej trasy i nic więcej. Zmierzone: POST ze złym tokenem → **419, `<title>Page Expired</title>`**, dane przepadają. Zero wzmianek o `TokenMismatchException` w repo. |
| **Z-2** | `pages/settings/privacy.blade.php:9-10` | „Chcę raz w tygodniu dostawać e-mail z Kuking… Jeden e-mail tygodniowo, nigdy więcej.” | Zapisuje `wants_weekly_digest` i koniec. W `app/Mail/` jest jeden mailable (`DataExportReady`), w `routes/console.php` dwa zadania — żadne nie wysyła digestu. |
| **Z-3** | `pages/onboarding/interests.blade.php:13` | „podpowiemy Ci ludzi, którzy gotują podobnie” | `OnboardingController.php:65` → sesja, `:107` → `forget()`. `DailyBoard::peopleToFollow()` nigdy nie czyta zainteresowań. |
| **Z-4** | `components/confirm-button.blade.php:4-8` | „Działa bez JavaScriptu” | Potwierdzenie to `onsubmit="return confirm(...)"`. Bez JS formularz wysyła się **od razu, bez pytania**. Komentarz sam to przyznaje w nawiasie, ale `AGENTS.md` §5 wymaga potwierdzenia bezwarunkowo. Dotyczy usuwania przepisu, wpisu, wykonania, komentarza, zeszytu i blokowania osoby. |
| **Z-5** | `pages/recipes/show.blade.php:66` | Okruszek schema.org: `['name' => 'Przepisy', 'item' => route('discover')]` | `/odkryj` to lista **wpisów**, nie przepisów. Nieprawdziwe dane strukturalne. |

### 2.2. Zbudowane, ale nieosiągalne z interfejsu

| # | Co | Dowód |
|---|---|---|
| **Z-6** | **Wylogowanie.** `POST /logout` (`routes/web.php:110-112`) nie ma w widokach żadnego przycisku. Słowo „Wyloguj” nie występuje w `resources/`. | porównanie 74 nazw tras z `route('…')` w widokach |
| **Z-7** | **Ustawienia na telefonie.** `.side-nav { display:none }` poniżej 64 rem (`app.css:172`); dolna nawigacja (`layout.blade.php:258-274`) nie zawiera Ustawień, „Świeżo z Kuking” ani moderacji. | Playwright, 360 px |
| **Z-8** | **Skan zeszytu.** `pages/recipes/show.blade.php:128` — cała sekcja „Skąd ten przepis”, razem ze zdjęciem kartki, jest schowana pod `@if($recipe->source_note \|\| $recipe->source_person)`. Kto wgra zdjęcie starego zeszytu, ale nie wypełni „po kim” ani „historii”, **nie zobaczy go nigdzie.** Poprawka to jeden warunek: `\|\| $recipe->sourceScan`. | lektura kodu; kolumna `source_scan_media_id` istnieje i jest zapisywana (`PublishRecipe.php:110`) |
| **Z-9** | **Tekst alternatywny zdjęć.** `StoreUploadedImage::handle()` przyjmuje `?string $altText = null`; **żaden z czterech wywołujących go kontrolerów go nie podaje** (`PostController.php:60`, `CookedEventController.php:81`, `RecipeController.php:90,96,145,155`, `ProfileSettingsController.php:72`), a żaden formularz o niego nie pyta. Skutek: **każde zdjęcie w serwisie ma `alt=""`.** Kolumna, obsługa w widoku (`photo.blade.php:36`) i eksport (`CollectUserExportData.php:349`) istnieją i są martwe. axe tego nie zgłasza, bo `alt=""` traktuje jako dekorację. | grep po `alt_text` |
| **Z-10** | **Edycja wpisu.** Brak `posts.edit`/`posts.update`, mimo `docs/FEATURES.md` (MVP: „Wpis: … edycja”) i `docs/ROADMAP.md` §3 („edit”). | `php artisan route:list` |
| **Z-11** | **Zaproszenie do zamkniętej alfy.** Jedyna kontrola to globalny przełącznik `KUKING_REGISTRATION_OPEN` (`config/kuking.php:74`) → `abort_unless(..., 503)`. Nie ma zaproszeń. `docs/product/SOUL.md:203` ma „Zaproś kogoś z rodziny” jako MVP. Wyłączenie rejestracji odcina także rodzinę uczestników alfy — i pokazuje im surową, angielską stronę 503. | lektura kodu |

### 2.3. Polszczyzna i teksty

| # | Miejsce | Problem |
|---|---|---|
| **Z-12** | `components/comment-thread.blade.php:61` i `:123` | `Str::plural('minutę', $n)` — **angielski** fleksor. Sprawdzone w konsoli: `2 → "minutęs"`, `5 → "minutęs"`. Pod każdym własnym komentarzem świeci: **„Możesz poprawić jeszcze przez 5 minutęs.”** |
| **Z-13** | `pages/search.blade.php:33` | `count() === 1 ? 'przepis' : 'przepisów'` → „Znaleziono 2 przepisów”. Polski ma trzy formy. Model `Recipe` ma już poprawny odmieniacz (`Recipe::odmianaPorcji`, opisany i przetestowany w `OdmianaPorcjiTest`) — jest prywatny i nikt go nie użył ponownie. |
| **Z-14** | `pages/profile/show.blade.php:50-52` | Liczniki bez odmiany: „1 wpisów”, „2 przepisów”, „1 obserwujących”, „3 razy ugotowała/ugotował”. |
| **Z-15** | `pages/notifications.blade.php:47` vs `pages/home.blade.php:30` | **To samo zdanie w dwóch rodzajach.** Powitalne powiadomienie: „Zacznij od zdjęcia tego, co dziś **ugotowałaś**”. Pusty stan feedu: „Zacznij od zdjęcia tego, co dziś **ugotowałeś**”. Podobnie `onboarding/done.blade.php:14` (żeńskie) obok `landing.blade.php:6` (męskie), `settings/data.blade.php:4` („wrzuciłaś”), `settings/privacy.blade.php:19` („zablokowałaś”), `collections/index.blade.php:3` („zapisałaś”). Serwis mówi do tej samej osoby raz w rodzaju męskim, raz w żeńskim, w zależności od ekranu. |
| **Z-16** | `FeedController.php:81` | Powitanie na `/home` po 15:00: „Dobry wieczór, {imię}. Co dziś **ugotowałeś**?”. `COPY_STYLE.md` §2 utrwala rodzaj męski **tylko w haśle głównym** i zaleca konstrukcje bezrodzajowe w pozostałych tekstach. Zrzut ekranu pokazuje „Dzień dobry, **Basia**. …” oraz przycisk „Dodaj zdjęcie tego, co **ugotowałeś**”. Przy 83,8% kobiet w grupie docelowej (`COPY_STYLE.md` §2) to jest widoczne. |
| **Z-17** | `CommentController.php:27,71` + `comment-thread.blade.php:30,46` | Stan „usunięty” zakodowany jako **magiczny napis w treści**: `$comment->body === 'Komentarz usunięty.'`. Kto wpisze dokładnie ten tekst jako komentarz, dostanie stylizację „komentarz usunięty”. Zmiana stałej odczaruje wszystkie stare komentarze. W tabeli jest kolumna `status` i `deleted_at` — nieużyte do tego celu. |
| **Z-18** | `CommentPolicy.php:18` vs `comment-thread.blade.php:56,118` | Okno edycji „15 minut” zapisane trzy razy jako literał, w dwóch plikach. Nie ma go w `config/kuking.php`, gdzie `AGENTS.md` każe trzymać progi produktowe. |

### 2.4. Dostępność ponad automat

| # | Znalezisko |
|---|---|
| **Z-19** | **axe-core, moje przejście, 12 ekranów × 360 px:** trzy naruszenia poziomu `serious`, wszystkie tej samej reguły — `link-in-text-block` na `/register`, `/login`, `/pomoc`. Przyczyna w `resources/css/tokens.css:252-256`: reguła `a { color: …; text-underline-offset: 3px }` **nigdy nie ustawia `text-decoration: underline`**, a reset Tailwinda ustawia `text-decoration: inherit`. Linki w akapicie („Zaloguj się”, „Załóż konto”, „Ustawienia → Rozmiar tekstu”, „Nie pamiętam hasła”) są odróżnialne **wyłącznie kolorem**. WCAG 1.4.1. Poprawka: jedna linia. |
| **Z-20** | **Reflow** — patrz R-3. Poziomy pasek przewijania na każdej stronie przy 360 px i 320 px CSS. axe tego nie testuje. |
| **Z-21** | **Cele dotykowe paginacji** — 30 px wysokości (zmierzone), przy wymaganych 48. Patrz R-6. |
| **Z-22** | **Kolejność Tab** — sprawdzona na stronie powitalnej i w rejestracji: poprawna, logiczna, `skip-link` pierwszy, formularz rejestracji przechodzi się samym Tabem (14 przystanków, żadnej pułapki, żadnego elementu niewidocznego w układzie). To działa dobrze. |
| **Z-23** | **200% na desktopie** (1280 px → 640 px CSS): `scrollWidth == clientWidth`, nic nie wystaje. Też działa dobrze. |
| **Z-24** | `pages/recipes/show.blade.php` — formularz komentarza stoi na końcu bardzo długiej strony i **nie ma podsumowania błędów na górze** (`x-error-summary`). Po nieudanej walidacji człowiek wraca na górę strony i nie widzi żadnego sygnału; komunikat jest kilkaset pikseli niżej. `AGENTS.md` §5: „błąd przy polu **ORAZ** w podsumowaniu na górze formularza”. Ta sama uwaga dotyczy `settings/accessibility.blade.php` i `settings/privacy.blade.php`. |

### 2.5. Skala — co zadziała przy 5, a zaboli przy 5 000

Zmierzone na 405 kontach / 995 wpisach. **Liczba zapytań na ekran nie rośnie** — nie ma
klasycznego N+1 (patrz §4). Problem jest gdzie indziej: w kształcie jednego zapytania.

| # | Znalezisko |
|---|---|
| **Z-25** | `DailyBoard::peopleToFollow()` (`DailyBoard.php:120-135`) sortuje **skorelowanym podzapytaniem** `order by (select max(published_at) from posts where posts.author_id = users.id)`. `EXPLAIN (ANALYZE)` na 405 kontach: **`Seq Scan on users`** + `SubPlan 2 … loops=403`, czyli jedno wyszukanie indeksowe **na każde aktywne konto**, przy każdym żądaniu. Do tego lista wykluczeń: `id NOT IN (?, ?, … )` z **201 parametrami** dla widza obserwującego 200 osób — rośnie liniowo z liczbą obserwowanych. Dziś 1,9 ms; przy 20 000 kont to pełny skan tabeli użytkowników i 20 000 podzapytań. Zapytanie chodzi na `/`, `/home`, `/odkryj` i `/witaj/ludzie`, czyli praktycznie na każdym wejściu. **Lekarstwo mieści się w regułach projektu:** kolumna `profiles.last_published_at` odświeżana przy publikacji + indeks, albo cache tablicy na kilka minut (`CACHE_STORE=database`, bez Redisa). |
| **Z-26** | `ProfileController::show()` woła `$viewer->isFollowing($owner)` — osobne zapytanie — do pięciu razy w jednym żądaniu (`postsFor`, zakładka przepisów, trzy liczniki, `whereHas` przy wykonaniach). Do tego pięć osobnych `count()` na statystyki. Zmierzone 16 zapytań na `/@basia`. Nie boli dziś, ale to darmowa poprawka: policzyć `isFollowing` raz i podać dalej. |
| **Z-27** | `layout.blade.php:32` liczy `unreadNotificationsCount()` na **każdym renderze każdej strony** — `count(*)` z podzapytaniem `NOT EXISTS` po `blocks`. Jedno zapytanie na odsłonę, bez cache. |
| **Z-28** | `SearchQuery::recipes()` i `people()` mają twardy `limit(20)` bez paginacji (`SearchQuery.php:39,68`). `pages/search.blade.php:33` podaje `count()` **jako liczbę znalezionych**: przy 200 pasujących przepisach napisze „Znaleziono 20 przepisów”, a reszty nie da się zobaczyć w żaden sposób. |
| **Z-29** | `FollowingFeed::paginate()` robi `pluck` wszystkich obserwowanych i `whereIn` (`FollowingFeed.php:30-35`), a `isEmptyFor()` wywołuje to zapytanie po raz drugi w tym samym żądaniu (`FeedController.php:48,55`). Świadoma decyzja z `AGENTS.md` §8 — zostawiam, ale drugi `pluck` jest zbędny. |

---

## 3. Co ODRZUCAM i dlaczego

To jest połowa wartości tego dokumentu. Poniższe wyglądają na dobre pomysły i **nie są**
dla tego produktu.

| Odrzucone | Dlaczego |
|---|---|
| **Redis / warstwa cache na feed** | Zmierzone: 15-24 zapytania i 20-60 ms na ekran przy 405 kontach. Nie ma zmierzonej potrzeby, a `AGENTS.md` wymaga jej przed dodaniem. Z-25 rozwiązuje się kolumną w bazie, nie nowym serwisem. |
| **Fanout-on-write / osobna tabela feedu** | Ten sam powód. Przy 200 osobach to koszt utrzymania bez zysku, a przy nieudanej alfie — koszt bez produktu. |
| **Algorytmiczny feed, „polecane dla Ciebie”, ranking popularności** | Zamknięte w `AGENTS.md` §8 i `SOUL.md:186-188`. Nie mam nowego argumentu, a stary (dzieli ludzi na widzianych i niewidzianych, wyłącza publikowanie u większości) jest mocny. |
| **Osobny silnik wyszukiwania (Typesense/Meili/Elastic)** | D-004. `pg_trgm` + `unaccent` działa i jest przetestowane. Z-28 to brak paginacji, nie brak silnika. |
| **API „pod przyszłą aplikację”, SPA, Inertia** | D-014, rozstrzygnięte i dobrze uzasadnione. |
| **Web Push zamiast e-maila (R-2)** | Kuszące, bo jest w `FEATURES.md` na V1. Ale push wymaga JavaScriptu, uprawnienia systemowego i zainstalowanej PWA — trzy ściany pod rząd dla osoby 65+, a `D-014` sam zauważa, że „Dodaj do ekranu głównego” jest dla niej trudniejsze niż instalacja z Play. E-mail dociera do wszystkich i jest kanałem, którego ta grupa używa. Push może być dodatkiem, nigdy zamiennikiem. |
| **Logowanie przez Facebooka / Google** | Wygląda jak skrót przez najtrudniejszy ekran w produkcie. Trzy argumenty przeciw: (1) lekcja Flickra opisana w `COMPETITIVE_LANDSCAPE.md` §3 — zmiana sposobu logowania rozwaliła społeczność, a my byśmy od razu uzależnili wejście od cudzej decyzji; (2) ekran zgody Google/Facebooka jest dla tej grupy kolejną, obcą ścianą, a nie ułatwieniem; (3) informacja „ta osoba ma konto w serwisie kulinarnym dla 50+” wyciekłaby do Mety, co kłóci się z obietnicą prywatności. Zamiast tego: naprawić R-1, żeby odzyskiwanie hasła w ogóle działało. |
| **Pełne wyniesienie tekstów UI do `lang/pl` (SOUL.md #11)** | Odrzucam w wersji „wszystko”. To duży refaktor ~200 komunikatów, który przez pół roku będzie źródłem rozjazdów, a teksty w Blade i tak są dziś spójniejsze niż byłyby w plikach. **Robimy tylko to, co naprawia realne błędy:** `lang/pl/pagination.php`, `lang/pl/passwords.php`, `lang/pl/validation.php` i własne klasy powiadomień (R-1, R-6). |
| **Streaki, punkty, odznaki za liczbę wpisów, publiczne rankingi** | `AGENTS.md` §12, zakaz bezwarunkowy. R-8 celowo proponuje licznik **prywatny** — to nie jest to samo. |
| **Gwiazdki / oceny przepisów zamiast „Ugotowałem”** | „Ugotowałem” jest mocniejszym i uczciwszym sygnałem, a oceny zapraszają do hejtu ocenami (`SOUL.md:63`). Dane, których potrzebujemy, już zbieramy: `would_make_again`, `perceived_difficulty`, `actual_minutes`. |
| **Masowy import przepisów, AI generujące treści pod SEO** | `AGENTS.md` §9. Skasowałoby jedyny wyróżnik i jest nieodwracalne. |
| **Nieskończone przewijanie zamiast „Pokaż więcej”** | `AGENTS.md` §5. Komponent `x-show-more` jest poprawny — trzeba go użyć w pozostałych siedmiu miejscach, nie zastępować. |
| **Usunięcie kreatora Livewire na rzecz jednego formularza** | Kuszące („mniej kodu, jedna droga”), ale kreator ma autosave szkicu, a formularz jednostronicowy nie. Przy przepisie pisanym 40 minut autosave jest wartością, nie ozdobą. Obie drogi zostają — tak jak zapisano w `RecipeController` §1-2. |
| **Ekran „Ustawienia” jako szósta pozycja dolnej nawigacji** | `AGENTS.md` §5: maksymalnie pięć. R-5 rozwiązuje to inaczej: wejście z belki albo z profilu. |
| **Rozbudowa moderacji, kolejnych ról, panelu statystyk** | Przy 20 kontach to praca bez odbiorcy. Moderacja jest zrobiona (Z-31) i wystarcza do bramki „closed alpha”. |

---

## 4. Co sprawdziłem i **nie znalazłem** problemu

Żeby nikt nie badał tego drugi raz.

| # | Obszar | Jak sprawdzone | Wynik |
|---|---|---|---|
| **Z-30** | **N+1 w feedach, profilu, przepisie, wyszukiwarce** | licznik `DB::getQueryLog()` na 12 ekranach, dwa razy: przy 5 kontach i przy 405 kontach / 995 wpisach | Liczba zapytań **identyczna** w obu przebiegach: `/` 16, `/odkryj` 15, `/home` 24, `/@basia` 16, przepis 20, wpis 9, `/zeszyt` 4, `/powiadomienia` 7. Eager loading (`with`, `withCount`) jest zrobiony konsekwentnie. Zero N+1. |
| **Z-31** | **Widoczność i blokady** | lektura + istniejący pakiet `tests/Feature/Visibility/*` (7 plików) | Filtr blokad jest w feedzie, discover, tablicy, wyszukiwarce, powiadomieniach, liczniku nieprzeczytanych, komentarzach i na profilu — czyli także tam, gdzie Policy nie sięga (listy). Świadomie opisane w komentarzach. Nie znalazłem dziury. |
| **Z-32** | **Pipeline zdjęć** | lektura `StoreUploadedImage`, `ProcessUploadedImage`, `config/kuking.php` | Kolejność sprawdzeń poprawna (bajty → `getimagesize` → MIME z nagłówka → limit megapikseli), własny klucz obiektu, oryginał prywatny pod `incoming/`, re-enkodowanie zdejmujące EXIF, widoki pokazują wyłącznie `ready`. Zgodne z `AGENTS.md` §7. |
| **Z-33** | **`status` / `role` poza `$fillable`** | `app/Models/User.php:48-56` | Poprawne. Zmiany stanu przez `suspend()`, `ban()`, `markForDeletion()`, `promoteTo()`, z unieważnieniem sesji. |
| **Z-34** | **Limity zapytań** | `config/kuking.php` + `routes/web.php` | Wszystkie progi w jednym pliku, nakładane na trasy. Zgodne z `AGENTS.md`. |
| **Z-35** | **Kolejność sekcji na stronie przepisu** | `pages/recipes/show.blade.php` | „Skąd ten przepis” (131) stoi **przed** „Składniki” (152) — zgodnie z `SOUL.md:84`. |
| **Z-36** | **Odmiana porcji** | `Recipe::servingsLabel()` + `OdmianaPorcjiTest` | Poprawna, łącznie z ułamkami i przecinkiem dziesiętnym. Wzór do skopiowania dla Z-13 i Z-14. |
| **Z-37** | **Kolejność Tab i skip-link** | Playwright, klawiatura | Poprawna, patrz Z-22. |
| **Z-38** | **200% powiększenia na desktopie** | Playwright, 640 px CSS | Bez przepełnienia, patrz Z-23. |
| **Z-39** | **Sitemapa i dane strukturalne** | `SitemapController`, `pages/recipes/show.blade.php` | Do mapy trafiają tylko publiczne przepisy, wpisy z treścią i niepuste profile, z cache 6 h i `chunkById`. Recipe schema jest kompletna. Jedyny błąd to okruszek z Z-5. |
| **Z-40** | **Harmonogram bez `proc_open`** | `routes/console.php` + `HarmonogramBezProcOpenTest` | `Schedule::call()` zamiast `command()` — poprawnie i dobrze uzasadnione. |
| **Z-41** | **Kolejka i przetwarzanie zdjęć na produkcji** | `docker/entrypoint.sh` | Rola `worker` z `queue:work --max-time=3600`, rola `scheduler` osobno. Nie ma dziury „zdjęcia nigdy nie stają się `ready`”. |
| **Z-42** | **Zestaw testów** | `php artisan test` z `APP_BASE_PATH` | 369 testów, 1457 asercji, wszystkie zielone. |

---

## 5. Konkurencja — czego nie było w `COMPETITIVE_LANDSCAPE.md`

Istniejący dokument jest bardzo dobry (sekcja zwłok Garnka, Durszlak, Nasza-Klasa, Fotka,
Flickr, Cookpad, Ravelry, Strava, Untappd, Goodreads, Nextdoor, Pinterest). Nie powtarzam go.
Trzy uzupełnienia:

1. **Cookpad PL nadal żyje** (sprawdzone wrzesień 2026): `cookpad.com/pl` odpowiada,
   aplikacja jest aktualizowana i ma polskojęzyczny profil. Nie znalazłem żadnej zapowiedzi
   wygaszenia wersji polskiej. Czyli ostrzeżenie z `COMPETITIVE_LANDSCAPE.md` §4 zostaje
   w mocy — konkurent produktowy 1:1 z gotowym Cooksnapem jest obecny.
   Skala polska: **[niepotwierdzone]**, nie ma cytowalnych liczb.
   ([cookpad.com/pl](https://cookpad.com/pl), [App Store PL](https://apps.apple.com/pl/app/cookpad-przepisy/id585332633?l=pl))
2. **Wydruk przepisu jest u konkurencji standardem, u nas go nie ma.** Kwestia Smaku ma
   ikonę drukarki nad każdym przepisem i osobny wpis na blogu tłumaczący, jak z niej
   korzystać — czyli to jest funkcja, o którą ich czytelnicy **pytali**.
   ([Kwestia Smaku](https://www.kwestiasmaku.com/blog-kulinarny/drukowanie-przepisow-i-zamieszczanie-waszych-zdjec)).
   Fora kulinarne mają osobne wątki „drukowanie przepisów”
   ([Gotujmy.pl](https://gotujmy.pl/forum/drukowanie-przepisow,pomoc-kuchenna-posty,101464.html),
   [Przepisownia](https://www.przepisownia.pl/forum/drukowanie-przepisow/1161)).
   To jest R-7 i jest to najtańsza przewaga na liście.
3. **Czego nie warto kopiować, a co kusi:** żaden z polskich liderów nie ma sensownego
   sposobu przeglądania treści innego niż SEO i kategorie. Kuking nie powinien budować
   drzewa kategorii („Zupy → Krem → Dyniowa”) tylko dlatego, że wszyscy tak robią —
   `SOUL.md:120` proponuje zamiast tego **zamkniętą listę ~30 tematów**. To jest lepsze
   rozwiązanie tego samego problemu i mieści się w R-9.

---

## 6. Trzy rzeczy najbliżej

W kolejności: **R-1** (polskie maile systemowe), **R-3** (belka wychodzi poza ekran),
**R-4** (polskie strony błędów + 419 bez utraty danych). Wszystkie trzy są w kategorii S,
wszystkie trzy dotykają każdego użytkownika i żadna nie wymaga decyzji produktowej.

Gotowe opisy do wklejenia jako issues — w podsumowaniu przekazanym zlecającemu.
