## D-080 · Blokada i obserwowanie nie mogą współistnieć: jedna kolejność blokad na PARZE osób, rewalidacja pod blokadą i twarda bariera w bazie

**Data:** 10 września 2026 · **Znalezisko:** SOCIAL-01 (P1) z audytu trzeciej
warstwy · Status: **obowiązuje**

### Co było zmierzone PRZED zmianą

`FollowUser::handle()` sprawdzał blokadę zwykłym `exists()`
(`hasBlockRelationWith`) i zaraz potem robił `attach()` — **bez transakcji
i bez blokady wiersza**. `BlockUser::handle()` robił swoje dwie rzeczy (zapis
blokady i `detach` obserwowania w obie strony) w jednej transakcji, ale
transakcja jednej strony nie pomaga, gdy druga strona nie blokuje niczego.

Przeplot odtworzony deterministycznie (`BlokadaWygrywaZObserwowaniemTest`,
wstrzyknięcie przez `DB::listen` w chwilę po odczycie tabeli `blocks`):

| Krok | Żądanie A („Obserwuj") | Żądanie B („Zablokuj") |
|---|---|---|
| 1 | pyta o blokadę → nie ma | |
| 2 | | zapisuje blokadę, zdejmuje obserwowanie w obie strony |
| 3 | dopina `follows` | |

Zmierzony wynik na `origin/main` @ `94081ff`: **po blokadzie w tabeli
`follows` zostaje wiersz.** Test oblewał się z komunikatem „Po blokadzie
zostało obserwowanie".

**Jedna teza znaleziska NIE potwierdziła się w tym pomiarze.** Audyt mówi, że
człowiek dostaje wtedy także powiadomienie „X zaczyna Cię obserwować" po
zablokowaniu. Na jednym połączeniu tego nie widać: `NotifyUser` ma własny
filtr blokad i odczytuje relację jeszcze raz, już po zapisie żądania B, więc
powiadomienie było wyciszane. Ta teza zostaje **niepotwierdzona i możliwa
zarazem** — przy dwóch prawdziwych połączeniach odczyt `NotifyUser` też
mógłby nie zobaczyć jeszcze niezatwierdzonej blokady. Nie sprzedajemy jej
jako zmierzonej.

### Dlaczego to jest granica prywatności, nie kosmetyka

Blokada w tym serwisie ma jedno zadanie: żeby ktoś przestał widzieć moje
rzeczy i przestał się pojawiać w moim życiu. Zostawione obserwowanie znaczy,
że zablokowana osoba dalej dostaje moje wpisy w swoim **feedzie
obserwowanych** — czyli blokada nie zrobiła tej jednej rzeczy, po którą
człowiek po nią sięgnął. Reszta filtrów widoczności (`visibleTo`,
wyszukiwarka ludzi, listy obserwujących) stoi na założeniu, że relacja
blokady jest **ostateczna**.

I to nie jest wyścig o milisekundy: człowiek blokuje kogoś zwykle **w momencie
konfliktu**, czyli dokładnie wtedy, gdy druga strona jest aktywna i klika.
Dwie osoby robiące coś naraz w tej samej sprawie, nie zbieg okoliczności.

### Decyzja

**1. Obie operacje na parze osób wchodzą przez jedno gardło —
`App\Domain\Social\ZamekPary`.** Transakcja plus `SELECT … FOR UPDATE` na
wierszach OBU kont. Wiersze kont, nie wiersz relacji: wiersza relacji może
nie być, a `FOR UPDATE` na nieistniejącym wierszu nie blokuje niczego i nie
powstrzyma cudzego `INSERT`-a (ten sam powód co w `ZamekKonta`).

**2. NAJWAŻNIEJSZA RZECZ W CAŁEJ TEJ ZMIANIE: przy dwóch wierszach kolejność
blokowania musi być ustalona przez DANE, nie przez wywołanie.** Wiersze są
blokowane **rosnąco po identyfikatorze**. `ZamekKonta` (D-079) tego pytania
nie rozstrzyga i nie mógł — tam jest jeden wiersz, więc nie ma czego
szeregować. Tutaj wierszy są dwa, i gdyby każda operacja brała je w kolejności
swoich argumentów, dwie równoległe operacje na tej samej parze w przeciwnych
kierunkach zakleszczyłyby się nawzajem:

