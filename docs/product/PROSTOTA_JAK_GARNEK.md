# Prostota jak Garnek — co u nas jest bardziej skomplikowane, niż musi być

> **To, czym ten dokument NIE jest:** planem powrotu do Garnek.pl. Kuking nie
> jest jego następcą i nie sugerujemy tego nigdzie — ani tutaj, ani
> w marketingu (`docs/marketing/KAMPANIA_GARNEK.md`, sekcja o granicach
> prawnych i o tym, że Garnek jest „akceleratorem pierwszego wejścia, nie
> tożsamością produktu”). Ten dokument mówi wyłącznie o **mechanice
> interfejsu**: ile kliknięć i ile ekranów kosztuje konkretna czynność,
> i czy da się to zrobić taniej bez łamania zasad, którym Kuking już
> świadomie służy (moderacja i DSA, RODO, chronologiczny feed, zakaz
> rankingów).

## 0. Skąd to zadanie i jak liczono

Właściciel: *„chodzi żeby zrobić prościej, jak garnek.pl”*. Jego mama, po
próbie użycia Kuking: *„W garnku na jednej stronie. Było chyba można umieścić
10 różnych potraw”*.

Wszystkie liczby niżej sprawdzono w kodzie tej gałęzi (nie z pamięci):

- ścieżki i kontrolery: `routes/web.php`,
- widoki: `resources/views/pages/**`, `resources/views/components/**`,
- zapytania i eager loading: `app/Domain/Feed/*.php`,
  `app/Http/Controllers/ProfileController.php`, `app/Http/Controllers/TagController.php`,
- liczby zapytań przy feedach: istniejące testy N+1 (`grep -rn "N03" tests/`).

„Kliknięcie” = jedna interakcja, która zmienia stronę albo wysyła formularz.
Wpisywanie tekstu w pole nie jest kliknięciem. „Ekran” = jeden adres/widok,
który człowiek ogląda po drodze (stan pośredni na tym samym adresie, np.
wynik wyszukiwania na tym samym URL-u co pusty formularz, liczymy jako ten
sam ekran).

Materiał źródłowy o Garnku pochodzi z opisu przekazanego przez właściciela
(zrzuty i wspomnienia), nie z żywego serwisu — Garnek.pl nie istnieje od
25 listopada 2024 r. Tam, gdzie źródło nie podaje szczegółu (np. dokładnej
treści formularza uploadu), zaznaczamy to wprost zamiast zgadywać.

---

## 1. Sześć ścieżek — kliknięcia i ekrany, liczbami

| # | Ścieżka | Kuking — kliknięcia | Kuking — ekrany | Garnek — kliknięcia | Garnek — ekrany | Różnica |
|---|---|---|---|---|---|---|
| 1 | Wejście na stronę główną | 0 | 1 (`/` → `landing.blade.php` gość, `/home` → `home.blade.php` zalogowany) | 0 | 1 | brak — patrz jednak 1a niżej |
| 2 | Dodanie zdjęcia (od Startu) | **2** przez kafelek „Dodaj zdjęcie…” na Starcie; **3** przez pozycję „Dodaj” w nawigacji | **3** / **4** (patrz 2a) | 2 | 2 | +0 / +1, zależnie od drogi |
| 3 | Obejrzenie własnego wpisu (z feedu) | 1 | 2 (Start → wpis) | 1 | 1 (zdjęcie jest już na osi czasu; klik otwiera od razu docelowy, kompletny ekran) | +0 kliknięć, +1 ekran (patrz 3a) |
| 4 | Obejrzenie cudzego wpisu (osoby nieobserwowanej) | 2 | 3 (Start → Świeżo z Kuking → wpis) | 2 | 2 (przeglądaj → zdjęcie; albo fotofora → zdjęcie) | +0 kliknięć, +1 ekran |
| 5 | Znalezienie czegoś przez frazę | 2 | 2 (Szukaj → wynik na tym samym adresie) | — | — | Garnek **nie miał** wyszukiwania frazą (patrz 5a) |
| 6 | Skomentowanie (od otwartego wpisu) | 1 | 0 (formularz stoi na tej samej stronie) | 1 | 0 | brak |

### 1a. Strona główna — ta sama liczba kliknięć, dużo więcej na ekranie

