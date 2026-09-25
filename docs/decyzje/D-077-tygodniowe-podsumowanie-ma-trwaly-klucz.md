## D-077 · Tygodniowe podsumowanie ma trwały klucz idempotencji w bazie: rezerwacja `(osoba, tydzień)` PRZED wysłaniem, a przy awarii wolimy pominięcie niż duplikat

**Data:** 10 września 2026 · Audyt drugiej warstwy QUEUE-01 / MAIL-02 /
RACE-04 (P1) · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Rdzeń obowiązuje i ma
> pokrycie: rezerwacja `(osoba, tydzień)` PRZED `Mail::queue()`, klucz główny
> `(user_id, week_start)` i `CHECK` na poniedziałek
> (`database/migrations/2026_09_10_400000_create_weekly_digest_sends_table.php:146,152`).
> Nieaktualna jest §8 „Czego ta decyzja NIE dotyka". Zdanie
> „**`DziennyBudzetListow` zostaje bez zmian** … pętla woła budżet tak jak
> dotąd, **po udanej rezerwacji tygodnia**" opisuje odwrotną kolejność niż
> kod: `app/Console/Commands/WyslijPodsumowaniaTygodnia.php:226` rezerwuje
> budżet dobowy, `:282` dopiero tydzień, a `:287` zwalnia miejsce przy
> niepowodzeniu — pod nagłówkiem „KOLEJNOŚĆ TYCH DWÓCH REZERWACJI JEST
> MERYTORYCZNA" (`:233-254`), który tę zmianę uzasadnia. Sam
> `DziennyBudzetListow` też „bez zmian" nie został — D-076 dołożyła mu
> `sprobujZarezerwowac()` i `zwolnij()`.

### 1. Co dokładnie było zepsute — kolejność, nie brak sprawdzenia

`WyslijPodsumowaniaTygodnia` robiło dla każdej osoby w pętli:

```text
1. Mail::to(...)->queue($list)    ← SKUTEK ZEWNĘTRZNY JUŻ SIĘ STAŁ
2. $budzetDnia->zajmij()
3. sygnał WEEKLY_DIGEST_SENT
4. $wyslane[] = $osoba
```

a `OdbiorcyDigestu::oznaczWyslane($wyslane)` — jedyny zapis mówiący „ta osoba
jest obsłużona" — wykonywało się **dopiero po całej pętli**, jednym
zapytaniem. Awaria po zakolejkowaniu N wiadomości, ale przed tym zbiorczym
zapisem, zostawiała N listów w kolejce i **zero** śladu w bazie. Następny
przebieg kwalifikował te same osoby ponownie i pisał do nich drugi raz.

Okno tej awarii miało rozmiar CAŁEJ PACZKI — do sześćdziesięciu osób
(`kuking.digest.dzienny_limit`) — i nie było hipotetyczne: wysyłka trwa około
czterdziestu minut (odstęp 20 s na list), chodzi w tym samym procesie co
serwer WWW (`Schedule::call()`, bo `proc_open` jest wyłączone) i mieszka na
kontenerze Railway, który wolno zrestartować w każdej chwili.

**`withoutOverlapping()` tego nie chronił i nigdy nie chronił.** Zapobiega
dwóm przebiegom JEDNOCZEŚNIE, a duplikat powodował przebieg KOLEJNY — po
awarii. To jest różnica, którą łatwo przeczytać jako „już się tym zajęliśmy",
i komentarz w `routes/console.php` faktycznie tak brzmiał. Został poprawiony.

### 2. Dlaczego to boli bardziej niż zwykły duplikat

Ekran `/ustawienia/prywatnosc` obiecuje **jeden e-mail tygodniowo, nigdy
więcej**. Ta obietnica jest złożona ludziom 50+, którzy nie chcą, żeby serwis
ich zasypywał, i którzy przy drugim identycznym liście w tym samym tygodniu
mają prawo pomyśleć, że coś jest zepsute albo że to spam. Digest jest do tego
funkcją **na zgodę** (art. 6 ust. 1 lit. a RODO): wysyłka ponad obiecany rytm
podważa to, na co ktoś się zgodził, a nie tylko psuje wrażenie.

