<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\TagFeed;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Obserwowanie tagu nie może być obejściem widoczności PRZEPISU.
 *
 * `TagFeed` filtruje wpisy przez `widoczneDla()` — i to działa. Ale wpis
 * wskazujący przepis (issue #368) jest na stałe `public`
 * (`WpisWskazujacyPrzepis::dopisz()`), bo widoczność ma trzymać PRZEPIS,
 * nie jego zapowiedź. Filtr po widoczności wpisu przepuszcza więc zapowiedź
 * przepisu, którego widz zobaczyć nie ma prawa.
 *
 * Pozostałe trzy strumienie mają na to `zWidocznymPrzepisem($widz)`:
 * `FollowingFeed` (dwa razy), `DiscoverFeed`, `DailyBoard` (cztery razy).
 * `TagFeed` jako jedyny go nie miał, a że `with('recipe:id,title,slug,…')`
 * dociąga tytuł i zdjęcie główne, karta wypisywała jedno i drugie.
 *
 * CO DOKŁADNIE WYCIEKAŁO. Nie sam przepis — w niego nie da się wejść,
 * `RecipePolicy` trzyma. Wyciekał TYTUŁ i ZDJĘCIE GŁÓWNE, czyli to, co
 * karta rysuje bez pytania o zgodę. Dla przepisu „tylko dla obserwujących"
 * to cała treść, jaką widać z zewnątrz.
 *
 * DOBÓR WIDZA JEST CZĘŚCIĄ TESTU. Ola obserwuje TAG, ale nie obserwuje
 * BASI — i to jedyna konfiguracja, w której ten wyciek widać. Gdyby Ola
 * obserwowała Basię, przepis byłby dla niej widoczny zgodnie z ustawieniem
 * i test przechodziłby z niewłaściwego powodu.
 */
class FeedTagowNiePokazujeCudzegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_przepis_tylko_dla_obserwujacych_nie_wchodzi_do_strumienia_tagow_obcej_osoby(): void
    {
        [$ola, $wpisPrzepisu, $wpisPubliczny] = $this->strumien();

        $widziane = collect(app(TagFeed::class)->paginate($ola)->items())
            ->map(fn (Post $wpis) => (string) $wpis->getKey())
            ->all();

        // KONTROLA DODATNIA: strumień w ogóle coś oddaje i ten sam tag
        // przepuszcza wpis, który przepuścić ma. Bez tego asercja niżej
        // przechodziłaby także wtedy, gdyby feed był pusty z byle powodu.
        $this->assertContains(
            (string) $wpisPubliczny->getKey(),
            $widziane,
            'Strumień tagów nie oddał nawet publicznego wpisu z tym tagiem — asercja niżej '
            .'nie mówiłaby wtedy o widoczności przepisu, tylko o pustym feedzie.',
        );

        $this->assertNotContains(
            (string) $wpisPrzepisu->getKey(),
            $widziane,
            'Zapowiedź przepisu „tylko dla obserwujących" weszła do strumienia tagów osoby, '
            .'która autora nie obserwuje. Obserwowanie tagu obeszło ustawienie prywatności.',
        );
    }

    public function test_tytul_i_zdjecie_cudzego_przepisu_nie_pojawiaja_sie_na_stronie_tagu(): void
    {
        [$ola] = $this->strumien();

        $html = $this->actingAs($ola)->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'Bigos z kapusty kiszonej',
            $html,
            'Tytuł przepisu „tylko dla obserwujących" stoi na ekranie osoby, która autora nie obserwuje.',
        );

        $this->assertStringNotContainsString(
            'Zdjęcie do przepisu: Bigos z kapusty kiszonej',
            $html,
            'Zdjęcie główne przepisu „tylko dla obserwujących" stoi na ekranie osoby, '
            .'która autora nie obserwuje.',
        );
    }

    public function test_autor_swoj_wlasny_przepis_w_strumieniu_tagow_dalej_widzi(): void
    {
        [, $wpisPrzepisu] = $this->strumien();

        $basia = User::query()->whereKey($wpisPrzepisu->author_id)->firstOrFail();

        $widziane = collect(app(TagFeed::class)->paginate($basia)->items())
            ->map(fn (Post $wpis) => (string) $wpis->getKey())
            ->all();

        $this->assertContains(
            (string) $wpisPrzepisu->getKey(),
            $widziane,
            'Poprawka odcięła autorowi jego własny przepis. „Poprawne dane nigdy nie znikają" — '
            .'własne archiwum widać zawsze, także prywatne.',
        );
    }

    /**
     * `maTresci()` musi pytać DOKŁADNIE tak samo jak `paginate()`.
     *
     * `FeedController::home()` wybiera strumień po tej metodzie. Gdyby pytała
     * szerzej, odpowiadałaby „jest co pokazać" o wpisach, których `paginate()`
     * i tak nie odda — i widz dostałby pusty strumień zamiast ekranu pustego
     * stanu, który mówi, co zrobić dalej. Rozjazd między tymi dwoma
     * zapytaniami nie rzuca wyjątku i nie zostawia śladu w dzienniku.
     */
    public function test_sam_ukryty_przepis_to_dla_strumienia_tagow_brak_tresci(): void
    {
        [$ola, $wpisPrzepisu, $wpisPubliczny] = $this->strumien();

        // KONTROLA DODATNIA: dopóki stoi publiczny wpis, treść JEST.
        $this->assertTrue(
            app(TagFeed::class)->maTresci($ola),
            'Strumień tagów nie widzi nawet publicznego wpisu — asercja niżej nie mówiłaby wtedy '
            .'o bramce przepisu.',
        );

        $wpisPubliczny->forceDelete();

        $this->assertSame(
            1,
            Post::query()->whereKey($wpisPrzepisu->getKey())->count(),
            'Zapowiedź przepisu zniknęła razem z publicznym wpisem — nie ma czego mierzyć.',
        );

        $this->assertFalse(
            app(TagFeed::class)->maTresci($ola),
            'Zostaje sama zapowiedź cudzego przepisu „tylko dla obserwujących", a strumień tagów '
            .'mówi, że jest co pokazać. `paginate()` odda pustą listę i widz zobaczy pusty ekran '
            .'zamiast pustego stanu.',
        );
    }

    /**
     * Basia ma przepis „tylko dla obserwujących" z tagiem, Ola obserwuje ten
     * sam tag i nie obserwuje Basi.
     *
     * @return array{0: User, 1: Post, 2: Post}
     */
    private function strumien(): array
    {
        $tag = Tag::create(['slug' => 'obiady', 'name' => 'Obiady', 'normalized_name' => 'obiady']);

        $basia = $this->user('basia');
        $ola = $this->user('ola');
        $ola->followedTags()->attach($tag->getKey(), ['created_at' => now()]);
        $basia->followedTags()->attach($tag->getKey(), ['created_at' => now()]);

        $this->assertFalse(
            $ola->following()->whereKey($basia->getKey())->exists(),
            'Ola obserwuje Basię — wtedy przepis „tylko dla obserwujących" jest dla niej widoczny '
            .'zgodnie z ustawieniem i ten test nie mierzy wycieku.',
        );

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Bigos z kapusty kiszonej', 'visibility' => 'followers', 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        $przepis->forceFill([
            'hero_media_id' => Media::factory()->create(['owner_id' => $basia->getKey()])->getKey(),
        ])->save();

        $this->assertSame('followers', $przepis->fresh()->visibility);

        /** @var Post $wpisPrzepisu */
        $wpisPrzepisu = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();
        $wpisPrzepisu->tags()->attach($tag->getKey(), ['position' => 0]);

        $this->assertSame(
            Post::VISIBILITY_PUBLIC,
            $wpisPrzepisu->visibility,
            'Zapowiedź przepisu nie jest publiczna — wtedy odciąłby ją zwykły filtr widoczności wpisu '
            .'i ten test przechodziłby z niewłaściwego powodu.',
        );

        $wpisPubliczny = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Zwykły obiad, bez przepisu.',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subMinute(),
        ]);
        $wpisPubliczny->tags()->attach($tag->getKey(), ['position' => 0]);

        return [$ola, $wpisPrzepisu, $wpisPubliczny];
    }
}
