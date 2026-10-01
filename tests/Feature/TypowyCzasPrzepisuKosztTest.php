<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\TypowyCzasPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Koszt typowego czasu z wykonań (#2067): jedno zapytanie agregujące, stała
 * liczba zapytań niezależna od liczby wykonań, a szacunek planu przy dużej
 * liczbie wykonań jednego przepisu poniżej `jit_above_cost` (inaczej
 * PostgreSQL kompilowałby je przez JIT przy każdym wejściu na stronę przepisu).
 */
final class TypowyCzasPrzepisuKosztTest extends TestCase
{
    use RefreshDatabase;

    private ?\PDO $poZasiewie = null;

    /** Patrz `FeedObserwowanychKosztPlanuTest::tearDown()`. */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->poZasiewie !== null) {
            $this->poZasiewie->exec('VACUUM (ANALYZE) users, profiles, recipes, cooked_events, blocks');
            $this->poZasiewie = null;
        }
    }

    /** @return list<string> instrukcje SQL wykonane przez wywołanie */
    private function zapytania(Recipe $przepis, ?User $widz): array
    {
        $sql = [];
        DB::listen(function ($z) use (&$sql): void {
            $sql[] = $z->sql;
        });
        TypowyCzasPrzepisu::dla($przepis, $widz);

        return $sql;
    }

    public function test_liczba_zapytan_jest_stala_i_rowna_jeden_niezaleznie_od_liczby_wykonan(): void
    {
        $maly = $this->zasiej(8, 1);
        $widz = $this->user();
        $duzy = $this->zasiej(120, 1);

        foreach ([null, $widz] as $kto) {
            $this->assertCount(1, $this->zapytania($maly, $kto), 'Mało wykonań: dokładnie jedno zapytanie.');
            $this->assertCount(1, $this->zapytania($duzy, $kto), 'Dużo wykonań: nadal jedno zapytanie.');
        }
    }

    public function test_agregat_jest_liczony_w_bazie_a_nie_w_php(): void
    {
        $przepis = $this->zasiej(40, 3);

        $sql = $this->zapytania($przepis, null)[0];

        $this->assertStringContainsString('percentile_cont', $sql);
        $this->assertStringContainsString('group by "cooked_events"."user_id"', $sql);
    }

    public function test_koszt_planu_przy_duzej_liczbie_wykonan_nie_przekracza_progu_jit(): void
    {
        $this->poZasiewie = DB::connection()->getPdo();
        $przepis = $this->zasiej(120, 25);
        DB::statement('ANALYZE');

        // Kontrola sceny: tysiące wykonań tego jednego przepisu.
        $this->assertGreaterThan(2500, $przepis->cookedEvents()->count());
        $this->assertSame(120, TypowyCzasPrzepisu::dla($przepis, null)?->osoby);

        $prog = (float) DB::selectOne("SELECT current_setting('jit_above_cost')::float8 AS prog")->prog;
        $widz = $this->user();

        foreach (['gość' => null, 'zalogowany' => $widz] as $opis => $kto) {
            $zapytania = [];
            DB::listen(function ($z) use (&$zapytania): void {
                $zapytania[] = [$z->sql, $z->bindings];
            });
            TypowyCzasPrzepisu::dla($przepis, $kto);

            $this->assertNotEmpty($zapytania);
            foreach ($zapytania as [$sql, $parametry]) {
                $plan = json_decode((string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
                $koszt = (float) $plan[0]['Plan']['Total Cost'];

                fwrite(STDERR, sprintf("\n[#2067 typowy czas, %s] szacunek %.0f (próg JIT %.0f)\n", $opis, $koszt, $prog));

                $this->assertLessThan(
                    $prog,
                    $koszt,
                    "Typowy czas ({$opis}) ma szacowany koszt {$koszt} ≥ jit_above_cost ({$prog}), więc PostgreSQL kompiluje go przez JIT przy każdym wejściu na przepis.",
                );
            }
        }
    }

    /**
     * Przepis opublikowany z `osob` osobami, każda z `razy` wykonaniami
     * (hurtem w SQL — fabryka przy tysiącach wierszy jest za wolna), plus tło:
     * inne przepisy z wykonaniami.
     */
    private function zasiej(int $osob, int $razy): Recipe
    {
        $przepis = Recipe::factory()->create(['status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $inne = Recipe::factory()->count(5)->create(['status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);

        $idOsob = User::factory()->count($osob)->create()->pluck('id')->all();
        $lista = "'".implode("','", $idOsob)."'";
        $n = count($idOsob);
        $listaPrzepisow = "'".implode("','", $inne->pluck('id')->all())."'";

        DB::insert(<<<SQL
            INSERT INTO cooked_events (user_id, recipe_id, actual_minutes, cooked_at)
            SELECT (ARRAY[{$lista}]::uuid[])[1 + g % {$n}], ?, 20 + g % 90, now() - make_interval(mins => g)
            FROM generate_series(1, ?) AS g
            SQL, [$przepis->getKey(), $osob * $razy]);
        DB::insert(<<<SQL
            INSERT INTO cooked_events (user_id, recipe_id, actual_minutes, cooked_at)
            SELECT (ARRAY[{$lista}]::uuid[])[1 + g % {$n}], (ARRAY[{$listaPrzepisow}]::uuid[])[1 + g % 5], 30, now() - make_interval(mins => g)
            FROM generate_series(1, 1500) AS g
            SQL);

        return $przepis;
    }
}