Ma to też cenę techniczną, której nie widać z ekranu: każdy duplikat zjada
list z wiadra 300 na dobę, dzielonego z potwierdzeniami rejestracji (D-057).
Duplikat biuletynu potrafi więc zamknąć komuś rejestrację.

### 3. Decyzja: bariera w bazie, nie sprawdzenie w PHP

Nowa tabela `weekly_digest_sends` z **kluczem głównym (a więc unikalnym) na
parze `(user_id, week_start)`** i wiersz zajmowany **przed** `Mail::queue()`:

```text
1. INSERT weekly_digest_sends (osoba, poniedziałek tygodnia)
   + UPDATE users.weekly_digest_sent_at       ← JEDNA TRANSAKCJA
2. dopiero teraz Mail::to(...)->queue($list)
3. budżet, sygnał
```

Konflikt unikalności znaczy „ta osoba ma ten okres obsłużony" i wtedy po
prostu ją pomijamy — bez błędu, bez listu, z jednym zdaniem na wyjściu
komendy, bo to jedyny moment, w którym widać, że poprzedni przebieg nie
doszedł do końca.

**Dlaczego constraint, a nie `exists()`.** Sprawdzenie w PHP jest odczytem,
po którym następuje zapis, a między nimi jest luka. Dwa przebiegi (dwa
kontenery, albo ręczny przebieg właściciela obok harmonogramu po wygaśnięciu
blokady) przechodzą oba przez ten sam `SELECT`, oba widzą „jeszcze nie
wysłano" i oba wysyłają — to jest RACE-04. `UNIQUE` tej luki nie ma. `exists()`
w PHP zostaje, ale jako sposób na ŁADNE zachowanie, nie jako gwarancja.

**Dlaczego OBIE warstwy zostają.** `weekly_digest_sends` mówi „najwyżej jeden
list na tydzień kalendarzowy", `users.weekly_digest_sent_at` — „nie częściej
niż raz na siedem dni" plus kolejność „kto czeka najdłużej". Sam tydzień
kalendarzowy pozwoliłby na list w niedzielę i w poniedziałek; sam odstęp jest
porównaniem z luką. Kolumna nadal istnieje i nadal jest potrzebna — zmieniło
się to, że jest zapisywana **osobno dla każdej osoby i przed wysłaniem**.

### 4. Okres to DATA PONIEDZIAŁKU w strefie człowieka, nie numer tygodnia ISO

Numer tygodnia sam z siebie nie jest identyfikatorem: `2026-12-28` należy do
tygodnia 1 **roku 2027**, więc numer wymaga pary (rok ISO, tydzień) — a klucz
idempotencji zapisany niepełny przestaje być unikalny. Data poniedziałku to
jedna kolumna `date`: porównywalna, sortowalna, czytelna w zrzucie bazy
i zgodna z tym, co w PostgreSQL znaczy `date_trunc('week', …)` (tygodnie
Postgresa zaczynają się w poniedziałek).

Liczy ją `App\Support\Czas::poczatekTygodniaData()`, a nie `now()`, i to nie
jest formalność: `app.timezone` musi zostać UTC (patrz komentarz klasy
`Czas`), a poniedziałek UTC zaczyna się w Polsce w niedzielę o 22:00.
Przebieg uruchomiony w poniedziałek nad ranem trafiałby więc do tygodnia
POPRZEDNIEGO — czyli do klucza, który dla części osób jest już zajęty.

Tydzień jest liczony **raz na cały przebieg**, przed pętlą. Gdyby każda osoba
pytała o „teraz" osobno, paczka schodząca przez północ z niedzieli na
poniedziałek rozpadłaby się na dwa różne klucze.

