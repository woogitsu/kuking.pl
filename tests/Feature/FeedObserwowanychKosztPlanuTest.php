<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\FollowingFeed;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feed obserwowanych (`FollowingFeed`, Start zalogowanej osoby) nie wpada w
 * kompilację JIT przy każdym żądaniu — i pokazuje przy tym DOKŁADNIE to samo
 * co przedtem (issue #599, alarm „Łączny czas zapytań SQL" na `GET /`).
 *
 * CO TU PILNUJEMY
 *
 * 1. Szacowany koszt planu zapytań feedu przy zbiorze w skali #605 (6000
 *    wpisów, 300 obserwowanych osób, 20 000 przepisów, pięć obserwowanych
 *    tematów) jest niższy niż `jit_above_cost`. Szacunek, a nie czas: czas
 *    zależy od maszyny CI, a to szacunek decyduje o JIT. Przed zmianą było
 *    ok. 155 tys. (próg 100 tys.), po zmianie kilka tysięcy
 *    (docs/infra/FEED_OBSERWOWANYCH_JIT_599.md). Przyczyna: skorelowane
 *    `EXISTS` (przepis wpisu, zdjęcie wpisu, obserwowanie autora, ukrycie
 *    osoby, temat wpisu) w alternatywach `OR` planer nalicza za KAŻDY
 *    kandydujący wiersz `posts`.
 *
 * 2. Ta sama lista w tej samej kolejności. `staraLista()` niżej to zapytanie
 *    sprzed zmiany, przepisane dosłownie (w tym stare postacie reguł
 *    widoczności wpisu i przepisu). Nowe zapytanie musi zwrócić to samo na
 *    zbiorze z pułapkami: wpisy ukryte, bez treści, z przepisem prywatnym,
 *    szkicem, usuniętym, autorzy zablokowani w obie strony i zbanowani,
 *    „tylko dla obserwujących" od obcych, tematy aktywne i ukryte.
 */
final class FeedObserwowanychKosztPlanuTest extends TestCase
{
    use RefreshDatabase;

    /** Połączenie z bazą, gdy test zasiał duży zbiór (do posprzątania po wycofaniu transakcji). */
    private ?\PDO $poZasiewie = null;

