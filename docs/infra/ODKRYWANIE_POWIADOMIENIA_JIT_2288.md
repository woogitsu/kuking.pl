# „Świeżo z Kuking”, powiadomienia i JIT; `statement_timeout` HTTP (#2288, #2289, #2290, #2291)

Poprawki do audytu `docs/audyt/2026-09-30-wydajnosc-baza.md` (gałąź `claude/audyt-2909-wydajnosc-baza`),
znaleziska F1–F4. Wzorzec: #599 (`FEED_OBSERWOWANYCH_JIT_599.md`).

## F1: `DiscoverFeed` (#2288)

**Przyczyna.** Tak samo jak w #599. W podzapytaniu rotacji skorelowane `EXISTS` przepisu wpisu
i zdjęcia wpisu stały w alternatywie (`OR`), a planer naliczał je za każdy kandydujący wpis.
W audycie szacunek wynosił 525–528 tys. przy progu `jit_above_cost` 100 tys., a JIT zajmował 1,3–2,5 s
z 1,4–2,8 s wykonania.

**Zmiana.** W `app/Domain/Feed/DiscoverFeed.php` są dwie zmiany:

- `zWidocznymPrzepisemAlboWlasnaTrescia()` zastąpiono istniejącym
  `zWidocznymPrzepisemAlboWlasnaTresciBezKorelacji()` z #599. Postać `IN (podzapytanie)` daje ten sam wynik.
- `bezUkrytychOsob($viewer, bezKorelacji: true)`, czyli `NOT IN` z jawnym `IS NOT NULL`.

Reguły widoczności się nie zmieniły. Stare postacie scope’ów zostają dla profilu, tagu i tablicy.

**Wynik w teście** (`tests/Feature/OdkrywanieKosztPlanuTest.php`, skala #605: 6000 wpisów,
20 000 przepisów, 361 kont, ukrycia, blokady w obie strony, konta zbanowane i zawieszone):

| Widz | Najwyższy szacunek przed | Po |
|---|---:|---:|
| gość (`/`, `/odkryj`) | 85 378 | 2 199 |
| zalogowany (`/odkryj`) | 133 526 | 3 062 |
| zalogowany, Start z własnymi | nie zmierzono osobno (test zatrzymał się wcześniej, na wierszu wyżej) | 3 092 |

Test sprawdza dwie rzeczy:

1. Najwyższy koszt SELECT-ów jednego `paginate()` jest poniżej `jit_above_cost` i poniżej jego piątej
   części. Przy samym progu stara postać gościa (85 tys.) by przeszła, a na 30 tys. wpisów audyt zmierzył
   317 tys.
2. Lista jest ta sama co ze starego zapytania. Porównanie idzie na scenie z pułapkami
   (przepisy prywatne, „dla obserwujących”, szkic i usunięty przepis, własna treść z samym zdjęciem,
   ukrycia aktywne i wygasłe, blokady, konto zawieszone i zbanowane), dla pięciu widzów, przy dwóch
   rozmiarach strony kursora i przy skali (trzy strony).

Kontrole ujemne:

- przywrócenie starego scope’u: test kosztu oblewa (85 378 ≥ 20 000);
- usunięcie `IS NOT NULL`: oblewa test zgodności listy;
- wyłączenie gałęzi zdjęcia: oblewa test zgodności listy.

Samo cofnięcie `bezKorelacji` przy ukrytych osobach testu nie wywraca (koszt 3 471). Obie postacie
dają ten sam wynik, więc to tylko drobna oszczędność i nic więcej go nie pilnuje.

## F2: `/powiadomienia` (#2289)

W `OdczytPowiadomien::strona()` jest teraz `simplePaginate()` zamiast `paginate()`. Lista pokazuje
tylko „Następna strona powiadomień”, więc pełne `COUNT(*)` było zbędne. Nowe
`OdczytPowiadomien::saNieprzeczytane()` (`EXISTS`) zastąpiło w kontrolerze
`unreadNotificationsCount() > 0`. To zapytanie przycisk „Oznacz wszystkie” wysyła, gdy strona ma same
przeczytane.

Test `tests/Feature/PowiadomieniaKosztPlanuTest.php` bierze 2000 widocznych powiadomień i sprawdza cały
`GET /powiadomienia`. Najwyższy szacunek wynosi 24 113. Kontrole ujemne: powrót do `paginate()` daje
453 033, a powrót do `COUNT` w kontrolerze daje 271 933. W obu przypadkach test oblewa. Drugi test
sprawdza, że 65 powiadomień rozkłada się na trzy strony bez zgubionych i powtórzonych wierszy
i w tej samej kolejności, i że ostatnia strona nie ma przycisku dalej.

Bez zmian zostają `User::unreadNotificationsCount()` i plakietka. Plakietka ma sufit od B4 S1.

## F3: `statement_timeout` tylko dla HTTP (#2290)