Zero kliknięć w obie strony, ale to, co ląduje na ekranie, nie jest
porównywalne. Policzone wprost z `resources/views/pages/landing.blade.php`
(gość, bez logowania):

- pasek górny: 2 odnośniki („Zaloguj się”, „Załóż konto”),
- sekcja hasła: 2 duże przyciski + tablica „kuKINGi na dziś”,
- 4 pozycje „Cztery rzeczy i nic więcej” (bez odnośników),
- cytat z przykładowym powiadomieniem (bez odnośnika),
- sekcja „Świeżo z Kuking”: do 9 kart wpisów, każda z ok. 3 klikalnymi
  elementami dla gościa (autor, data/link do wpisu, komentarze) — do **27**
  odnośników w jednej sekcji,
- sekcja „Twoje dane” (bez odnośników),
- sekcja końcowa: 2 odnośniki,
- stopka: **8–9** odnośników w czterech grupach + przełącznik motywu +
  metryczka wersji.

Razem: **ok. 45–50 klikalnych elementów na pierwszym ekranie**, w jednym
przewijalnym dokumencie złożonym z sześciu sekcji o różnym tle.

Opis Garnka z materiału źródłowego: nazwa, jedno zdanie, kilka miniatur,
jedno pole „użytkownik: [pokaż]” — góra strony to **pięć odnośników w
belce** (`+ wrzuć foto`, `przeglądaj`, `fotofora`, `zaloguj się`, `załóż
konto`) i nic więcej poza treścią.

To nie jest zarzut sam w sobie — landing ma inne zadanie niż Garnka strona
startowa (przekonać kogoś, kto nic o Kuking nie wie, patrz komentarz na
górze pliku: „to jest pierwsze, co widzi człowiek, który o Kuking nie wie
nic”). Ale to jest dokładnie ten rodzaj gęstości, o którym mówiła mama
właściciela. Patrz ranking, poz. 6.

### 2a. Dodanie zdjęcia — dwie drogi, dwie różne liczby

**Nie proponujemy ujednolicenia tych dróg — jest już w robocie.** Podajemy
tylko zmierzoną różnicę, bo tłumaczy część wrażenia „za dużo kroków”:

- **Kafelek na Starcie** (`resources/views/pages/home.blade.php`,
  `<a class="card composer" href="{{ route('posts.create') }}">`):
  Start → **klik 1**: od razu formularz `/dodaj/zdjecie` → **klik 2**:
  „Opublikuj” → przekierowanie prosto na własny wpis (`PostController::store()`,
  linia z `redirect()->route('posts.show', $post)`). **2 kliknięcia, 3 ekrany.**
- **Pozycja „Dodaj” w nawigacji** (side-nav/bottom-nav, `route('add')`):
  Start → **klik 1**: ekran wyboru `/dodaj` („Zdjęcie i kilka słów” / „Cały
  przepis”, `resources/views/pages/add.blade.php`) → **klik 2**: wybór
  „Zdjęcie i kilka słów” → formularz `/dodaj/zdjecie` → **klik 3**:
  „Opublikuj” → wpis. **3 kliknięcia, 4 ekrany.**

Garnek: klik „+ wrzuć foto” → formularz → klik „wyślij” → zdjęcie na osi
czasu. **2 kliknięcia, 2 ekrany.** Nasza szybsza droga (kafelek) ma tyle
samo kliknięć, ale jeden ekran więcej, bo dochodzi ekran wyniku z osobnym
adresem (u nas to wartość — „Zobacz swój wpis” z datą, `docs/product/SOUL.md`
§4.1 — nie tylko koszt). Droga przez nawigację kosztuje jedno kliknięcie
więcej niż Garnek, bo trafia najpierw na ekran wyboru.

### 3a i 4a. Jeden dodatkowy ekran wynika z tego, że karta już pokazuje treść

