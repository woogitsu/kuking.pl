<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trzy komunikaty o zdjęciach, które mówiły nieprawdę (G30).
 *
 * #882 — po ułożeniu zdjęć PRYWATNEGO wpisu potwierdzenie mówiło „Tak
 *        zobaczą ten wpis inni.", choć innych odbiorców nie ma.
 * #752 — odrzucone zdjęcie PRZEPISU dostawało radę „Wpis możesz usunąć
 *        i dodać ponownie" zamiast drogi do wymiany zdjęcia w edycji.
 * #891 — awatar `rejected` bez wariantu kazał czekać na przygotowanie,
 *        choć obróbka skończyła się odmową i czekać nie ma na co.
 *
 * Każdy test sprawdza TEKST, który człowiek czyta, a nie sam boolean.
 */
class KomunikatyZdjecMowiaPrawdeTest extends TestCase
{
    use RefreshDatabase;

    private const ZDANIE_O_INNYCH = 'Tak zobaczą ten wpis inni.';

    private const RADA_O_USUNIECIU = 'Wpis możesz usunąć i dodać ponownie z innym zdjęciem.';

    // -----------------------------------------------------------------
    // #882 — układ zdjęć wpisu a jego widoczność
    // -----------------------------------------------------------------

    #[Test]
    public function test_zapis_ukladu_prywatnego_wpisu_nie_mowi_o_innych_odbiorcach(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, Post::VISIBILITY_PRIVATE);
        $kolejnosc = $wpis->media->pluck('id')->all();

        $this->actingAs($autor)
            ->followingRedirects()
            ->post(route('posts.media.update', $wpis), ['display_mode' => Post::DISPLAY_CAROUSEL])
            ->assertOk()
            ->assertSee('Zapisane. Ten wpis widzisz tylko Ty.', false)
            ->assertDontSee(self::ZDANIE_O_INNYCH, false);

