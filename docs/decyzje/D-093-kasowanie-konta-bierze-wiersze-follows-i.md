## D-093 · Kasowanie konta bierze wiersze `follows` i `blocks` po jednym, w kolejności ustalonej PRZEZ DANE — `ZamekPary` się tu nie da i to jest zmierzone

**Data:** 10 września 2026 · Audyt kolejności blokad, znalezisko Z-2
(`docs/research/2026-09-10-kolejnosc-blokad.md`) · Status: **obowiązuje**

### Co było złamane

`EraseAccountData` bierze wiersz `users` pod `FOR UPDATE` — zgodnie z regułą
„konto najpierw" z D-075 — ale relacje kasowało **bez żadnej ustalonej
kolejności wierszy**, dwoma hurtowymi `detach()` w stałej kolejności RÓL:

```php
$fresh->following()->detach();   // wiersze (X, *)
$fresh->followers()->detach();   // wiersze (*, X)
```

Dla pary, która obserwuje się wzajemnie, w `follows` leżą DWA wiersze:
`(X,Y)` i `(Y,X)`. Egzekucja konta X brała najpierw `(X,Y)`, potem `(Y,X)`;
egzekucja konta Y — dokładnie odwrotnie. Każda trzymała to, na co czekała
druga, i PostgreSQL zabijał jedną z nich (pomiar E5 audytu, odtworzony przy
tej poprawce na dwóch połączeniach):

```text
ERROR: deadlock detected
CONTEXT: while deleting tuple (0,5) in relation "follows"
```

**Co widział człowiek.** Nocna komenda `kuking:usun-wygasle-konta` przerywa
się w połowie, a konto, które **prosiło o usunięcie**, nie zostaje tej nocy
wymazane. Przy kolejnym przebiegu zwykle przejdzie — ale „zwykle" nie jest
obietnicą, a to jest obowiązek prawny (RODO art. 17), nie wygoda.

**Jak realne.** `routes/console.php:96` ma `withoutOverlapping()`, więc
harmonogram nie zderzy się sam ze sobą. Zderzy się z **ręcznym przebiegiem
właściciela**, a D-077 §3 wymienia ten scenariusz wprost jako realny
w tym projekcie.

### Decyzja

Wiersze `follows` i `blocks` kasujemy **po jednym, w kolejności wyliczonej
z klucza głównego wiersza** — nowa metoda prywatna
`EraseAccountData::usunRelacjeWKolejnosciDanych()`. Który identyfikator siada
w której pozycji klucza, wynika z DEFINICJI RELACJI (`following()` to zawsze
`follower_id → followed_id`, dla każdego konta jednakowo), a nie z tego, KTÓRE
konto jest wymazywane. Obie egzekucje wyliczają więc dla wiersza `(X,Y)` ten
sam klucz, ustawiają się w kolejce i nie mają z czego zbudować cyklu.

Kontrola dodatnia naprawy, zmierzona na dwóch połączeniach do PostgreSQL:
kolejność po rolach → `deadlock detected`; kolejność po danych → druga
egzekucja **czeka w kolejce**, po obu zostaje zero wierszy.

**`detach()` zostaje.** Kasujemy nadal przez relację, tylko z jawnym
identyfikatorem: `detach([$id])` jest zawężony do tego konta ORAZ do jednego
wskazanego wiersza, więc najgorsza możliwa awaria tej ścieżki — zabranie
relacji dwóch obcych osób — pozostaje niemożliwa z konstrukcji (pilnuje tego
`WymazanieKontaUsuwaRelacjeI2FATest::test_relacje_innych_osob_zostaja_nietkniete`).
Sam odczyt listy wierszy idzie po surowej tabeli: odczyt niczego nie kasuje,
a branie go przez relację dokładałoby złączenie z `users` i narażało listę na
dowolny przyszły zakres globalny na modelu konta.

**Schemat bazy się NIE zmienia.** Żadnej migracji, żadnego wpisu
w `docs/DATABASE.md` — to poprawka kolejności operacji, nie modelu danych.

### Dlaczego NIE `ZamekPary` — nie „drożej", a **nie da się**