```text
żądanie A (Basia → Marek):  blokuje wiersz Basi,  czeka na Marka
żądanie B (Marek → Basia):  blokuje wiersz Marka, czeka na Basię
```

PostgreSQL wykryłby to po `deadlock_timeout` i **zabiłby jedną transakcję** —
człowiek zobaczyłby błąd serwera zamiast założonej blokady. A „Basia blokuje
Marka" i „Marek obserwuje Basię" w tej samej sekundzie to dokładnie ten
scenariusz, o który w tym zadaniu chodzi. Kolejność stoi w JEDNYM miejscu, bo
dwie kopie tej samej reguły rozjadą się przy pierwszej zmianie. Pilnuje jej
`ZamekParyTest::test_kolejnosc_blokad_nie_zalezy_od_kolejnosci_argumentow` —
sprawdzany **wprost, przez podejrzenie wykonanych zapytań**, bo złamanie
kolejności nie objawia się złym wynikiem, tylko zakleszczeniem, którego żaden
test sekwencyjny nie zobaczy.

**3. Blokady wierszy brane są dwoma osobnymi zapytaniami, nie jednym
z `ORDER BY`.** `WHERE id IN (a, b) ORDER BY id FOR UPDATE` blokuje wiersze
w kolejności, w jakiej wypuszcza je plan (`LockRows` nad `Sort`) — w praktyce
dobrze, ale to zależy od planu, a plan od statystyk i wersji bazy. Gwarancja
trzymająca się na kształcie planu nie jest gwarancją.

**4. Warunek jest sprawdzany PONOWNIE pod blokadą.** Blokada tylko ustawia
w kolejce; nie mówi żądaniu A, że świat zmienił się, gdy ono czekało.
Sprawdzenie przed blokadą **zostaje** — służy taniej odmowie bez transakcji
w najczęstszym przypadku (ktoś klika „Obserwuj" u osoby, którą już
zablokował). Oba komunikaty są **identyczne**: człowiek nie ma prawa
dowiedzieć się z treści zdania, czy trafił w wyścig.

**5. Powiadomienie o nowym obserwującym powstaje POD blokadą**, w tej samej
transakcji co wiersz `follows` — albo są oba, albo nie ma żadnego.
`NotifyUser` tylko zapisuje do bazy (nie wysyła poczty, nie kolejkuje
zadania), więc wejście z nim do transakcji nic nie kosztuje i nie wysyła
niczego przed `COMMIT`-em.

**6. Do tego twarda bariera w bazie: wyzwalacz
`follows_blokada_ma_pierwszenstwo_trg`** (`BEFORE INSERT ON follows`).
Odrzuca zapis, jeśli dla tej pary istnieje **zatwierdzona** blokada
w którąkolwiek stronę. Zasada z D-079 §4 bez zmian: `exists()` w PHP jest
dobre na ładny komunikat, gwarancję daje constraint albo lock.

Bariera i blokada **nie zastępują się wzajemnie i trzeba obu**:

| | pilnuje | nie pilnuje |
|---|---|---|
| wyzwalacz | każdej DROGI ZAPISU — druga akcja dopisana za pół roku, komenda, seeder, ręczny `INSERT` w psql | równoległości: przy `READ COMMITTED` nie widzi blokady jeszcze niezatwierdzonej |
| `ZamekPary` | RÓWNOLEGŁOŚCI dwóch żądań na tej samej parze | dróg zapisu, które go omijają |

### Czego świadomie NIE zrobiliśmy

**`CHECK` ani `EXCLUDE` zamiast wyzwalacza.** Inwariant dotyczy DWÓCH tabel,
a `CHECK` w PostgreSQL ma prawo patrzeć tylko na sprawdzany wiersz
(podzapytanie jest zabronione, a `CHECK` na funkcji czytającej drugą tabelę
nie jest wymuszany przy zmianie tamtej tabeli i zawodzi przy
`pg_restore`). `EXCLUDE` działa w obrębie jednej tabeli.