Do tego CHECK w bazie: `extract(isodow from week_start) = 1`. Bez niego data
ze środka tygodnia dałaby tej samej osobie dwa różne, oba wolne klucze
w jednym tygodniu — czyli dwa listy przy nietkniętym `UNIQUE`. Bariera bez
tego CHECK-a broni się przed powtórzeniem, ale nie przed pomyłką w kluczu.

**Kalendarzowy tydzień nikogo nie opóźnia.** Dzień `x` i dzień `x + 7` zawsze
mają różne poniedziałki, więc bariera nie blokuje wysyłki, na którą odstęp
siedmiu dni już pozwala. Dwie warstwy razem dają zdanie mocniejsze niż każda
z osobna: **najwyżej jeden list na tydzień kalendarzowy i nie częściej niż raz
na siedem dni.**

### 5. Wybór, którego nie da się uniknąć: rezerwacja została, wysyłka padła

To jest prawdziwy rozstrzygnięty wybór, nie szczegół implementacji, więc jest
nazwany wprost:

> **Rezerwacja ZOSTAJE. Ta osoba nie dostaje listu za ten tydzień.**

Nie da się mieć naraz „nikt nie dostanie dwa razy" i „nikt nie zostanie
pominięty", bo po wyjściu z `Mail::queue()` nie wiemy, czy wiadomość weszła do
kolejki. Wycofanie rezerwacji przy złapanym wyjątku wyglądałoby na
ostrożność, a byłoby przywróceniem usterki dokładnie w tym jednym przypadku,
w którym stan jest niejednoznaczny — a niejednoznaczny jest zawsze, bo proces
może padnąć MIĘDZY udanym `queue()` a naszym `catch`.

Uzasadnienie kierunku, a nie przemilczenie:

1. **Przy tygodniowym podsumowaniu pominięcie jest odwracalne, a duplikat
   nie.** Kto nie dostał listu, dostanie go za tydzień i najprawdopodobniej
   nie zauważy — treść to trzy pozycje z ostatnich siedmiu dni, nie termin
   ani nie decyzja. List wysłany drugi raz jest u człowieka w skrzynce na
   zawsze.
2. **Digest jest funkcją powrotu, nie funkcją krytyczną.** Nic się nie psuje
   w serwisie, gdy list nie przyjdzie. Psuje się, gdy przyjdzie dwa razy.
3. **Ta strona pomyłki jest już wybrana w tym samym miejscu** — przy
   `oznaczWyslane()` stoi od D-057: „lepiej, żeby ktoś dostał o jeden list za
   mało, niż żeby dostał trzy". Odwrócenie jej tylko przy awarii dałoby dwie
   sprzeczne reguły w jednej pętli.
4. **Skala jest znana i mała.** Pominięcie dotyczy najwyżej tych osób, dla
   których przebieg padł — nie całej paczki, bo rezerwacja jest per osoba.
   Wcześniej duplikat dotyczył wszystkich obsłużonych do momentu awarii.