Limit ustawia `App\Support\Baza\LimitCzasuZapytanHttp` razem z globalnym middleware
`App\Http\Middleware\UstawLimitCzasuZapytan` (piąty w stosie globalnym, przed grupą `web`):

- na początku żądania wysyła `SET statement_timeout = <ms>` na połączeniach PostgreSQL, które już
  są otwarte. Nowe połączenia i połączenia po zerwaniu dostają to samo przez `ConnectionEstablished`;
- na końcu żądania wysyła `RESET`;
- polecenia idą przez surowe PDO, więc `SET` nie wlicza się do sumy czasu SQL (`CzasZapytan`) ani do
  liczników zapytań w testach.

Wartość: `kuking.polaczenia.limit_zapytania_http_ms` (`KUKING_POLACZENIA_LIMIT_ZAPYTANIA_HTTP_MS`),
domyślnie **15 000 ms**, 0 wyłącza limit.

Worker, harmonogram i migracje nie przechodzą przez kernel HTTP, więc limitu nie dostają. Migracje
mają osobno `lock_timeout` (`LimitBlokadMigracji`).

Przerwane zapytanie kończy się błędem SQLSTATE `57014`. Osoba widzi wtedy ekran
`errors/zapytanie-za-dlugo` (503, `Retry-After: 30`): „Strona ładuje się za długo… Odczekaj pół
minuty i otwórz ją jeszcze raz.” Ekran pokazuje też kod błędu. Wyjątek dalej idzie do raportu
i alarmu. Żądania JSON zostają na domyślnej ścieżce.

Test `tests/Feature/LimitCzasuZapytanHttpTest.php` sprawdza pięć rzeczy:

- żądanie widzi `15s`;
- po żądaniu połączenie ma znów `0`;
- nowe połączenie otwarte w trakcie żądania też dostaje limit;
- komenda konsoli widzi `0`, a wartość 0 wyłącza limit;
- `pg_sleep(3)` przy limicie 300 ms daje 503 z polskim tekstem w czasie poniżej 2,5 s.

Kontrole ujemne (każda oblewa co najmniej jeden test): usunięcie middleware ze stosu, usunięcie
słuchacza `ConnectionEstablished`, usunięcie renderowania, usunięcie `RESET`.

**PgBouncer (D-312).** W trybie transakcyjnym `SET` sesyjne przeciekałoby do cudzych żądań. Gdy
PgBouncer wejdzie, limit trzeba przenieść na `SET LOCAL` albo na osobną rolę bazy dla procesu web.

## F4: JIT globalnie (#2291) — decyzja właściciela, BEZ zmiany w kodzie

W tym audycie JIT za każdym razem kosztował więcej, niż oszczędzał. Pomiar z `SET jit = off`:

| Strona | JIT włączony | JIT wyłączony |
|---|---|---|
| `/odkryj` zalogowany | 2 008–2 652 ms | 116–150 ms |
| `/powiadomienia` | 523–682 ms | 6–14 ms |
| `/` gość | 149–317 ms | 125–146 ms |

F1 i F2 usuwają te trzy przypadki w kodzie. Każda nowa lista z regułami widoczności może jednak
znów przekroczyć próg, a zauważymy to dopiero po alarmie. To był już trzeci taki przypadek, po #585
i #599.

**Rekomendacja:** wyłączyć JIT dla roli, z której łączy się aplikacja. Ma to być siatka pod
poprawkami w kodzie, a nie ich zamiennik. Testy kosztu planu (#599, #2288, #2289) zostają i dalej
pilnują, żeby zapytania nie rosły.

Kroki właściciela:

1. Odczyt na produkcji: `SHOW jit; SHOW jit_above_cost; SELECT current_user;`
   (patrz `docs/flota/sesja-koordynatora-2909-b/HANDOVER.md`).
2. Jeśli `jit = on`, wykonać `ALTER ROLE <rola_aplikacji> SET jit = off;`. Ustawienie działa od
   następnego połączenia. W klasycznym trybie FrankenPHP każde żądanie łączy się od nowa, a worker
   kolejki trzeba zrestartować. Wariant ostrożniejszy to `ALTER ROLE … SET jit_above_cost = 1000000`.
3. Sprawdzić wynik z nowego połączenia: `SHOW jit` = `off`. Potem przez dobę obserwować alarm „wolna
   baza” (`CzasZapytan`, próg 1000 ms).
4. Wycofanie: `ALTER ROLE <rola_aplikacji> RESET jit;`

Nie zalecamy zmiany przez `options: -c jit=off` w `config/database.php`. Objęłaby także raporty,
które mogłyby na JIT zyskać. Ustawienie roli widać też w samej bazie, a nie tylko w kodzie.

## Środowisko pomiaru

Pomiary w teście robiono na lokalnym PostgreSQL 16 (127.0.0.1:5432; o rozjeździe wersji mówi F7
audytu), z domyślnymi `jit = on` i `jit_above_cost = 100000`, po `ANALYZE`. Testy mierzą szacunek
planu, a nie czas, więc nie zależą od szybkości maszyny CI.