    /**
     * Wycofanie transakcji nie oddaje bazie stron ani statystyk: zostają martwe
     * krotki (tysiące wierszy w `posts`, `recipes`, `follows`) i `reltuples` z
     * dużego zbioru. Następne testy w tym samym procesie planowałyby zapytania
     * przy zawyżonych statystykach — `SzynaOstatnioZapisanychKosztTest` dawał
     * wtedy koszt 621 tys. zamiast 211 tys. `VACUUM` nie działa w transakcji,
     * więc idzie tu, PO jej wycofaniu.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->poZasiewie !== null) {
            $this->poZasiewie->exec('VACUUM (ANALYZE) users, profiles, follows, blocks, hides, recipes, posts, media, post_media, tags, post_tags, tag_follows');
            $this->poZasiewie = null;
        }
    }

    public function test_feed_zwraca_ta_sama_liste_co_zapytanie_sprzed_zmiany(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC'));

        $ja = $this->user('ja_feed_599');
        $obserwowany = $this->user('obserwowany_599');
        $obcy = $this->user('obcy_599');
        $blokowany = $this->user('blokowany_599');
        $blokujacy = $this->user('blokujacy_599');
        $zbanowany = $this->user('zbanowany_599', ['status' => User::STATUS_BANNED]);
        $ukrywany = $this->user('ukrywany_599');
        $ukrytyObcy = $this->user('ukryty_obcy_599');
        $temat = Tag::factory()->create();
        $ukrytyTemat = Tag::factory()->hidden()->create();

        DB::table('follows')->insert([
            ['follower_id' => $ja->getKey(), 'followed_id' => $obserwowany->getKey()],
            ['follower_id' => $ja->getKey(), 'followed_id' => $blokowany->getKey()],
            ['follower_id' => $ja->getKey(), 'followed_id' => $blokujacy->getKey()],
            ['follower_id' => $ja->getKey(), 'followed_id' => $zbanowany->getKey()],
            // Osoba ukryta, ale OBSERWOWANA wprost: gałąź osób jej nie chowa.
            ['follower_id' => $ja->getKey(), 'followed_id' => $ukrywany->getKey()],
            // Obcy obserwuje MNIE, nie ja jego: nie otwiera mu „tylko dla obserwujących".
            ['follower_id' => $obcy->getKey(), 'followed_id' => $ja->getKey()],
        ]);
        DB::table('blocks')->insert([
            ['blocker_id' => $ja->getKey(), 'blocked_id' => $blokowany->getKey()],
            ['blocker_id' => $blokujacy->getKey(), 'blocked_id' => $ja->getKey()],
        ]);
        $ja->followedTags()->attach($temat->getKey(), ['created_at' => now()]);
        $ja->followedTags()->attach($ukrytyTemat->getKey(), ['created_at' => now()]);

        $nr = 0;
        $wpis = function (User $autor, array $atrybuty = [], ?Recipe $przepis = null, ?Tag $tag = null, bool $zeZdjeciem = false) use (&$nr): Post {
            $nr++;
            $post = Post::factory()->for($autor, 'author')->create([
                'body' => 'Wpis '.$nr,
                'visibility' => Post::VISIBILITY_PUBLIC,
                'published_at' => now()->subMinutes($nr),
                ...($przepis === null ? [] : ['recipe_id' => $przepis->getKey()]),
                ...$atrybuty,
            ]);
            if ($tag !== null) {
                $post->tags()->attach($tag->getKey(), ['position' => 0]);
            }
            if ($zeZdjeciem) {
                $post->media()->attach(Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(), ['position' => 0]);
            }

            return $post;
        };
        $przepis = fn (User $autor, string $widocznosc = 'public') => Recipe::factory()->for($autor, 'author')->create(['visibility' => $widocznosc]);
        $zapowiedz = ['body' => ''];

        $oczekiwane = [
            $wpis($obserwowany),
            $wpis($obserwowany, ['visibility' => Post::VISIBILITY_FOLLOWERS]),
            $wpis($ja, ['visibility' => Post::VISIBILITY_FOLLOWERS]),
            $wpis($obserwowany, $zapowiedz, $przepis($obserwowany)),
            $wpis($obserwowany, $zapowiedz, $przepis($obserwowany, 'followers')),
            $wpis($obserwowany, $zapowiedz, $przepis($ja, 'private')),
            $wpis($obserwowany, ['body' => '   '], null, null, true),
            $wpis($obserwowany, ['body' => 'Własny opis'], $przepis($obcy, 'private')),
            $wpis($obserwowany, $zapowiedz, $przepis($obcy, 'private'), null, true),
            $wpis($obcy, ['body' => 'Z tematu'], null, $temat),
            $wpis($obcy, $zapowiedz, $przepis($obcy), $temat),
            $wpis($obserwowany, [], null, $temat),
            $wpis($ukrywany, ['body' => 'Obserwowana wprost, choć ukryta jako podsunięta']),
        ];
        $pulapki = [
            // Własny prywatny wpis nie jest w feedzie obserwowanych (gałąź osób: publiczne i „dla obserwujących").
            $wpis($ja, ['visibility' => Post::VISIBILITY_PRIVATE]),
            $wpis($obserwowany, ['visibility' => Post::VISIBILITY_PRIVATE]),
            $wpis($obcy, ['visibility' => Post::VISIBILITY_FOLLOWERS], null, $temat),
            $wpis($obcy, ['visibility' => Post::VISIBILITY_PRIVATE], null, $temat),
            $wpis($obcy),
            $wpis($obserwowany, $zapowiedz, $przepis($obcy, 'private')),
            $wpis($obserwowany, $zapowiedz, $przepis($obcy, 'followers')),
            $wpis($obserwowany, $zapowiedz, Recipe::factory()->for($obcy, 'author')->draft()->create()),
            $wpis($obserwowany, ['body' => "  \n "], $przepis($obcy, 'private')),
            $wpis($blokowany),
            $wpis($blokowany, [], null, $temat),
            $wpis($blokujacy),
            $wpis($blokujacy, [], null, $temat),
            $wpis($obserwowany, $zapowiedz, $przepis($blokowany)),
            $wpis($obserwowany, $zapowiedz, $przepis($blokujacy)),
            $wpis($zbanowany),
            $wpis($obcy, [], null, $ukrytyTemat),
            $wpis($obserwowany, ['status' => Post::STATUS_DRAFT]),
        ];
        $usuniety = $wpis($obserwowany);
        $usuniety->delete();
        $usunietyPrzepis = $przepis($obcy);
        $zUsunietymPrzepisem = $wpis($obserwowany, $zapowiedz, $usunietyPrzepis);
        $usunietyPrzepis->delete();
        $ukryty = $wpis($obserwowany);
        // Ukryta osoba, której NIE obserwuję: jej wpis z obserwowanego tematu odpada.
        $ukrytaOsobaZTematu = $wpis($ukrytyObcy, ['body' => 'Ukryta osoba, tylko z tematu'], null, $temat);
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
            $ukrycie(['hidden_user_id' => $ukrywany->getKey()]),
            $ukrycie(['hidden_user_id' => $ukrytyObcy->getKey()]),
            // Ukrycie wygasłe: nie działa.
            $ukrycie(['hidden_user_id' => $obcy->getKey()], now()->subDay()),
        ]);
        $pulapki = [...$pulapki, $zUsunietymPrzepisem, $ukryty, $ukrytaOsobaZTematu];

        $stara = $this->staraLista($ja);
        $nowa = $this->nowaLista($ja, po: 5);

        $this->assertSame($stara, $nowa, 'Nowe zapytanie feedu zwróciło inną listę albo kolejność niż zapytanie sprzed zmiany.');
        $this->assertEqualsCanonicalizing(
            collect($oczekiwane)->pluck('id')->all(),
            $nowa,
            'Kontrola zbioru: lista ma zawierać dokładnie widoczne wpisy sceny.',
        );
        foreach ($pulapki as $numer => $wpisPulapka) {
            $this->assertNotContains($wpisPulapka->getKey(), $nowa, "Pułapka nr {$numer} wyszła w feedzie.");
        }
        $this->assertFalse(app(FollowingFeed::class)->isEmptyFor($ja));
    }

    public function test_koszt_planu_feedu_przy_skali_605_nie_przekracza_progu_jit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC'));
        $ja = $this->zasiej605();

        $prog = (float) DB::selectOne("SELECT current_setting('jit_above_cost')::float8 AS prog")->prog;

        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            if (preg_match('/^\s*select\b/i', $zapytanie->sql)) {
                $zapytania[] = [$zapytanie->sql, $zapytanie->bindings];
            }
        });
        $strona = app(FollowingFeed::class)->paginate($ja);
        $this->assertNotEmpty($strona->items(), 'Kontrola: feed przy skali #605 nie jest pusty.');

        $najdrozsze = ['koszt' => 0.0, 'sql' => ''];
        foreach ($zapytania as [$sql, $parametry]) {
            $plan = json_decode((string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
            $koszt = (float) $plan[0]['Plan']['Total Cost'];
            if ($koszt > $najdrozsze['koszt']) {
                $najdrozsze = ['koszt' => $koszt, 'sql' => mb_substr($sql, 0, 160)];
            }
        }

        fwrite(STDERR, sprintf("\n[#599 feed] 6000 wpisów, 300 obserwowanych, 20 000 przepisów: najwyższy szacunek %.0f (próg JIT %.0f)\n", $najdrozsze['koszt'], $prog));

        $this->assertLessThan(
            $prog,
            $najdrozsze['koszt'],
            "Zapytanie feedu obserwowanych ma szacowany koszt {$najdrozsze['koszt']} ≥ jit_above_cost ({$prog}), więc PostgreSQL kompiluje je przez JIT przy każdym żądaniu: {$najdrozsze['sql']}",
        );

        // Ten sam wynik co zapytanie sprzed zmiany — także przy skali, na trzech stronach kursora.
        $this->assertSame(array_slice($this->staraLista($ja), 0, 45), $this->nowaLista($ja, po: 15, stron: 3));
    }

    /**
     * Kolejne strony kursora, sklejone w jedną listę identyfikatorów.
     *
     * @return list<string>
     */
    private function nowaLista(User $ja, int $po, int $stron = 100): array
    {
        $ids = [];
        $kursor = null;
        for ($i = 0; $i < $stron; $i++) {
            // `cursorPaginate()` czyta kursor z parametru `cursor` żądania.
            request()->merge(['cursor' => $kursor]);
            $strona = app(FollowingFeed::class)->paginate($ja, $po);
            $ids = [...$ids, ...collect($strona->items())->pluck('id')->all()];
            if (! $strona->hasMorePages()) {
                break;
            }
            $kursor = $strona->nextCursor()?->encode();
        }
        request()->merge(['cursor' => null]);

        return $ids;
    }