Raport dawał drugą drogę: przepuścić kasowanie relacji przez `ZamekPary` dla
każdej pary z osobna, z ceną „tyle transakcji, ile relacji". Ta droga jest
odrzucona nie z powodu ceny, tylko dlatego, że **jest niepoprawna w tym
miejscu** — i to jest zmierzone, nie wydedukowane.

`handle()` trzyma już wiersz `users` wymazywanego konta pod `FOR UPDATE`
(D-075). `ZamekPary` bierze OBA wiersze pary rosnąco po identyfikatorze —
czyli dla pary, w której wymazywane konto ma identyfikator wyższy, chciałby
wziąć najpierw wiersz drugiej osoby. Dwie egzekucje na parze wzajemnej
odtwarzają wtedy **dokładnie cykl z Z-1/D-090**: każda trzyma własny wiersz
`users` i czeka na cudzy. Zmierzone:

```text
ERROR: deadlock detected
CONTEXT: while locking tuple … in relation "users"
```

Czyli lekarstwo wprowadzałoby tę samą chorobę, którą D-090 właśnie
wyleczyło — tylko na innej tabeli. Dodatkowo zagnieżdżone `DB::transaction()`
jest w Laravelu tylko **punktem powrotu**, a nie osobną transakcją, więc
obiecana cena („tyle transakcji, ile relacji") i tak nie jest osiągalna
z wnętrza tej transakcji: blokady żyłyby do commitu transakcji zewnętrznej.

`ZamekPary` zostaje więc **nietknięty** i nadal jest jedynym gardłem dla
operacji na parze osób wykonywanych Z ZEWNĄTRZ (`FollowUser`, `BlockUser`).
Kasowanie konta nie jest taką operacją: dotyczy JEDNEGO konta i wszystkich
jego par naraz, a wchodzi od strony blokady konta, nie pary.

### Dlaczego NIE jedno zapytanie z `ORDER BY … FOR UPDATE`

To była pierwsza, tańsza droga z raportu — dwa zapytania na tabelę zamiast
tylu, ile relacji. Odrzucona z tego samego powodu, dla którego `ZamekPary`
bierze swoje dwa wiersze `users` dwoma osobnymi zapytaniami, i ten powód jest
już zapisany w tym repozytorium:

> `SELECT … ORDER BY id FOR UPDATE` blokuje wiersze w kolejności, w jakiej
> wypuszcza je plan zapytania. (…) Gwarancja, która trzyma się na kształcie
> planu, nie jest gwarancją.

Druga, słabsza reguła kolejności blokad w tej samej dziedzinie to dokładnie
ten rozjazd, przed którym ostrzega D-079 („dwie różne kolejności w jednym
repozytorium to zakleszczenie, a nie zabezpieczenie"). `DELETE` w PostgreSQL
nie przyjmuje przy tym `ORDER BY` wcale, więc wariant „jedno zapytanie"
i tak wymagałby osobnego `SELECT … FOR UPDATE` przed nim.

Rozważony i odrzucony był też wariant naprawdę tani: dwa hurtowe `DELETE`
rozdzielone warunkiem na danych (`follower_id < followed_id` i odwrotnie).
Dla DWÓCH równoległych egzekucji jest poprawny, ale dla trzech już nie —
w obrębie jednego hurtowego `DELETE` kolejność wierszy nadal ustala plan,
więc trzy egzekucje na trzech parach mogą domknąć cykl `X→Y→Z→X`. Poprawność
zależna od liczby równoległych przebiegów nie jest poprawnością, a nic nie
gwarantuje, że przebiegów będzie najwyżej dwa.

**Cena, którą płacimy, nazwana wprost:** tyle zapytań `DELETE`, ile relacji
ma kasowane konto — ale w JEDNEJ transakcji (nie tyle transakcji, ile
relacji) i w nocnej komendzie, nie w żądaniu HTTP. Przy koncie z setką relacji
to setka zapytań do lokalnej bazy, czyli rzędu kilkudziesięciu milisekund.

### `blocks` ma ten sam kształt — i został naprawiony razem

Sprawdzone przy migracji, nie założone.
`2026_09_05_000300_create_follows_and_blocks_tables` daje `blocks` klucz
główny `(blocker_id, blocked_id)` i CHECK `blocker_id <> blocked_id`, ale
**nic nie zabrania pary wzajemnej** — „X zablokował Y" i „Y zablokował X" to
dwa osobne wiersze, dokładnie jak w `follows`. Ta sama usterka, ta sama
naprawa, osobny test (bo osobne wywołanie łatwo poprawić tylko w jednym
z dwóch miejsc).

`tag_follows` **zostaje jednym hurtowym `detach()`** i to nie jest
niedokończona robota: kluczem jest `(user_id, tag_id)`, więc dwie egzekucje
różnych kont nie mają ani jednego wspólnego wiersza — nie ma czego szeregować
i nie ma jak zbudować cyklu. Wiersz `tags` po drugiej stronie klucza obcego
też nie tworzy wspólnego punktu, i to jest zmierzone: `DELETE` z tabeli
odsyłającej nie bierze na wierszu rodzica ŻADNEJ blokady (0 blokad krotek
i 0 wpisów w `pg_locks` dla relacji rodzica). Blokady kluczy obcych, które
dały Z-1, bierze `INSERT`, nie `DELETE`.

### Czego ta decyzja NIE rozstrzyga

**Nie zakłada grupy testów `dwa-polaczenia`.** Rozdział 7 raportu wycenia ją
na pół dnia szkieletu i nazywa najwyższy koszt: testy na prawdziwej
równoległości bywają niestabilne, a niestabilny test jest tu gorszy niż jego
brak. To zostaje osobną decyzją. Skutkiem jest to, że **brak `40P01` przy
prawdziwej równoległości nie jest dziś pilnowany żadnym testem** — zmierzono
go poza zestawem, a `KasowanieKontaBierzeRelacjeWKolejnosciDanychTest`
pilnuje dwóch rzeczy słabszych, ale sprawdzalnych na jednym połączeniu:
że każde zapytanie kasujące wskazuje dokładnie jeden wiersz (więc kolejności
nie ustala plan) i że dwie egzekucje na tej samej parze biorą wiersze w tej
samej kolejności (więc kolejność jest funkcją danych, nie roli). Ograniczenie
jest wypisane w docblocku tego pliku, zgodnie z `docs/PULAPKI_TESTOW.md` §6.

**Nie rusza `usunTresci()`, a znaleziono tam podobny kształt.** Przy zakresie
`everything` kasowanie przepisu zabiera kaskadą CUDZE komentarze i CUDZE
wykonania pod nim (to jest świadome, D-022) — a to znaczy, że dwie egzekucje
mogą dotknąć tego samego wiersza `comments` z dwóch stron: jedna przez
`$user->comments()`, druga kaskadą od swojego przepisu. Kształt jest podobny
do Z-2, ale **nie jest zmierzony** i nie ma zgłoszenia; zapisuję go tu jako
znalezisko z czytania, nie jako ustalenie. Naprawianie go „przy okazji" tej
poprawki byłoby dokładnie tym, przed czym raport ostrzegał przy Z-2.

**Nie rusza Z-3** (`LoginLinkController::store()` bierze wiersz tokenu bez
wiersza konta). Osobna decyzja.

### Jak to wycofać

Jedna metoda prywatna i dwa wywołania. Przywrócenie czterech hurtowych
`detach()` w miejsce dwóch wywołań `usunRelacjeWKolejnosciDanych()` wraca do
stanu sprzed poprawki — bez migracji, bez zmiany schematu i bez wpływu na
dane już wymazane. Cena wycofania to powrót zakleszczenia Z-2.

**Zmiana wymaga:** rezygnacji z zasady „kolejność blokad ustalają dane, nie
role" — a wtedy razem z nią z D-079, D-080 i D-090. Sama kolejność (rosnąco
po kluczu) nie podlega zmianie inaczej niż we wszystkich miejscach naraz.

**Pliki:** `app/Domain/Users/Actions/EraseAccountData.php` ·
`tests/Feature/KasowanieKontaBierzeRelacjeWKolejnosciDanychTest.php` ·
`docs/research/2026-09-10-kolejnosc-blokad.md` ·
D-022 · D-075 · D-077 · D-079 · D-080 · D-090