W obu ścieżkach (własny i cudzy wpis) Kuking dokłada jeden ekran względem
Garnka, ale nie dlatego, że jest wolniej — dlatego, że **karta w feedzie już
pokazuje prawie wszystko** (`components/post-card.blade.php`: autor, tekst,
zdjęcie, znacznik „Z przepisu”). Kliknięcie w kartę otwiera osobny ekran
głównie po komentarze i akcje właściciela (edycja, usunięcie, kolejność
zdjęć), nie po samą treść — tę człowiek już widział bez klikania. Garnek
pokazywał na osi czasu wyłącznie miniaturę, więc jego „jeden ekran więcej”
u nas jest właściwie zerowym kosztem informacyjnym: to nie jest brakujący
klik, to inaczej rozłożona ta sama treść.

### 5a. Wyszukiwanie: realna przewaga, z jedną realną luką

Garnek **nie miał wyszukiwania frazą** — wynika to z materiału źródłowego
(pięć odnośników w belce, żadnego pola szukania; jedyne pole na stronie
głównej to „użytkownik: [pokaż]”, czyli wyszukiwanie osoby po nicku, nie
treści). Odnajdywanie czegokolwiek szło przez przeglądanie osi czasu albo
przez **fotofora** — tematyczne zbiory zdjęć z alfabetyczną listą i
licznikiem („Jedzonko 34203 zdj.”).

Kuking ma prawdziwe wyszukiwanie pełnotekstowe (PostgreSQL FTS + `pg_trgm` +
`unaccent`, `resources/views/pages/search.blade.php`,
`app/Http/Controllers/SearchController.php`) — po nazwie dania, składniku
i osobie, odporne na polskie znaki. To jest coś, czego Garnek nie miał, i
nie proponujemy tego wycinać.

Ale odpowiednik **fotofora** — zamknięta lista ok. 30 tagów (D-021) z
alfabetycznym spisem i liczbą wpisów — **nie ma u nas własnej strony**.
Sprawdzone wprost w `routes/web.php`: jest `/tag/{tag}` (`tags.show`), jest
`/ustawienia/tagi` (edycja własnych obserwowanych tagów), **nie ma** żadnej
trasy typu `tags.index`. Efekt: żeby dotrzeć do tematu, trzeba już znać jego
adres (czyli już widzieć gdzieś odnośnik doń) — a do 10 września 2026
odnośniki do `/tag/{slug}` w ogóle nie stały przy wpisach w feedzie (patrz
punkt 3 poniżej i uproszczenie zaimplementowane w tym PR-ze). Dla osoby,
która nikogo jeszcze nie obserwuje i nie trafiła przypadkiem na czyjś tag,
**„przeglądanie po temacie” kosztowało w Kuking nieskończenie wiele
kliknięć — było niewykonalne przez UI**, podczas gdy w Garnku kosztowało
3 kliknięcia (belka „fotofora” → lista alfabetyczna → wybrany temat).
Patrz ranking, poz. 3 i 7.

### 6a. Komentowanie — już dziś ten sam kształt

Formularz komentarza stoi na dole tej samej strony co treść, bez
przechodzenia gdziekolwiek dalej — dokładnie jak w Garnku („a niżej
komentarze z awatarami i dodaj komentarz”). Sprawdzone w
`resources/views/components/comment-thread.blade.php`. Nic tu nie trzeba
zmieniać.

---

## 2. Tabela: mechanika Garnka → co mamy → wniosek