    /**
     * Zapytanie feedu sprzed zmiany (#599), dosłownie: skorelowane `EXISTS`
     * w regułach widoczności i w gałęzi tematów.
     *
     * @return list<string>
     */
    private function staraLista(User $ja): array
    {
        $obserwowane = DB::table('tag_follows')->select('tag_id')->where('user_id', $ja->getKey());
        $tematy = Tag::query()->aktywne()
            ->where(fn ($q) => $q->whereIn('id', $obserwowane)->orWhereIn(
                'id',
                Tag::query()->select('merged_into_tag_id')->where('status', Tag::STATUS_MERGED)->whereIn('id', $obserwowane),
            ))
            ->pluck('id')->all();
        $autorzy = array_values(array_unique([...$ja->following()->pluck('users.id')->all(), $ja->getKey()]));

        $zapytanie = Post::query()
            ->enabledKinds()
            ->published()
            ->where(function (Builder $zrodla) use ($ja, $autorzy, $tematy): void {
                $zrodla->where(fn (Builder $osoby) => $osoby
                    ->whereIn('posts.author_id', $autorzy)
                    ->whereIn('posts.visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS]));
                if ($tematy === []) {
                    return;
                }
                $zrodla->orWhere(fn (Builder $tagi) => $tagi
                    ->where('posts.visibility', Post::VISIBILITY_PUBLIC)
                    ->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tematy)->where('tags.status', Tag::STATUS_ACTIVE))
                    ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('hides')
                        ->where('hides.user_id', $ja->getKey())
                        ->whereColumn('hides.hidden_user_id', 'posts.author_id')
                        ->where(fn ($q) => $q->whereNull('hides.hidden_until')->orWhere('hides.hidden_until', '>', now()))));
            });
        $this->staraWidocznoscWpisu($zapytanie, $ja->getKey());
        $zapytanie->tylkoOdAktywnychAutorow()
            ->bezUkrytychWpisow($ja)
            ->where(function (Builder $w) use ($ja): void {
                $w->where(fn (Builder $tresc) => $tresc->zWlasnaTrescia())
                    ->orWhere(fn (Builder $zapowiedz) => $zapowiedz->where(function (Builder $p) use ($ja): void {
                        $p->whereNull('posts.recipe_id')->orWhereHas('recipe', function ($przepis) use ($ja): void {
                            $przepis->published();
                            $this->staraWidocznoscPrzepisu($przepis, $ja->getKey());
                        });
                    }));
            });

