<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Profil autora z ~2000 wpisów (audyt wydajności, P3 W4): ekran nie liczy
 * dwa razy tego samego i nie wpada w kompilację JIT.
 *
 * 1. `stats.posts` i `paginate()` liczyły ten sam COUNT — raz na licznik
 *    w nagłówku, drugi raz na sumę paginatora. Teraz liczba idzie do
 *    paginatora gotowa (gdy nie ma filtra roku, który zmienia zakres).
 * 2. Skorelowane `EXISTS` (zdjęcie wpisu, przepis wpisu, autor przepisu)
 *    w alternatywach `OR` planer nalicza za KAŻDY kandydujący wiersz autora;
 *    przy kilku tysiącach wpisów szacunek przekraczał `jit_above_cost` i
 *    PostgreSQL kompilował zapytanie przez JIT przy każdym wejściu na profil.
 *    Wersje „…BezKorelacji" (#599, #2288) zapisują to samo przez `IN`.
 *
 * Równoważność wyniku pilnują pozostałe testy profilu
 * (`ProfilLicznikiTresciZgadzajaSieZListamiTest`, `ProfilNiePokazujeUkrytegoPrzepisuTest`,
 * `ZapowiedzUsunietegoPrzepisuNaWlasnymProfiluTest`) — tu sprawdzamy dodatkowo,
 * że licznik, lista i lata zgadzają się na dużym zbiorze.
 */
final class ProfilAutoraZDuzaLiczbaWpisowKosztTest extends TestCase
{
    use RefreshDatabase;

    private ?\PDO $poZasiewie = null;