| Mechanika Garnka | Co mamy u nas | Wniosek |
|---|---|---|
| Jedna oś czasu, zero folderów i albumów | Feed obserwowanych chronologiczny + „Świeżo z Kuking” chronologiczne (`app/Domain/Feed/FollowingFeed.php`, `DiscoverFeed.php`) | **Mamy to samo, tylko inaczej nazwane.** Zero rankingu, zero algorytmu — to samo zdanie co w AGENTS.md §8 i SOUL.md §4.12. |
| Strona zdjęcia to jeden ekran bez klikania w cokolwiek | Karta wpisu (`post-card.blade.php`) już pokazuje autora, tekst, zdjęcie i licznik komentarzy w feedzie; strona wpisu (`pages/posts/show.blade.php`) dokłada tylko akcje właściciela i pełny wątek komentarzy | **Mamy to samo, tylko inaczej zorganizowane.** Treść jest widoczna bez klikania (w karcie); klik otwiera „więcej”, nie „resztę treści”. |
| Nawigacja „kolejne >” po archiwum autora prosto ze strony zdjęcia | Brak. Strona wpisu (`pages/posts/show.blade.php`) nie ma odnośnika do poprzedniego/następnego wpisu tego samego autora — trzeba wrócić do jego profilu | **Warto uprościć — konkretnie tak:** patrz ranking, poz. 4. (Nie proponujemy tego jako zaimplementowany element tego PR-a — plik `pages/posts/show.blade.php` jest poza zakresem tej pracy.) |
| Prawa szyna na stronie zdjęcia: nick, awatar, „archiwum”, „ulubieni”, „+ dodaj do ulubionych” | Autor i awatar stoją w nagłówku karty z linkiem do profilu; „Obserwuj” jest wyłącznie na stronie profilu, nie na stronie wpisu | **Warto uprościć — konkretnie tak:** patrz ranking, poz. 5. |
| Górna belka: pięć odnośników i nic więcej | Belka + nawigacja boczna/dolna (5 pozycji, zgodnie z `docs/UX_50_PLUS.md`) + stopka z 8–9 odnośnikami w 4 grupach + 9 ekranów ustawień | **Świadomie inaczej, w większości bez wyjścia.** Stopka niesie odnośniki wymagane prawem (Regulamin, Prywatność, „Zgłoś nielegalną treść” — DSA art. 16, RODO) i drogę do własnych zgłoszeń (DSA art. 16 ust. 4–5) — Garnek nie musiał tego mieć. Reszta (9 ekranów ustawień) wynika z tego, że Kuking ma więcej stanu na koncie (2FA, prywatność, eksport danych) niż serwis z 2007 r. |
| Fotofora — zbiory tematyczne, lista alfabetyczna z licznikiem | Tagi (D-021), zamknięta lista ok. 30, własna strona `/tag/{slug}`, ale **bez** strony zbiorczej listującej wszystkie tagi | **Warto uprościć — konkretnie tak:** patrz ranking, poz. 3 i 7. To jest ten sam pomysł pod inną nazwą (D-021 wprost: „to jest ten sam rodzaj wyboru redakcyjnego”), ale brakuje mu wejścia. |
| Amatorskie zdjęcia bez wymogu jakości | Zero filtrów, zero „popraw zdjęcie” (SOUL.md §4.11: „Brak wymogu ładnego zdjęcia”) | **Mamy to samo, tylko inaczej nazwane.** |
| Krótkie komplementy w komentarzach („pychotka”) | Cztery nazwane akcje (`Ugotowałem`/`Zapisuję`/`Ładne!`/`Pytanie`) zamiast jednego serca; komentarz nadal wolny tekst | **Świadomie inaczej, bo:** SOUL.md §4.8 — nazwana czynność niesie więcej niż lajk, a „Ugotowałem” jest ważniejsze niż komplement (AGENTS.md §1). To jest rozszerzenie Garnka, nie komplikacja bez powodu. |
| Brak liczników popularności, brak rankingu osób | Liczba obserwujących ukryta/dyskretna, „Ładne!” niewidoczne publicznie, zero „top kuKINGi tygodnia” | **Mamy to samo, świadomie.** AGENTS.md §12 — zakaz publicznych rankingów jest anty-wzorcem wymienionym wprost, nie przeoczeniem. |
| Zero moderacji widocznej dla użytkownika | Panel moderacji, zgłoszenia, odwołania, sygnały automatu, kolejka „bez odpowiedzi” — 6 ekranów admina + widoczne dla użytkownika: „Zgłoś”, „Zgłoś nielegalną treść”, „Twoje zgłoszenia”, powiadomienia o decyzji z uzasadnieniem | **Świadomie inaczej, bo musi być.** DSA (art. 14, 16, 17, 20) wymaga mechanizmu zgłaszania, uzasadnienia decyzji i odwołania — to nie istniało w prawie 2007 r. Nie proponujemy niczego z tego do uproszczenia. |
| Brak prywatności wpisów (wszystko publiczne) | Trzy poziomy widoczności na każdym wpisie: wszyscy / obserwujący / tylko ja | **Świadomie inaczej, bo musi być.** RODO i zwykła przyzwoitość wobec osoby, która chce zdjęcia rodzinnego przepisu bez publikowania go całemu internetowi. Jedno dodatkowe pole w formularzu (`pages/posts/create.blade.php`), nie ekran. |
| „Fotoblog” = jedna, płaska rzecz | U nas: wpisy, przepisy, wykonania (`Ugotowałem`), kolekcje/Zeszyt, tagi — pięć różnych obiektów z osobnymi ekranami | **Świadomie inaczej, bo Kuking robi więcej niż fotoblog.** Rodzinne przepisy z historią i podpisem autora (SOUL.md §4.3) to główna, nieskopiowalna przewaga produktu — Garnek nie miał w ogóle pojęcia „przepis” jako osobnego obiektu z wersjami. Ale liczba osobnych „miejsc” (Zeszyt, Archiwum, Tagi, zakładki profilu) jest realnym kosztem orientacji — patrz ranking, poz. 8. |
| Odmiana liczebników nie istniała (albo była błędna) w ówczesnych serwisach | `App\Support\Odmiana` — poprawna polska odmiana wszędzie („3 przepisy”, „12 osób”) | **Świadomie inaczej, bo musi być.** To nie jest komplikacja, to poprawna polszczyzna — jej brak byłby błędem, nie prostotą. |

