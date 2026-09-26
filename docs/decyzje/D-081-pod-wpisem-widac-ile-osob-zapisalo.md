## D-081 · Pod wpisem widać, ile OSÓB zapisało go do zeszytu — autor od pierwszej, obcy od trzeciej; liczba, nie imiona; nigdzie sortowania

**Data:** 10 września 2026 · **Decyzja właściciela** (issue #275) · Status: **obowiązuje**

### Co zdecydował właściciel i czego to nie znaczy

Właściciel powiedział wprost, po wysłuchaniu argumentów przeciw:

> „ale jednak to trzeba pokazać ile osób zapisało, żeby autor wiedział i inni
> wiedzieli, i autor czuł się doceniony, **nie chodzi o rywalizację
> a docenienie**"

Ta decyzja **nie odwraca** żadnej z zasad, które licznika dotyczyły:
`AGENTS.md` §12 (zakaz publicznych rankingów użytkowników) i `CLAUDE.md`
(feed chronologiczny, bez punktów i grywalizacji) obowiązują dalej. Zmienia się
jedna rzecz: pod wpisem stoi zdanie o tym, ilu LUDZIOM ten wpis się przydał.
Liczba, która DOCENIA, i liczba, która USTAWIA W SZEREGU, mają ten sam
kształt — różni je to, komu i od kiedy się ją pokazuje, i gdzie się jej
NIE pokazuje. Cała reszta tego wpisu jest o tej różnicy.

### Próg: autor od 1, ktokolwiek inny od 3

Powód progu jest **arytmetyczny, nie ideologiczny**: serwis jest na starcie
prawie pusty (`docs/product/COLD_START.md`). Licznik liczony od jednego
pokazywałby pod większością dań „1 osoba zapisała", a pod czyimś pierwszym
daniem — nic. „1 osoba zapisała" docenia autora **słabiej niż brak liczby**,
a zero obok cudzej dziesiątki jest dokładnie tym, przed czym ostrzega
`docs/brand/COPY_STYLE.md` przy zakazie komplementów za publikację
(ponad połowa osób 50+ w mediach społecznościowych nigdy nic nie publikuje —
`docs/research/AUDIENCE_50_PLUS.md`).

**Autor widzi liczbę od pierwszego zapisu**, bo to jest cała treść decyzji
właściciela: autor ma prawo wiedzieć, że jego danie komuś się przydało. Reszta
świata nie ma czego porównywać, dopóki liczby są jednocyfrowe.

**Dla obcych próg wynosi 3, nie 2** — i to jest wybór, nie zaokrąglenie.
Właściciel w tym samym zgłoszeniu sam nazwał dwójkę liczbą, która wypada
słabo: *„ludzie widzieli że to zapisało 10 osób a to tylko 2"*. Skoro „tylko 2"
czyta się jak porażka, próg musi stać NAD dwójką — inaczej licznik pokazywałby
obcym dokładnie tę liczbę, która autorowi szkodzi. Precedens na próg
widoczności licznika jest w projekcie od #38: stopka nie pokazuje liczby
kuKINGów poniżej 20 (`LiczbaKukingow`, D-012) z tego samego powodu.

### Liczba, nie imiona — bo zeszyt jest PRYWATNY (sprawdzone w kodzie)

Rozważane było „zapisali to: Halina, Marek i jeszcze 3 osoby" — informacja
o LUDZIACH zamiast wyniku, lepiej pasująca do serwisu, który nigdzie nie ma
punktów. **Odpada**, i to nie z ostrożności, a z ustalenia w kodzie:

- `collections.visibility` ma `DEFAULT 'private'`
  (migracja `2026_09_05_000800_create_collections_tables`), a komentarz tej
  migracji mówi to wprost: *„Ktoś, kto zapisuje przepis »na potem«, nie ogłasza
  tego światu. Publiczna kolekcja jest świadomą decyzją, nie ustawieniem
  domyślnym"*;
- ekran zeszytu (`CollectionController::show()`) i `CollectionPolicy` traktują
  zeszyt jak cudzy pojemnik z własną granicą widoczności.

Zapisanie cudzego wpisu do zeszytu **nie jest dziś czynnością publiczną**,
więc nie wolno jej taką zrobić bez osobnej decyzji właściciela. Imiona
wyciągnęłyby na wierzch zawartość prywatnych zeszytów. Sama liczba — i to od
trzech dla obcych — niczyjego zeszytu nie zdradza.

### Kto się liczy

`users.status = active`, czyli granica „promocyjna", ta sama co
`Post::scopeTylkoOdAktywnychAutorow()`, `DiscoverFeed` i `SearchQuery`
(audyt A5). Nie `widocznyJakoOsoba()`, bo tamten zakres przepuszcza konta
**zawieszone**, a zapis od konta pod sankcją nie ma podbijać liczby
pokazywanej nieznajomym. Jednym warunkiem wypadają konta zbanowane,
zawieszone, w trakcie usuwania i usunięte.

**Blokada — w obie strony i bezwarunkowo** (`AGENTS.md` §4), tym samym
wzorcem co `Comment::scopeWidoczneDla()` i `CookedEvent::scopeWidoczneDla()`.
Liczba jest więc policzona OCZAMI WIDZA.

**Własny zapis autora się nie liczy.** Licznik, który autor może sobie sam
podbić, nie jest informacją o niczym.

`count(distinct users.id)`, nie `count(*)`: liczymy LUDZI, a jedna osoba
z dwoma zeszytami może wrzucić ten sam wpis dwa razy.

### Po „Zapisuję" widać, że się zapisało (część 1 — usterka, nie decyzja)

Potwierdzenie **istniało**: `CollectionController::savePost()` ustawiał
komunikat „Zapisane w zeszycie …", a `components/layout.blade.php` pokazuje go
w `.flash` z `aria-live`. Czego nie było: śladu **w miejscu, gdzie człowiek
kliknął**. Komunikat stoi na górze strony, „Zapisuję" klika się w połowie
feedu, a karta po powrocie wyglądała identycznie jak przed kliknięciem.
Przy grupie 50–75 to jest ta cisza, po której człowiek klika drugi raz.

Naprawa nie dokłada drugiego mechanizmu komunikatów: karta pokazuje **stan**,
tak jak ekran przepisu robi to od dawna (`$isSaved`). Stanem jest zdanie
„Masz to w zeszycie" z odnośnikiem do zeszytu, a **nie** przycisk kasujący
— w feedzie przycisk usuwający pod tym samym palcem zabierałby z zeszytu to,
co ktoś właśnie do niego włożył (podwójne kliknięcie w tej grupie to norma,
issue #43). Wyjąć z zeszytu można nadal w samym zeszycie.

> **Poprawione 20 września 2026 — patrz D-224.** Ostatnie zdanie było
> nieprawdziwe: ekran zeszytu renderuje TĘ SAMĄ kartę, więc przycisku
> wyjęcia nie było tam, gdzie to zdanie obiecywało (audyt L1). Przycisk
> „Usuń z zeszytu" stoi teraz na karcie, OBOK odnośnika „Masz to
> w zeszycie" — a nie zamiast niego, więc opisana wyżej obawa o podwójne
> kliknięcie zostaje zaadresowana układem.

Wszystko działa **bez JavaScriptu**: formularz `POST`, przekierowanie, `GET`.

### Gdzie liczba stoi, a gdzie CELOWO nie

**Jest — dla ZALOGOWANEGO:** feed obserwowanych, „Świeżo z Kuking"
(`/odkryj`), feed tagów, strona tematu, profil, zeszyt, ekran pojedynczego
wpisu — czyli tam, gdzie wpis stoi w chronologicznym strumieniu albo sam.

**Nie ma i to jest część decyzji:**

- **„kuKINGi na dziś"** (`DailyBoard`) — cztery dania wybrane redakcyjnie,
  obok siebie; liczba pod nimi byłaby zestawieniem, nie docenieniem;
- **wyniki wyszukiwania** — liczby jedna pod drugą to porównanie;
- **wszędzie dla GOŚCIA** — liczba mówi „przydało się ludziom z tej
  społeczności" i jest adresowana do jej członków, nie do otwartego
  internetu. Praktyczny powód dokłada się do zasady: strona powitalna układa
  wpisy w siatkę (`landing-wpisy`), a liczby jedna obok drugiej to
  zestawienie. Wychodzi z tego jedna reguła zamiast wyjątku na ekran: **nie
  ma widza, nie ma liczby** — więc gość nie zobaczy jej ani na stronie
  powitalnej, ani na `/odkryj` bez logowania.

Technicznie robi to jedna rzecz: karta pokazuje liczbę tylko wtedy, gdy
zapytanie ekranu ją doliczyło (`ZapisyWpisu::dolicz()`), więc ekran, który jej
nie dolicza, nie pokazuje nic i **nie odpala zapytania na kartę**. Ten sam
wzorzec co `relationLoaded('tags')`.

### Czego ta decyzja NIE rozstrzyga i co wymaga OSOBNEJ decyzji właściciela

1. **Sortowanie, ważenie ani promowanie po liczbie zapisów.** Feed jest
   chronologiczny (`CLAUDE.md`, `AGENTS.md`), a audyt z 10.09 stawia „nie
   budować algorytmu feedu" jako punkt 3 listy „czego NIE robić". Właściciel
   wspomniał o „algorytmie, żeby pokazywał ciekawe tematy" — to jest **punkt 3
   issue #275**, sprawa osobna i wyłączona z tej zmiany; droga do „ludzie widzą
   ciekawe rzeczy" prowadzi przez jawne tematy (#273), nie przez popularność.
2. **Żadnych zestawień** typu „najczęściej zapisywane".
3. **Imiona osób, które zapisały** — wymagają najpierw rozstrzygnięcia, czy
   zapisywanie do zeszytu ma być czynnością publiczną. Dziś nie jest.
4. **Powiadomienie autora o zapisaniu WPISU.** Przy przepisie takie
   powiadomienie jest, przy wpisie nie ma i ta zmiana tego nie dokłada —
   powód stoi w `SavePostToCollection` (ekran powiadomień renderuje każdy typ
   osobno, więc nowy typ bez własnego tekstu dałby pusty wiersz).
5. **Liczba pod wspomnieniem** („rok temu") — jeden ekran, którego ta zmiana
   nie dotknęła; do dołożenia, gdyby właściciel chciał.

**Zmiana wymaga:** decyzji właściciela. Próg jest pilnowany testem
(`LicznikZapisowWidacOdProguTest::test_prog_dla_obcych_jest_decyzja_wlasciciela`),
żeby nie dało się go przesunąć po cichu.

**Pliki:** `app/Domain/Collections/ZapisyWpisu.php` ·
`resources/views/components/post-card.blade.php` ·
`app/Domain/Feed/{FollowingFeed,DiscoverFeed,TagFeed}.php` ·
`app/Http/Controllers/{PostController,ProfileController,TagController,CollectionController}.php` ·
`tests/Feature/LicznikZapisowWidacOdProguTest.php` ·
`tests/Feature/LicznikZapisowBezWachlarzaZapytanTest.php` ·
`tests/Feature/PoZapisaniuWidacPotwierdzenieTest.php`