Świadoma cena: nie ma sposobu, żeby dowiedzieć się z bazy, którym osobom list
przepadł — wiersz rezerwacji wygląda identycznie dla „wysłano" i dla „padło
po rezerwacji". Rozróżnienie wymagałoby stanu wiersza i potwierdzeń doręczenia
od dostawcy, czyli dokładnie tego, czego produkt nie chce (#204). Widać za to
liczbę: komenda wypisuje, ile osób pominięto jako już obsłużone.

### 6. Dlaczego NIE transactional outbox

Outbox rozwiązuje inny problem: **at-least-once** przy niepewnym transporcie
(zapisz zamiar w tej samej transakcji co dane, osobny proces dowozi i ponawia).
Tutaj potrzebne jest **at-most-once na parę (osoba, tydzień)** — i to daje
jeden indeks unikalny, bez ani jednej nowej ruchomej części.

Co by doszło z outboxem: tabela z zamiarem wysyłki, proces ją opróżniający
(a więc druga kolejka przed kolejką Laravela), retencja tej tabeli, obsługa
zamiarów zawieszonych i nowy tryb awarii „outbox rośnie, nikt nie zauważył".
Za to nie doszłoby ani jedno powiadomienie więcej: pominięcie po awarii
zostaje pominięciem, bo o ponawianiu listu rozstrzyga §5, a nie mechanizm.

AGENTS.md §3 mówi wprost: bez zmierzonej, udokumentowanej potrzeby nie
dokładamy mechanizmów. Pomiaru mówiącego, że tracimy listy w kolejce, nie ma
— jest pomiar mówiący, że wysyłamy je dwa razy. Na to wystarcza `UNIQUE`.

**Zmiana wymaga:** zmierzonej straty listów w kolejce (np. z `failed_jobs`
poczty, PR #253), której nie da się przyjąć jako „ta osoba czeka tydzień".

### 7. Rollback — i dlaczego `down()` nie przywraca stanu groźnego po cichu

`down()` kasuje tabelę. Nie ginie ani jedno słowo od człowieka i nie ginie
pamięć o wysyłce (`users.weekly_digest_sent_at` zostaje) — ale **ginie
bariera**. Po wycofaniu jedyną ochroną przed drugim listem zostaje porównanie
w PHP, czyli dokładnie ten mechanizm, którego luka jest powodem tej migracji.
Stan po rollbacku jest więc stanem sprzed poprawki, tylko z mniejszym oknem
awarii (znacznik jest już zapisywany per osoba, nie po pętli).

Dlatego:

- rollback robi się **wyłącznie razem z `KUKING_DIGEST_WLACZONY=false`**,
  nigdy „przy okazji" innej zmiany;
- kolejność: **najpierw kod, potem migracja.** Nowy kod bez tabeli pada na
  pierwszej osobie i nie wysyła nikomu nic — kierunek awarii bezpieczny, ale
  wysyłka staje, więc wycofanie samej migracji jest wyłączeniem digestu
  okrężną drogą. Do wyłączania jest zmienna środowiskowa.

### 8. Czego ta decyzja NIE dotyka

- **`DziennyBudzetListow` zostaje bez zmian.** Atomowa rezerwacja dobowego
  budżetu jest osobnym zadaniem (gałąź `claude/atomowy-budzet-listow`);
  pętla woła budżet tak jak dotąd, po udanej rezerwacji tygodnia.
- **Treść listu i harmonogram** (codziennie 08:30) — nietknięte.
- **`KUKING_DIGEST_WLACZONY`** nadal domyślnie `false`; włącza właściciel.
- **Żadnego śledzenia otwarć ani doręczeń** — otwarta sprawa #204, produkt
  świadomie tego nie chce. Nowa tabela nie jest do tego furtką: nie ma w niej
  stanu wiersza ani niczego o doręczeniu.
- **Droga listu próbnego `--tylko` omija barierę świadomie.** Flaga istnieje,
  żeby właściciel zobaczył list TERAZ, i już dziś pomija odstęp tygodniowy.
  Gdyby zajmowała klucz tygodnia, drugi list próbny w tym samym tygodniu byłby
  niemożliwy, a konto użyte do próby straciłoby prawdziwe podsumowanie.
  Bariera pilnuje wysyłki masowej; jednego adresu wpisanego ręcznie w konsoli
  pilnuje człowiek, który tę komendę wpisał.

📄 `database/migrations/2026_09_10_400000_create_weekly_digest_sends_table.php` ·
`app/Domain/Digest/OdbiorcyDigestu.php` ·
`app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`app/Support/Czas.php` · `routes/console.php` ·
`tests/Feature/DigestNieWysylaDwaRazyTest.php` ·
`docs/DATABASE.md` (sekcja `weekly_digest_sends`) ·
D-057 · audyt `docs/research/audyt-2026-09-10/` (QUEUE-01, MAIL-02, RACE-04)