    /** Wycofanie transakcji nie oddaje statystyk ani stron: sprzątamy po niej (jak w `FeedObserwowanychKosztPlanuTest`). */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->poZasiewie !== null) {
            $this->poZasiewie->exec('VACUUM (ANALYZE) users, profiles, recipes, posts, media, post_media');
            $this->poZasiewie = null;
        }
    }

    public function test_licznik_wpisow_jest_liczony_raz_na_zadanie(): void
    {
        [$autor, $widz] = $this->zasiej(120);

        $zapytania = $this->zapytaniaProfilu($widz, $autor);

        $liczniki = array_filter($zapytania, fn (array $z): bool => $this->liczyWpisy($z[0]));

        $this->assertCount(
            1,
            $liczniki,
            "COUNT po wpisach autora ma być jeden (licznik idzie do paginatora gotowy), a jest:\n"
            .implode("\n", array_map(fn (array $z): string => mb_substr($z[0], 0, 200), $liczniki)),
        );
    }

    public function test_paginator_z_filtrem_roku_liczy_wlasny_zakres(): void
    {
        [$autor, $widz] = $this->zasiej(120);
        $rok = (int) now()->year;

        // Wpisy z `zasiej()` są z bieżącego roku; ten jeden przesuwamy rok wstecz.
        // Wpis nr 1 jest publiczny, z tekstem i bez przepisu, więc widzi go każdy.
        DB::table('posts')->where('author_id', $autor->getKey())->where('body', 'Wpis 1')
            ->update(['published_at' => now()->subYears(2)]);

        $wszystkie = $this->actingAs($widz)->get(route('profile.show', $autor->profile->username))->assertOk();
        $zRokiem = $this->actingAs($widz)->get(route('profile.show', [$autor->profile->username, 'rok' => $rok]))->assertOk();

        $this->assertSame(
            $wszystkie->viewData('stats')['posts'],
            $wszystkie->viewData('posts')->total(),
            'Licznik w nagłówku i suma paginatora to ta sama liczba.',
        );
        // Filtr roku zawęża listę, ale licznik w nagłówku zostaje sumą całości.
        $this->assertSame($wszystkie->viewData('stats')['posts'], $zRokiem->viewData('stats')['posts']);
        $this->assertSame($wszystkie->viewData('stats')['posts'] - 1, $zRokiem->viewData('posts')->total());
    }

    public function test_koszt_planu_profilu_z_2000_wpisow_nie_przekracza_progu_jit(): void
    {
        [$autor, $widz] = $this->zasiej(2000, tlo: true);

        $prog = (float) DB::selectOne("SELECT current_setting('jit_above_cost')::float8 AS prog")->prog;
        $zapytania = $this->zapytaniaProfilu($widz, $autor);

        $najdrozsze = ['koszt' => 0.0, 'sql' => ''];
        foreach ($zapytania as [$sql, $parametry]) {
            $plan = json_decode((string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
            $koszt = (float) $plan[0]['Plan']['Total Cost'];
            if ($koszt > $najdrozsze['koszt']) {
                $najdrozsze = ['koszt' => $koszt, 'sql' => mb_substr($sql, 0, 200)];
            }
        }

        fwrite(STDERR, sprintf("\n[profil 2000 wpisów] najwyższy szacunek %.0f (próg JIT %.0f)\n", $najdrozsze['koszt'], $prog));

        // Ćwierć progu, nie cały próg: przed zmianą profil w tym zbiorze miał ok. 66 tys.
        // (próg 100 tys.) i rósł liniowo z liczbą wpisów autora — sam próg nie odróżniłby
        // wersji skorelowanej od tej bez korelacji na tak małym zbiorze. Po zmianie
        // ok. 2,7 tys.
        $this->assertLessThan(
            $prog / 4,
            $najdrozsze['koszt'],
            "Zapytanie profilu ma szacowany koszt {$najdrozsze['koszt']} ≥ jednej czwartej jit_above_cost ({$prog}): skorelowane EXISTS w alternatywie OR wróciły? {$najdrozsze['sql']}",
        );
    }

    public function test_licznik_lista_i_lata_zgadzaja_sie_na_duzym_zbiorze(): void
    {
        [$autor, $widz] = $this->zasiej(600);

        $odpowiedz = $this->actingAs($widz)->get(route('profile.show', $autor->profile->username))->assertOk();
        $wlasne = $this->actingAs($autor)->get(route('profile.show', $autor->profile->username))->assertOk();

        // Widz: bez wpisów prywatnych (co 17.), „dla obserwujących" (co 9.) i zapowiedzi
        // prywatnych przepisów cudzego autora; właściciel widzi wszystkie własne.
        $oczekiwaneDlaWidza = (int) DB::selectOne(
            "SELECT count(*) AS n FROM posts p LEFT JOIN recipes r ON r.id = p.recipe_id
             WHERE p.author_id = ? AND p.status = 'published' AND p.deleted_at IS NULL AND p.visibility = 'public'
               AND (p.recipe_id IS NULL OR p.body ~ '\\S' OR EXISTS (SELECT 1 FROM post_media m WHERE m.post_id = p.id)
                    OR (r.id IS NOT NULL AND r.deleted_at IS NULL AND r.status = 'published' AND r.visibility = 'public'))",
            [$autor->getKey()],
        )->n;

        $this->assertSame($oczekiwaneDlaWidza, $odpowiedz->viewData('stats')['posts']);
        $this->assertSame($oczekiwaneDlaWidza, $odpowiedz->viewData('posts')->total());
        $this->assertGreaterThan($oczekiwaneDlaWidza, $wlasne->viewData('stats')['posts']);
        $this->assertSame($wlasne->viewData('stats')['posts'], $wlasne->viewData('posts')->total());
    }

    /** @return list<array{0: string, 1: array<int, mixed>}> */
    private function zapytaniaProfilu(User $widz, User $autor): array
    {
        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            if (preg_match('/^\s*select\b/i', $zapytanie->sql)) {
                $zapytania[] = [$zapytanie->sql, $zapytanie->bindings];
            }
        });

        $this->actingAs($widz)->get(route('profile.show', $autor->profile->username))->assertOk();

        return $zapytania;
    }

    private function liczyWpisy(string $sql): bool
    {
        return (bool) preg_match('/select count\(\*\) as "aggregate" from "posts" where "posts"."author_id"/i', $sql);
    }

    /**
     * Autor z `$ile` wpisami hurtem: co 3. zapowiedź przepisu (inny autor),
     * co 4. ze zdjęciem, co 9. „dla obserwujących", co 17. prywatny.
     *
     * @return array{0: User, 1: User}
     */
    private function zasiej(int $ile, bool $tlo = false): array
    {
        $this->poZasiewie = DB::connection()->getPdo();

        $autor = $this->user('autor_duzy_profil');
        $widz = $this->user('widz_duzego_profilu');
        $inny = $this->user('inny_autor_przepisow');
        $teraz = now()->toIso8601String();

        DB::insert(<<<'SQL'
            INSERT INTO recipes (author_id, title, slug, visibility, status, published_at, created_at, updated_at)
            SELECT ?, 'Przepis ' || g, 'przepis-profil-' || g,
                   CASE WHEN g % 10 = 0 THEN 'private' WHEN g % 7 = 0 THEN 'followers' ELSE 'public' END,
                   'published', ?::timestamptz, ?::timestamptz, ?::timestamptz
            FROM generate_series(1, 400) AS g
            SQL, [$inny->getKey(), $teraz, $teraz, $teraz]);
        DB::insert(<<<'SQL'
            INSERT INTO posts (author_id, body, recipe_id, visibility, status, published_at, created_at, updated_at, kind)
            SELECT ?,
                   CASE WHEN g % 3 = 0 THEN '' ELSE 'Wpis ' || g END,
                   CASE WHEN g % 3 = 0 THEN (SELECT id FROM recipes ORDER BY slug OFFSET (g % 400) LIMIT 1) END,
                   CASE WHEN g % 17 = 0 THEN 'private' WHEN g % 9 = 0 THEN 'followers' ELSE 'public' END,
                   'published', ?::timestamptz - make_interval(mins => g), ?::timestamptz, ?::timestamptz, 'dish'
            FROM generate_series(1, ?) AS g
            SQL, [$autor->getKey(), $teraz, $teraz, $teraz, $ile]);
        DB::insert(<<<'SQL'
            INSERT INTO media (id, owner_id, object_key, status, created_at, updated_at)
            SELECT gen_random_uuid(), p.author_id, 'kprofil/' || p.id, 'ready', now(), now()
            FROM (SELECT id, author_id, row_number() OVER (ORDER BY id) rn FROM posts) p WHERE p.rn % 4 = 0
            SQL);
        DB::insert("INSERT INTO post_media (post_id, media_id, position) SELECT p.id, m.id, 0 FROM posts p JOIN media m ON m.object_key = 'kprofil/' || p.id");
        if ($tlo) {
            // Tło w skali #605 (20 000 przepisów, 6000 cudzych wpisów): szacunek
            // skorelowanych podzapytań zależy od rozmiaru tabel, a nie tylko autora.
            DB::insert(<<<'SQL'
                INSERT INTO recipes (author_id, title, slug, visibility, status, published_at, created_at, updated_at)
                SELECT ?, 'Tlo ' || g, 'przepis-tlo-' || g, 'public', 'published', ?::timestamptz, ?::timestamptz, ?::timestamptz
                FROM generate_series(1, 20000) AS g
                SQL, [$inny->getKey(), $teraz, $teraz, $teraz]);
            DB::insert(<<<'SQL'
                INSERT INTO posts (author_id, body, visibility, status, published_at, created_at, updated_at, kind)
                SELECT ?, 'Tlo ' || g, 'public', 'published', ?::timestamptz - make_interval(mins => g), ?::timestamptz, ?::timestamptz, 'dish'
                FROM generate_series(1, 6000) AS g
                SQL, [$inny->getKey(), $teraz, $teraz, $teraz]);
        }
        DB::statement('ANALYZE');

        return [$autor, $widz];
    }
}