---

## 3. Ranking uproszczeń — od najtańszego do decyzji właściciela

Każda pozycja: co zmienić, gdzie, co może się zepsuć, jak sprawdzić skutek.

### Zrobione w tym PR-ze

**0. Tagi wpisu widoczne na karcie, tak jak Garnek pokazywał fotofora na
stronie zdjęcia.** Opis pełny w sekcji 4. Pliki:
`resources/views/components/post-card.blade.php`,
`app/Domain/Feed/FollowingFeed.php`, `app/Domain/Feed/DiscoverFeed.php`,
`app/Http/Controllers/ProfileController.php`,
`tests/Feature/KartaWpisuTest.php`.

### Zrobić od razu, małym kosztem (godziny, jeden plik, jasny test)

**1. Strona ze spisem wszystkich tagów (odpowiednik listy fotofora).**
Co: nowa trasa `GET /tagi` (`tags.index`), kontroler zwracający
`Tag::query()->where('status', Tag::STATUS_ACTIVE)->withCount('posts')->orderBy('name')->get()`,
prosty widok — nazwa + liczba wpisów, alfabetycznie, bez rankingu (dokładnie
jak Garnek: „Jedzonko 34203 zdj.”). Dołożyć jeden odnośnik do niej z
`pages/tags/show.blade.php` („Zobacz wszystkie tematy”) i z
`pages/settings/tags.blade.php`, którego pusty stan dziś odsyła donikąd
(„Zacznij od strony dowolnego tagu” — a skąd wziąć adres tej strony, gdy nie
ma żadnej listy startowej?). Co może się zepsuć: nic istniejącego — to nowa
trasa GET, bez zapisu. Jak sprawdzić: test feature odwiedzający `/tagi` i
sprawdzający obecność tagu z co najmniej jednym wpisem oraz brak sortowania
po liczbie wpisów (assert kolejności alfabetycznej, nie malejącej po
liczniku — żeby nikt przypadkiem nie zamienił tego w ranking).

**2. Odnośnik „Zobacz wszystkie tematy” w prawej szynie Startu i Szukaj.**
Skoro (1) istnieje, dołożyć go też do `x-kuking-board` albo obok niej —
jedna linijka `<a href="{{ route('tags.index') }}">`. Bez tego strona (1)
sama jest martwym punktem, do którego nikt nie trafi. Ryzyko: żadne — jeden
odnośnik tekstowy. Sprawdzić: `assertSee(route('tags.index'))` na `/home`
i `/szukaj`.

**3. Dołożyć eager-loading tagów tam, gdzie karta wpisu jeszcze go nie ma.**
Sprawdzone: `PostController::show()` (strona pojedynczego wpisu) i zakładka
„Ugotowane” na profilu nie ładują `tags`, więc po tym PR-ze karta wpisu na
tych dwóch ekranach nadal nie pokaże chipów tematów (nie przez błąd — przez
świadomy warunek `relationLoaded()`, który nie robi tego automatycznie, żeby
nie dociągać relacji po cichu). To jest dokończenie pracy zaczętej w tym
PR-ze, jedna linijka na każdy z tych dwóch kontrolerów. Ryzyko: żadne — ten
sam wzorzec co reszta zmiany, już objęty testami N+1 (`grep -rn "N03"
tests/`). Sprawdzić: nowy test w stylu `test_karta_pokazuje_tematy_wpisu_*`
z tego PR-a, tylko celujący w `route('posts.show', $post)`.

