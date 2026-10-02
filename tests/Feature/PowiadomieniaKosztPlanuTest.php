<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\OdczytPowiadomien;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lista `/powiadomienia` nie liczy wszystkich powiadomień przy każdym wejściu
 * (issue #2289, audyt docs/audyt/2026-09-30-wydajnosc-baza.md, F2).
 *
 * `paginate()` dokładał `COUNT(*)` przez cały filtr widoczności. Od ok. 600
 * widocznych powiadomień szacunek tego zapytania przekraczał `jit_above_cost`,
 * więc PostgreSQL kompilował je przez JIT przy każdym wejściu (0,4–0,5 s przy
 * 1000–2000). Lista nie pokazuje liczby stron, więc `COUNT` nie był do niczego
 * potrzebny. Pilnujemy: (1) żadne zapytanie strony i przycisku „Oznacz
 * wszystkie" nie przekracza progu przy 2000 powiadomieniach; (2) kolejne
 * strony dalej działają i idą w tej samej kolejności.
 */
final class PowiadomieniaKosztPlanuTest extends TestCase
{
    use RefreshDatabase;

    /** Połączenie z bazą, gdy test zasiał duży zbiór (do posprzątania po wycofaniu transakcji). */
    private ?\PDO $poZasiewie = null;

    /** Patrz `FeedObserwowanychKosztPlanuTest::tearDown()`. */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->poZasiewie !== null) {
            $this->poZasiewie->exec('VACUUM (ANALYZE) users, profiles, notifications, blocks');
            $this->poZasiewie = null;
        }
    }

    public function test_koszt_planu_listy_przy_2000_powiadomien_nie_przekracza_progu_jit(): void
    {
        $this->poZasiewie = DB::connection()->getPdo();
        $ja = $this->user('ja_powiadomienia_2289');
        $this->zasiej($ja, 2000, przeczytane: true);
        // Jedno nieprzeczytane NA KOŃCU listy: pierwsza strona ma same
        // przeczytane, więc kontroler pyta, czy gdzieś dalej coś czeka.
        DB::table('notifications')->where('user_id', $ja->getKey())
            ->where('created_at', DB::table('notifications')->where('user_id', $ja->getKey())->min('created_at'))
            ->update(['read_at' => null]);
        // Całe ANALYZE, jak w FeedObserwowanychKosztPlanuTest: filtr widoczności
        // sięga też do `blocks` i `users`, a ich statystyki po wcześniejszych
        // testach w tym samym procesie potrafią być nieaktualne (w CI szacunek
        // wychodził 321 tys. zamiast ok. 24 tys.).
        DB::statement('ANALYZE');

        $widoczne = $ja->notifications()->visibleTo($ja)->count();
        $this->assertGreaterThan(1000, $widoczne, 'Kontrola sceny: za mało widocznych powiadomień, żeby próg JIT miał znaczenie.');

        $prog = (float) DB::selectOne("SELECT current_setting('jit_above_cost')::float8 AS prog")->prog;

        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            if (preg_match('/^\s*select\b/i', $zapytanie->sql)) {
                $zapytania[] = [$zapytanie->sql, $zapytanie->bindings];
            }
        });
        $this->actingAs($ja)->get(route('notifications.index'))
            ->assertOk()
            // Przycisk dalej wie, że gdzieś niżej jest nieprzeczytane.
            ->assertSee('Oznacz wszystkie jako przeczytane');
        // Zakres „Nieprzeczytane” (#2442) idzie tym samym filtrem plus `read_at IS NULL`:
        // jego zapytania też nie mogą przekroczyć progu JIT.
        $this->actingAs($ja)->get(route('notifications.index', ['zakres' => 'nieprzeczytane']))
            ->assertOk()
            ->assertSee('Oznacz wszystkie jako przeczytane');

        $najdrozsze = ['koszt' => 0.0, 'sql' => ''];
        foreach ($zapytania as [$sql, $parametry]) {
            $plan = json_decode((string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
            $koszt = (float) $plan[0]['Plan']['Total Cost'];
            if ($koszt > $najdrozsze['koszt']) {
                $najdrozsze = ['koszt' => $koszt, 'sql' => mb_substr($sql, 0, 200)];
            }
        }

        fwrite(STDERR, sprintf("\n[#2289 powiadomienia] %d widocznych: najwyższy szacunek %.0f (próg JIT %.0f)\n", $widoczne, $najdrozsze['koszt'], $prog));

        $this->assertLessThan(
            $prog,
            $najdrozsze['koszt'],
            "Zapytanie strony powiadomień ma szacowany koszt {$najdrozsze['koszt']} ≥ jit_above_cost ({$prog}), więc PostgreSQL kompiluje je przez JIT przy każdym wejściu: {$najdrozsze['sql']}",
        );
    }

    public function test_kolejne_strony_ida_w_tej_samej_kolejnosci_i_konczą_sie(): void
    {
        $ja = $this->user('ja_strony_2289');
        $this->zasiej($ja, 65, przeczytane: false);
        $wszystkie = $ja->notifications()->visibleTo($ja)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $this->assertCount(65, $wszystkie);

        $odczyt = app(OdczytPowiadomien::class);
        $zebrane = [];
        foreach ([1, 2, 3] as $numer) {
            request()->merge(['page' => $numer]);
            $strona = $odczyt->strona($ja);
            $zebrane = [...$zebrane, ...collect($strona->items())->pluck('id')->map(fn ($id): string => (string) $id)->all()];
            $this->assertSame($numer < 3, $strona->hasMorePages(), "Strona {$numer}: zły stan „jest dalej”.");
        }
        request()->merge(['page' => null]);

        $this->assertSame($wszystkie, $zebrane, 'Strony powiadomień zgubiły, powtórzyły albo przestawiły wiersze.');

        $this->actingAs($ja)->get(route('notifications.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Następna strona powiadomień')
            ->assertSee('page=3', false);
        $this->actingAs($ja)->get(route('notifications.index', ['page' => 3]))
            ->assertOk()
            ->assertDontSee('Następna strona powiadomień');
    }

    /**
     * Powiadomienia hurtem (fabryka przy 2000 jest za wolna): mieszane typy
     * od kilkudziesięciu nadawców, co sekundę jedno — plus tło innych osób.
     */
    private function zasiej(User $odbiorca, int $ile, bool $przeczytane): void
    {
        User::factory()->count(40)->create();
        $nadawcy = User::query()->where('id', '!=', $odbiorca->getKey())->orderBy('id')->pluck('id')->all();
        $lista = "'".implode("','", $nadawcy)."'";
        $n = count($nadawcy);

        DB::insert(<<<SQL
            INSERT INTO notifications (user_id, actor_id, type, data, read_at, created_at)
            SELECT ?, (ARRAY[{$lista}]::uuid[])[1 + g % {$n}],
                   CASE g % 3 WHEN 0 THEN ? WHEN 1 THEN ? ELSE ? END,
                   CASE g % 3 WHEN 0 THEN '{"username":"x"}'::jsonb ELSE jsonb_build_object('recipe_id', gen_random_uuid()) END,
                   CASE WHEN ? THEN now() END,
                   now() - make_interval(secs => g)
            FROM generate_series(1, {$ile}) AS g
            SQL, [$odbiorca->getKey(), Notification::TYPE_FOLLOW, Notification::TYPE_FORKED, Notification::TYPE_WELCOME, $przeczytane]);
        DB::insert(<<<SQL
            INSERT INTO notifications (user_id, actor_id, type, data, created_at)
            SELECT (ARRAY[{$lista}]::uuid[])[1 + g % {$n}], (ARRAY[{$lista}]::uuid[])[1 + (g + 1) % {$n}], ?, '{"username":"x"}'::jsonb, now() - make_interval(secs => g)
            FROM generate_series(1, 3000) AS g
            SQL, [Notification::TYPE_FOLLOW]);
    }
}
