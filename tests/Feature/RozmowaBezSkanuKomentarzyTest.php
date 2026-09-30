<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nagłówek „Komentarze (N)” pod przepisem i pod „Ugotowałem” nie czyta całej
 * tabeli `comments` (#2292, audyt docs/audyt/2026-09-30-wydajnosc-baza.md, F5).
 *
 * `Comment::policzRozmowe()` liczył rozmowę jednym warunkiem
 * `comments.id IN (korzenie) OR comments.parent_id IN (korzenie)`. Przy takim
 * `OR` planer nie ma indeksu, po którym mógłby iść, więc robił `Seq Scan on
 * comments` — koszt rósł z liczbą WSZYSTKICH komentarzy w serwisie, na każdym
 * wejściu na przepis, także gościa. Pilnujemy dwóch rzeczy:
 *
 * 1. plan zapytania licznika przy kilkudziesięciu tysiącach cudzych
 *    komentarzy nie ma ani jednego `Seq Scan` na `comments`;
 * 2. liczba jest ta sama co przedtem na scenie z D-281 i #1396 (odpowiedź
 *    osoby zablokowanej, korzeń zablokowanej osoby z cudzą odpowiedzią,
 *    ślad usuniętego korzenia, odpowiedź ukryta i skasowana) — dla widza
 *    z blokadą, dla kogoś spoza blokady i dla gościa. Pełną równoważność
 *    na stronach trzyma `LicznikKomentarzyLiczyOdpowiedziTest`.
 */
final class RozmowaBezSkanuKomentarzyTest extends TestCase
{
    use RefreshDatabase;

    /** Połączenie z bazą, gdy test zasiał duży zbiór (do posprzątania po wycofaniu transakcji). */
    private ?\PDO $poZasiewie = null;

