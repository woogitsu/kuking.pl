# USPRAWNIENIA-2.md — druga tura audytu kodu

> Dokument badawczy. Nie zmienia blueprintu i nie jest planem prac — jest listą
> ustaleń z dowodami, uszeregowaną tak, żeby dało się podjąć decyzję bez
> otwierania kodu.
>
> **Relacja do `docs/research/USPRAWNIENIA.md`:** w chwili pisania tego pliku
> tamten dokument nie istniał w repozytorium (gałąź `worktree-agent-a916279bfa7095de9`,
> HEAD `755824b`). Nie mogłem więc odwołać się do jego treści punkt po punkcie.
> Wiem natomiast z zadania, że wcześniejszy audyt znalazł co najmniej dwa
> przykłady wzorca „kod odpowiada na inne pytanie niż zadane": `where(kolumna, null)`
> zamieniane przez buildera na `IS NULL` oraz `hide()` z gałęziami dla trzech
> modeli. Ten drugi **nadal jest w kodzie** — opisuję go w A6 i oznaczam jako
> prawdopodobnie już zgłoszony. Reszta ustaleń jest nowa.
>
> **Metoda:** lektura kodu w worktree, bez uruchamiania aplikacji i bez bazy
> (worktree nie ma `vendor` ani `kuking_test`). Wszystko, czego nie dało się
> potwierdzić w kodzie, jest oznaczone jako **opinia**. Jeden jednorazowy skrypt
> pomiarowy — opisany w załączniku na końcu.
>
> **Stan issues sprawdzony** przez `list_issues` i `search_issues` (owner
> `woogitsu`, repo `kuking.pl`) 6 września 2026: 39 otwartych, 10 zamkniętych.
> Przy każdym ustaleniu piszę, czy ma issue.

---

## Spis ustaleń