**Symetrycznego wyzwalacza na `blocks` NIE MA — i to jest decyzja, nie
przeoczenie.** Obie tabele nie są równorzędne: **blokada musi się udać
zawsze.** To jedyna czynność, jaką człowiek ma, gdy ktoś staje się dla niego
problemem, a bariera potrafiąca jej ODMÓWIĆ (bo istnieje jakiś wiersz
`follows`) byłaby zamkniętymi drzwiami w najgorszym możliwym momencie.
Konflikt na tej stronie rozstrzyga `BlockUser`, kasując obserwowanie w obie
strony pod blokadą wierszy — jawnie, w kodzie, który da się przeczytać.
Wyzwalacz, który zamiast odmawiać cicho KASOWAŁBY wiersze w drugiej tabeli,
byłby jeszcze gorszy: ukryta mutacja za plecami wywołującego zamienia każdą
przyszłą sesję debugowania w zgadywanie.

**Migracja nie sprząta danych istniejących.** Gdyby na produkcji leżał już
wiersz-sierota z tego wyścigu, wyzwalacz go nie ruszy — pilnuje nowych
zapisów. Kasowanie relacji społecznych migracją, bez wglądu w to, co zostało
skasowane, jest tą destrukcyjną operacją, której zabrania `AGENTS.md` §6.
Zapytanie diagnostyczne jest w `docs/DATABASE.md`; sprzątanie to osobna,
jawna decyzja.

**Nie ruszaliśmy `visibleTo` ani wyszukiwarki ludzi** — one czytają relację
blokady i są poprawne. Nie ruszaliśmy `ZamekKonta`; `ZamekPary` jest osobną
klasą w osobnej domenie, bo szereguje coś innego (parę, nie konto) i ma
regułę, której `ZamekKonta` nie ma (kolejność).

### Skutek uboczny, który wyszedł za darmo

Znalezisko P2 „równoległe podwójne follow powinno być idempotentne" jest
zamknięte przy okazji. Wcześniej dwa równoległe kliknięcia „Obserwuj" oba
widziały „nie obserwuję" i oba robiły `attach`, więc drugie dostawało
naruszenie klucza głównego `follows` — błąd serwera za powtórzone kliknięcie.
Teraz drugie żądanie czyta stan po pierwszym i zwraca `false`, a kontroler
mówi „Już obserwujesz tę osobę." Pilnuje tego
`test_powtorne_obserwowanie_nie_dubluje_wiersza`.

### Plan rollbacku

`down()` migracji zdejmuje wyzwalacz i funkcję. Jest bezstratny — nie zmienia
danych — i wolno go wykonać na produkcji pod ruchem: żaden kod nie zależy od
wyzwalacza, a gwarancję dla drogi przez `FollowUser` trzyma dalej
`ZamekPary`. Cofnięcie samego `ZamekPary` wymaga rewertu commita.

**Zmiana wymaga:** zmierzonego kosztu wyzwalacza przy zapisie do `follows`
(dziś to jedno indeksowane `EXISTS` na kliknięcie „Obserwuj", a `FollowUser`
i tak wykonuje to samo pytanie) albo przypadku, w którym blokada dwóch
wierszy kont okazuje się zbyt szeroka. Kolejność blokad rosnąco po
identyfikatorze **nie podlega zmianie bez zmiany jej we WSZYSTKICH miejscach
naraz** — połowiczna zmiana daje zakleszczenia.

📄 `app/Domain/Social/ZamekPary.php` · `app/Domain/Social/Actions/FollowUser.php` ·
`database/migrations/2026_09_10_400000_obserwowanie_nie_wspolistnieje_z_blokada.php` ·
`tests/Feature/BlokadaWygrywaZObserwowaniemTest.php` ·
`tests/Feature/ZamekParyTest.php` · `docs/DATABASE.md`
