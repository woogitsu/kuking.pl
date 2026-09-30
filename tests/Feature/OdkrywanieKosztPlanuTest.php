<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DiscoverFeed;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Świeżo z Kuking" (`DiscoverFeed`: `/odkryj`, `/` gościa i Start osoby,
 * która nikogo nie obserwuje) nie wpada w kompilację JIT przy każdym
 * żądaniu — i pokazuje przy tym DOKŁADNIE to samo co przedtem (issue #2288,
 * audyt docs/audyt/2026-09-30-wydajnosc-baza.md, F1). Wzorzec: #599,
 * `FeedObserwowanychKosztPlanuTest`.
 *
 * CO TU PILNUJEMY
 *
 * 1. Najwyższy szacowany koszt planu SELECT-ów jednego `paginate()` przy
 *    zbiorze w skali #605 jest niższy niż `jit_above_cost` — dla gościa, dla
 *    zalogowanej osoby z blokadami i ukryciami i dla Startu z własnymi
 *    wpisami. Szacunek, a nie czas: czas zależy od maszyny CI, a to szacunek
 *    decyduje o JIT. Przyczyna przed zmianą: skorelowane `EXISTS` (przepis
 *    wpisu, zdjęcie wpisu, obserwowanie autora przepisu, ukrycie osoby)
 *    w alternatywach `OR` planer nalicza za KAŻDY kandydujący wiersz.
 *
 * 2. Ta sama lista w tej samej kolejności rund. `staraLista()` to podzapytanie
 *    rotacji sprzed zmiany (skorelowane postacie scope'ów, które zostają
 *    w modelu dla innych list) plus ta sama trójka sortowania. Nowe zapytanie,
 *    przeczytane stronami kursora, musi dać to samo na scenie z pułapkami.
 */
final class OdkrywanieKosztPlanuTest extends TestCase
{
    use RefreshDatabase;

    /** Połączenie z bazą, gdy test zasiał duży zbiór (do posprzątania po wycofaniu transakcji). */
    private ?\PDO $poZasiewie = null;