### Warto zrobić, umiarkowany koszt (dni, kilka plików, wymaga decyzji projektowej co do miejsca)

**4. Nawigacja „poprzedni / następny wpis tego samego autora” na stronie
wpisu.** Co: pod treścią (albo w prawej szynie) `pages/posts/show.blade.php`
dwa odnośniki liczone z `Post::where('author_id', ...)->where('published_at', '<'/'>', ...)->first()`
— zapytanie tanie przy indeksie na `(author_id, published_at)`, który już
istnieje (feed z niego korzysta). Odtwarza dokładnie Garnkowe „kolejne >”
z miniaturą. Ryzyko: `pages/posts/show.blade.php` jest plikiem, w którym „pracują
inni” (poza zakresem tego PR-a) — to jest issue do zgłoszenia, nie do
zrobienia teraz. Jak sprawdzić po zrobieniu: test feature z trzema wpisami
tego samego autora, klik „następny” prowadzi do środkowego wpisu, na
najstarszym nie ma „poprzedni”.

**5. Przycisk „Obserwuj”/„Przestań obserwować” bezpośrednio na stronie
wpisu**, nie tylko na profilu autora. Odtwarza Garnkowe „+ dodaj do
ulubionych” w prawej szynie strony zdjęcia. Co: formularz identyczny jak
w `pages/profile/show.blade.php` (linie ok. 223–234), przeniesiony do komponentu
współdzielonego i wstawiony do `pages/posts/show.blade.php`. Ryzyko: duplikacja
logiki widoczności przycisku (obserwuje/nie obserwuje/zablokowany), jeśli
zrobione bez wydzielenia komponentu — **rozwiązanie: nowy komponent
`x-obserwuj-przycisk` używany w obu miejscach**, żeby nie rozjechały się dwie
kopie tej samej reguły. Jak sprawdzić: test na stronie wpisu z osobą
nieobserwowaną (widać „Obserwuj”), zablokowaną (nie widać nic) i z samym
sobą (nie widać nic).

**6. Landing page — mniej sekcji albo krótsze sekcje na pierwszym ekranie.**
Nie proponujemy kasowania treści prawnie/produktowo ważnej (RODO, eksport
danych), ale sekcja „Świeżo z Kuking” na `landing.blade.php` pokazuje
obecnie do 9 pełnych kart wpisów na stronie, którą ma obejrzeć ktoś, kto o
Kuking jeszcze nic nie wie — to jest największy pojedynczy wkład do
45–50 klikalnych elementów policzonych w sekcji 1a. Propozycja: ograniczyć
do 3 kart (parametr w `FeedController::landing()`, dziś `paginate(null, 9)`)
z jednym wyraźnym „Zobacz więcej” prowadzącym do `/odkryj`. To jest zmiana
w jednym pliku (`FeedController.php`, jedna liczba) plus dostosowanie CSS
siatki (`.landing-wpisy`), więc technicznie tania — ale wymaga zgody
właściciela, bo to jest decyzja o pierwszym wrażeniu marketingowym, nie
czysto techniczna poprawka. Jak sprawdzić: `assertSee` liczy dokładnie 3
karty wpisów w sekcji „Świeżo z Kuking” na `/` dla gościa.

### Duża zmiana, wymaga decyzji właściciela

**7. Fotofora jako pełnoprawna, widoczna z góry ścieżka odkrywania —
nie tylko strona docelowa.** Dziś tag jest „miejscem, do którego trafiasz”,
nigdy „miejscem, od którego zaczynasz” — bo w piątce głównej nawigacji
(`Start | Szukaj | Dodaj | Moje | Profil`, twardy limit z
`docs/UX_50_PLUS.md`) nie ma miejsca na szósty punkt „Tematy”. Żeby zrobić
z tagów prawdziwy odpowiednik Garnkowej belki, trzeba by albo zastąpić jedną
z pięciu pozycji, albo wbudować tematy w istniejącą (np. rozszerzyć „Szukaj”
o zakładkę „Tematy” obok „Wszystko/Przepisy/Ludzie”, które już tam są —
patrz `pages/search.blade.php`, chipsy zakresu). To jest decyzja o
priorytecie nawigacji głównej, nie o jednym pliku — stąd wymaga
właściciela, nie agenta.

