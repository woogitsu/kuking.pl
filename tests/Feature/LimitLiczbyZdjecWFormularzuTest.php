<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Formularze wpisu i „Ugotowałem" mówią limit liczby zdjęć PRZED wysłaniem
 * (issue #883).
 *
 * Do tej poprawki człowiek dowiadywał się o limicie dopiero z komunikatu
 * błędu, po wybraniu i wysłaniu siedmiu zdjęć. Test czyta WYRENDEROWANĄ
 * pomoc powiązaną z polem (`aria-describedby="f-photos-help"`), przy dwóch
 * wartościach konfiguracji — nie obecność helpera w źródle.
 */
class LimitLiczbyZdjecWFormularzuTest extends TestCase
{
    use RefreshDatabase;

    private function pomocPolaZdjec(string $html): string
    {
        $this->assertStringContainsString('aria-describedby="f-photos-help"', $html);
        $this->assertSame(1, preg_match('~id="f-photos-help">(.*?)</span>~s', $html, $m));

        return trim((string) preg_replace('~\s+~u', ' ', $m[1]));
    }

    private function recipe(): Recipe
    {
        return Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);
    }

    public function test_formularz_wpisu_podaje_limit_szesciu_zdjec(): void
    {
        config(['kuking.media.max_per_post' => 6]);

        $html = (string) $this->actingAs($this->user('basia'))
            ->get(route('posts.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Możesz dodać najwyżej 6 zdjęć naraz.', $this->pomocPolaZdjec($html));
    }

    public function test_formularz_wpisu_podaje_limit_jednego_zdjecia_poprawna_polszczyzna(): void
    {
        config(['kuking.media.max_per_post' => 1]);

        $pomoc = $this->pomocPolaZdjec((string) $this->actingAs($this->user('basia'))
            ->get(route('posts.create'))->assertOk()->getContent());

        $this->assertStringContainsString('Możesz dodać jedno zdjęcie.', $pomoc);
        $this->assertStringNotContainsString('1 zdjęć', $pomoc);
    }

    public function test_formularz_wpisu_liczy_zachowane_zdjecia_do_limitu(): void
    {
        config(['kuking.media.max_per_post' => 6]);
        $user = $this->user('basia');
        $zachowane = Media::factory()->count(2)->create(['owner_id' => $user->getKey()]);

        $pomoc = $this->pomocPolaZdjec((string) $this->actingAs($user)
            ->withSession(['_old_input' => ['media_ids' => $zachowane->pluck('id')->all()]])
            ->get(route('posts.create'))->assertOk()->getContent());

        $this->assertStringContainsString('Możesz dodać jeszcze 4 zdjęcia — razem z zachowanymi najwyżej 6.', $pomoc);
        $this->assertStringNotContainsString('najwyżej 6 zdjęć naraz', $pomoc);
    }

    public function test_formularz_wpisu_przy_komplecie_zachowanych_nie_zacheca_do_nowych(): void
    {
        config(['kuking.media.max_per_post' => 2]);
        $user = $this->user('basia');
        $zachowane = Media::factory()->count(2)->create(['owner_id' => $user->getKey()]);

        $pomoc = $this->pomocPolaZdjec((string) $this->actingAs($user)
            ->withSession(['_old_input' => ['media_ids' => $zachowane->pluck('id')->all()]])
            ->get(route('posts.create'))->assertOk()->getContent());

        $this->assertStringContainsString('Masz już tyle zdjęć, ile można dodać. Nowych nie wybieraj.', $pomoc);
    }

    public function test_formularz_ugotowalem_podaje_limit_liczby_i_rozmiaru(): void
    {
        config(['kuking.media.max_per_post' => 6, 'kuking.media.max_bytes' => 15 * 1024 * 1024]);
        $recipe = $this->recipe();

        $pomoc = $this->pomocPolaZdjec((string) $this->actingAs($this->user('kucharz'))
            ->get(route('cooked.create', $recipe->slug))->assertOk()->getContent());

        $this->assertStringContainsString('Możesz dodać najwyżej 6 zdjęć naraz.', $pomoc);
        $this->assertStringContainsString('Największy plik: 15 MB.', $pomoc);
    }

    public function test_formularz_ugotowalem_przy_limicie_jednego_zdjecia(): void
    {
        config(['kuking.media.max_per_post' => 1]);
        $recipe = $this->recipe();

        $pomoc = $this->pomocPolaZdjec((string) $this->actingAs($this->user('kucharz'))
            ->get(route('cooked.create', $recipe->slug))->assertOk()->getContent());

        $this->assertStringContainsString('Możesz dodać jedno zdjęcie.', $pomoc);
    }

    /** Walidacja bez zmian: limit przyjmuje dozwoloną liczbę i odrzuca nadmiar. */
    public function test_ugotowalem_przyjmuje_limit_i_odrzuca_nadmiar(): void
    {
        Storage::fake('public');
        config(['kuking.media.max_per_post' => 2]);
        $recipe = $this->recipe();
        $kucharz = $this->user('kucharz');

        $this->actingAs($kucharz)->post(route('cooked.store', $recipe->slug), [
            'photos' => [
                UploadedFile::fake()->image('a.jpg', 400, 300),
                UploadedFile::fake()->image('b.jpg', 400, 300),
                UploadedFile::fake()->image('c.jpg', 400, 300),
            ],
        ])->assertSessionHasErrors('photos');

        $this->actingAs($kucharz)->post(route('cooked.store', $recipe->slug), [
            'photos' => [
                UploadedFile::fake()->image('a.jpg', 400, 300),
                UploadedFile::fake()->image('b.jpg', 400, 300),
            ],
        ])->assertSessionHasNoErrors();
    }
}
