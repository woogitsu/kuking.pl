# Ekran „Twój zeszyt”: plany zapytań przed i po #2030

Pomiar z 28 września 2026 do issue [#2030](https://github.com/woogitsu/kuking.pl/issues/2030).
Mierzyłem `EXPLAIN (ANALYZE, BUFFERS)` każdego SELECT-a jednego wejścia na `/zeszyt`
(`CollectionController::index()`) na tych samych danych. „Przed” to kod bazy `dd05fbe26`,
„po” to gałąź `claude/2030-zeszyty-koszt`.

**Wynik w skrócie.** Wynik ekranu się nie zmienił: te same liczby na 200 kartach i te same
pięć pozycji szyny. W każdym przebiegu porównałem go 1:1 z dosłowną kopią starych zapytań.
Czas zapytań spadł z ok. 0,85–1,05 s do ok. 0,12–0,14 s, a bufory z ok. 152 tys. do
ok. 30 tys. Zysk ma dwa źródła:

1. liczniki kart nie wpadają już w kompilację JIT z inliningiem (ok. 0,6 s z ok. 0,8 s
   starego zapytania o listę),
2. szyna „Ostatnio zapisane” nie liczy `max()` ani widoczności dla całej historii zapisów.

## Dane i sposób pomiaru

- PostgreSQL 18.6 lokalnie, ustawienia domyślne (`jit = on`, `jit_above_cost = 100000`,
  `jit_inline_above_cost = 500000`, `jit_optimize_above_cost = 500000`), po `ANALYZE`.
- Konto mierzone ma **200 zeszytów**. W każdym leży 50 przepisów i 50 wpisów, razem
  **20 000 zapisów** z dwóch lat. Ten sam przepis leży średnio w 1,25 zeszytu.
- Tło to 100 innych kont × 5 zeszytów × 100 zapisów, czyli 50 000 zapisów.
  Tabela `collection_items` ma więc 70 000 wierszy.
- Treści pochodzą od 40 autorów: 8000 przepisów i 8000 wpisów. Co 10. jest prywatny,
  co 10. „dla obserwujących”, co 17. to szkic, a co 29. jest usunięty miękko. Dwóch autorów
  jest zbanowanych. Jeden jest zablokowany przez właściciela, drugi blokuje właściciela.
  Właściciel obserwuje 10 autorów.
- 40 najnowszych zapisów właściciela to rzeczy prywatne, których nie widzi. Szyna musi je
  ominąć, a przy nowym kodzie przepisy potrzebują przez to drugiej partii kandydatów.
- Plan liczę w `SAVEPOINT` tuż przed właściwym zapytaniem (`DB::beforeExecuting`), potem
  robię `ROLLBACK TO SAVEPOINT`. Harness jest w dodatku na końcu. Bufory to
  `shared hit + read` z węzła najwyższego.
- Kontrola w każdym przebiegu: `liczniki_jak_stary_kod = true` i `szyna_jak_stary_kod = true`.
  Po żądaniu harness wykonuje dosłowną kopię starych zapytań `index()`
  i `ostatnioZapisane()` i porównuje wynik z tym, co dostał widok.
- Czasy pochodzą z pojedynczych przebiegów na jednej maszynie (4 CPU, obciążonej innymi
  procesami). Liczą się rzędy wielkości. Tabele niżej pokazują przebieg środkowy z trzech.

## Przed (`dd05fbe26`)

| Nr | Zapytanie | Parametrów | Koszt (plan) | Czas wykonania | Bufory | JIT |
|---:|---|---:|---:|---:|---:|---|
| 1 | lista zeszytów + 4 liczniki `withCount` na każdy zeszyt | 30 | 995 386 | 819,1 ms | 51 680 | **632,9 ms, z inliningiem i optymalizacją** |
| 2 | szyna: przepisy (`max()` i widoczność wszystkich kandydatów, potem `limit 5`) | 11 | 201 751 | 64,6 ms | 32 474 | 21,3 ms |
| 3–4 | szyna: autorzy i profile przepisów | 5 | ≤ 6 | 0,0 ms | 7 | — |
| 5 | szyna: wpisy (jak wyżej) | 22 | 356 128 | 83,3 ms | 67 093 | 27,8 ms |
| 6–8 | szyna: zdjęcia, autorzy i profile wpisów | 5 | ≤ 6 | 0,1 ms | 29 | — |
| 9 | licznik powiadomień (układ strony, poza #2030) | 66 | 7 | 1,9 ms | 324 | — |
| | **suma** | | | **968,9 ms** | **151 607** | |

Trzy przebiegi dały sumy: 1051,2 / 968,9 / 850,9 ms oraz 173 945 / 151 607 / 151 616 buforów.
Pełne plany: [`pomiary/2030/plany-przed.txt`](pomiary/2030/plany-przed.txt).

Co widać w planach:

- **Zapytanie 1.** Każdy z czterech liczników to skorelowane podzapytanie wykonane 200 razy
  (`loops=200`). Rzeczywista praca to ok. 0,5 ms na zeszyt, czyli ok. 110 ms. Planer mnoży
  jednak koszt jednego podzapytania przez 200. Samo podzapytanie o widoczne wpisy ma
  szacunek ok. 3170 na zeszyt, bo filtr widoczności zawiera kilka podplanów. Szacunek
  całości, ok. 995 tys., przekracza `jit_inline_above_cost` i `jit_optimize_above_cost`.
  PostgreSQL kompiluje więc zapytanie przez LLVM z inliningiem i optymalizacją, a to
  zajmuje **580–700 ms**, znacznie dłużej niż właściwe liczenie.
- **Zapytania 2 i 5.** Szyna wybiera najpierw wszystkie rzeczy konta, które przechodzą
  filtr widoczności (ok. 5,6 tys. przepisów i tyle samo wpisów). Dla każdej z nich wykonuje
  skorelowane `max(collection_items.created_at)` (`loops=5585`, ok. 31,5 tys. buforów samego
  podzapytania w każdym z dwóch zapytań),
  sortuje całość (`top-N heapsort`) i dopiero wtedy bierze pięć.

## Po (`claude/2030-zeszyty-koszt`)

| Nr | Zapytanie | Parametrów | Koszt (plan) | Czas wykonania | Bufory | JIT |
|---:|---|---:|---:|---:|---:|---|
| 1 | lista zeszytów + 2 liczniki całości (`withTrashed`, bez zmian) | 1 | 203 897 | 48,4 ms | 2 161 | 11,5 ms, bez inliningu |
| 2 | liczniki widocznych przepisów, jedno zgrupowane zapytanie | 10 | 11 009 | 15,0 ms | 873 | — |
| 3 | liczniki widocznych wpisów, jedno zgrupowane zapytanie | 21 | 166 461 | 55,0 ms | 24 442 | 26,1 ms, bez inliningu |
| 4 | szyna, przepisy: pierwsza partia zapisów (21 wierszy) | 1 | 1 667 | 6,2 ms | 588 | — |
| 5 | szyna, przepisy: widoczność pierwszej partii | 20 | 99 | 0,1 ms | 28 | — |
| 6 | szyna, przepisy: druga partia zapisów (81 wierszy) | 3 | 2 071 | 6,7 ms | 463 | — |
| 7 | szyna, przepisy: widoczność drugiej partii | 84 | 299 | 0,3 ms | 104 | — |
| 8–9 | szyna: autorzy i profile pięciu przepisów | 5 | ≤ 6 | 0,0 ms | 7 | — |
| 10 | szyna, wpisy: pierwsza partia zapisów | 1 | 1 665 | 6,2 ms | 588 | — |
| 11 | szyna, wpisy: widoczność pierwszej partii | 40 | 625 | 0,2 ms | 51 | — |
| 12–14 | szyna: zdjęcia, autorzy i profile pięciu wpisów | 5 | ≤ 6 | 0,1 ms | 31 | — |
| 15 | licznik powiadomień (układ strony, poza #2030) | 66 | 7 | 0,9 ms | 324 | — |
| | **suma** | | | **139,3 ms** | **29 660** | |

Trzy przebiegi dały sumy: 267,3 / 139,3 / 124,2 ms oraz 29 660 / 29 660 / 29 670 buforów.
W pierwszym przebiegu JIT zapytań 1 i 3 był wolniejszy (23 i 57 ms). Nadal bez inliningu.
Pełne plany: [`pomiary/2030/plany-po.txt`](pomiary/2030/plany-po.txt).

Co się zmieniło:

- **Liczniki widoczne** (`recipes_count`, `posts_count`) liczy teraz
  `CollectionController::policzWidoczne()`, podpięte przez `afterQuery()` do zapytania
  o listę. Na rodzaj jest jedno zgrupowane zapytanie `join collection_items … group by
  collection_id` dla wszystkich zeszytów naraz. Praca jest ta sama (każdy zapis przechodzi
  przez filtr widoczności raz). Szacunek nie jest już mnożony przez liczbę zeszytów: ok.
  166 tys. zamiast 995 tys., więc JIT nie włącza inliningu ani optymalizacji. Liczby całości
  (`withTrashed`) zostały w `withCount` bez zmian.
- **Szyna** (`ostatnioOdlozone()`) czyta zapisy konta od najnowszego, po (`created_at` DESC,
  id DESC), partiami 20, 80, 320, 1000. Pierwsze spotkanie danej rzeczy to jej najpóźniejszy
  zapis, czyli dokładnie dawne `max()`. Kolejność pierwszych spotkań to dawne
  `ORDER BY max DESC, id DESC`. Widoczność sprawdzają te same zakresy
  (`widoczneDla`, `dostepnyJakoAutor`, `widoczneWZeszycieDla`), ale tylko dla partii
  kandydatów (`whereKey`). Relacje dociągam wyłącznie do pięciu wybranych rzeczy.
  W tym zestawie druga partia była potrzebna, bo 40 najnowszych zapisów jest niewidocznych.
  To zamierzony przypadek brzegowy.

## Porównanie

| | Przed | Po |
|---|---:|---:|
| Czas zapytań ekranu (środkowy przebieg) | 968,9 ms | 139,3 ms |
| Zakres z trzech przebiegów | 850,9–1051,2 ms | 124,2–267,3 ms |
| Bufory (suma) | 151 607 | 29 660 |
| Najwyższy szacunek kosztu | 995 386 | 203 897 |
| Kompilacja JIT z inliningiem | 1 zapytanie, 580–700 ms | 0 |
| Wiersze `recipes` + `posts` czytane przez szynę (zwrócone i odrzucone filtrem) | 13 219 | 106 |
| Liczba zapytań | 9 | 15 tutaj, 13 przy jednej partii na rodzaj; nie rośnie z liczbą zeszytów (`SzynaBezWachlarzaZapytanTest`, `LicznikKartyZeszytuSpojnyTest`) |
| Liczby na kartach i pozycje szyny | — | identyczne (kontrola harnessu) |

## Test regresyjny

`tests/Feature/SzynaOstatnioZapisanychKosztTest.php`:

- `test_szyna_i_liczniki_pokazuja_to_samo_co_przed_zmiana` sprawdza kolejność i widoczność
  szyny oraz liczby kart. Zestaw obejmuje blokady w obie strony, rzeczy prywatne, szkic,
  treść usuniętą miękko, zbanowanego autora, „dla obserwujących” (obserwowany
  i nieobserwowany), własny prywatny przepis, rzecz w dwóch zeszytach (liczy się
  najpóźniejszy zapis), remis w tej samej sekundzie (wyższe id pierwsze), 31 niewidocznych
  rzeczy nad widocznymi (druga partia) i zapis w cudzym zeszycie. **Przechodzi na starym
  kodzie**, bo to strażnik niezmienności.
- `test_szyna_sprawdza_widocznosc_tylko_malej_partii_kandydatow` sumuje wiersze `recipes`
  i `posts` przeczytane (`EXPLAIN ANALYZE`, zwrócone i odrzucone filtrem, razy pętle)
  w zapytaniach ekranu poza licznikami, przy 600 odłożonych rzeczach. Stary kod: 600,
  nowy: 40, próg: 100. **Oblewa na starym kodzie.**
  Jeśli odczyt przekroczy 100, test wypisuje każdy SELECT, który czytał `recipes`
  lub `posts`, wraz z liczbą odczytów i kształtem planu. Nie wypisuje wartości
  parametrów ani warunków planu z identyfikatorami. Próg i ustawienia planera
  pozostają bez zmian: diagnostyka ma rozróżnić regresję od innego planu
  PostgreSQL, a nie ukrywać czerwień.

### Nowy sygnał z CI, 1 października 2026

PR #2496, run `36930817846`, job `110599411663`: pomiar tej samej fixture
zwrócił 320 przy progu 100. W niezależnym PR #2493, run `36930561348`,
job `110598584632`, pomiar zwrócił 40. Zmiany #2496 nie obejmowały
`CollectionController` ani tego testu. Log czerwonego joba zawierał tylko sumę,
bez SQL i planu, więc nie ustalał, czy zmieniła się praca szyny, czy wybór
planu przez PostgreSQL. Powyższa diagnostyka zapisze te dane dopiero przy
następnej porażce. Sam wynik 320 nie jest dowodem regresji produktu.
- `test_liczniki_kart_nie_przekraczaja_progu_jit_przy_200_zeszytach` sprawdza, że żadne
  zapytanie ekranu przy 200 zeszytach i 20 000 zapisów nie ma szacunku ≥
  `jit_inline_above_cost`. Stary kod: ok. 902 tys., nowy: ok. 211 tys., próg: 500 tys.
  **Oblewa na starym kodzie.** Mierzę szacunek, a nie czas, bo to szacunek decyduje o JIT,
  a czas zależy od maszyny CI. Wynik nie zależy też od tego, czy serwer ma LLVM.

## Czego ta zmiana nie robi — decyzje do właściciela

1. **Stronicowanie listy zeszytów: nie wprowadzam.** Samo zapytanie o 200 zeszytów kosztuje
   3 bufory. Koszt leżał w licznikach, a nie w liczbie wierszy listy. Stronicowanie zmieniłoby
   obietnicę ekranu („wszystkie Twoje zeszyty w jednym miejscu”) i dołożyłoby osobom 50+
   krok „następna strona” w miejscu, gdzie szukają konkretnego zeszytu.
   **Rekomendacja:** zostawić jedną listę.
2. **Limit liczby zeszytów na konto: nie wprowadzam.** To decyzja produktowa, a po tej
   zmianie koszt nie rośnie już z liczbą zeszytów, tylko z liczbą zapisów.
   **Rekomendacja:** bez limitu, dopóki nie będzie danych z produkcji.
3. **Liczby widoczne dalej rosną z historią.** Każdy zapis przechodzi przez filtr widoczności,
   bo widoczność zależy od oglądającego (blokady, obserwowanie, status autora). Dlatego nie
   da się jej trzymać jako podtrzymywanego licznika w tabeli. Zapytanie o widoczne wpisy
   szacuje ok. 8 na zapis konta. Próg inliningu JIT (500 tys.) przekroczy więc dopiero przy
   ok. trzy razy większej historii niż w pomiarze (ok. 60 tys. zapisów jednego konta).
   Wtedy do wyboru są: stronicowanie, liczby leniwe (dociągane po wyświetleniu) albo
   ustawienia JIT bazy. Ta ostatnia sprawa jest otwarta w #605/#609 i jej nie ruszam.
   **Rekomendacja:** nic teraz, wrócić przy realnym koncie powyżej 30 tys. zapisów.
4. **Szyna dalej czyta indeks zapisów całego konta.** Pierwsza partia to `top-N` po
   `created_at` ze wszystkich zapisów właściciela: 6 ms i 588 buforów przy 20 tys./70 tys.
   Nie ma tu już filtra widoczności, `max()` ani hydratacji. Pełne ograniczenie wymagałoby
   indeksu po właścicielu i czasie zapisu, czyli kolumny `owner_id` w `collection_items`
   (zmiana schematu). Indeks `(collection_id, created_at)` nie wystarczy, bo PostgreSQL nie
   scala uporządkowanych strumieni 200 zeszytów w jednym skanie.
   **Rekomendacja:** bez migracji. Wrócić, jeśli pomiar produkcyjny `/zeszyt` pokaże ten krok.

## Rollback

Zmiana dotyczy wyłącznie kodu w `CollectionController` (`index()`, `policzWidoczne()`,
`ostatnioZapisane()`, `ostatnioOdlozone()`). Nie ma migracji ani zmiany danych.
Rollback to revert commita.

## Jak powtórzyć

1. Skopiuj harness z dodatku do `tests/Feature/PomiarPlanowZeszytow2030Test.php`. Nie
   commituj go, bo to pomiar, a nie test regresyjny.
2. Uruchom:
   `POMIAR_WYJSCIE=/tmp/plany.json vendor/bin/phpunit --filter=PomiarPlanowZeszytow2030`
3. Dla wariantu „przed” podstaw `app/Http/Controllers/CollectionController.php` z `dd05fbe26`.
   Po pomiarze przywróć plik.

## Dodatek: harness

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POMIAR (nie test regresyjny) do issue #2030: EXPLAIN (ANALYZE, BUFFERS)
 * każdego zapytania ekranu /zeszyt na reprezentatywnych danych — przed
 * i po zmianie. Plan liczony w SAVEPOINT tuż przed właściwym zapytaniem
 * i cofany, więc ekran wykonuje się na tym samym stanie, który zmierzono.
 */
final class PomiarPlanowZeszytow2030Test extends TestCase
{
    use RefreshDatabase;

    public function test_pomiar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
        $teraz = now()->toIso8601String();

        $wlasciciel = $this->user('wlasciciel_pomiar_2030', ['display_name' => 'Halina']);
        // 40 autorów treści: 2 zbanowanych, 2 zablokowanych (w obie strony), 10 obserwowanych.
        $autorzy = collect(range(0, 39))->map(fn (int $i) => $this->user('autor_pomiar_2030_'.$i, [
            'status' => $i < 2 ? User::STATUS_BANNED : User::STATUS_ACTIVE,
        ]));
        DB::table('blocks')->insert(['blocker_id' => $wlasciciel->getKey(), 'blocked_id' => $autorzy[2]->getKey()]);
        DB::table('blocks')->insert(['blocker_id' => $autorzy[3]->getKey(), 'blocked_id' => $wlasciciel->getKey()]);
        foreach (range(4, 13) as $i) {
            DB::table('follows')->insert(['follower_id' => $wlasciciel->getKey(), 'followed_id' => $autorzy[$i]->getKey()]);
        }
        $idAutorow = '{'.$autorzy->pluck('id')->implode(',').'}';

        // 8000 przepisów i 8000 wpisów: co 10. prywatny, co 10. dla obserwujących,
        // co 17. szkic, co 29. usunięty miękko.
        DB::insert(<<<'SQL'
            INSERT INTO recipes (author_id, title, slug, visibility, status, published_at, created_at, updated_at, deleted_at)
            SELECT a.ids[1 + (g % 40)], 'Przepis pomiarowy ' || g, 'przepis-pomiarowy-' || g,
                   CASE g % 10 WHEN 0 THEN 'private' WHEN 1 THEN 'followers' ELSE 'public' END,
                   CASE WHEN g % 17 = 0 THEN 'draft' ELSE 'published' END,
                   CASE WHEN g % 17 = 0 THEN NULL ELSE ?::timestamptz - make_interval(days => g % 900) END,
                   ?::timestamptz - make_interval(days => g % 900), ?::timestamptz,
                   CASE WHEN g % 29 = 0 THEN ?::timestamptz ELSE NULL END
            FROM (SELECT ?::uuid[] AS ids) a, generate_series(1, 8000) AS g
            SQL, [$teraz, $teraz, $teraz, $teraz, $idAutorow]);
        DB::insert(<<<'SQL'
            INSERT INTO posts (author_id, body, visibility, status, published_at, created_at, updated_at, deleted_at, kind)
            SELECT a.ids[1 + (g % 40)], 'Wpis pomiarowy ' || g,
                   CASE g % 10 WHEN 0 THEN 'private' WHEN 1 THEN 'followers' ELSE 'public' END,
                   CASE WHEN g % 17 = 0 THEN 'draft' ELSE 'published' END,
                   CASE WHEN g % 17 = 0 THEN NULL ELSE ?::timestamptz - make_interval(days => g % 900) END,
                   ?::timestamptz - make_interval(days => g % 900), ?::timestamptz,
                   CASE WHEN g % 29 = 0 THEN ?::timestamptz ELSE NULL END, 'dish'
            FROM (SELECT ?::uuid[] AS ids) a, generate_series(1, 8000) AS g
            SQL, [$teraz, $teraz, $teraz, $teraz, $idAutorow]);

        // Konto mierzone: 200 zeszytów, w każdym 50 przepisów i 50 wpisów = 20 000 zapisów
        // z dwóch lat. Tło: 100 innych kont × 5 zeszytów × 100 zapisów = 50 000.
        $inni = collect(range(0, 99))->map(fn (int $i) => User::factory()->create());
        $this->zeszyty($wlasciciel->getKey(), 200, 50, 0);
        foreach ($inni as $i => $inny) {
            $this->zeszyty($inny->getKey(), 5, 50, 1000 + $i * 37);
        }

        // Najnowsze zapisy właściciela to rzeczy NIEWIDOCZNE — szyna musi je ominąć.
        DB::update(<<<'SQL'
            UPDATE collection_items ci SET created_at = ?::timestamptz - make_interval(secs => r.nr)
            FROM (SELECT ci2.ctid AS c, row_number() OVER () AS nr
                  FROM collection_items ci2
                  JOIN collections c ON c.id = ci2.collection_id AND c.owner_id = ?
                  LEFT JOIN recipes r ON r.id = ci2.recipe_id
                  LEFT JOIN posts p ON p.id = ci2.post_id
                  WHERE coalesce(r.visibility, p.visibility) = 'private' LIMIT 40) r
            WHERE ci.ctid = r.c
            SQL, [$teraz, $wlasciciel->getKey()]);
        DB::statement('ANALYZE');

        $plany = [];
        $wPlanie = false;
        $mierz = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $c) use (&$plany, &$wPlanie, &$mierz): void {
            if (! $mierz || $wPlanie || ! preg_match('/^\s*select\b/i', $sql)) {
                return;
            }
            $wPlanie = true;
            $pdo = $c->getPdo();
            $pdo->exec('SAVEPOINT pomiar_2030');
            $st = $pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) '.$sql);
            $c->bindValues($st, $c->prepareBindings($bindings));
            $st->execute();
            $plan = implode("\n", $st->fetchAll(\PDO::FETCH_COLUMN));
            $pdo->exec('ROLLBACK TO SAVEPOINT pomiar_2030');
            $wPlanie = false;
            $plany[] = ['sql' => preg_replace('/\s+/', ' ', $sql), 'parametrow' => count($bindings), 'plan' => $plan];
        });

        $odpowiedz = $this->actingAs($wlasciciel)->get(route('collections.index'))->assertOk();
        $mierz = false;

        $zeszyty = $odpowiedz->viewData('collections');
        $szyna = $odpowiedz->viewData('ostatnioZapisane')->map(fn ($p) => $p['href'].' @ '.$p['zapisano_at'])->all();
        [$wzorLicznikow, $wzorSzyny] = $this->wzorzecStaregoKodu($wlasciciel);
        $kontrola = [
            'zeszytow' => $zeszyty->count(),
            'zapisow_wlasciciela' => DB::table('collection_items')->join('collections', 'collections.id', '=', 'collection_items.collection_id')->where('owner_id', $wlasciciel->getKey())->count(),
            'zapisow_razem' => DB::table('collection_items')->count(),
            'suma_licznikow' => $zeszyty->sum(fn ($z) => $z->recipes_count + $z->posts_count),
            'suma_calosci' => $zeszyty->sum(fn ($z) => $z->recipes_total_count + $z->posts_total_count),
            'liczniki_jak_stary_kod' => $wzorLicznikow === $zeszyty->map(fn ($z) => [$z->getKey(), (int) $z->recipes_count, (int) $z->posts_count, (int) $z->recipes_total_count, (int) $z->posts_total_count])->all(),
            'szyna_jak_stary_kod' => $wzorSzyny === $szyna,
            'ostatnio_zapisane' => $odpowiedz->viewData('ostatnioZapisane')->map(fn ($p) => $p['nazwa'].' @ '.$p['zapisano_at'])->all(),
        ];
        file_put_contents((string) getenv('POMIAR_WYJSCIE'), json_encode(['kontrola' => $kontrola, 'plany' => $plany],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Wynik zapytań sprzed #2030 (dosłowna kopia starego `index()`
     * i `ostatnioZapisane()`), liczony PO pomiarze — do porównania 1:1.
     *
     * @return array{0: list<array{0: string, 1: int, 2: int, 3: int, 4: int}>, 1: list<string>}
     */
    private function wzorzecStaregoKodu(User $user): array
    {
        $liczniki = $user->collections()
            ->withCount([
                'recipes as recipes_count' => fn ($q) => $q->widoczneDla($user)
                    ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor()),
                'posts as posts_count' => fn ($q) => $q->widoczneWZeszycieDla($user),
                'recipes as recipes_total_count' => fn ($q) => $q->withTrashed(),
                'posts as posts_total_count' => fn ($q) => $q->withTrashed(),
            ])
            ->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($z) => [$z->getKey(), (int) $z->recipes_count, (int) $z->posts_count, (int) $z->recipes_total_count, (int) $z->posts_total_count])->all();

        $zapisano = fn (string $kolumna, string $tabela) => DB::table('collection_items')
            ->join('collections', 'collections.id', '=', 'collection_items.collection_id')
            ->whereColumn('collection_items.'.$kolumna, $tabela.'.id')
            ->where('collections.owner_id', $user->getKey())
            ->selectRaw('max(collection_items.created_at)');
        $przepisy = \App\Models\Recipe::query()->widoczneDla($user)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->whereHas('collections', fn ($q) => $q->where('collections.owner_id', $user->getKey()))
            ->addSelect(['zapisano_at' => $zapisano('recipe_id', 'recipes')])
            ->orderByDesc('zapisano_at')->orderByDesc('recipes.id')->limit(5)->get()
            ->map(fn ($p) => ['href' => $p->url(), 'zapisano_at' => $p->getAttribute('zapisano_at')]);
        $wpisy = \App\Models\Post::query()->widoczneWZeszycieDla($user)
            ->whereHas('collections', fn ($q) => $q->where('collections.owner_id', $user->getKey()))
            ->addSelect(['zapisano_at' => $zapisano('post_id', 'posts')])
            ->orderByDesc('zapisano_at')->orderByDesc('posts.id')->limit(5)->get()
            ->map(fn ($p) => ['href' => $p->url(), 'zapisano_at' => $p->getAttribute('zapisano_at')]);
        $szyna = $przepisy->concat($wpisy)
            ->sortByDesc(fn (array $p) => \Illuminate\Support\Carbon::parse($p['zapisano_at'])->getTimestamp())
            ->take(5)->values()->map(fn ($p) => $p['href'].' @ '.$p['zapisano_at'])->all();

        return [$liczniki, $szyna];
    }

    /** Zeszyty konta: $ile zeszytów, w każdym $naZeszyt przepisów i $naZeszyt wpisów, rozłożonych na dwa lata. */
    private function zeszyty(string $wlascicielId, int $ile, int $naZeszyt, int $przesuniecie): void
    {
        $teraz = now()->toIso8601String();
        DB::insert(<<<'SQL'
            INSERT INTO collections (owner_id, name, visibility, is_default, created_at, updated_at)
            SELECT ?, CASE WHEN z = 0 THEN 'Zapisane' ELSE 'Zeszyt ' || z END, CASE WHEN z % 5 = 0 THEN 'public' ELSE 'private' END,
                   z = 0, ?::timestamptz, ?::timestamptz
            FROM generate_series(0, ? - 1) AS z
            SQL, [$wlascicielId, $teraz, $teraz, $ile]);
        foreach (['recipe_id' => ['recipes', 'slug'], 'post_id' => ['posts', 'body']] as $kolumna => [$tabela, $porzadek]) {
            DB::insert(<<<SQL
                INSERT INTO collection_items (collection_id, {$kolumna}, created_at)
                SELECT c.id, t.id, ?::timestamptz - make_interval(mins => ((c.nr * 7919 + t.nr * 104729 + ?) % 1051200)::int)
                FROM (SELECT id, row_number() OVER (ORDER BY created_at, id) - 1 AS nr FROM collections WHERE owner_id = ?) c
                JOIN (SELECT id, row_number() OVER (ORDER BY {$porzadek}) - 1 AS nr FROM {$tabela}) t
                  ON t.nr >= ((c.nr * 40 + ?) % 7900) AND t.nr < ((c.nr * 40 + ?) % 7900) + ?
                SQL, [$teraz, $przesuniecie, $wlascicielId, $przesuniecie, $przesuniecie, $naZeszyt]);
        }
    }
}
```
