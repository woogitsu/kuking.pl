<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ekran „Twój zeszyt" (`/zeszyt`) nie przerabia całej historii zapisów przy
 * każdym wejściu — i pokazuje przy tym DOKŁADNIE to samo co przedtem (#2030).
 *
 * DWA KOSZTY, KTÓRE TU PILNUJEMY
 *
 * 1. Szyna „Ostatnio zapisane" pokazuje pięć rzeczy, ale liczyła
 *    `max(collection_items.created_at)` i pełną regułę widoczności dla
 *    KAŻDEJ rzeczy kiedykolwiek odłożonej, sortowała wszystko i dopiero
 *    wtedy brała pięć. Pilnuje tego liczba wierszy `recipes` i `posts`,
 *    które baza naprawdę przeczytała (`EXPLAIN ANALYZE`) w zapytaniach
 *    szyny — przy 600 odłożonych rzeczach, nie przy pięciu.
 *
 * 2. Liczby na kartach zeszytów były skorelowanymi podzapytaniami na każdy
 *    zeszyt. Pracy było w nich tyle samo co teraz, ale planer mnożył koszt
 *    podzapytania przez liczbę zeszytów i przy 200 zeszytach szacunek
 *    przekraczał `jit_inline_above_cost` — PostgreSQL kompilował zapytanie
 *    przez JIT z inliningiem, ok. 0,6–0,7 s na samą kompilację
 *    (docs/infra/ZESZYTY_KOSZT_2030.md). Pilnuje tego szacowany koszt
 *    najdroższego zapytania ekranu przy 200 zeszytach i 20 000 zapisów.
 *    Szacunek, a nie czas: czas zależy od maszyny CI, a to szacunek
 *    decyduje o JIT. Zapas jest dwukrotny w obie strony (stary kod ok.
 *    995 tys., nowy ok. 204 tys., próg 500 tys.).
 *
 * I JEDNA RZECZ, KTÓRA SIĘ NIE ZMIENIA: kolejność i widoczność szyny oraz
 * liczby na kartach — blokady w obie strony, prywatne i szkice, treść
 * usunięta miękko, zbanowany autor, „dla obserwujących", rzecz w dwóch
 * zeszytach naraz, remis w tej samej sekundzie i więcej niewidocznych
 * rzeczy na górze, niż mieści jedna partia kandydatów.
 */
final class SzynaOstatnioZapisanychKosztTest extends TestCase
{
    use RefreshDatabase;

    public function test_szyna_i_liczniki_pokazuja_to_samo_co_przed_zmiana(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));

        $ja = $this->user('ja_szyna_2030');
        $autor = $this->user('autor_szyna_2030');
        $blokowany = $this->user('blokowany_szyna_2030');
        $blokujacy = $this->user('blokujacy_szyna_2030');
        $zbanowany = $this->user('zbanowany_szyna_2030', ['status' => User::STATUS_BANNED]);
        $obserwowany = $this->user('obserwowany_szyna_2030');
        $nieobserwowany = $this->user('nieobserwowany_szyna_2030');
        $obcy = $this->user('obcy_szyna_2030');

        DB::table('blocks')->insert(['blocker_id' => $ja->getKey(), 'blocked_id' => $blokowany->getKey()]);
        DB::table('blocks')->insert(['blocker_id' => $blokujacy->getKey(), 'blocked_id' => $ja->getKey()]);
        DB::table('follows')->insert(['follower_id' => $ja->getKey(), 'followed_id' => $obserwowany->getKey()]);

        $pierwszy = $this->zeszyt($ja, 'Pierwszy');
        $drugi = $this->zeszyt($ja, 'Drugi');
        $cudzy = $this->zeszyt($obcy, 'Cudzy');

        // Widoczne, w kolejności odłożenia.
        $wDwoch = $this->przepis($autor);
        $dlaObserwujacych = $this->przepis($obserwowany, 'followers');
        $wlasnyPrywatny = $this->przepis($ja, 'private');
        [$remisNizszy, $remisWyzszy] = collect([$this->przepis($autor), $this->przepis($autor)])
            ->sortBy(fn (Recipe $r) => (string) $r->getKey())->values()->all();
        $stary = $this->przepis($autor);

        $this->odloz($pierwszy, $wDwoch, 10);
        $this->odloz($drugi, $wDwoch, 1);
        $this->odloz($pierwszy, $dlaObserwujacych, 3);
        $this->odloz($pierwszy, $wlasnyPrywatny, 4);
        $this->odloz($pierwszy, $remisNizszy, 5);
        $this->odloz($pierwszy, $remisWyzszy, 5);
        $this->odloz($pierwszy, $stary, 100);

        // Niewidoczne, wszystkie NOWSZE niż cokolwiek widocznego — więcej niż
        // mieści pierwsza partia kandydatów (20).
        $usuniety = $this->przepis($autor);
        foreach ([
            $this->przepis($autor, 'private'),
            $this->przepis($blokowany),
            $this->przepis($blokujacy),
            $this->przepis($nieobserwowany, 'followers'),
            Recipe::factory()->for($autor, 'author')->draft()->create(),
            $usuniety,
        ] as $niewidoczny) {
            $this->odloz($pierwszy, $niewidoczny, 0);
        }
        $usuniety->delete();
        $this->odloz($drugi, $this->przepis($zbanowany), 0);
        for ($i = 0; $i < 25; $i++) {
            $this->odloz($pierwszy, $this->przepis($autor, 'private'), 0);
        }
        // Rzecz odłożona przez KOGOŚ INNEGO nie wchodzi do mojej szyny.
        $this->odloz($cudzy, $this->przepis($autor), 0);

        $wpis = $this->wpis($autor);
        $staryWpis = $this->wpis($autor);
        $this->odloz($drugi, $wpis, 2);
        $this->odloz($pierwszy, $staryWpis, 6);
        $usunietyWpis = $this->wpis($autor);
        $this->odloz($pierwszy, $usunietyWpis, 0);
        $usunietyWpis->delete();
        $this->odloz($pierwszy, $this->wpis($zbanowany), 0);
        $this->odloz($pierwszy, $this->wpis($autor, Post::VISIBILITY_PRIVATE), 0);
        $this->odloz($pierwszy, $this->wpis($blokowany), 0);

        $odpowiedz = $this->actingAs($ja)->get(route('collections.index'))->assertOk();

        $szyna = $odpowiedz->viewData('ostatnioZapisane');
        $this->assertSame([
            $wDwoch->url(),
            $wpis->url(),
            $dlaObserwujacych->url(),
            $wlasnyPrywatny->url(),
            $remisWyzszy->url(),
        ], $szyna->pluck('href')->all(), 'Szyna zmieniła kolejność albo widoczność.');
        // Czas odłożenia to NAJPÓŹNIEJSZY zapis po wszystkich zeszytach.
        $this->assertSame(now()->subMinutes(1)->getTimestamp(), CarbonImmutable::parse($szyna[0]['zapisano_at'])->getTimestamp());

        $liczniki = $odpowiedz->viewData('collections')->mapWithKeys(fn (Collection $z) => [$z->name => [
            (int) $z->recipes_count, (int) $z->getAttribute('recipes_total_count'), (int) $z->posts_count, (int) $z->getAttribute('posts_total_count'),
        ]])->all();
        $this->assertSame([
            // widoczne przepisy, wszystkie zachowane przepisy, widoczne wpisy, wszystkie zachowane wpisy
            'Drugi' => [1, 2, 1, 1],
            'Pierwszy' => [6, 37, 1, 5],
        ], $liczniki);
    }

    public function test_szyna_sprawdza_widocznosc_tylko_malej_partii_kandydatow(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
        $ja = $this->user('ja_koszt_szyny_2030');
        $autor = $this->user('autor_koszt_szyny_2030');
        $this->zasiej($ja, $autor, zeszytow: 10, naZeszyt: 30);
        DB::statement('ANALYZE');

        $zapytania = $this->zapytaniaEkranu($ja);

        // Liczniki kart czytają każdy zapis z definicji — ich koszt pilnuje
        // drugi test. Tu liczymy resztę ekranu: szynę i dociąganie relacji.
        $wiersze = 0;
        foreach ($zapytania as [$sql, $parametry]) {
            if (str_contains($sql, 'count(')) {
                continue;
            }
            $plan = json_decode((string) DB::selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
            $wiersze += $this->przeczytaneWiersze($plan[0]['Plan'], ['recipes', 'posts']);
        }

        fwrite(STDERR, "\n[#2030 szyna] 600 odłożonych rzeczy: przeczytanych wierszy recipes+posts poza licznikami: {$wiersze}\n");

        $this->assertLessThanOrEqual(
            100,
            $wiersze,
            "Szyna „Ostatnio zapisane” przeczytała {$wiersze} wierszy przepisów i wpisów przy 600 odłożonych rzeczach — sprawdza widoczność całej historii zamiast partii kandydatów.",
        );
    }

    public function test_liczniki_kart_nie_przekraczaja_progu_jit_przy_200_zeszytach(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
        $ja = $this->user('ja_koszt_licznikow_2030');
        $autor = $this->user('autor_koszt_licznikow_2030');
        $this->zasiej($ja, $autor, zeszytow: 200, naZeszyt: 50);
        DB::statement('ANALYZE');

        $prog = (float) DB::selectOne("SELECT current_setting('jit_inline_above_cost')::float8 AS prog")->prog;
        $najdrozsze = ['koszt' => 0.0, 'sql' => ''];

        foreach ($this->zapytaniaEkranu($ja) as [$sql, $parametry]) {
            $plan = json_decode((string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
            $koszt = (float) $plan[0]['Plan']['Total Cost'];
            if ($koszt > $najdrozsze['koszt']) {
                $najdrozsze = ['koszt' => $koszt, 'sql' => mb_substr($sql, 0, 160)];
            }
        }

        fwrite(STDERR, sprintf("\n[#2030 liczniki] 200 zeszytów, 20 000 zapisów: najwyższy szacunek %.0f (próg inliningu JIT %.0f)\n", $najdrozsze['koszt'], $prog));

        $this->assertLessThan(
            $prog,
            $najdrozsze['koszt'],
            "Zapytanie ekranu /zeszyt ma szacowany koszt {$najdrozsze['koszt']} ≥ jit_inline_above_cost ({$prog}), więc PostgreSQL kompiluje je przez JIT z inliningiem: {$najdrozsze['sql']}",
        );
    }

    /**
     * Wszystkie SELECT-y jednego wejścia na `/zeszyt`, z parametrami.
     *
     * @return list<array{0: string, 1: array<int, mixed>}>
     */
    private function zapytaniaEkranu(User $ja): array
    {
        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            if (preg_match('/^\s*select\b/i', $zapytanie->sql)) {
                $zapytania[] = [$zapytanie->sql, $zapytanie->bindings];
            }
        });

        $this->actingAs($ja)->get(route('collections.index'))->assertOk();

        $this->assertNotEmpty($zapytania);

        return $zapytania;
    }

    /**
     * Wiersze tabel `$tabele`, które węzły planu przeczytały: zwrócone
     * i odrzucone filtrem, razy liczba wykonań węzła.
     *
     * @param  array<string, mixed>  $wezel
     * @param  list<string>  $tabele
     */
    private function przeczytaneWiersze(array $wezel, array $tabele): int
    {
        $wiersze = 0;
        if (in_array($wezel['Relation Name'] ?? null, $tabele, true)) {
            $wiersze += (int) round((($wezel['Actual Rows'] ?? 0) + ($wezel['Rows Removed by Filter'] ?? 0)) * ($wezel['Actual Loops'] ?? 1));
        }
        foreach ($wezel['Plans'] ?? [] as $dziecko) {
            $wiersze += $this->przeczytaneWiersze($dziecko, $tabele);
        }

        return $wiersze;
    }

    /**
     * Hurtem, z pominięciem fabryk: `$zeszytow` zeszytów, w każdym
     * `$naZeszyt` widocznych przepisów i tyle samo wpisów, rozłożonych
     * na dwa lata.
     */
    private function zasiej(User $ja, User $autor, int $zeszytow, int $naZeszyt): void
    {
        $teraz = now()->toIso8601String();
        $rzeczy = $zeszytow * $naZeszyt;

        DB::insert(<<<'SQL'
            INSERT INTO recipes (author_id, title, slug, visibility, status, published_at, created_at, updated_at)
            SELECT ?, 'Przepis kosztowy ' || g, 'przepis-kosztowy-' || g, 'public', 'published', ?::timestamptz, ?::timestamptz, ?::timestamptz
            FROM generate_series(1, ?) AS g
            SQL, [$autor->getKey(), $teraz, $teraz, $teraz, $rzeczy]);
        DB::insert(<<<'SQL'
            INSERT INTO posts (author_id, body, visibility, status, published_at, created_at, updated_at, kind)
            SELECT ?, 'Wpis kosztowy ' || g, 'public', 'published', ?::timestamptz, ?::timestamptz, ?::timestamptz, 'dish'
            FROM generate_series(1, ?) AS g
            SQL, [$autor->getKey(), $teraz, $teraz, $teraz, $rzeczy]);
        DB::insert(<<<'SQL'
            INSERT INTO collections (owner_id, name, visibility, is_default, created_at, updated_at)
            SELECT ?, 'Zeszyt ' || z, 'private', false, ?::timestamptz, ?::timestamptz
            FROM generate_series(1, ?) AS z
            SQL, [$ja->getKey(), $teraz, $teraz, $zeszytow]);

        foreach (['recipe_id' => ['recipes', 'slug'], 'post_id' => ['posts', 'body']] as $kolumna => [$tabela, $porzadek]) {
            DB::insert(<<<SQL
                INSERT INTO collection_items (collection_id, {$kolumna}, created_at)
                SELECT c.id, t.id, ?::timestamptz - make_interval(mins => (t.nr * 97 % 1051200)::int)
                FROM (SELECT id, row_number() OVER (ORDER BY name) - 1 AS nr FROM collections WHERE owner_id = ?) c
                JOIN (SELECT id, row_number() OVER (ORDER BY {$porzadek}) - 1 AS nr FROM {$tabela}) t
                  ON t.nr / ? = c.nr
                SQL, [$teraz, $ja->getKey(), $naZeszyt]);
        }
    }

    private function zeszyt(User $wlasciciel, string $nazwa): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => 'private']);
    }

    private function przepis(User $autor, string $widocznosc = 'public'): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create(['visibility' => $widocznosc]);
    }

    private function wpis(User $autor, string $widocznosc = Post::VISIBILITY_PUBLIC): Post
    {
        return Post::factory()->for($autor, 'author')->create(['visibility' => $widocznosc]);
    }

    private function odloz(Collection $zeszyt, Recipe|Post $rzecz, int $minutTemu): void
    {
        $relacja = $rzecz instanceof Recipe ? $zeszyt->recipes() : $zeszyt->posts();
        $relacja->attach($rzecz->getKey(), ['created_at' => now()->subMinutes($minutTemu)]);
    }
}