        $swiezy = $wpis->fresh();
        $this->assertSame(Post::VISIBILITY_PRIVATE, $swiezy->visibility, 'Zapis układu zmienił widoczność wpisu.');
        $this->assertSame(Post::DISPLAY_CAROUSEL, $swiezy->display_mode);
        $this->assertSame($kolejnosc, $swiezy->media->pluck('id')->all(), 'Zapis układu zgubił kolejność zdjęć.');
    }

    #[Test]
    public function test_koniec_publikacji_prywatnego_wpisu_nie_mowi_o_innych_odbiorcach(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, Post::VISIBILITY_PRIVATE);

        $this->actingAs($autor)
            ->post(route('posts.media.update', $wpis), [
                'display_mode' => Post::DISPLAY_NORMAL,
                'wroc_do_wpisu' => '1',
            ])
            ->assertRedirect($wpis->url())
            ->assertSessionHas('status', 'Opublikowane. Ten wpis widzisz tylko Ty.');

        $this->assertSame(Post::VISIBILITY_PRIVATE, $wpis->fresh()->visibility);
    }

    #[Test]
    public function test_ekran_ukladu_prywatnego_wpisu_nie_obiecuje_widzow(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, Post::VISIBILITY_PRIVATE);

        $this->actingAs($autor)
            ->get(route('posts.media.edit', $wpis))
            ->assertOk()
            ->assertSee('Ten wpis widzisz tylko Ty.', false)
            ->assertDontSee('Zmiany zobaczą wszyscy', false);
    }

    #[Test]
    public function test_wpis_dla_obserwujacych_i_publiczny_dostaja_zdanie_o_swoich_odbiorcach(): void
    {
        $autor = $this->user('basia');

        $dlaObserwujacych = $this->wpisZeZdjeciami($autor, Post::VISIBILITY_FOLLOWERS);
        $this->actingAs($autor)
            ->post(route('posts.media.update', $dlaObserwujacych), ['display_mode' => Post::DISPLAY_NORMAL])
            ->assertSessionHas('status', 'Zapisane. Tak zobaczą ten wpis osoby, które Cię obserwują.');

        $publiczny = $this->wpisZeZdjeciami($autor, Post::VISIBILITY_PUBLIC);
        $this->actingAs($autor)
            ->post(route('posts.media.update', $publiczny), ['display_mode' => Post::DISPLAY_NORMAL])
            ->assertSessionHas('status', 'Zapisane. '.self::ZDANIE_O_INNYCH);

        // Strzałka dalej mówi o przestawieniu i nie gubi kolejności.
        $kolejnosc = $publiczny->media->pluck('id')->all();
        $this->actingAs($autor)
            ->post(route('posts.media.update', $publiczny), ['przenies_w_dol' => $kolejnosc[0]])
            ->assertRedirect(route('posts.media.edit', $publiczny));
        $this->assertSame([$kolejnosc[1], $kolejnosc[0]], $publiczny->fresh()->media->pluck('id')->all());
    }

    // -----------------------------------------------------------------
    // #752 — odrzucone zdjęcie przepisu
    // -----------------------------------------------------------------

    #[Test]
    public function test_wlasciciel_przepisu_z_odrzuconym_zdjeciem_dostaje_wymiane_a_nie_rade_o_usuwaniu(): void
    {
        $autor = $this->user('basia');
        $przepis = $this->przepisZOdrzuconymiZdjeciami($autor);
        $edycja = route('recipes.edit', $przepis->slug);

        $html = $this->actingAs($autor)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::RADA_O_USUNIECIU, $html);
        $this->assertStringContainsString('Wymień zdjęcie', $html);
        $this->assertStringContainsString('href="'.e($edycja.'#f-hero_photo').'"', $html);
        $this->assertStringContainsString('href="'.e($edycja.'#f-source_scan').'"', $html);
        $this->assertStringContainsString('href="'.e($edycja.'#f-steps-0-photo').'"', $html);

        // Kotwice istnieją po drugiej stronie linku.
        $this->actingAs($autor)->get($edycja)->assertOk()
            ->assertSee('id="f-hero_photo"', false)
            ->assertSee('id="f-source_scan"', false)
            ->assertSee('id="f-steps-0-photo"', false);
    }

    #[Test]
    public function test_tryb_gotowania_prowadzi_do_wymiany_zdjecia_kroku(): void
    {
        $autor = $this->user('basia');
        $przepis = $this->przepisZOdrzuconymiZdjeciami($autor);

        $this->actingAs($autor)
            ->get(route('cooking.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee(self::RADA_O_USUNIECIU, false)
            ->assertSee('href="'.e(route('recipes.edit', $przepis->slug).'#f-steps-0-photo').'"', false);
    }

    #[Test]
    public function test_przepis_ukryty_przez_moderacje_nie_dostaje_linku_do_zamknietej_edycji(): void
    {
        $autor = $this->user('basia');
        $przepis = $this->przepisZOdrzuconymiZdjeciami($autor);
        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $this->assertFalse($autor->can('update', $przepis->fresh()), 'Test zakłada, że Policy zamyka edycję ukrytego przepisu.');

        $this->actingAs($autor)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee('Wymień zdjęcie', false)
            ->assertDontSee(self::RADA_O_USUNIECIU, false);
    }

    #[Test]
    public function test_zawieszone_konto_nie_dostaje_linku_do_edycji_ktorej_nie_zapisze(): void
    {
        $autor = $this->user('basia');
        $przepis = $this->przepisZOdrzuconymiZdjeciami($autor);
        $autor->suspend(now()->addWeek());

        $this->actingAs($autor)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee('Wymień zdjęcie', false)
            ->assertDontSee(self::RADA_O_USUNIECIU, false);
    }

    #[Test]
    public function test_obcy_widz_nie_dostaje_instrukcji_edycji_a_poprawne_zdjecie_dalej_sie_pokazuje(): void
    {
        $autor = $this->user('basia');
        $obcy = $this->user('czesia');
        $przepis = $this->przepisZOdrzuconymiZdjeciami($autor);

        $this->actingAs($obcy)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Tego zdjęcia nie udało się przygotować.', false)
            ->assertDontSee('Wymień zdjęcie', false)
            ->assertDontSee(route('recipes.edit', $przepis->slug), false);

        $dobre = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $przepis->forceFill(['hero_media_id' => $dobre->getKey()])->save();

        $this->actingAs($autor)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('<img', false);
    }

    #[Test]
    public function test_odrzucone_zdjecie_zwyklego_wpisu_zachowuje_rade_dla_wpisu(): void
    {
        $autor = $this->user('basia');
        $zdjecie = $this->odrzucone($autor);

        $wpis = app(PublishPost::class)->handle(
            author: $autor,
            body: 'Obiad, który się nie udał na zdjęciu.',
            mediaIds: [$zdjecie->getKey()],
            visibility: Post::VISIBILITY_PUBLIC,
            displayMode: Post::DISPLAY_NORMAL,
        );

        $this->actingAs($autor)
            ->get($wpis->url())
            ->assertOk()
            ->assertSee(self::RADA_O_USUNIECIU, false)
            ->assertDontSee('Wymień zdjęcie', false);
    }

    // -----------------------------------------------------------------
    // #891 — awatar odrzucony bez wariantu
    // -----------------------------------------------------------------

    #[Test]
    public function test_odrzucony_awatar_bez_wariantu_mowi_o_odrzuceniu_i_jak_dodac_nowy(): void
    {
        $osoba = $this->user('basia');
        $this->ustawAwatar($osoba, ['status' => Media::STATUS_REJECTED, 'metadata' => ['variants' => []]]);

        $profil = $osoba->profile->fresh();
        $this->assertFalse($profil->zdjecieSieJeszczePrzygotowuje());
        $this->assertTrue($profil->zdjecieNieUdaloSiePrzygotowac());

        $this->actingAs($osoba)
            ->get(route('settings.avatar'))
            ->assertOk()
            ->assertSee('Nie udało się przygotować Twojego zdjęcia.', false)
            ->assertSee('Wybierz inne zdjęcie poniżej', false)
            ->assertDontSee('się przygotowuje', false)
            ->assertDontSee('Odśwież tę stronę', false)
            ->assertSee('Usuń zdjęcie', false);

        $this->actingAs($osoba)
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Nie udało się przygotować Twojego zdjęcia.', false)
            ->assertDontSee('Nie możemy teraz pokazać zdjęcia', false);
    }

    #[Test]
    public function test_pozostale_stany_awatara_zachowuja_swoje_zdania(): void
    {
        $osoba = $this->user('basia');

        // Brak relacji.
        $this->assertFalse($osoba->profile->zdjecieNieUdaloSiePrzygotowac());
        $this->assertFalse($osoba->profile->zdjecieSieJeszczePrzygotowuje());

        foreach ([Media::STATUS_PENDING, Media::STATUS_PROCESSING] as $status) {
            $this->ustawAwatar($osoba, ['status' => $status, 'metadata' => ['variants' => []]]);
            $this->actingAs($osoba)->get(route('settings.avatar'))->assertOk()
                ->assertSee('Twoje nowe zdjęcie się przygotowuje.', false)
                ->assertDontSee('Nie udało się przygotować', false);
        }

        // Odrzucone, ale z bezpiecznym podglądem — obrazek zostaje (Media::maWariantDoPokazania).
        $zPodgladem = Media::factory()->make()->metadata;
        $this->ustawAwatar($osoba, ['status' => Media::STATUS_REJECTED, 'metadata' => $zPodgladem]);
        $profil = $osoba->profile->fresh();
        $this->assertNotNull($profil->zdjecieDoPokazania(), 'Odrzucone zdjęcie z wariantem ma się dalej pokazywać.');
        $this->assertFalse($profil->zdjecieNieUdaloSiePrzygotowac());
        $this->actingAs($osoba)->get(route('settings.avatar'))->assertOk()
            ->assertSee('To jest Twoje zdjęcie.', false);

        $this->ustawAwatar($osoba, ['status' => Media::STATUS_READY]);
        $this->actingAs($osoba)->get(route('settings.avatar'))->assertOk()
            ->assertSee('To jest Twoje zdjęcie.', false);

        $this->ustawAwatar($osoba, ['status' => Media::STATUS_DELETED]);
        $profil = $osoba->profile->fresh();
        $this->assertFalse($profil->zdjecieNieUdaloSiePrzygotowac());
        $this->actingAs($osoba)->get(route('settings.avatar'))->assertOk()
            ->assertSee('Nie masz jeszcze swojego zdjęcia.', false);
    }

    // -----------------------------------------------------------------

    private function wpisZeZdjeciami(User $autor, string $widocznosc): Post
    {
        $zdjecia = Media::factory()->count(2)->create(['owner_id' => $autor->getKey()]);

        return app(PublishPost::class)->handle(
            author: $autor,
            body: 'Obiad w dwóch odsłonach.',
            mediaIds: $zdjecia->pluck('id')->all(),
            visibility: $widocznosc,
            displayMode: Post::DISPLAY_NORMAL,
        );
    }

    private function odrzucone(User $wlasciciel): Media
    {
        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'status' => Media::STATUS_REJECTED,
            'metadata' => ['variants' => []],
        ]);
    }

    private function przepisZOdrzuconymiZdjeciami(User $autor): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'hero_media_id' => $this->odrzucone($autor)->getKey(),
            'source_type' => Recipe::SOURCE_FAMILY,
            'source_person' => 'od mamy',
            'source_scan_media_id' => $this->odrzucone($autor)->getKey(),
        ]);

        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Pokrój cebulę.',
            'media_id' => $this->odrzucone($autor)->getKey(),
        ]);

        return $przepis->fresh();
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function ustawAwatar(User $osoba, array $atrybuty): void
    {
        $zdjecie = Media::factory()->create(['owner_id' => $osoba->getKey(), ...$atrybuty]);

        // Przez obiekt, który trzyma zalogowany `$osoba` — `actingAs()` oddaje
        // kontrolerowi TEN egzemplarz, więc aktualizacja samym zapytaniem
        // zostawiłaby mu w pamięci stare `avatar_media_id`.
        $osoba->profile->forceFill(['avatar_media_id' => $zdjecie->getKey()])->save();
        $osoba->profile->unsetRelation('avatar');
    }
}