        return $zapytanie->orderByDesc('published_at')->orderByDesc('id')->pluck('posts.id')->all();
    }

    /**
     * `Post::scopeWidoczneDla()` sprzed zmiany (obserwowanie jako skorelowane `EXISTS`).
     *
     * @param  Builder<Post>  $query
     */
    private function staraWidocznoscWpisu(Builder $query, string $widzId): void
    {
        $query->enabledKinds();
        $query->whereNotExists(function ($sub) use ($widzId): void {
            $sub->selectRaw('1')->from('blocks')
                ->where(fn ($w) => $w->where('blocks.blocker_id', $widzId)->whereColumn('blocks.blocked_id', 'posts.author_id'))
                ->orWhere(fn ($w) => $w->whereColumn('blocks.blocker_id', 'posts.author_id')->where('blocks.blocked_id', $widzId));
        });
        $query->where(function ($w) use ($widzId): void {
            $w->where('posts.author_id', $widzId)->orWhere(fn ($cudze) => $cudze->published()->where(
                fn ($widok) => $widok->where('visibility', Post::VISIBILITY_PUBLIC)->orWhere(
                    fn ($obs) => $obs->where('visibility', Post::VISIBILITY_FOLLOWERS)->whereExists(
                        fn ($sub) => $sub->selectRaw('1')->from('follows')
                            ->where('follows.follower_id', $widzId)->whereColumn('follows.followed_id', 'posts.author_id'),
                    ),
                ),
            ));
        });
    }

    /**
     * `Recipe::scopeWidoczneDla()` sprzed zmiany.
     *
     * @param  Builder<Recipe>  $query
     */
    private function staraWidocznoscPrzepisu(Builder $query, string $widzId): void
    {
        $query->whereNotExists(function ($sub) use ($widzId): void {
            $sub->selectRaw('1')->from('blocks')
                ->where(fn ($w) => $w->where('blocks.blocker_id', $widzId)->whereColumn('blocks.blocked_id', 'recipes.author_id'))
                ->orWhere(fn ($w) => $w->whereColumn('blocks.blocker_id', 'recipes.author_id')->where('blocks.blocked_id', $widzId));
        });
        $query->where(function ($w) use ($widzId): void {
            $w->where('recipes.author_id', $widzId)->orWhere(fn ($cudze) => $cudze->published()->where(
                fn ($widok) => $widok->where('visibility', 'public')->orWhere(
                    fn ($obs) => $obs->where('visibility', 'followers')->whereExists(
                        fn ($sub) => $sub->selectRaw('1')->from('follows')
                            ->where('follows.follower_id', $widzId)->whereColumn('follows.followed_id', 'recipes.author_id'),
                    ),
                ),
            ));
        });
    }

    /**
     * Zbiór w skali #605, hurtem (fabryka przy tej liczbie jest za wolna):
     * 361 kont, 300 obserwowanych, 6000 wpisów (co 3. zapowiedź przepisu,
     * co 4. ze zdjęciem, mieszane widoczności), 20 000 przepisów, tło obserwowań
     * (60 osób na konto), pięć obserwowanych tematów, ukrycia i blokady.
     */
    private function zasiej605(): User
    {
        $this->poZasiewie = DB::connection()->getPdo();
        $teraz = now()->toIso8601String();
        $ja = $this->user('ja_feed_605');
        User::factory()->count(360)->create();
        $ids = User::query()->where('id', '!=', $ja->getKey())->orderBy('id')->pluck('id')->all();
        $lista = "'".implode("','", $ids)."'";
        $n = count($ids);

        DB::table('follows')->insert(array_map(fn ($id) => ['follower_id' => $ja->getKey(), 'followed_id' => $id], array_slice($ids, 0, 300)));
        DB::insert(<<<'SQL'
            INSERT INTO follows (follower_id, followed_id)
            SELECT a.id, b.id FROM (SELECT id, row_number() OVER (ORDER BY id) rn FROM users WHERE id <> ?) a
            JOIN (SELECT id, row_number() OVER (ORDER BY id) rn FROM users WHERE id <> ?) b ON ((b.rn - a.rn + 360) % 360) BETWEEN 1 AND 60
            ON CONFLICT DO NOTHING
            SQL, [$ja->getKey(), $ja->getKey()]);
        DB::insert(<<<SQL
            INSERT INTO recipes (author_id, title, slug, visibility, status, published_at, created_at, updated_at)
            SELECT (ARRAY[{$lista}]::uuid[])[1 + g % {$n}], 'Przepis ' || g, 'przepis-599-' || g,
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
        DB::insert(<<<'SQL'
            INSERT INTO media (id, owner_id, object_key, status, created_at, updated_at)
            SELECT gen_random_uuid(), p.author_id, 'k599/' || p.id, 'ready', now(), now()
            FROM (SELECT id, author_id, row_number() OVER (ORDER BY id) rn FROM posts) p WHERE p.rn % 4 = 0
            SQL);
        DB::insert("INSERT INTO post_media (post_id, media_id, position) SELECT p.id, m.id, 0 FROM posts p JOIN media m ON m.object_key = 'k599/' || p.id");
        DB::insert(<<<'SQL'
            INSERT INTO tags (id, name, normalized_name, slug, status, created_at, updated_at)
            SELECT gen_random_uuid(), 'temat' || g, 'temat' || g, 'temat-599-' || g, 'active', now(), now() FROM generate_series(1, 30) g
            SQL);
        DB::insert(<<<'SQL'
            INSERT INTO post_tags (post_id, tag_id, position)
            SELECT p.id, t.id, 0 FROM (SELECT id, row_number() OVER (ORDER BY id) rn FROM posts) p
            JOIN (SELECT id, row_number() OVER (ORDER BY id) rn FROM tags) t ON t.rn = 1 + p.rn % 30 WHERE p.rn % 2 = 0
            SQL);
        DB::insert('INSERT INTO tag_follows (user_id, tag_id, created_at) SELECT ?, id, now() FROM (SELECT id FROM tags ORDER BY slug LIMIT 5) x', [$ja->getKey()]);
        DB::insert('INSERT INTO hides (user_id, post_id) SELECT ?, id FROM posts ORDER BY id LIMIT 10', [$ja->getKey()]);
        DB::insert('INSERT INTO hides (user_id, hidden_user_id) SELECT ?, u.id FROM users u WHERE u.id <> ? ORDER BY u.id DESC LIMIT 3', [$ja->getKey(), $ja->getKey()]);
        DB::table('blocks')->insert([
            ['blocker_id' => $ja->getKey(), 'blocked_id' => $ids[3]],
            ['blocker_id' => $ids[7], 'blocked_id' => $ja->getKey()],
        ]);
        DB::table('users')->where('id', $ids[11])->update(['status' => User::STATUS_BANNED]);
        DB::statement('ANALYZE');

        return $ja;
    }
}