    /** Patrz `FeedObserwowanychKosztPlanuTest::tearDown()`: martwe krotki i `reltuples` psują plany następnych testów. */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->poZasiewie !== null) {
            $this->poZasiewie->exec('VACUUM (ANALYZE) users, profiles, follows, blocks, hides, recipes, posts, media, post_media');
            $this->poZasiewie = null;
        }
    }

    public function test_odkrywanie_zwraca_ta_sama_liste_co_zapytanie_sprzed_zmiany(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00', 'UTC'));

        $ja = $this->user('ja_odkryj_2288');
        $obserwowany = $this->user('obserwowany_2288');
        $obcy = $this->user('obcy_2288');
        $drugiObcy = $this->user('drugi_obcy_2288');
        $blokowany = $this->user('blokowany_2288');
        $blokujacy = $this->user('blokujacy_2288');
        $zawieszony = $this->user('zawieszony_2288', ['status' => User::STATUS_SUSPENDED]);
        $zbanowany = $this->user('zbanowany_2288', ['status' => User::STATUS_BANNED]);
        $ukrywany = $this->user('ukrywany_2288');
        $ukrytyDawniej = $this->user('ukryty_dawniej_2288');

        DB::table('follows')->insert([
            ['follower_id' => $ja->getKey(), 'followed_id' => $obserwowany->getKey()],
            ['follower_id' => $ja->getKey(), 'followed_id' => $ukrywany->getKey()],
            // Obcy obserwuje MNIE, nie ja jego: nie otwiera mi jego przepisów „dla obserwujących".
            ['follower_id' => $obcy->getKey(), 'followed_id' => $ja->getKey()],
        ]);
        DB::table('blocks')->insert([
            ['blocker_id' => $ja->getKey(), 'blocked_id' => $blokowany->getKey()],
            ['blocker_id' => $blokujacy->getKey(), 'blocked_id' => $ja->getKey()],
        ]);

        $nr = 0;
        $wpis = function (User $autor, array $atrybuty = [], ?Recipe $przepis = null, bool $zeZdjeciem = false) use (&$nr): Post {
            $nr++;
            $post = Post::factory()->for($autor, 'author')->create([
                'body' => 'Wpis '.$nr,
                'visibility' => Post::VISIBILITY_PUBLIC,
                'published_at' => now()->subMinutes($nr),
                ...($przepis === null ? [] : ['recipe_id' => $przepis->getKey()]),
                ...$atrybuty,
            ]);
            if ($zeZdjeciem) {
                $post->media()->attach(Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(), ['position' => 0]);
            }

            return $post;
        };
        $przepis = fn (User $autor, string $widocznosc = 'public') => Recipe::factory()->for($autor, 'author')->create(['visibility' => $widocznosc]);
        $zapowiedz = ['body' => ''];

        // Kilka wpisów na autora, żeby rundy rotacji miały co numerować —
        // i pułapki PRZEPLECIONE z widocznymi: odsiany wpis nie może zostawić
        // dziury w rundach autora ani w starym, ani w nowym zapytaniu.
        foreach ([$obserwowany, $obcy, $drugiObcy, $ja] as $autor) {
            $wpis($autor);
            $wpis($autor, $zapowiedz, $przepis($autor));
            $wpis($autor, $zapowiedz, $przepis($obcy, 'private'));
            $wpis($autor, ['body' => '   '], null, true);
            $wpis($autor, ['body' => 'Własny opis'], $przepis($drugiObcy, 'private'));
            $wpis($autor, $zapowiedz, $przepis($obserwowany, 'followers'));
            $wpis($autor, $zapowiedz, $przepis($obcy, 'followers'));
            $wpis($autor, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
            $wpis($autor, ['visibility' => Post::VISIBILITY_PRIVATE]);
            $wpis($autor, ['body' => "  \n "], $przepis($obcy, 'private'));
            $wpis($autor, $zapowiedz, $przepis($obcy, 'private'), true);
            $wpis($autor, $zapowiedz, Recipe::factory()->for($obcy, 'author')->draft()->create());
            $wpis($autor, $zapowiedz, $przepis($blokowany));
            $wpis($autor, $zapowiedz, $przepis($blokujacy));
            $wpis($autor, $zapowiedz, $przepis($ja, 'private'));
            $wpis($autor, $zapowiedz, $przepis($ja, 'followers'));
            $wpis($autor, ['status' => Post::STATUS_DRAFT]);
            $wpis($autor);
        }
        foreach ([$blokowany, $blokujacy, $zawieszony, $zbanowany, $ukrywany, $ukrytyDawniej] as $autor) {
            $wpis($autor);
            $wpis($autor, $zapowiedz, $przepis($autor));
        }
        $usuniety = $wpis($obcy);
        $usuniety->delete();
        $usunietyPrzepis = $przepis($obcy);
        $wpis($drugiObcy, $zapowiedz, $usunietyPrzepis);
        $usunietyPrzepis->delete();
        $ukryty = $wpis($obcy);
        $ukrytyWygaslo = $wpis($obcy);

        $ukrycie = fn (array $cel, $do = null): array => [
            'id' => (string) Str::uuid(),
            'user_id' => $ja->getKey(),
            'post_id' => null,
            'hidden_user_id' => null,
            'hidden_until' => $do,
            'created_at' => now(),
            'updated_at' => now(),
            ...$cel,
        ];
        DB::table('hides')->insert([
            $ukrycie(['post_id' => $ukryty->getKey()]),
            $ukrycie(['post_id' => $ukrytyWygaslo->getKey()], now()->subDay()),
            // Obserwowana wprost, ale Odkrywanie PODSUWA ludzi — ukrycie osoby działa.
            $ukrycie(['hidden_user_id' => $ukrywany->getKey()]),
            $ukrycie(['hidden_user_id' => $ukrytyDawniej->getKey()], now()->subDay()),
            $ukrycie(['hidden_user_id' => $drugiObcy->getKey()], now()->addDay()),
        ]);

        $widzowie = [
            'gość' => [null, false],
            'ja' => [$ja, false],
            'ja, Start z własnymi' => [$ja, true],
            'obcy' => [$obcy, false],
            'zablokowany przeze mnie' => [$blokowany, false],
        ];
        foreach ($widzowie as $opis => [$widz, $zWlasnymi]) {
            $stara = $this->staraLista($widz, $zWlasnymi);
            $this->assertGreaterThan(10, count($stara), "Kontrola sceny ({$opis}): stara lista nie może być prawie pusta.");
            foreach ([4, 7] as $po) {
                $this->assertSame(
                    $stara,
                    $this->nowaLista($widz, $po, $zWlasnymi),
                    "Odkrywanie ({$opis}, po {$po}) zwróciło inną listę albo kolejność niż zapytanie sprzed zmiany.",
                );
            }
        }

        // Kontrola, że scena ma zęby: pułapki odpadają, a to, co ma wyjść, wychodzi.
        $moja = $this->staraLista($ja, false);
        foreach ([$ukryty, $usuniety] as $pulapka) {
            $this->assertNotContains($pulapka->getKey(), $moja);
        }
        $this->assertContains($ukrytyWygaslo->getKey(), $moja, 'Wygasłe ukrycie wpisu nie działa.');
        foreach (['blokowany' => $blokowany, 'blokujący' => $blokujacy, 'zawieszony' => $zawieszony, 'zbanowany' => $zbanowany, 'ukrywany' => $ukrywany, 'ukryty do jutra' => $drugiObcy] as $kto => $autor) {
            $this->assertSame([], Post::query()->whereIn('id', $moja)->where('author_id', $autor->getKey())->pluck('id')->all(), "Wpis autora „{$kto}” wyszedł mimo bramki.");
        }
        $this->assertNotSame([], Post::query()->whereIn('id', $moja)->where('author_id', $ukrytyDawniej->getKey())->pluck('id')->all(), 'Wygasłe ukrycie osoby nie działa.');
    }

    public function test_koszt_planu_odkrywania_przy_skali_605_nie_przekracza_progu_jit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00', 'UTC'));
        $ja = $this->zasiej605();

        $prog = (float) DB::selectOne("SELECT current_setting('jit_above_cost')::float8 AS prog")->prog;

        $zapytania = null;
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            if ($zapytania !== null && preg_match('/^\s*select\b/i', $zapytanie->sql)) {
                $zapytania[] = [$zapytanie->sql, $zapytanie->bindings];
            }
        });

        foreach ([[null, false], [$ja, false], [$ja, true]] as [$widz, $zWlasnymi]) {
            $opis = $widz === null ? 'gość' : ($zWlasnymi ? 'zalogowany, Start z własnymi' : 'zalogowany');
            $zapytania = [];
            $strona = app(DiscoverFeed::class)->paginate($widz, null, null, $zWlasnymi);
            $zbierane = $zapytania;
            $zapytania = null;
            $this->assertNotEmpty($strona->items(), "Kontrola ({$opis}): Odkrywanie przy skali #605 nie jest puste.");

            $najdrozsze = ['koszt' => 0.0, 'sql' => ''];
            foreach ($zbierane as [$sql, $parametry]) {
                $plan = json_decode((string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
                $koszt = (float) $plan[0]['Plan']['Total Cost'];
                if ($koszt > $najdrozsze['koszt']) {
                    $najdrozsze = ['koszt' => $koszt, 'sql' => mb_substr($sql, 0, 160)];
                }
            }

            fwrite(STDERR, sprintf("\n[#2288 odkrywanie, %s] 6000 wpisów, 20 000 przepisów: najwyższy szacunek %.0f (próg JIT %.0f)\n", $opis, $najdrozsze['koszt'], $prog));

            $this->assertLessThan(
                $prog,
                $najdrozsze['koszt'],
                "Zapytanie Odkrywania ({$opis}) ma szacowany koszt {$najdrozsze['koszt']} ≥ jit_above_cost ({$prog}), więc PostgreSQL kompiluje je przez JIT przy każdym żądaniu: {$najdrozsze['sql']}",
            );

            // ZAPAS. Koszt rośnie z liczbą wpisów mniej więcej liniowo, a audyt
            // z 30.09 zmierzył na 30 tys. wpisów kilka razy więcej niż ta scena
            // (gość: 317 tys.). Stara postać gościa ma tu ok. 85 tys. — pod
            // progiem, ale bez zapasu — więc sam próg nie złapałby jej powrotu.
            // Piąta część progu łapie, a nowa postać (ok. 2–3 tys.) ma zapas.
            $this->assertLessThan(
                $prog / 5,
                $najdrozsze['koszt'],
                "Zapytanie Odkrywania ({$opis}) ma szacowany koszt {$najdrozsze['koszt']}: przy pięć razy większym serwisie przekroczy jit_above_cost ({$prog}). {$najdrozsze['sql']}",
            );

            // Ten sam wynik co zapytanie sprzed zmiany — także przy skali, na trzech stronach kursora.
            $this->assertSame(
                array_slice($this->staraLista($widz, $zWlasnymi), 0, 45),
                $this->nowaLista($widz, 15, $zWlasnymi, stron: 3),
                "Odkrywanie ({$opis}) przy skali zwróciło inną listę niż zapytanie sprzed zmiany.",
            );
        }
    }

    /**
     * Kolejne strony kursora, sklejone w jedną listę identyfikatorów. Dalsze
     * strony niosą `stan` (chwilę pierwszej strony), tak jak odnośnik „Pokaż więcej".
     *
     * @return list<string>
     */
    private function nowaLista(?User $widz, int $po, bool $zWlasnymi, int $stron = 100): array
    {
        $ids = [];
        $kursor = null;
        $stan = null;
        for ($i = 0; $i < $stron; $i++) {
            request()->merge(['cursor' => $kursor]);
            $strona = app(DiscoverFeed::class)->paginate($widz, $po, $stan, $zWlasnymi);
            $ids = [...$ids, ...collect($strona->items())->pluck('id')->all()];
            if (! $strona->hasMorePages()) {
                break;
            }
            $kursor = $strona->nextCursor()?->encode();
            $stan = (string) now()->startOfSecond()->getTimestamp();
        }
        request()->merge(['cursor' => null]);

        return $ids;
    }

    /**
     * Podzapytanie rotacji sprzed zmiany (#2288), dosłownie: skorelowane
     * `zWidocznymPrzepisemAlboWlasnaTrescia()` i `bezUkrytychOsob()` bez
     * `bezKorelacji`. Obie postacie zostają w `Post` dla innych list, a ich
     * własną treść pilnują testy widoczności — tu są punktem odniesienia.
     *
     * @return list<string>
     */
    private function staraLista(?User $widz, bool $zWlasnymi): array
    {
        $wlasne = $zWlasnymi && $widz !== null;
        $ukryciAutorzy = $widz === null ? [] : array_values(array_unique([
            ...$widz->blocking()->pluck('users.id')->all(),
            ...$widz->blockedBy()->pluck('users.id')->all(),
        ]));

        $rundy = Post::query()
            ->select('posts.id')
            ->selectRaw('row_number() OVER (PARTITION BY posts.author_id ORDER BY posts.published_at DESC, posts.id DESC) AS runda')
            ->when(! $wlasne, fn ($query) => $query->publiclyVisible())
            ->when($wlasne, fn ($query) => $query
                ->enabledKinds()
                ->published()
                ->where(fn ($widocznosc) => $widocznosc
                    ->where('posts.visibility', Post::VISIBILITY_PUBLIC)
                    ->orWhere(fn ($moje) => $moje
                        ->where('posts.author_id', $widz->getKey())
                        ->where('posts.visibility', Post::VISIBILITY_FOLLOWERS))))
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->when($widz !== null, fn ($query) => $query
                ->whereNotIn('posts.author_id', $ukryciAutorzy)
                ->bezUkrytychWpisow($widz)
                ->bezUkrytychOsob($widz))
            ->zWidocznymPrzepisemAlboWlasnaTrescia($widz);

        return Post::query()
            ->select('posts.id')
            ->joinSub($rundy, 'rotacja', 'rotacja.id', '=', 'posts.id')
            ->orderBy('rotacja.runda')
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id')
            ->pluck('posts.id')
            ->all();
    }

    /**
     * Zbiór w skali #605, hurtem (fabryka przy tej liczbie jest za wolna):
     * 361 kont (część zawieszona i zbanowana), 6000 wpisów (co 3. zapowiedź
     * przepisu, co 4. ze zdjęciem, mieszane widoczności), 20 000 przepisów,
     * tło obserwowań, ukrycia wpisów i osób, blokady w obie strony.
     */
    private function zasiej605(): User
    {
        $this->poZasiewie = DB::connection()->getPdo();
        $teraz = now()->toIso8601String();
        $ja = $this->user('ja_odkryj_605');
        User::factory()->count(360)->create();
        $ids = User::query()->where('id', '!=', $ja->getKey())->orderBy('id')->pluck('id')->all();
        $lista = "'".implode("','", $ids)."'";
        $n = count($ids);

        DB::table('follows')->insert(array_map(fn ($id) => ['follower_id' => $ja->getKey(), 'followed_id' => $id], array_slice($ids, 0, 40)));
        DB::insert(<<<'SQL'
            INSERT INTO follows (follower_id, followed_id)
            SELECT a.id, b.id FROM (SELECT id, row_number() OVER (ORDER BY id) rn FROM users WHERE id <> ?) a
            JOIN (SELECT id, row_number() OVER (ORDER BY id) rn FROM users WHERE id <> ?) b ON ((b.rn - a.rn + 360) % 360) BETWEEN 1 AND 60
            ON CONFLICT DO NOTHING
            SQL, [$ja->getKey(), $ja->getKey()]);
        DB::insert(<<<SQL
            INSERT INTO recipes (author_id, title, slug, visibility, status, published_at, created_at, updated_at)
            SELECT (ARRAY[{$lista}]::uuid[])[1 + g % {$n}], 'Przepis ' || g, 'przepis-2288-' || g,
                   CASE WHEN g % 10 = 0 THEN 'private' WHEN g % 7 = 0 THEN 'followers' ELSE 'public' END,
                   CASE WHEN g % 13 = 0 THEN 'draft' ELSE 'published' END,
                   ?::timestamptz, ?::timestamptz, ?::timestamptz
            FROM generate_series(1, 20000) AS g
            SQL, [$teraz, $teraz, $teraz]);
        DB::insert(<<<SQL
            INSERT INTO posts (author_id, body, recipe_id, visibility, status, published_at, created_at, updated_at, kind)
            SELECT (ARRAY[{$lista}]::uuid[])[1 + g % {$n}],
                   CASE WHEN g % 3 = 0 THEN '' WHEN g % 11 = 0 THEN '   ' ELSE 'Wpis ' || g END,
                   CASE WHEN g % 3 = 0 OR g % 5 = 0 THEN (SELECT id FROM recipes ORDER BY slug OFFSET (g % 2000) LIMIT 1) END,
                   CASE WHEN g % 17 = 0 THEN 'private' WHEN g % 9 = 0 THEN 'followers' ELSE 'public' END,
                   'published', ?::timestamptz - make_interval(mins => g), ?::timestamptz, ?::timestamptz, 'dish'
            FROM generate_series(1, 6000) AS g
            SQL, [$teraz, $teraz, $teraz]);
        // Własne wpisy widza — Start z własnymi (`$zWlasnymi`) ma je w rotacji.
        DB::insert(<<<'SQL'
            INSERT INTO posts (author_id, body, visibility, status, published_at, created_at, updated_at, kind)
            SELECT ?, 'Mój wpis ' || g, CASE WHEN g % 2 = 0 THEN 'followers' ELSE 'public' END,
                   'published', ?::timestamptz - make_interval(mins => g * 7), ?::timestamptz, ?::timestamptz, 'dish'
            FROM generate_series(1, 20) AS g
            SQL, [$ja->getKey(), $teraz, $teraz, $teraz]);
        DB::insert(<<<'SQL'
            INSERT INTO media (id, owner_id, object_key, status, created_at, updated_at)
            SELECT gen_random_uuid(), p.author_id, 'k2288/' || p.id, 'ready', now(), now()
            FROM (SELECT id, author_id, row_number() OVER (ORDER BY id) rn FROM posts) p WHERE p.rn % 4 = 0
            SQL);
        DB::insert("INSERT INTO post_media (post_id, media_id, position) SELECT p.id, m.id, 0 FROM posts p JOIN media m ON m.object_key = 'k2288/' || p.id");
        DB::insert('INSERT INTO hides (user_id, post_id) SELECT ?, id FROM posts ORDER BY id LIMIT 10', [$ja->getKey()]);
        DB::insert('INSERT INTO hides (user_id, hidden_user_id) SELECT ?, u.id FROM users u WHERE u.id <> ? ORDER BY u.id DESC LIMIT 3', [$ja->getKey(), $ja->getKey()]);
        DB::table('blocks')->insert([
            ['blocker_id' => $ja->getKey(), 'blocked_id' => $ids[3]],
            ['blocker_id' => $ids[7], 'blocked_id' => $ja->getKey()],
        ]);
        DB::table('users')->where('id', $ids[11])->update(['status' => User::STATUS_BANNED]);
        DB::table('users')->where('id', $ids[12])->update(['status' => User::STATUS_SUSPENDED]);
        DB::statement('ANALYZE');

        return $ja;
    }
}