**8. Zmniejszenie liczby osobnych „miejsc” na treść (Zeszyt / Archiwum /
Tagi / zakładki profilu).** Osoba przyzwyczajona do Garnkowej jednej osi
czasu dziś musi zrozumieć różnicę między: własnym archiwum (profil,
zakładka „Wszystko”), Zeszytem (zapisane cudze rzeczy), tagami (tematyczne
zbiory) i „Świeżo z Kuking” (globalna oś czasu). To nie jest przypadek do
skasowania — Zeszyt istnieje, bo „zapisany przepis” i „zdjęcie z przeszłości”
to dwie różne potrzeby (RETENTION_LOOPS.md, pętla 3) — ale połączenie liczby
tych pojęć z prostym słownictwem (czy „Zeszyt” i „Archiwum” są dla nowej
osoby odróżnialne z samej nazwy?) to pytanie do testu z użytkownikami
(`docs/product/TESTY_Z_UZYTKOWNIKAMI.md`, już zaplanowanego), nie do zmiany
kodu na ślepo. Rekomendacja: dodać do najbliższej sesji testów z
użytkownikami 50+ zadanie „znajdź przepis, który zapisałaś tydzień temu” i
zmierzyć, czy testerka trafia w Zeszyt, w Archiwum, czy w żadne z nich.

---

## 4. Uproszczenie zaimplementowane w tym PR-ze

### Co i dlaczego akurat to

**Tagi wpisu (Garnkowa „fotofora”) są teraz widoczne bezpośrednio na
karcie wpisu, jako zwykłe odnośniki do strony tematu — dokładnie tak, jak
Garnek pokazywał na stronie zdjęcia „listę fotoforów, do których zdjęcie
dodano”.**

Wybraliśmy akurat to uproszczenie z trzech powodów:

1. **To jest jedna z dwóch pozycji tabeli w sekcji 2, gdzie mamy dokładnie
   ten sam pomysł co Garnek (D-021 nazywa to wprost), ale brakowało mu
   jednego, prostego elementu — nie trzeba było projektować niczego od
   zera.**
2. **Dane już istniały i częściowo już były ładowane.** `Post::tags()`
   istnieje od dawna, autor wybiera do pięciu tagów przy publikacji
   (`x-tagi-formularz` w `pages/posts/create.blade.php`), a `TagController::show()`
   i `App\Domain\Feed\TagFeed` **już** eager-loadowały `tags:id,slug,name` —
   po prostu nic z tego nie renderowało się na karcie. To jest odtworzenie
   zapomnianego kawałka istniejącej funkcji, nie nowa funkcja.
3. **Nie da się tego zepsuć w sposób, który boli.** Zmiana w
   `post-card.blade.php` to nowy blok chroniony warunkiem
   `relationLoaded('tags')` — jeśli ktoś zapomni dołożyć eager loading
   w nowym miejscu, wpisy tam po prostu nie pokażą tagów (zero regresji,
   zero N+1, zero wyjątku). Reużywa gotowych, już przetestowanych klas
   `.chipsy`/`.chip` (te same, których używają `search.blade.php` i
   `szyna-profilu.blade.php`) — zero nowego CSS-u, więc rozmiar dotyku
   (48 px) i kontrast mają policzone pokrycie od pierwszego dnia.

### Co dokładnie się zmieniło

| Plik | Zmiana |
|---|---|
| `resources/views/components/post-card.blade.php` | Nowy blok `@if($post->relationLoaded('tags') && $post->tags->isNotEmpty())` — lista odnośników `<a class="chip" href="{{ route('tags.show', $tag) }}">`, między znacznikiem „Z przepisu” a paskiem akcji. |
| `app/Domain/Feed/FollowingFeed.php` | `tags:id,slug,name` dołożone do `->with([...])` — feed obserwowanych (`/home`). |
| `app/Domain/Feed/DiscoverFeed.php` | To samo — „Świeżo z Kuking” (`/odkryj`) i strona powitalna (`landing.blade.php`, która używa tego samego feedu). |
| `app/Http/Controllers/ProfileController.php` | To samo w `postsFor()` — zakładka „Wszystko” na profilu. |
| `tests/Feature/KartaWpisuTest.php` | Trzy nowe testy (patrz niżej). |

