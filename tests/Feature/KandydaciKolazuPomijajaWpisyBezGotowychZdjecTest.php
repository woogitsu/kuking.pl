<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\HeroKolaz;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #1802 — lista zdjęć do wyboru w panelu kolażu powitalnego nie może się
 * opróżnić tylko dlatego, że najnowsze wpisy nie mają jeszcze gotowych zdjęć.
 *
 * Do 25.09.2026 `HeroKolaz::kandydaci()` brał 60 najnowszych wpisów i dopiero
 * potem odsiewał w PHP te bez zdjęcia `ready`. Seria świeżych wpisów ze
 * zdjęciami w obróbce (albo bez zdjęć) wypychała poza limit starsze wpisy,
 * które miały czym się pokazać, a gospodarz widział pusty ekran.
 */
class KandydaciKolazuPomijajaWpisyBezGotowychZdjecTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, int $minutTemu, ?Media $zdjecie): Post
    {
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subMinutes($minutTemu),
        ]);

        if ($zdjecie !== null) {
            $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
        }

        return $wpis;
    }

    /** @return array{0: Post, 1: Media} */
    private function swiat(): array
    {
        $autor = $this->user('autor');

        // Starszy wpis z gotowym zdjęciem — jedyny prawdziwy kandydat.
        $gotowe = Media::factory()->for($autor, 'owner')->create();
        $starszy = $this->wpis($autor, 10_000, $gotowe);

        // 60 nowszych wpisów: połowa ze zdjęciem w obróbce, połowa bez zdjęć.
        for ($i = 1; $i <= 60; $i++) {
            $zdjecie = $i % 2 === 0
                ? Media::factory()->for($autor, 'owner')->pending()->create()
                : null;

            $this->wpis($autor, $i, $zdjecie);
        }

        return [$starszy, $gotowe];
    }

    public function test_starszy_wpis_z_gotowym_zdjeciem_jest_kandydatem_mimo_60_nowszych_bez_gotowych(): void
    {
        [$starszy] = $this->swiat();

        $kandydaci = app(HeroKolaz::class)->kandydaci(60);

        $this->assertSame(
            [(string) $starszy->getKey()],
            $kandydaci->map(fn (Post $post) => (string) $post->getKey())->all(),
        );
    }

    public function test_panel_pokazuje_gotowe_zdjecie_starszego_wpisu(): void
    {
        [, $gotowe] = $this->swiat();

        $this->actingAs($this->moderator())
            ->get(route('admin.hero-kolaz'))
            ->assertOk()
            // Pole wyboru z listy, nie sam identyfikator — to samo zdjęcie
            // pokazuje też podgląd kolażu nad formularzem (dobór automatyczny).
            ->assertSee('id="zdjecie-'.$gotowe->getKey().'"', false)
            ->assertDontSee('Nie ma jeszcze ani jednego publicznego zdjęcia do wyboru.');
    }

    public function test_limit_liczy_tylko_wpisy_z_gotowym_zdjeciem(): void
    {
        $autor = $this->user('autor');

        for ($i = 1; $i <= 3; $i++) {
            $this->wpis($autor, $i * 10, Media::factory()->for($autor, 'owner')->create());
            $this->wpis($autor, $i * 10 - 5, Media::factory()->for($autor, 'owner')->pending()->create());
        }

        $this->assertCount(2, app(HeroKolaz::class)->kandydaci(2));
        $this->assertCount(3, app(HeroKolaz::class)->kandydaci(10));
    }
}