| # | Ustalenie | Issue | Koszt |
|---|---|---|---|
| **A. Zepsute teraz** | | | |
| A1 | `post_max_size` 28 MB kontra 6 × 15 MB — publikacja kilku zdjęć kończy się angielskim „Page Expired" i utratą tekstu | brak (styka się z #81) | S |
| A2 | Zaraz po publikacji zdjęcia nie widać, nigdzie nie ma o tym słowa, a testy nie mogą tego zobaczyć | brak | S–M |
| A3 | `og:image` świeżego wpisu to plik SVG — karta w Messengerze jest pusta | brak (dotyczy zamkniętego #14) | S |
| A4 | Galeria „Komu wyszło" nie respektuje blokad | brak (luka w macierzy z zamkniętego #41) | S |
| A5 | Zbanowane konto: profil daje 403, ale wpisy i przepisy zostają publiczne | brak | M |
| A6 | „Ukryj treść" na „Ugotowałem" nie robi nic, a „Usuń treść" kasuje je twardo | prawdopodobnie w USPRAWNIENIA.md | S |
| A7 | Nie ma katalogu `lang/` — 126 par (pole, reguła) mówi po angielsku, paginacja też | rozszerza #79 i #81 | M |
| A8 | Konto „do usunięcia": komunikat każe się zalogować, logowanie jest zamknięte, a po 30 dniach nic się nie dzieje | brak | M |
| **B. Ryzyko przy pierwszych stu użytkownikach** | | | |
| B1 | Po pierwszym wpisie „Świeżo z Kuking" znika ze strony głównej na zawsze | brak (sąsiaduje z #31, #37) | S |
| B2 | Powiadomienia dla konta zawieszonego przepadają bezpowrotnie | brak | S |
| B3 | Listy obserwujących: 40 dodatkowych zapytań na stronę i zły licznik | brak (dotyczy zamkniętego #16) | S |
| B4 | `peopleToFollow` sortuje skorelowanym podzapytaniem po wszystkich kontach, na każdym wejściu na stronę główną | brak | S |
| B5 | „Znaleziono 20 przepisów", gdy jest ich dwieście — i zła odmiana liczebnika | brak | S |
| B6 | `/@Basia` działa, `/@Basia/obserwujacy` daje 404 | brak (ta sama klasa co audyt A25) | S |
| **C. Warto zrobić** | | | |
| C1 | Nieudana walidacja kasuje wybrane zdjęcia | brak | M |
| C2 | Potwierdzenie kasowania istnieje tylko z JavaScriptem | brak (zderzy się z #12) | S |
| C3 | Przepis bez wykonań nie ma pustego stanu | brak (SOUL 4.11, MVP) | S |
| C4 | „Zrobię ponownie" zbieramy i wyrzucamy | brak (SOUL 4.2, MVP) | S |
| C5 | Nikt nie obserwuje gospodarza po rejestracji | brak (COLD_START 6.4) | S |
| C6 | Po publikacji nie proponujemy drugiego zdjęcia | brak (COLD_START 7.2) | S |
| C7 | `robots.txt` blokuje adresy, których nie ma | brak | S |
| C8 | Limit `upload` w configu nie jest nigdzie podpięty | brak | S |
| C9 | Odmowa dla konta zawieszonego gubi wpisany tekst | brak | S |

Sekcje **D** (świadomie odkładamy), **E** (sprawdzone, nie potwierdziło się)
i **F** (białe plamy) są niżej.

---

## A. Zepsute teraz

### A1. Publikacja kilku zdjęć kończy się angielskim „Page Expired" i utratą tekstu

**Dowód.**
`docker/php.ini:57-59`:

```ini
upload_max_filesize=24M
post_max_size=28M
max_file_uploads=12
```

`config/kuking.php:34` — `max_bytes` = 15 MB. `config/kuking.php:57` —
`max_per_post` = 6. Aplikacja przyjmuje więc żądanie o objętości do **90 MB**,
a PHP odcina je na **28 MB**.

Formularz mówi to wprost użytkownikowi:
`resources/views/pages/posts/create.blade.php:11` — „Zdjęcie *(możesz wybrać
kilka)*", `:14` — „Największy plik: 15 MB", `:16-18` — `<input type="file"
name="photos[]" multiple>`.

Komentarz w `docker/php.ini:55` brzmi: *„Limit po stronie aplikacji (walidacja
Laravel) MUSI być mniejszy niż tutaj"*. I jest — **dla jednego pliku** (15 < 24).
Nikt nie porównał `post_max_size` z `max_per_post × max_bytes`. To jest dokładnie
ten wzorzec: warunek został sprawdzony, tylko odpowiada na inne pytanie niż to,
które zadaje formularz.

**Scenariusz porażki.** Basia (61 lat, iPhone) robi trzy zdjęcia niedzielnego
obiadu. Zdjęcie z nowoczesnego telefonu to realnie 4–12 MB, więc trzy sztuki
przekraczają 28 MB. Klika „Opublikuj", czeka na wysyłkę przez LTE minutę i pół.
PHP odrzuca całe ciało żądania — `$_POST` i `$_FILES` stają się puste tablicami,
**łącznie z polem `_token`**. Laravel widzi żądanie bez tokenu CSRF i zwraca
**419 z angielską stroną „Page Expired"** (nie ma `resources/views/errors/`,
patrz issue #81). Napisany tekst przepada. W logu aplikacji nie ma nic —
to nie jest wyjątek, tylko warning PHP przy parsowaniu ciała żądania. W Sentry
nie ma nic. Nie dowiemy się o tym z telemetrii, tylko z telefonu od Basi —
albo nie dowiemy się wcale, bo Basia po prostu przestanie wrzucać zdjęcia.

**Dlaczego testy tego nie łapią.** `php artisan test` chodzi przez jądro HTTP
Laravela, a nie przez PHP-FPM z `docker/php.ini`. Limitów z tego pliku nie widać
z poziomu testu feature w ogóle.

**Koszt.** S. Trzy możliwe ruchy, najlepiej wszystkie trzy:
1. `post_max_size` ≥ `max_per_post × max_bytes` + zapas (np. 100 MB) i `max_execution_time`/`max_input_time` do tego dopasowane;
2. albo obniżyć `max_per_post` do 2 i zostawić `post_max_size` — mniej zdjęć, ale prawdziwa spójność;
3. i tak czy owak dopisać obsługę pustego żądania: gdy `$_SERVER['CONTENT_LENGTH']` przekracza limit, a `$_POST` jest puste, oddać polski komunikat „Te zdjęcia razem ważą za dużo. Dodaj je w dwóch wpisach" **zamiast** 419.

**Czego się wyrzekamy.** Podniesienie `post_max_size` znaczy, że jedno żądanie
może zająć 100 MB pamięci bufora i 120 s czasu procesu web na Railway. Przy
jednym kontenerze to realne ryzyko, że jedna osoba wysyłająca zdjęcia zajmuje
worker FPM na dwie minuty. Wariant 2 (mniej zdjęć) jest tańszy operacyjnie
i uczciwszy wobec zasady „prostota > liczba funkcji", ale odbiera funkcję,
która już jest w interfejsie.

**Opinia (nie sprawdzone w kodzie):** docelowa ścieżka opisana w komentarzu
`docker/php.ini:48-49` — pliki prosto do R2 przez presigned URL — usuwa ten
problem u źródła, ale wymaga JavaScriptu, więc **nie może być jedyną drogą**
(AGENTS.md §5).

---

### A2. Zaraz po publikacji zdjęcia nie widać i nikt o tym nie mówi

**Dowód.**
- `app/Domain/Media/Actions/StoreUploadedImage.php:118` — nowe `Media` dostaje status `pending`;
- `:132` — `ProcessUploadedImage::dispatch($media->getKey())` (kolejka, nie `dispatchSync`);
- `config/queue.php:18` i `.env.example:51` — sterownik `database`;
- `app/Http/Controllers/PostController.php:78` — natychmiastowy `redirect()->route('posts.show', $post)`;
- `resources/views/components/photo.blade.php:9` — `@if($media && $media->isReady())`, czyli dla `pending` komponent nie renderuje **nic**;
- `resources/views/components/post-card.blade.php:23-29` — `@if($post->media->isNotEmpty())` otwiera `<div class="photo-grid">`, więc na stronie zostaje pusty kontener.

Ta sama dziura jest na stronie przepisu: `resources/views/pages/recipes/show.blade.php:110`
(`@if($recipe->heroMedia)` → pusty blok) i w karcie wykonania
`resources/views/components/cooked-card.blade.php:36-42`.

**Kluczowe: testy nie mogą tego zobaczyć.** `phpunit.xml:43` ustawia
`QUEUE_CONNECTION=sync`. W teście `ProcessUploadedImage` wykonuje się **wewnątrz
żądania**, więc zanim wyrenderuje się odpowiedź, `Media` ma już status `ready`.
Okno, w którym żyje ten błąd, w środowisku testowym nie istnieje w ogóle.
Zielone testy nie mówią tu nic o produkcji.

**Scenariusz porażki.** Basia publikuje pierwsze zdjęcie i nie pisze nic (pole
tekstowe jest opcjonalne — `PublishPost.php:38`). Ląduje na stronie wpisu
z komunikatem *„Gotowe. To Twój pierwszy wpis w Kuking — od teraz masz swoje
archiwum"* (`PostController.php:81`) i widzi kartę z własnym imieniem, dzisiejszą
datą i **niczym więcej**. Odświeża — nadal nic. Wnioskuje, że zdjęcie się nie
zapisało, i publikuje jeszcze raz. Teraz ma dwa wpisy, jeden pusty. To jest
pierwsza minuta pierwszego dnia w serwisie i jedyny moment, w którym decyduje
się, czy wróci.

Okno jest krótkie, gdy worker chodzi. Nie jest krótkie po deployu (worker się
restartuje), przy kolejce zapchanej eksportami RODO albo przy nieudanym zadaniu:
`ProcessUploadedImage.php:112-117` ustawia wtedy status `rejected` **na zawsze**.

**Druga część tego samego ustalenia: obiecany komunikat nie istnieje.**
`app/Jobs/ProcessUploadedImage.php:30-32` mówi:

> *„Jeśli cokolwiek pójdzie nie tak, zdjęcie dostaje status `rejected`, a powód
> ląduje w metadanych — użytkownik widzi wtedy komunikat po polsku, a nie pustą
> ramkę."*

`grep -rn "rejected" resources/views/` nie zwraca **ani jednego** trafienia.
Żaden widok nie renderuje niczego dla `rejected`. Człowiek dostaje dokładnie tę
pustą ramkę, przed którą komentarz zapewnia, że go chronimy. To ta sama klasa
rozjazdu co audyt A16 („dokumentacja twierdziła, że autora poinformowano,
a autor nie dostawał niczego").

**Koszt.** S dla komunikatu, M jeśli chcemy zrobić to porządnie.
Minimum: w `x-photo` (albo w `post-card`/`cooked-card`/`recipes.show`) dodać
gałąź dla `pending`/`processing` („To zdjęcie jeszcze się przygotowuje. Odśwież
za chwilę — nic nie zginęło") i dla `rejected` („Tego zdjęcia nie udało się
przygotować. Spróbuj dodać je jeszcze raz"). Do tego test, który **nie** działa
na `sync`: `Queue::fake()` albo jawne `QUEUE_CONNECTION=database` w tym jednym
teście, żeby okno w ogóle istniało.

**Czego się wyrzekamy.** Nic istotnego. Jedyny koszt to przyznanie w interfejsie,
że coś dzieje się w tle — czego produkt do tej pory unikał.

---

### A3. Karta do wysłania rodzinie pokazuje pustą ramkę dokładnie tam, gdzie nie powinna

**Dowód.** `resources/views/components/layout.blade.php:49-58`:

```php
$ogImage = $image?->url('large');

if ($ogImage !== null && ! str_starts_with($ogImage, 'http')) {
    $ogImage = url($ogImage);
}

// Zapasowa karta dla stron bez zdjęcia. SVG tu NIE ZADZIAŁA: Facebook,
// WhatsApp i Signal go nie renderują i pokazują pustą ramkę. Stąd PNG
// w formacie 1200×630, czyli tym, którego wszyscy oczekują.
$ogImage ??= asset('icons/kuking-udostepnianie.png');
```

`app/Models/Media.php:103-110`: gdy `Media` nie ma jeszcze wariantów (status
`pending`, `processing` albo `rejected`), `url()` zwraca
`asset('icons/kuking-mark.svg')`.

Czyli: `$ogImage` **nie jest** `null`, więc `??=` w linii 58 nigdy się nie
uruchamia, a w `<meta property="og:image">` ląduje **plik SVG** — dokładnie ten,
o którym komentarz dwie linijki wyżej pisze, że go nie wolno tam wstawiać.
Dodatkowo `layout.blade.php:99` ustawia wtedy `twitter:card =
summary_large_image`, czyli prosi o duży format dla obrazka, którego nie da się
narysować.

Warunek `$image?->isReady()` nie występuje tu w ogóle. Występuje natomiast
w structured data przepisu (`resources/views/pages/recipes/show.blade.php:39` —
`$recipe->heroMedia?->isReady() ? [...] : null`), więc jeden z dwóch bliźniaczych
kawałków tego samego ekranu pyta o gotowość, a drugi nie.

**Scenariusz porażki.** Basia publikuje przepis babci, kopiuje adres z paska
i wysyła córce na Messengerze — to jest najbardziej naturalna rzecz, jaką można
zrobić z rodzinnym przepisem, i jednocześnie nasz najtańszy kanał wzrostu
(COLD_START, „Zaproś kogoś z rodziny"). Facebook pobiera stronę w ciągu sekund
od publikacji, gdy `heroMedia` jeszcze nie jest gotowe, dostaje SVG, nie
renderuje go — i **cache'uje pustą kartę**. Córka widzi goły link. Ponowne
udostępnienie tego samego adresu za godzinę daje ten sam wynik, bo scraper trzyma
odpowiedź w cache. Issue #14 („karty Open Graph") jest zamknięte, więc problem
jest podwójnie niewidoczny: temat uchodzi za zrobiony.

Efekt uboczny: `Media::url()` przy każdym takim renderze pisze
`Log::warning('Zdjęcie bez wygenerowanych wariantów')` — czyli log rośnie, ale
nikt na niego nie patrzy, bo to „tylko warning".

**Koszt.** S. Jedna linia: `$ogImage = $image?->isReady() ? $image->url('large') : null;`.
Do tego test rozszerzający istniejący `tests/Feature/KartaDoUdostepnianiaTest.php`
o przypadek zdjęcia w stanie `pending`.

**Czego się wyrzekamy.** Świeżo opublikowany wpis dostaje kartę z logo zamiast
ze zdjęciem. To jest lepsze niż pusta ramka i i tak poprawi się przy następnym
odświeżeniu cache przez scrapera.

---

### A4. Galeria „Komu wyszło" nie respektuje blokad

**Dowód.** `app/Http/Controllers/RecipeController.php:215-219`:

```php
'cookedEvents' => $model->cookedEvents()
    ->with(['user.profile.avatar', 'media'])
    ->limit(12)
    ->get(),
'cookedCount' => $model->cookedEvents()->count(),
```

Żadnego filtra blokady. Dla porównania — w tym samym pliku, dziesięć linii wyżej
(`:207-210`), komentarze **są** filtrowane przez `widoczneDla($request->user())`
z powołaniem na issue #41.

Widok renderuje te wykonania przez `resources/views/pages/recipes/show.blade.php:230-238`
→ `x-cooked-card`, który pokazuje avatar, imię, link do profilu
(`components/cooked-card.blade.php:10-12`), zdjęcie (`:36-42`), notatkę (`:44-46`)
i „zrobiłam po swojemu" (`:48-50`).

Issue #41 („Testy widoczności: macierz każdy stan × każdy typ obserwatora") jest
zamknięte i pokrywa wpisy, przepisy, wykonania na profilu, komentarze, zeszyt
i wyszukiwarkę (`tests/Feature/Visibility/`). **Galerii wykonań na stronie
przepisu w tej macierzy nie ma** — `grep` po `cookedEvents` w `tests/Feature/Visibility/`
nie zwraca nic.

**Scenariusz porażki.** Halina zablokowała Krzysztofa, bo pisał jej pod zdjęciami
nieprzyjemne rzeczy o tym, jak powinna gotować. Krzysztof gotuje z publicznego
przepisu Marka, dodaje zdjęcie i uwagę „u mnie wyszło lepiej, bo nie oszczędzam
na maśle". Halina wchodzi na przepis Marka — i widzi twarz Krzysztofa, jego imię
i jego uwagę. Blokada jest dla naszej grupy jedną z dwóch rzeczy, które decydują
o poczuciu bezpieczeństwa (SOUL 4.12: „Kluczowe dla kobiet 50+, które
doświadczyły hejtu na FB"). Blokada, która działa wszędzie poza jednym miejscem,
jest — cytując komentarz z `app/Policies/CollectionPolicy.php:19-24` — „obietnicą
bez pokrycia".

Licznik też kłamie: `cookedCount` wlicza wykonania osób zablokowanych, więc pod
tytułem stoi „Ugotowane 12 ×", a w galerii widać 11.

**Koszt.** S. Wykonanie nie ma własnej widoczności, więc filtr jest taki sam jak
w `Comment::scopeWidoczneDla` — `whereNotExists` po `blocks` na `cooked_events.user_id`.
Najlepiej jako **scope na modelu `CookedEvent`**, nie prywatna metoda kontrolera:
to samo pytanie zadaje profil (`ProfileController::tylkoZWidocznychPrzepisow`
filtruje po przepisie, nie po autorze wykonania) i zapewne przyszły ekran
„Komuś wyszło" (#17).

**Czego się wyrzekamy.** Nic. To jest domknięcie reguły, która już obowiązuje.

---

### A5. Zbanowane konto znika z profilu, ale jego treści zostają na widoku

**Dowód.**
- `app/Policies/UserPolicy.php:11-18` — profil konta w stanie innym niż `active`/`suspended` widzi wyłącznie moderator. `/@nazwa` zwraca 403.
- `app/Policies/PostPolicy.php:19-37` — `view()` pyta o publikację, blokadę i widoczność. **Nie pyta o status konta autora.**
- `app/Policies/RecipePolicy.php:13-30` — tak samo.
- `app/Domain/Feed/DiscoverFeed.php:33-43` — `publiclyVisible()` + wykluczenie blokad. Bez filtra po statusie autora.
- `app/Domain/Search/SearchQuery.php:49-69` — przepisy: bez filtra po statusie autora. Dla porównania `:88` — przy szukaniu **osób** filtr `status = active` jest.
- `app/Domain/Feed/DailyBoard.php:78` i `:121` — tablica redakcyjna **ma** `where('status', User::STATUS_ACTIVE)`.

Czyli trzy z pięciu ścieżek pytają o status konta, dwie najważniejsze nie.

Do tego `app/Models/ModerationAction.php:65` — dla celu `user` dozwolone są
tylko `no_action`, `warn`, `suspend`, `ban`. Moderator, który zbanował konto,
**nie ma w panelu żadnego przycisku**, którym zdjąłby jego treści hurtem.
Musiałby czekać, aż ktoś zgłosi każdy wpis z osobna.

**Scenariusz porażki.** Ktoś zakłada konto i wrzuca serię zdjęć, które nie mają
nic wspólnego z gotowaniem i których nie chcemy pokazywać nikomu. Zgłoszenie
przychodzi, moderator klika „Zablokuj konto autora na stałe". Konto wylatuje
z sesji, profil daje 403, w wyszukiwarce osób go nie ma. A zdjęcia dalej wiszą
na `/odkryj`, w feedzie osób, które je obserwowały, i pod bezpośrednimi
adresami — z podpisem imieniem i **z linkiem do profilu, który oddaje 403**.
Moderator jest przekonany, że sprawa jest załatwiona. Zgłaszający widzi, że nie
jest, i drugi raz już nie zgłosi.

To jest ta sama klasa błędu, którą opisuje komentarz w `ModerationAction.php:49-51`
(„`suspend` na zgłoszonym WPISIE tylko ukrywało wpis. Moderator był przekonany,
że zawiesił kogoś, kogo nie zawiesił") — tylko z drugiej strony.

**Koszt.** M. Dwie rzeczy, obie potrzebne:
1. Filtr statusu autora w `PostPolicy::view`, `RecipePolicy::view`,
   `CookedEventPolicy::view`, `DiscoverFeed`, `FollowingFeed`, `SearchQuery::recipes`
   i `Recipe::scopeWidoczneDla`. Siedem miejsc to za dużo na kopiuj-wklej — to
   jest argument za jednym wspólnym scope'em (`scopeAutorDostepny`) i za
   dopisaniem wiersza „autor zbanowany" do macierzy z `WidocznoscTestCase`.
2. Decyzja produktowa: czy ban ma **ukrywać** treści (odwracalnie, przy zdjęciu
   bana wracają), czy tylko odcinać konto. Rekomendacja: ukrywać, bo inaczej
   ban nie robi tego, po co istnieje.

**Czego się wyrzekamy.** Ukrycie treści zbanowanego autora usuwa z serwisu także
cudze wykonania jego przepisów i cudze komentarze pod jego wpisami. Przy 100
użytkownikach to jest do zaakceptowania. Przy 10 000 — trzeba będzie odróżnić
„ban za spam" (ukryj wszystko) od „ban za nękanie" (zostaw przepisy, zabierz
konto), a to już jest osobna decyzja produktowa i nie należy jej podejmować
teraz.

---

### A6. „Ukryj treść" na wykonaniu nie robi nic, a „Usuń treść" kasuje je bezpowrotnie

**Uwaga:** to najprawdopodobniej ustalenie już zgłoszone w `USPRAWNIENIA.md`
(zadanie wymienia „`hide()` z gałęziami dla trzech modeli" jako znany przykład).
Wpisuję je, bo **w kodzie nadal jest**, i dokładam część, której tamten opis
mógł nie obejmować: twarde kasowanie.

**Dowód.** `app/Models/ModerationAction.php:69` dopuszcza dla celu `cooked_event`
komplet decyzji, w tym `hide` i `remove`.

`app/Http/Controllers/Admin/ModerationController.php:248-259`:

```php
private function hide(object $target): void
{
    if ($target instanceof Post || $target instanceof Recipe) { ... return; }
    if ($target instanceof Comment) { ... }
}
```

`CookedEvent` przelatuje przez obie gałęzie i metoda kończy się bez efektu.
Zgłoszenie i tak dostaje status `resolved` (`:128-135`), do `moderation_actions`
trafia wpis „hide", a autor dostaje powiadomienie „Moderacja Kuking ukryła Twoją
treść" (`NotifyModerationDecision.php:61`). Trzy niezależne zapisy twierdzą, że
treść jest ukryta. Treść jest widoczna.

Nie da się tego naprawić samym `if`-em, bo **tabela `cooked_events` nie ma
kolumny `status`** — `database/migrations/2026_09_05_000600_create_cooked_events_tables.php:24-57`.

Druga połowa: `ModerationController.php:215` — `ACTION_REMOVE => $target->delete()`.
`app/Models/CookedEvent.php:22-27` **nie używa `SoftDeletes`** (używają go `Post`,
`Recipe` i `Comment`). Więc „Usuń treść" na wykonaniu to twarde `DELETE`, razem
z kaskadą na `cooked_event_media` i `comments`
(`migrations/...000600:43`, `...000700:28`). Nie da się tego cofnąć i nie da się
tego pokazać przy odwołaniu (DSA art. 17). To dokładnie ten scenariusz, przed
którym ostrzega komentarz w `ModerationAction.php:43-47`, tylko dla innego typu celu.

**Scenariusz porażki.** Ktoś zgłasza wykonanie z nieprzyjemną uwagą pod cudzym
przepisem. Moderator wybiera „Ukryj treść" — bo to łagodniejsza decyzja — i widzi
„Decyzja zapisana". Uwaga wisi dalej. Autorka przepisu, która to zgłosiła, pisze
drugi raz. Moderator, przekonany, że pierwsza próba się nie przyjęła, wybiera
teraz „Usuń treść" — i kasuje na stałe czyjeś zdjęcie i notatkę, bez możliwości
przywrócenia, przy czym „Ugotowałem" to najcenniejsze zdarzenie w produkcie
(AGENTS.md §1).

**Koszt.** S–M. Migracja dodająca `status` do `cooked_events` (z CHECK-iem,
jak reszta schematu) + `SoftDeletes` na modelu + gałąź w `hide()` + filtr
w zapytaniach czytających wykonania. Zgodnie z AGENTS.md §6 to jest migracja +
test + `docs/DATABASE.md` + rollback. Alternatywa na dziś, gdyby trzeba było
domknąć to w godzinę: usunąć `hide` i `remove` z `DOZWOLONE['cooked_event']`,
żeby moderator dostawał błąd walidacji zamiast fałszywego potwierdzenia. To jest
gorsze produktowo, ale **uczciwe**.

**Czego się wyrzekamy.** Kolumna `status` na `cooked_events` to czwarty model
z własnym cyklem stanów. Warto to zrobić raz i porządnie, zamiast dokładać
piąty wariant „ukrywania".

---

### A7. Nie ma katalogu `lang/` — 126 par (pole, reguła) mówi po angielsku

**Dowód.** `ls lang` → katalog nie istnieje. `config/app.php:83-85` czyta
`APP_LOCALE`/`APP_FALLBACK_LOCALE`, a `.env.example:12-13` i
`.railway/railway.ts:173` ustawiają je na `pl`. Bez plików tłumaczeń Laravel
sięga do własnych, wbudowanych:
`vendor/laravel/framework/src/Illuminate/Translation/lang/en/{validation,pagination,auth,passwords}.php`.

Skutek 1 — **walidacja**. Kontrolery podają ręcznie napisane polskie komunikaty
tylko dla części reguł. Skrypt pomiarowy (załącznik) naliczył **126 par
(pole, reguła) bez własnego komunikatu**. Najbardziej bolesne, bo osiągalne
przez zwykłego użytkownika:

| Miejsce | Co człowiek zobaczy |
|---|---|
| `RecipeController.php:270` — `family_since_year` min/max | *„The family since year field must be at least 1850."* |
| `RecipeController.php:270` — `summary.max`, `title.max` | *„The summary field must not be greater than 2000 characters."* |
| `RecipeController.php:270` — `servings` numeric/min/max | *„The servings field must be at least 0.5."* |
| `RecipeController.php:270` — `steps.*.instruction.max`, `ingredients.*.text.max` | *„The steps.0.instruction field must not be…"* |
| `CollectionController.php:61` — `name` min/max, `visibility` required/in | *„The name field must be at least…"* |
| `ProfileSettingsController.php:55` — `display_name` min/max, `avatar.max` | *„The avatar field must not be greater than…"* |
| `PasswordResetController.php:66` — `token`, `email`, `password` required | *„The password field is required."* |
| `CookedEventController.php:47` — `actual_minutes` integer/min/max, `perceived_difficulty.in` | *„The actual minutes field must be an integer."* |

Skutek 2 — **paginacja**. Siedem widoków renderuje domyślny paginator Laravela
(`pagination::tailwind`, `AbstractPaginator.php:130`):
`pages/notifications.blade.php:98`, `pages/profile/show.blade.php:128,141,156`,
`pages/profile/connections.blade.php:70`, `pages/collections/show.blade.php:18`,
`pages/admin/reports.blade.php:99`. Ten szablon:
- pisze *„Showing 1 to 30 of 87 results"* po angielsku,
- daje `aria-label="Pagination Navigation"` po angielsku,
- na desktopie renderuje strzałki jako **same SVG bez tekstu** (AGENTS.md §5:
  „ikona nigdy nie jest jedynym opisem ważnej akcji"),
- i używa klas Tailwinda z pliku w `vendor/`, którego Tailwind 4 **nie skanuje**
  (automatyczne wykrywanie treści pomija ścieżki z `.gitignore`) — więc te klasy
  nie zostaną wygenerowane i paginacja renderuje się w ogóle bez stylów.

Feed i Discover robią to poprawnie (`x-show-more`, przycisk „Pokaż więcej wpisów"
zgodnie z AGENTS.md §5). Siedem pozostałych list — nie.

**Scenariusz porażki.** Basia wpisuje przepis babci i w polu „W rodzinie od"
podaje 1830, bo tak jej się wydaje. Klika „Opublikuj". Nad formularzem pojawia
się nagłówek po polsku („Jednej rzeczy jeszcze brakuje" —
`resources/views/components/error-summary.blade.php:11`)
i pod nim jedno zdanie po angielsku. Basia nie wie, którego pola dotyczy ani co
ma zrobić. SOUL 4.9: *„Jedno angielskie słowo w interfejsie mówi 60-latkowi:
to nie dla mnie"*.

**Relacja do issues.** Issue **#79** wskazuje brak `lang/` jako przyczynę, ale
jego kryteria akceptacji obejmują **wyłącznie dwa listy uwierzytelniające**.
Issue **#81** dotyczy stron błędów (`resources/views/errors/`). Issue **#38**
dotyczy przepisania **istniejących** tekstów według COPY_STYLE. Walidacja
i paginacja nie należą do żadnego z nich. To jest osobne zgłoszenie — albo
rozszerzenie #79 o `lang/pl/{validation,pagination,auth,passwords}.php`
i `resources/views/vendor/pagination/kuking.blade.php`.

**Koszt.** M. `php artisan lang:publish`, tłumaczenie czterech plików, blok
`attributes` z polskimi nazwami pól, własny widok paginacji oparty na
`x-show-more` (jeden przycisk „Pokaż więcej", nie numery stron —
AGENTS.md §5 mówi to wprost), `Paginator::defaultView()` w `AppServiceProvider`.
Test: żądanie z błędną wartością → odpowiedź nie zawiera słowa „field".

**Czego się wyrzekamy.** Pliku `validation.php` po polsku trzeba potem pilnować
razem z COPY_STYLE (#38) — dochodzi jedno miejsce do przeglądu językowego.
To jest zresztą dokładnie to, co SOUL 4.9 nazywa „jeden redaktor językowy na
wszystkie teksty".

---

### A8. Usunięcie konta: komunikat każe zrobić rzecz, której kod zabrania, a po 30 dniach nic się nie dzieje

**Dowód — trzy niezależne kawałki tej samej dziury.**

1. `app/Http/Controllers/Settings/DataSettingsController.php:136-138`:

```php
return redirect()->route('landing')->with('status',
    "Konto zostało oznaczone do usunięcia. Masz {$days} dni, żeby zmienić zdanie
     — wystarczy, że się zalogujesz i napiszesz do nas.",
);
```

`app/Http/Controllers/Auth/LoginController.php:77-83` odmawia logowania kontom
`pending_delete`. Komunikat każe zrobić rzecz, która jest zablokowana.

2. `app/Models/User.php:377-383` — metoda `cancelDeletion()` istnieje.
`grep -rn "cancelDeletion" app/ tests/` zwraca **wyłącznie tę definicję**.
Nikt jej nie wywołuje, nie ma trasy, nie ma przycisku, nie ma komendy
artisan. Cofnięcie decyzji jest dziś możliwe tylko przez `psql` na produkcji —
czyli przez operację, której AGENTS.md §6 zabrania bez jawnej zgody właściciela.

3. `config/kuking.php:80-82` — `delete_grace_days => 30`, z komentarzem
*„Ile dni konto czeka w stanie 'pending_delete', zanim dane zostaną trwale
usunięte"*. `routes/console.php` ma dokładnie dwa zadania w harmonogramie:
`kuking:sprzataj-eksporty` (`:44`) i `kuking:zdejmij-wygasle-kary` (`:60`).
`app/Console/Commands/` zawiera dwie klasy o tych samych nazwach. **Nie ma
niczego, co po 30 dniach cokolwiek usuwa.** `docs/legal/COMPLIANCE.md:121`
wymienia to jako rzecz do zaprojektowania, ale w repozytorium nie ma na to issue.

**Scenariusz porażki.** Pani Krystyna, 68 lat, klika „Usuń konto", bo pomyliła
przycisk albo bo się zdenerwowała po nieprzyjemnym komentarzu. Czyta: *„Masz 30
dni, żeby zmienić zdanie — wystarczy, że się zalogujesz"*. Następnego dnia
próbuje się zalogować i dostaje: *„To konto jest oznaczone do usunięcia. Jeśli
chcesz je odzyskać, napisz do nas"* — czyli inną instrukcję niż wczoraj. Jeśli
napisze, ktoś musi ręcznie wejść do bazy produkcyjnej. Jeśli nie napisze, jej
konto i wszystkie przepisy po mamie zostają w bazie **na zawsze** — a obietnica
z konfiguracji i z polityki prywatności mówi co innego. To jest jednocześnie
problem produktowy (SOUL: *„Mam tu przepisy po mamie i nie stracę ich"* działa
w obie strony) i problem zgodności z RODO art. 17.

**Koszt.** M, rozbite na trzy tanie kroki:
1. **S** — poprawić komunikat, żeby mówił prawdę: „Napisz do nas na {adres}
   w ciągu 30 dni, a przywrócimy konto." Jedna linia.
2. **S** — ekran/trasa „przywróć konto" dla moderatora, wołająca `cancelDeletion()`,
   z wpisem do `audit_log`. Dziś jedyna alternatywa to `psql` na produkcji.
3. **M** — komenda `kuking:usun-konta-po-karencji` + wpis w harmonogramie, plus
   **decyzja produktowa**, której nikt jeszcze nie podjął: co dokładnie znika,
   a co zostaje jako anonimowe. `docs/legal/COMPLIANCE.md:122` rekomenduje
   zachowanie przepisów jako „autor: konto usunięte", ale wymaga, żeby to było
   opisane w regulaminie. Bez tej decyzji nie da się napisać tej komendy.

**Czego się wyrzekamy.** Krok 3 jest nieodwracalny z definicji, więc musi mieć
własny test na bazie testowej i suchy przebieg (`--dry-run`) przed pierwszym
uruchomieniem na produkcji. To jest realnie dzień pracy, nie godzina.

---

## B. Ryzyko przy pierwszych stu użytkownikach

### B1. Po pierwszym wpisie „Świeżo z Kuking" znika ze strony głównej na zawsze

**Dowód.** `app/Domain/Feed/FollowingFeed.php:52-55`:

```php
public function isEmptyFor(User $viewer): bool
{
    return $viewer->following()->doesntExist() && $viewer->posts()->published()->doesntExist();
}
```

`app/Http/Controllers/FeedController.php:48-56` używa tego jako przełącznika
binarnego: albo Discover, albo feed obserwowanych. Nigdy oba.

Metoda nazywa się „czy feed jest pusty", a odpowiada na pytanie „czy ten
człowiek zrobił już cokolwiek". To są dwa różne pytania i rozjeżdżają się
natychmiast:

| Stan | `isEmptyFor` | Co człowiek widzi |
|---|---|---|
| 0 obserwowanych, 0 wpisów | `true` | Discover — dobrze |
| 0 obserwowanych, **1 własny wpis** | `false` | feed obserwowanych = **wyłącznie własny wpis** |
| 1 obserwowany, który nic nie opublikował, 0 wpisów | `false` | pusty stan „Jeszcze nic tu nie ma" |

`docs/product/COLD_START.md:198` opisuje ten sam stan zupełnie inaczej:

> **0–4 obserwowanych, ≥1 wpis** | 1. Pytanie dnia · 2. Feed obserwowanych
> (jeśli niepusty) · **3. „Świeżo z Kuking"** · 4. Temat tygodnia · 5. Propozycje osób

Dokument mówi „bloki jeden pod drugim", kod robi „albo–albo".

**Scenariusz porażki.** Basia zakłada konto, przechodzi onboarding, pomija krok
z obserwowaniem („Można pominąć. Nie blokujemy" — COLD_START 7.1) i publikuje
pierwsze zdjęcie, bo o to ją poprosiliśmy. Wraca na `/home`. Nad feedem jest
tablica „kuKINGi na dziś" (cztery osoby i cztery dania), a pod nią — jej własne
zdjęcie i nic więcej. Bez nagłówka, bez wyjaśnienia, bez „Świeżo z Kuking".
Następnego dnia to samo. Sygnał, który dostaje: „nikt tu nic nie robi".
A dokładnie na odwrót — Discover ma treści, tylko przestaliśmy je pokazywać
**w momencie, w którym Basia zrobiła to, o co prosiliśmy**.

Ta sama pułapka w drugą stronę: Basia zaprasza córkę (SOUL 4.13, „Zaproś kogoś
z rodziny"), obserwuje ją i tylko ją. Córka jeszcze nic nie wrzuciła.
`isEmptyFor` = `false`, więc Basia dostaje pusty stan „Jeszcze nic tu nie ma"
(`home.blade.php:28-31`) zamiast Discover.

**Issue.** Brak. Sąsiaduje z #31 (zainteresowania w bazie) i z zamkniętym #37
(tablica „kuKINGi na dziś"), ale ani jedno, ani drugie tego nie dotyka.

**Koszt.** S. Dwie decyzje, nie jedna:
1. `isEmptyFor` powinno pytać o to, co obiecuje — czy zapytanie feedu zwraca
   cokolwiek — a nie o dorobek konta.
2. Tam, gdzie feed obserwowanych ma mniej niż, powiedzmy, pięć pozycji, dołożyć
   pod nim blok „Świeżo z Kuking" z widoczną etykietą wyjaśniającą, dlaczego to
   widać (COLD_START 6.2: *„Użytkownik musi rozumieć, dlaczego to widzi. Zero
   tajemnicy"*).

**Czego się wyrzekamy.** Dwa zapytania zamiast jednego na `/home` dla osób
z małą siecią. Przy setce użytkowników to jest niezauważalne. Trzeba za to
uważać, żeby nie zrobić z tego rankingu — kolejność w obu blokach zostaje
chronologiczna.

---

### B2. Powiadomienia dla konta zawieszonego przepadają bezpowrotnie

**Dowód.** `app/Domain/Notifications/Actions/NotifyUser.php:29-31`:

```php
if (! $recipient->isActive()) {
    return null;
}
```

`app/Models/User.php:196-199` — `isActive()` to `status === 'active'`. Konto
`suspended` nie jest `active`, więc **żadne** powiadomienie do niego nie powstaje.

Tymczasem cały projekt zawieszenia mówi co innego. `EnsureAccountIsActive.php:30-32`:
*„`suspended` → dostęp tylko do ODCZYTU. Konto żyje, treści są widoczne"*.
`LoginController.php:65-73` wpuszcza zawieszonych właśnie po to, żeby mogli
czytać. Bramka w `NotifyUser` pyta „czy konto jest aktywne", kiedy pytanie brzmi
„czy ten człowiek może to jeszcze przeczytać".

`NotifyModerationDecision` świadomie omija tę bramkę (`:23-33`) — więc wiadomość
od moderacji dojdzie. Wszystko inne nie.

**Scenariusz porażki.** Halina dostaje 7-dniowe zawieszenie za ostrą wymianę
zdań w komentarzach. W czwartym dniu Marek gotuje z jej przepisu na kartacze,
robi zdjęcie i pisze „u mnie potrzebowały 10 minut dłużej". Powiadomienie nie
powstaje — nie jest opóźnione, nie czeka w kolejce, po prostu **nie istnieje**.
Halina wraca po tygodniu i nie dowiaduje się nigdy, że komuś wyszło z jej
przepisu. To jest najcenniejszy sygnał w produkcie (AGENTS.md §1) i jedyny
powód, dla którego ludzie tu publikują. Skasowaliśmy go karą, która miała
dotyczyć wyłącznie **pisania**.

Skala jest dziś zerowa (nie ma jeszcze zawieszonych kont), ale przy pierwszej
setce użytkowników zawieszenia się zdarzą i konsekwencja będzie asymetryczna:
karzemy osobę bardziej, niż zamierzaliśmy, i w sposób, którego nie widać
w logu moderacji.

**Issue.** Brak.

**Koszt.** S. Warunek powinien brzmieć „konto ma jeszcze dostęp do odczytu",
czyli wykluczać `banned` i `pending_delete`, a przepuszczać `suspended`.
Nazwana metoda na modelu (`mozeCzytac()`), nie warunek w akcji — bo to samo
pytanie zadaje `EnsureAccountIsActive` i `LoginController`, i już dziś
odpowiadają na nie każde po swojemu.

**Czego się wyrzekamy.** Zawieszona osoba zobaczy w powiadomieniach cudzą
aktywność, na którą nie może odpowiedzieć. To jest mniejsze zło niż cicha utrata
informacji — i tak czy owak trzeba obok tego pokazać pasek o zawieszeniu, który
już istnieje (`layout.blade.php:191-204`).

---

### B3. Listy obserwujących: czterdzieści dodatkowych zapytań i zły licznik

**Dowód.** `app/Http/Controllers/SocialController.php:107-120` — najpierw
`paginate(20)`, potem filtrowanie w PHP:

```php
$paginator->setCollection(
    $paginator->getCollection()->reject(
        fn (User $person): bool => $viewer->hasBlockRelationWith($person),
    )->values(),
);
```

`User::hasBlockRelationWith` (`app/Models/User.php:243-257`) to osobne
`SELECT EXISTS` na tabeli `blocks` — czyli **20 zapytań na stronę**.

Do tego `resources/views/pages/profile/connections.blade.php:38`:

```php
$isFollowingPerson = $viewer !== null && ! $isSelf && $viewer->isFollowing($person);
```

`User::isFollowing` (`:259-262`) — kolejne `SELECT EXISTS`. Razem **około 40
dodatkowych zapytań** przy każdym wejściu na `/@ktos/obserwujacy`.

Drugi skutek, cichszy: filtr działa **po** paginacji, więc `$paginator->total()`
i liczba stron liczą także osoby odfiltrowane. Strona pokazuje 17 osób i mówi,
że jest ich 20; ostatnia strona może wyjść pusta.

**Scenariusz porażki.** Przy 100 użytkownikach to jest ~40 ms i nikt tego nie
poczuje. Przy 2000 obserwujących u gospodarza i wolnym połączeniu z bazą na
Railway to jest już sekunda na stronę, a licznik „Obserwuje 23 osoby" nie zgadza
się z listą — czyli dokładnie ten objaw, który komentarz w `User.php:330-333`
opisuje jako „wygląda jak zepsuty serwis".

**Issue.** Brak. Kod pochodzi z zamkniętego #16.

**Koszt.** S. Jedno `whereNotExists` po `blocks` wpięte w zapytanie relacji
(taki sam kształt jak `Comment::scopeWidoczneDla`) plus jedno `whereExists`
albo `withExists('followers')` zamiast pętli. Dwa zapytania zamiast czterdziestu
i poprawny licznik przy okazji.

**Czego się wyrzekamy.** Nic. To jest przeniesienie warunku, który już istnieje,
z PHP do SQL-a.

---

### B4. `peopleToFollow` sortuje skorelowanym podzapytaniem po wszystkich kontach — na każdym wejściu na stronę główną

**Dowód.** `app/Domain/Feed/DailyBoard.php:120-135`:

```php
return User::query()
    ->where('status', User::STATUS_ACTIVE)
    ->whereHas('posts', fn ($query) => $query->publiclyVisible())
    ->orderByDesc(
        Post::query()
            ->selectRaw('max(published_at)')
            ->whereColumn('posts.author_id', 'users.id')
            ->publiclyVisible(),
    )
    ->with([...])
    ->limit($limit)
    ->get();
```

To zapytanie liczy `max(published_at)` **dla każdego konta, które przeszło
`whereHas`**, sortuje cały wynik i dopiero potem bierze cztery pozycje. Nie ma
tu żadnego cache'u.

Wywoływane jest z `DailyBoard::forViewer` (`:40`, wywołanie w `:49`), a `forViewer` chodzi na
**trzech** ekranach: `FeedController::landing` (`:34`), `::home` (`:52`)
i `::discover` (`:65`). Landing jest publiczny, więc wykonuje się także dla
każdego robota indeksującego.

**Scenariusz porażki.** Przy 100 kontach to jest nic. Przy 5 000 kont
i indeksowaniu przez Google (do czego dążymy — issue #14, sitemap już jest)
strona główna dla gościa liczy 5 000 agregacji przy każdym pobraniu. Objaw nie
będzie wyglądał jak błąd, tylko jak „serwis czasem wolno chodzi", i pojawi się
dokładnie wtedy, gdy zaczniemy rosnąć.

To **nie jest** argument za Redisem ani za osobnym indeksem (AGENTS.md §3 zamyka
ten temat). To jest argument za jedną z dwóch tanich rzeczy: `cache()->remember`
na 10 minut (sterownik `database`, który już mamy) albo denormalizowana kolumna
`profiles.last_published_at` aktualizowana przy publikacji.

**Issue.** Brak. Blisko #7 (analityka) i #33 (monitoring) — bez pomiaru nie
będzie wiadomo, kiedy to zaczyna boleć.

**Koszt.** S dla cache'u. M dla kolumny (migracja + test + `docs/DATABASE.md` +
rollback, zgodnie z AGENTS.md §6).

**Czego się wyrzekamy.** Cache oznacza, że nowa osoba pojawi się w propozycjach
z opóźnieniem do 10 minut. Przy propozycjach „kogo obserwować" to bez znaczenia.
Kolumna denormalizowana oznacza jedno miejsce więcej, które może się rozjechać
z prawdą — i dlatego jest droższa, mimo że wygląda na czystszą.

**Opinia (nie zmierzone):** nie uruchamiałem `EXPLAIN`. Wnioskuję z kształtu
zapytania, nie z planu. Przed zmianą warto to zmierzyć na kopii danych — właśnie
dlatego, że AGENTS.md §3 wymaga pomiaru przed optymalizacją.

---

### B5. „Znaleziono 20 przepisów", gdy jest ich dwieście

**Dowód.** `app/Domain/Search/SearchQuery.php:39` — domyślny `limit = 20`, `:68`
— `->limit($limit)->get()`. Zwracana jest zwykła kolekcja bez informacji o tym,
ile pasujących wierszy jest naprawdę.

`resources/views/pages/search.blade.php:33`:

```php
<p class="meta">Znaleziono {{ $recipes->count() }} {{ $recipes->count() === 1 ? 'przepis' : 'przepisów' }}.</p>
```

Dwa błędy w jednej linii:
1. `count()` liczy **pobrane**, nie **znalezione**. Przy 200 dopasowaniach ekran
   napisze „Znaleziono 20 przepisów" — zdanie nieprawdziwe, i to takie, na
   którego podstawie człowiek podejmuje decyzję („nie ma tego, czego szukam,
   dodam własny").
2. Odmiana liczebnika jest dwustanowa. Dla 2, 3, 4 wyjdzie **„Znaleziono
   3 przepisów"**. W repozytorium jest już `tests/Feature/OdmianaPorcjiTest.php`
   i `Recipe::servingsLabel()`, więc reguła odmiany gdzieś istnieje — tu jej nie
   użyto.

Nie ma też przejścia do dalszych wyników: `SearchController::index` nie
paginuje, a widok nie ma „Pokaż więcej".

**Scenariusz porażki.** Basia szuka „pierogi". Dostaje 20 z 200, komunikat
„Znaleziono 20 przepisów" i żadnej możliwości zobaczenia reszty. Wnioskuje, że
w serwisie jest 20 przepisów na pierogi — a nawet gdyby chciała zobaczyć więcej,
nie ma jak.

**Issue.** Brak. Nie jest to duplikat #38 (przepisanie tekstów) — tam chodzi
o ton, tu o nieprawdziwą liczbę.

**Koszt.** S. Albo napisać „Pokazujemy 20 pierwszych wyników" (uczciwie i bez
drugiego zapytania), albo przejść na paginację kursorową z `x-show-more` — ta
druga droga jest zgodna z AGENTS.md §5 i z resztą serwisu, i kosztuje kilka
godzin. Odmiana liczebnika: użyć tej, która już jest przy porcjach.

**Czego się wyrzekamy.** Paginacja wyszukiwarki to drugie zapytanie liczące
(`COUNT`) albo kursor. Kursor jest tańszy i już go używamy w feedzie — nie ma
powodu wprowadzać tu `COUNT`.

---

### B6. `/@Basia` działa, `/@Basia/obserwujacy` daje 404

**Dowód.** `app/Http/Controllers/ProfileController.php:35-38` — profil szuka
świadomie bez rozróżniania wielkości liter, z komentarzem powołującym się na
audyt A25:

```php
$profile = Profile::query()
    ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
```

`app/Http/Controllers/SocialController.php:131-134` — te same adresy, ale:

```php
private function findUser(string $login): User
{
    return Profile::where('username', $username)->firstOrFail()->user;
}
```

`app/Http/Controllers/OnboardingController.php:87` — to samo:
`Profile::where('username', $username)->first()?->user`.

PostgreSQL porównuje teksty z rozróżnianiem wielkości liter, więc te dwa
kontrolery odpowiadają na inne pytanie niż `ProfileController`, mimo że obsługują
adresy z tego samego wzorca `/@{username}`.

**Scenariusz porażki.** Basia dostaje od koleżanki SMS-a z linkiem do profilu.
Klawiatura telefonu podniosła pierwszą literę: `kuking.pl/@Halina`. Profil się
otwiera (`ProfileController` radzi sobie z wielkością liter), Basia klika
„Obserwujący" — i dostaje 404. To samo dotyczy `/@Halina/obserwuj`, gdyby adres
został przepisany ręcznie.

Skala jest mała, bo linki generowane przez widoki używają kanonicznej nazwy
z bazy. Ale to jest **ta sama klasa błędu, którą audyt A25 uznał za wartą
naprawy** — i naprawiono ją wtedy w jednym miejscu z trzech.

**Issue.** Brak.

**Koszt.** S. Jedna metoda `Profile::poNazwie(string $username)` na modelu,
używana przez `ProfileController`, `SocialController` i `OnboardingController`.
Trzy wywołania zamiast trzech różnych zapytań. Test: `/@Basia/obserwujacy`
zwraca 200.

**Czego się wyrzekamy.** Nic. Zapytanie trafia w istniejący unikalny indeks
funkcyjny `lower(username)` (migracja `2026_09_05_220000`), więc nie jest to
skan tabeli.

---

## C. Warto zrobić

### C1. Nieudana walidacja kasuje wybrane zdjęcia

`app/Http/Controllers/PostController.php:42-53` waliduje **przed** wgraniem
plików, a przy błędzie robi `back()->withInput()` (`:73`). `withInput()` nie
przenosi plików — przeglądarka nie pozwala wypełnić `<input type="file">`
z serwera. `resources/views/pages/posts/create.blade.php:16-18` nie mówi o tym
ani słowa.

**Scenariusz:** Basia wybiera trzy zdjęcia i pisze długi tekst — dłuższy niż
4000 znaków. Dostaje „Ten wpis jest za długi. Zmieść się w 4000 znakach", tekst
zostaje, zdjęcia znikają. Musi przejść przez galerię telefonu jeszcze raz.
AGENTS.md §5 mówi „poprawnie wpisane dane nigdy nie znikają"; zdjęcie jest w tym
produkcie najważniejszą wpisaną daną.

**Koszt.** M. Uczciwe rozwiązanie to wgrywanie zdjęć **przed** walidacją reszty
i trzymanie `media_id` w ukrytych polach formularza (co przy okazji rozwiązuje
połowę A1). Wymaga sprzątania osieroconych `media` — czyli kolejnego zadania
w harmonogramie. Rozwiązanie tanie i natychmiastowe: dopisać do komunikatu
zdanie „Zdjęcia trzeba wybrać jeszcze raz" — nie naprawia problemu, ale przestaje
zaskakiwać.

**Issue:** brak.

---

### C2. Potwierdzenie kasowania istnieje tylko z JavaScriptem

`resources/views/components/confirm-button.blade.php:11-12`:

```html
<form method="POST" action="{{ $action }}" onsubmit="return confirm(@js($question))">
```

Bez JavaScriptu kliknięcie „Usuń ten wpis" kasuje wpis od razu, bez pytania.
Komentarz w tym samym pliku (`:5-8`) uznaje to za akceptowalne. AGENTS.md §5
wymienia jednak dwie reguły, które tu kolidują: *„akcja destrukcyjna wymaga
potwierdzenia"* oraz *„żadna ważna funkcja nie wymaga JavaScriptu"*. Dziś
potwierdzenie jest funkcją, która JavaScriptu wymaga.

**Dodatkowo, na przyszłość.** Nagłówek wymuszający w
`app/Http/Middleware/ApplySecurityHeaders.php:77-83` **nie zawiera `script-src`**,
więc dziś `onsubmit` działa. Polityka docelowa (`:89-101`, mierzona w trybie
Report-Only) ma `script-src 'self'` bez `unsafe-inline` — a to blokuje atrybuty
zdarzeń w HTML-u. W dniu, w którym issue #12 domknie CSP, **wszystkie
potwierdzenia w serwisie przestaną działać po cichu**: żadnego błędu, po prostu
kasowanie bez pytania.

**Koszt.** S–M. Osobna strona potwierdzenia (`GET /wpisy/{post}/usun`) działa
bez skryptu, bez CSP-owych niespodzianek i jest zgodna z tym, jak 50+ czyta
interfejs — potwierdzenie w oknie przeglądarki bywa dla tej grupy niewidoczne.

**Issue:** brak; do dopisania jako kryterium akceptacji do #12.

---

### C3. Przepis bez wykonań nie ma pustego stanu

`resources/views/pages/recipes/show.blade.php:230` — `@if($cookedEvents->isNotEmpty())`.
Gdy nikt jeszcze nie gotował, na stronie **nie ma nic**. SOUL 4.2 wymienia to
jako ryzyko wprost: *„Przepis z 0 wykonań wygląda odrzucony → przy 0 pokazujemy
»Jeszcze nikt nie gotował — będziesz pierwsza?«"*, a SOUL 4.11 wpisuje ten tekst
do tabeli pustych stanów. Pozycja z „soul-packu MVP" (#10 na liście z SOUL §7).

**Koszt.** S — jedna gałąź `@else` z `x-empty-state`.
**Issue:** brak; nie jest to duplikat #38 (tam chodzi o przepisanie istniejących
tekstów, tu o brakujący element).

---

### C4. „Zrobię ponownie" zbieramy i wyrzucamy

`cooked_events.would_make_again` jest walidowane (`CookedEventController.php:52`),
poprawnie zapisywane w trzech stanach (`:73-75`, naprawa z audytu A22, chroniona
testem `OpiniaOPrzepisieTest`) i renderowane w karcie pojedynczego wykonania
(`components/cooked-card.blade.php:53-55`). **Nigdzie nie jest agregowane.**

SOUL 4.2 opisuje to jako mechanikę MVP: *„»10 z 12 osób zrobi to ponownie«.
Zdanie jest zrozumiałe dla każdego, gwiazdki są abstrakcją"*, z zastrzeżeniem
„pokazywać od ≥3 wykonań". To jedyna miara jakości przepisu, na jaką się
zgodziliśmy (ocen gwiazdkowych nie ma i nie będzie — SOUL §6).

**Koszt.** S. Jedno zapytanie agregujące obok `cookedCount`
(`RecipeController.php:219`) i jeden wiersz w `recipe-facts`.
Uwaga na odmianę liczebnika — patrz B5.

**Issue:** brak.

---

### C5. Nikt nie obserwuje gospodarza po rejestracji

`docs/product/COLD_START.md:229` wymienia „Automatyczne obserwowanie gospodarza
po rejestracji (z możliwością cofnięcia)" jako mechanizm, którego skutek to
*„Feed nigdy nie jest pusty — gospodarz publikuje codziennie"*.
`grep -rni "gospodarz" app/ config/ database/` zwraca wyłącznie komentarze.
Nie ma pojęcia „gospodarz" w kodzie ani w konfiguracji.

W połączeniu z B1 to znaczy, że nowe konto ma dziś dokładnie zero gwarantowanych
treści w feedzie obserwowanych.

**Koszt.** S. Wpis w `config/kuking.php` (`community.host_username`), jedno
`FollowUser::handle` w `RegisterController` po utworzeniu profilu, plus
widoczna możliwość cofnięcia (przycisk „Nie obserwuj" na profilu i tak już jest).

**Czego się wyrzekamy.** Automatyczne obserwowanie kogokolwiek jest formą
decyzji podjętej za człowieka. Dlatego COLD_START od razu dopisuje „z możliwością
cofnięcia" — i bez tego nie należy tego wdrażać.

**Issue:** brak; blisko #29 (pierwsze 20 użytkowników) i #6 (panel gospodarza).

---

### C6. Po publikacji nie proponujemy drugiego zdjęcia

`docs/product/COLD_START.md:260`: *„Po publikacji od razu proponujemy dodanie
następnego — człowiek ma w telefonie 40 zdjęć obiadów i jest w trybie »już wiem,
jak to działa«"*.

`resources/views/pages/posts/show.blade.php` (27 linii) zawiera kartę wpisu,
strefę usuwania i komentarze. Nie ma tam żadnego wezwania do dodania kolejnego
zdjęcia. `PostController.php:78-83` zna nawet moment „to pierwszy wpis" i pisze
o tym w komunikacie — a potem nie wykorzystuje tej wiedzy.

**Koszt.** S. Jeden blok na stronie wpisu, widoczny tylko dla autora, mocniejszy
przy pierwszym wpisie.

**Issue:** brak.

---

### C7. `robots.txt` blokuje adresy, których w serwisie nie ma

`app/Http/Controllers/SitemapController.php:94-96`:

```php
'Disallow: /search',
'Disallow: /home',
'Disallow: /add',
```

Adresy w serwisie to `/szukaj` (`routes/web.php:57`) i `/dodaj` (`:137`).
Wyszukiwarka i ekran dodawania **nie są** wyłączone z indeksowania w robots.

Ta sama lista, tylko poprawna, jest dziesięć plików dalej —
`app/Http/Middleware/ApplySecurityHeaders.php:108` używa polskich prefiksów
i wysyła `X-Robots-Tag: noindex, nofollow`. Czyli strony **nie trafią** do
indeksu; szkoda ogranicza się do budżetu indeksowania marnowanego na
`/szukaj?q=...` i do tego, że plik twierdzi coś, czego nie robi.

**Koszt.** S — dwie linie. Przy okazji warto dopisać `/witaj` i `/ugotowane`,
i rozstrzygnąć, czy `/o-kuking`, `/regulamin` i `/prywatnosc` mają być
w sitemapie (dziś nie są — `:28-33`).

**Issue:** brak.

---

### C8. Limit `upload` w konfiguracji nie jest nigdzie podpięty

`config/kuking.php:123` definiuje `'upload' => '30,1'`.
`grep -rn "limits\['upload'\]" routes/ app/` nie zwraca nic. Wgrywanie zdjęć
chodzi pod limitem `post` (20 na 10 minut), bo odbywa się w ramach
`posts.store` / `cooked.store` / `recipes.store`.

To nie jest dziura w bezpieczeństwie — limit istnieje, tylko inny. To jest
martwy wpis w pliku, o którym AGENTS.md §7 mówi, że jest jedynym źródłem
prawdy o limitach. Dokładnie ten sam kształt, co udokumentowany błąd
`kuking.media_disk` (`config/kuking.php:22-26`): konfiguracja, która wygląda,
jakby coś robiła, i nie robi nic.

**Koszt.** S — usunąć albo podpiąć.
**Issue:** brak.

---

### C9. Odmowa dla konta zawieszonego gubi wpisany tekst

`app/Http/Middleware/EnsureAccountIsActive.php:75-76`:

```php
if ($user->isSuspended() && $this->tozZapis($request)) {
    return back()->withErrors(['konto' => $this->komunikatZawieszenia($user)]);
}
```

`back()` bez `withInput()`. Osoba zawieszona, która mimo paska ostrzegawczego
napisała komentarz albo wypełniła formularz przepisu, traci tekst. To ta sama
reguła, o którą chodzi w issue #81 („poprawne dane nigdy nie znikają"), tylko
inna przyczyna.

**Koszt.** S — jedno `->withInput()`.
**Issue:** brak; naturalnie dołącza do #81.

---

## D. Świadomie odkładamy

Te rzeczy **nie są** propozycją pracy na teraz. Wypisuję je, żeby było widać,
że zostały rozważone i odrzucone jako niezgodne z zakresem MVP.

| Rzecz | Dlaczego nie teraz |
|---|---|
| Ekran „Komuś wyszło" (pełnoekranowa karta wykonania) | Ma issue **#17**, jest w soul-packu MVP. Nie dokładam nic ponad to, co tam napisano. |
| Tematy tygodnia i kalendarz sezonowy | Issue **#18**. Bez tego cold start jest słabszy, ale to osobna, zaplanowana praca. |
| Tematy tematyczne i zapisy na koła | Issue **#22** i **#31**. Rozwiązałyby B1 lepiej niż moja propozycja — ale są droższe i już mają swoje miejsce w kolejce. |
| „Moja wersja" (fork przepisu) | Issue **#23**, V1 wg SOUL 4.10. |
| Tryb gotowania | Issue **#24**, V1 wg SOUL 4.13. |
| Web Push | Issue **#35**, świadomie po ustaleniu limitów częstotliwości. |
| „Rok temu gotowałaś…" | Issue **#34**, V1 wg SOUL 4.5. Wymaga wyłącznika (ryzyko żałoby) — nie wolno tego zrobić „przy okazji". |
| OCR zeszytów, planer, lista zakupów | Issues **#28**, **#27**. V2 wprost w AGENTS.md §12. |
| Kolejka inna niż `database`, Redis, osobny silnik wyszukiwania | AGENTS.md §3 zamyka temat. Żadne z moich ustaleń tego nie wymaga — B4 rozwiązuje się cache'em na sterowniku, który już mamy. |
| Fanout-on-write dla feedu | AGENTS.md §8. Przy `whereIn` na kilkuset obserwowanych i indeksie `posts_author_published_idx` nie ma dziś powodu. |

---

## E. Sprawdzone, nie potwierdziło się

Sekcja jest tak samo ważna jak reszta: każda z tych hipotez wyglądała na błąd,
i każda kosztowałaby czyjś dzień, gdyby trafiła do issues bez sprawdzenia.

**E1. „Galeria wykonań nie ma `ORDER BY`, więc kolejność jest przypadkowa."**
`RecipeController.php:215` faktycznie nie sortuje. Ale sortowanie jest
w relacji: `app/Models/Recipe.php:125-128` — `hasMany(CookedEvent::class)->latest('cooked_at')`.
SOUL 4.2 („sortujemy chronologicznie, bez ocen") jest spełnione.
**Obalone przez:** definicję relacji.

**E2. „Inline `onsubmit` w `x-confirm-button` jest już dziś blokowany przez CSP."**
Nie jest. `ApplySecurityHeaders.php:77-83` — nagłówek **wymuszający** zawiera
tylko `base-uri`, `object-src`, `frame-ancestors`, `form-action` i `report-uri`.
Nie ma `default-src` ani `script-src`, więc atrybuty zdarzeń działają.
Pełna polityka (`:89-101`) idzie wyłącznie w `Content-Security-Policy-Report-Only`.
**Obalone przez:** treść nagłówka. Zostawiam natomiast ostrzeżenie w C2 — to
przestanie działać przy domykaniu #12.

**E3. „Na produkcji nie ma workera kolejki, więc żadne zdjęcie nigdy nie staje się `ready`."**
Jest. `docker/entrypoint.sh:8-9, 247-258` uruchamia rolę `worker`
(`php artisan queue:work --max-time=3600`) i rolę `scheduler`;
`.railway/railway.ts:516` konfiguruje dla niej długie okno na zamknięcie.
**Obalone przez:** entrypoint i konfigurację Railway.

**E4. „`with(['posts' => fn ($q) => $q->limit(3)])` w `DailyBoard` pobierze 3 wpisy łącznie dla wszystkich osób, więc większość kafli będzie bez zdjęć."**
To była prawda do Laravela 10. `composer.lock` → `laravel/framework v13.30.1`;
od 11 limity w eager loadingu są realizowane funkcją okna i działają per rodzic.
**Obalone przez:** wersję frameworka.

**E5. „Zawieszenie kasuje powiadomienie o decyzji moderacyjnej, bo `NotifyUser` odrzuca konta nieaktywne."**
Nie kasuje. `NotifyModerationDecision` świadomie omija wspólną bramkę i tworzy
wiersz bezpośrednio (`app/Domain/Moderation/Actions/NotifyModerationDecision.php:23-33, 100-115`),
z uzasadnieniem wypisanym w komentarzu klasy.
**Obalone przez:** tę klasę. Problem z `NotifyUser` **pozostaje** dla wszystkich
pozostałych typów powiadomień — patrz B2.

**E6. „Formularz przepisu bez JavaScriptu istnieje, ale jest nieosiągalny — nikt do niego nie linkuje."**
Jest osiągalny z trzech miejsc: `<noscript>` na górze kreatora
(`resources/views/pages/recipes/wizard.blade.php:13-22`), widoczny link zawsze
pod kreatorem (`:51-54`) i link w samym komponencie
(`components/recipe-wizard.blade.php:911`). Pilnuje tego
`tests/Feature/RecipeWizardTest.php:351`.
**Obalone przez:** widok kreatora. To jest zrobione lepiej, niż zakładałem.

**E7. „`markAllRead()` woła `update()` na relacji z `ORDER BY`, a PostgreSQL nie przyjmuje `ORDER BY` w `UPDATE`."**
`User::notifications()` (`app/Models/User.php:159-162`) faktycznie ma
`->latest('created_at')`, a `NotificationController::markAllRead` (`:38`) woła
na tym `update()`. Ale gramatyka PostgreSQL-a w Laravelu nie dokleja `orders`
do `compileUpdate` (robi to tylko gramatyka MySQL-a).
**Obalone przez:** budowę query buildera.

**E8. „`status` albo `role` przeciekły do `$fillable`."**
Nie. `app/Models/User.php:49-56` zawiera wyłącznie `email`, `password`, `locale`,
`text_scale`, `wants_weekly_digest`, `age_confirmed_at`. Wszystkie zmiany stanu
idą przez nazwane metody z `forceFill` (`:369-463`), z komentarzem wprost o tej
regule (`:361-367`).
**Obalone przez:** model. Reguła z AGENTS.md §7 jest tu przestrzegana wzorowo.

**E9. „`whereNotIn('author_id', [])` w `DiscoverFeed` przy pustej liście blokad wyzeruje wyniki."**
Nie wyzeruje. Laravel kompiluje pusty `NOT IN` do `1 = 1`.
**Obalone przez:** zachowanie buildera. (Warto natomiast wiedzieć, że **odwrotny**
przypadek — pusty `whereIn` — kompiluje się do `0 = 1`; w tym kodzie nie występuje.)

**E10. „Archiwum profilu po miesiącach z SOUL §7 nie istnieje."**
Istnieje. `resources/views/pages/profile/show.blade.php:116-127` grupuje wpisy
po `translatedFormat('F Y')` z nagłówkiem miesiąca.
**Obalone przez:** widok profilu.

**E11. „Pola hasła w formularzach nie mają `required`, więc `x-field` podpisze je »(nieobowiązkowe)«."**
Mają. `auth/login.blade.php:9,13` i `auth/register.blade.php:10,14,18,22` —
wszystkie z `required`.
**Obalone przez:** widoki uwierzytelniania.

---

## F. Czego nie zdążyłem obejrzeć — białe plamy

Uczciwie, żeby wiadomo było, gdzie ten dokument milczy.

1. **`resources/views/components/recipe-wizard.blade.php` (≈950 linii, Livewire 4)**
   — przejrzałem tylko fragmenty. Nie audytowałem autosave'u szkicu, walidacji
   między krokami, wgrywania zdjęć w kreatorze ani tego, co się dzieje przy
   zerwanym połączeniu w połowie kroku 2. To jest najbardziej złożony kawałek
   interfejsu w repozytorium i jednocześnie ten, którego dotyczą otwarte issues
   **#1** i **#13**.

2. **Eksport danych** — `app/Jobs/GenerateUserExport.php`,
   `app/Domain/Users/Exports/*` (cztery klasy). Nie sprawdzałem kompletności
   paczki względem RODO art. 15 i 20 ani tego, co się dzieje przy koncie
   z tysiącem zdjęć. Issue #2 jest zamknięte, więc nikt tam już nie zagląda.

3. **Warstwa wizualna** — `resources/css/tokens.css`, `app.css`, `fonts.css`.
   Nie zweryfikowałem twardych progów z `docs/UX_50_PLUS.md`: 18 px tekstu,
   48 px przycisków, kontrastu, użyteczności przy 320 px i przy 200%
   powiększenia. To jest dokładnie zakres issue **#26** (automaty axe-core
   i Lighthouse), które jest otwarte — więc pomiar zrobią narzędzia lepiej ode mnie.

4. **Nie uruchomiłem aplikacji ani testów.** Worktree nie ma `vendor` ani bazy
   `kuking_test`. Wszystko powyżej wynika z lektury kodu, konfiguracji i migracji.
   Trzy ustalenia — **A1**, **A2** i **A3** — dotyczą zachowań widocznych dopiero
   na uruchomionej aplikacji z prawdziwym PHP-FPM i prawdziwą kolejką. Zanim
   powstanie na nie issue, warto je potwierdzić na stagingu: A1 przez wysłanie
   trzech zdjęć po 12 MB, A2 przez zatrzymanie workera i publikację wpisu,
   A3 przez wklejenie świeżego linku do debuggera kart Facebooka.

5. **`Dockerfile` (≈370 linii) i `.railway/railway.ts`** — czytane wyrywkowo,
   pod kątem workera i limitów PHP. Nie audytowałem procesu wdrożenia,
   healthchecków ani migracji przy starcie.

6. **`docs/seo/`, `docs/brand/`, `docs/infra/`, `docs/decyzje/`** — nie czytane
   w całości. Możliwe, że część moich propozycji z sekcji C jest tam już
   rozstrzygnięta w drugą stronę. Przy każdej z nich sprawdziłem AGENTS.md,
   SOUL.md, COLD_START.md, ROADMAP.md i UX_50_PLUS.md — nie sprawdziłem
   `DECISIONS.md` punkt po punkcie.

---

## Załącznik: skrypt pomiarowy

Do ustalenia **A7** (liczba 126) użyłem jednorazowego skryptu; **nie jest to kod
produkcyjny i nie trafia do repozytorium**. Leży w katalogu roboczym sesji jako
`brakujace_komunikaty.py`. Co robi:

1. przechodzi po `app/Http/Controllers/**/*.php`;
2. wyrażeniem regularnym wyszukuje bloki `validate([ ...reguły... ], [ ...komunikaty... ])`;
3. z pierwszej tablicy wyciąga pary `'pole' => [reguła, reguła, …]`,
   z drugiej klucze `'pole.reguła' =>`;
4. pomija reguły, które nie generują komunikatu (`nullable`, `sometimes`,
   `present`, `filled`, `bail`);
5. wypisuje pary bez własnego komunikatu wraz z plikiem i numerem linii.

**Ograniczenia, które trzeba znać, zanim ktoś się powoła na tę liczbę:** to jest
parser tekstowy, nie analiza AST. Nie widzi reguł budowanych obiektowo
(`Password::min(10)`, `Rule::unique(...)`, własne klasy `App\Rules\*`) i liczy
także pary praktycznie nieosiągalne (np. `body.string`, gdy pole i tak przyjdzie
jako tekst). Liczba **126 jest więc górnym oszacowaniem**; realnie bolesnych,
osiągalnych przez zwykłego użytkownika par jest kilkadziesiąt — tabela w A7
wymienia te, które sprawdziłem ręcznie. Do decyzji „czy warto założyć `lang/pl`"
to wystarcza z dużym zapasem.