`TagController::show()` i `App\Domain\Feed\TagFeed` nie wymagały zmian —
już ładowały tę relację.

### Test i kontrola ujemna

Trzy nowe testy w `tests/Feature/KartaWpisuTest.php`:

- `test_karta_pokazuje_tematy_wpisu_na_stronie_glownej` — wpis z tagiem na
  `/home` ma odnośnik do `route('tags.show', $tag)` i widoczną nazwę tagu.
- `test_karta_pokazuje_tematy_wpisu_w_swiezo_z_kuking` — to samo na
  `/odkryj` (osobne zapytanie, osobne ryzyko regresji).
- `test_karta_bez_tematow_nie_pokazuje_pustej_listy` — wpis bez tagów nie
  zostawia pustego elementu „Tematy tego wpisu” na stronie.

**Kontrola ujemna, wykonana naprawdę (nie opisana z pamięci):**

1. Baseline: `php artisan test --filter=KartaWpisuTest` → **15/15 zielone**.
2. Zepsucie: tymczasowe usunięcie nowego bloku z `post-card.blade.php`
   (dokładnie ten fragment, który dodaje ta zmiana).
3. Ponowne uruchomienie: **13/15**, dwa nowe testy czerwone
   (`test_karta_pokazuje_tematy_wpisu_na_stronie_glownej` i
   `..._w_swiezo_z_kuking`) z komunikatem wprost mówiącym, czego brakuje.
   Trzeci test (`..._nie_pokazuje_pustej_listy`) zostaje zielony, bo
   sprawdza nieobecność — to jest oczekiwane i pokazuje, że test naprawdę
   mierzy dokładnie tę rzecz, a nie coś przypadkowo skorelowanego.
4. Przywrócenie bloku: **15/15 zielone** ponownie.

Po zmianie odpalono też pełny lokalny zestaw kontrolny:
`vendor/bin/pint` (bez uwag), `vendor/bin/phpstan analyse --no-progress`
(0 błędów, poziom z `phpstan.neon`) i **pełne** `php artisan test`
(**2400/2400 zielone**, w tym istniejące testy liczące zapytania N+1 dla
`/odkryj`, `/tag` i profilu — liczba zapytań nie wzrosła wraz z liczbą
wpisów, czyli nowy eager loading nie wprowadził żadnego nowego N+1).

---

## 5. Dwie rzeczy pominięte świadomie

Zgodnie z ustaleniem: **nie proponujemy** ujednolicenia dwóch dróg „Dodaj”
(sekcja 2a opisuje różnicę liczbowo, ale to już w robocie) ani pokazywania
zdjęcia od razu po opublikowaniu (też w robocie).

---

## 6. Podsumowanie w trzech zdaniach

1. Tam, gdzie liczby się różnią, różnica jest zwykle **jednym kliknięciem
   albo jednym ekranem**, nie przepaścią — Kuking nie jest dziesięciokrotnie
   bardziej złożony niż Garnek, jest kilka kroków bardziej złożony w kilku
   konkretnych miejscach, które da się nazwać i policzyć.
2. Największa realna luka względem Garnka to nie brakująca funkcja, tylko
   **brakujące wejście do funkcji, która już istnieje** — tagi były zbierane
   od pierwszego dnia i nigdzie się nie pokazywały; ten PR to naprawia w
   jednym, bezpiecznym miejscu i zostawia resztę (strona zbiorcza tagów) do
   zrobienia jako issue.
3. Tam, gdzie jesteśmy bardziej skomplikowani bez odwrotu — moderacja i DSA,
   prywatność wpisów, poprawna polska odmiana — to nie jest komplikacja do
   wycięcia, to jest różnica między serwisem z 2007 roku a serwisem, który
   musi działać zgodnie z prawem i szacunkiem do ludzi w 2026.
