# Harmonogram: kody wyjścia komend — #835

## Zmiana

Wszystkie 19 komend w `routes/console.php` korzysta z
`App\Support\ScheduledArtisanCommand::artisan()`. Adapter wykonuje komendę w tym samym
procesie PHP i zwraca `Artisan::call(...) === 0`. Laravel `CallbackEvent`
uznaje wyłącznie `false` za porażkę; liczby 1 i 2 były sukcesem. Wyjątki
pozostają wyjątkami. Nazwy, terminy i `withoutOverlapping()` nie zmieniają się.
Nie wymaga to `proc_open`, migracji ani dodatkowego pakietu.

## Własne pomiary — 20.09.2026

Punkt odniesienia: `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`.
Zastane, niezacommitowane zmiany odłożono poza repo, a zachowanie zmierzono
na oryginalnym `routes/console.php` z tego commita przed wprowadzeniem poprawki.

- Na bazowym harmonogramie test był czerwony dla kodów **1, 2 i 137**:
  zdarzenie miało `exitCode=0` zamiast 1. Sukces i wyjątek przechodziły.
- Po poprawce: `HarmonogramSprawdzaKodWyjsciaTest` — **11 testów, 1088 asercji**.
  Pięć scenariuszy używa rzeczywistej komendy konsolowej podmienionej w miejscu
  `kuking:sprawdz-kolejke` oraz faktycznie zarejestrowanego zdarzenia.
  Strażnik dodatkowo wykonuje wszystkie 19 zarejestrowanych zdarzeń przy kodach
  0, 1, 2, 137 i wyjątku. Kernel komend jest tu atrapą, więc żadne zadanie
  domenowe ani wysyłka nie jest wykonywana.
- Sprawdzono `exitCode`, `onSuccess`, `onFailure`, propagację wyjątku,
  zwolnienie blokady po każdym wyniku i brak wywołania przy zajętej blokadzie.
  Blokada jest atrapą `EventMutex`: to nie jest test współbieżności PostgreSQL.
- Kontrola dodatnia strażnika wymaga co najmniej 19 zdarzeń, trzech nazw
  kotwiczących oraz dokładnie jednego wywołania komendy w każdym zdarzeniu.
- `scripts/kontrola-ujemna.sh`: usunięcie `=== 0` oblało właściwe asercje.
  Dopisanie dwudziestego wadliwego callbacku też oblało strażnika, z nazwą
  `test:dwudziesty`. Oba przebiegi: PASS → FAIL → PASS; przywrócone MD5 i mtime.

Runtime: `/home/mateusz/flota/gpt-harmonogram-run`; baza testowa
`kuking_flota_gpt-harmonogram`, właściciel `kuking`, `127.0.0.1:55439`.
Nazwa stanowiska w skryptach to `gpt-harmonogram`, bo skrypt wyprowadza z niej
ścieżkę worktree. Z podanym skrótem `harmonogram` wskazywałby nieistniejący katalog.

## Granice i wycofanie

Status zdarzenia nie jest dowodem dostarczenia alarmu. Nie uruchamiano komend
produkcyjnych ani webhooków. Nie zmieniano konfiguracji monitoringu.
Wycofanie: odwrócić commit adaptera i tras; brak zmian danych. Przywróci to
również błędne rozpoznawanie niezerowego kodu jako sukcesu.

Osobne pytanie właściciela, bez zmiany zachowania w tym zadaniu:
`CleanUpDataExports` zwraca `SUCCESS` także przy częściowo nieudanym usuwaniu
(odczyt kodu, bez pomiaru tego scenariusza). Wariant pozostawienia kodu 0
zachowuje ponawianie i istniejące logi, ale nie daje porażki harmonogramu.
Wariant kodu 1 przy choć jednej porażce daje tę widoczność, kosztem częstszych
sygnałów przy przejściowym problemie storage. Adapter nie wybiera tego kontraktu
za właściciela i nie zmienia go asercją.

## Kontrola końcowa pakietu #835 / #809

Własny szeroki przebieg: **4396 testów, 84 582 asercje, 342,39 s**, bez porażek.
Pominięto wyłącznie `ProbaOdtworzeniaTest`, zgodnie z jawną instrukcją floty:
używa wspólnej bazy `kuking_zrodlo_proby_glowny`. Nie uruchamiano go na tej bazie.
Po tym przebiegu zmieniono nazwę adaptera na angielską `ScheduledArtisanCommand`
oraz zawężono odczyt odnośników sondy do link/script z prawdziwym src/href.
Dodatkowy czerwony test wykrył private bez spacji po dwukropku nagłówka;
po normalizacji dyrektyw ten scenariusz również przechodzi.
Na ostatecznym kodzie: filtr `Harmonogram` — **18 testów / 1134 asercje**,
`SondaCacheAssetow` — **2 testy / 7 asercji**, obejmujące **12 scenariuszy powłoki**.
Ponownie przeszły wszystkie cztery kontrole ujemne z przywróceniem MD5 i mtime.
Pint przeszedł dla wszystkich zmienionych plików PHP. Nie uruchamiano całego
`scripts/check.sh` ani zdalnego CI (zakaz push); osobno sprawdzono składnię Bash
zmienionych skryptów. Nie ma migracji ani zmian UI.

Wszystkie wyniki w tym raporcie są własnymi pomiarami tej sesji; opis
częściowego niepowodzenia eksportów jest jawnie oznaczonym odczytem kodu.