    /** Patrz `FeedObserwowanychKosztPlanuTest::tearDown()`. */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->poZasiewie !== null) {
            $this->poZasiewie->exec('VACUUM (ANALYZE) users, profiles, comments, blocks');
            $this->poZasiewie = null;
        }
    }

    public function test_licznik_pod_przepisem_nie_czyta_calej_tabeli_komentarzy(): void
    {
        $this->poZasiewie = DB::connection()->getPdo();
        $przepis = $this->przepis('kucharka_2292');
        $widz = $this->user('czytelniczka_2292');
        $this->rozmowa(['recipe_id' => $przepis->getKey()], $widz);
        $this->tloCudzychKomentarzy();

        $this->assertSame(4, Comment::policzRozmowe($przepis->comments(), $widz->refresh()));
        $this->assertBezSkanuKomentarzy(fn () => Comment::policzRozmowe($przepis->comments(), $widz), 'przepis, widz z blokadą');
        $this->assertBezSkanuKomentarzy(fn () => Comment::policzRozmowe($przepis->comments(), null), 'przepis, gość');
    }

    public function test_licznik_pod_ugotowalem_nie_czyta_calej_tabeli_komentarzy(): void
    {
        $this->poZasiewie = DB::connection()->getPdo();
        $wykonanie = CookedEvent::factory()->create();
        $widz = $this->user('czytelniczka_2292_u');
        $this->rozmowa(['cooked_event_id' => $wykonanie->getKey()], $widz);
        $this->tloCudzychKomentarzy();

        $this->assertSame(4, Comment::policzRozmowe($wykonanie->comments(), $widz->refresh()));
        $this->assertBezSkanuKomentarzy(fn () => Comment::policzRozmowe($wykonanie->comments(), $widz), 'Ugotowałem, widz z blokadą');
    }

    public function test_liczba_zgadza_sie_z_regulami_d281_i_1396_dla_kazdego_widza(): void
    {
        $przepis = $this->przepis('kucharka_2292_r');
        $widz = $this->user('czytelniczka_2292_r');
        $this->rozmowa(['recipe_id' => $przepis->getKey()], $widz);

        // Widz z blokadą: korzeń + dwie odpowiedzi + ślad usuniętego korzenia
        // (odpowiedź zbanowanego pod nim się nie liczy) = 4.
        $this->assertSame(4, Comment::policzRozmowe($przepis->comments(), $widz->refresh()));
        // Ktoś spoza blokady widzi też odpowiedź natręta, jego korzeń i cudzą
        // odpowiedź pod nim: 4 + 3 = 7.
        $this->assertSame(7, Comment::policzRozmowe($przepis->comments(), $this->user('ktos_2292')));
        // Gość: te same reguły co ktoś spoza blokady (bez blokad).
        $this->assertSame(7, Comment::policzRozmowe($przepis->comments(), null));
    }

    private function przepis(string $autor): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->user($autor)->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
    }

    /**
     * Rozmowa z pułapkami. Widz z blokadą widzi 4: korzeń, dwie widoczne
     * odpowiedzi, ślad usuniętego korzenia (strona go pokazuje), ale NIE
     * odpowiedź pod śladem od osoby zbanowanej ani odpowiedzi ukryte,
     * skasowane i pod korzeniem natręta.
     *
     * @param  array<string, string>  $podmiot
     */
    private function rozmowa(array $podmiot, User $widz): void
    {
        $natret = $this->user();
        $zbanowany = $this->user();
        $wstaw = fn (User $kto, ?Comment $rodzic = null, array $inne = []) => Comment::factory()->create([
            'post_id' => null,
            ...$podmiot,
            'author_id' => $kto->getKey(),
            'parent_id' => $rodzic?->getKey(),
            'status' => Comment::STATUS_PUBLISHED,
            ...$inne,
        ]);

        $korzen = $wstaw($this->user());
        $wstaw($this->user(), $korzen);
        $wstaw($this->user(), $korzen);
        $wstaw($natret, $korzen);
        $wstaw($this->user(), $korzen, ['status' => Comment::STATUS_HIDDEN]);
        $wstaw($this->user(), $korzen)->delete();

        $slad = $wstaw($this->user(), null, ['body' => 'Komentarz usunięty.', 'body_removed_at' => now()]);
        $wstaw($zbanowany, $slad);

        $korzenNatreta = $wstaw($natret);
        $wstaw($this->user(), $korzenNatreta);

        app(BlockUser::class)->handle($widz, $natret);
        $zbanowany->ban();
    }

    /**
     * 30 000 komentarzy innych przepisów (6 000 korzeni i 24 000 odpowiedzi)
     * hurtem — fabryka przy tej liczbie jest za wolna. Audyt mierzył skan
     * całej tabeli przy 40 tys.
     */
    private function tloCudzychKomentarzy(): void
    {
        $autorzy = User::factory()->count(20)->create()->modelKeys();
        $przepisy = Recipe::factory()->count(30)->create(['status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public'])->modelKeys();
        $listaAutorow = "'".implode("','", $autorzy)."'";
        $listaPrzepisow = "'".implode("','", $przepisy)."'";

        DB::insert(<<<SQL
            INSERT INTO comments (author_id, recipe_id, body, status, created_at, updated_at)
            SELECT (ARRAY[{$listaAutorow}]::uuid[])[1 + g % 20], (ARRAY[{$listaPrzepisow}]::uuid[])[1 + g % 30],
                   'Tło', 'published', now() - make_interval(secs => g), now()
            FROM generate_series(1, 6000) AS g
            SQL);
        DB::insert(<<<'SQL'
            INSERT INTO comments (author_id, recipe_id, parent_id, body, status, created_at, updated_at)
            SELECT k.author_id, k.recipe_id, k.id, 'Odpowiedź tła', 'published', k.created_at + make_interval(secs => g), now()
            FROM comments k CROSS JOIN generate_series(1, 4) AS g
            WHERE k.parent_id IS NULL AND k.body = 'Tło'
            SQL);
        DB::statement('ANALYZE');

        $this->assertGreaterThanOrEqual(30000, DB::table('comments')->count(), 'Kontrola sceny: za mało komentarzy, żeby plan miał znaczenie.');
    }

    /** @param  callable(): int  $licz */
    private function assertBezSkanuKomentarzy(callable $licz, string $opis): void
    {
        $zapytania = [];
        $zbieraj = true;
        DB::listen(function ($zapytanie) use (&$zapytania, &$zbieraj): void {
            if ($zbieraj && preg_match('/^\s*\(?\s*select\b/i', $zapytanie->sql)) {
                $zapytania[] = [$zapytanie->sql, $zapytanie->bindings];
            }
        });
        $licz();
        $zbieraj = false;

        $this->assertCount(1, $zapytania, "Licznik rozmowy ({$opis}) ma być jednym zapytaniem.");
        [$sql, $parametry] = $zapytania[0];

        $plan = json_decode((string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
        $skany = $this->skanySekwencyjne($plan[0]['Plan']);

        $this->assertSame([], $skany, "Licznik rozmowy ({$opis}) czyta całą tabelę `comments` (Seq Scan) — koszt rośnie z liczbą wszystkich komentarzy w serwisie. SQL: ".mb_substr($sql, 0, 300));
    }

    /**
     * @param  array<string, mixed>  $wezel
     * @return list<string>
     */
    private function skanySekwencyjne(array $wezel): array
    {
        $wynik = [];
        if (str_contains((string) ($wezel['Node Type'] ?? ''), 'Seq Scan') && ($wezel['Relation Name'] ?? null) === 'comments') {
            $wynik[] = (string) $wezel['Node Type'].' on comments';
        }
        foreach ($wezel['Plans'] ?? [] as $dziecko) {
            $wynik = [...$wynik, ...$this->skanySekwencyjne($dziecko)];
        }

        return $wynik;
    }
}
