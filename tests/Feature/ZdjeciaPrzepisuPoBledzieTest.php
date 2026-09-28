<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Błąd innego pola nie wymaga ponownego wybierania zdjęć przepisu (#2050). */
class ZdjeciaPrzepisuPoBledzieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
        Storage::fake('public');
        Queue::fake();
    }

    public function test_blad_tytulu_zachowuje_zdjecie_glowne_az_do_zapisu(): void
    {
        $autor = $this->user('basia');
        $formularz = route('recipes.create');

        $this->actingAs($autor)->from($formularz)->post(route('recipes.store'), [
            'title' => '',
            'hero_photo' => UploadedFile::fake()->image('danie.jpg', 800, 600),
            'skladniki_tekst' => '',
            'przygotowanie_tekst' => 'Ugotuj i podaj.',
            'visibility' => 'public',
            'action' => 'publish',
        ])->assertRedirect($formularz)->assertSessionHasErrors('title');

        $id = session()->getOldInput('zachowane_zdjecia.hero');
        $this->assertSame(1, Media::query()->where('owner_id', $autor->getKey())->count());
        $this->assertNotNull($id);
        $this->withCookie(config('session.cookie'), session()->getId())->get($formularz)
            ->assertOk()->assertSee('Twoje zdjęcie jest zachowane.')
            ->assertSee('name="zachowane_zdjecia[hero]"', false);

        $this->actingAs($autor)->post(route('recipes.store'), [
            'title' => 'Domowy rosół',
            'zachowane_zdjecia' => ['hero' => $id],
            'skladniki_tekst' => '',
            'przygotowanie_tekst' => 'Ugotuj i podaj.',
            'visibility' => 'public',
            'action' => 'publish',
        ])->assertSessionHasNoErrors();

        $this->assertSame($id, Recipe::query()->where('title', 'Domowy rosół')->firstOrFail()->hero_media_id);
        $this->assertSame(1, Media::query()->where('owner_id', $autor->getKey())->count());
    }

    public function test_jedna_strona_zachowuje_skan_i_zdjecie_wlasciwego_kroku(): void
    {
        $autor = $this->user('basia');
        $formularz = route('recipes.create.simple');
        $kroki = [3 => ['instruction' => 'Obierz warzywa.'], 7 => ['instruction' => 'Ugotuj zupę.']];

        $this->actingAs($autor)->from($formularz)->post(route('recipes.store'), [
            'title' => '',
            'source_scan' => UploadedFile::fake()->image('kartka.jpg', 800, 600),
            'steps' => [
                3 => [...$kroki[3], 'photo' => UploadedFile::fake()->image('warzywa.jpg', 800, 600)],
                7 => $kroki[7],
            ],
            'visibility' => 'public',
            'action' => 'publish',
        ])->assertRedirect($formularz)->assertSessionHasErrors('title');

        $ids = session()->getOldInput('zachowane_zdjecia');
        $this->assertSame(['scan', 'step_3'], array_keys($ids));
        $this->withCookie(config('session.cookie'), session()->getId())->get($formularz)
            ->assertOk()->assertSee('name="zachowane_zdjecia[scan]"', false)
            ->assertSee('name="zachowane_zdjecia[step_3]"', false);

        $this->actingAs($autor)->post(route('recipes.store'), [
            'title' => 'Zupa z zeszytu',
            'zachowane_zdjecia' => $ids,
            'steps' => $kroki,
            'visibility' => 'public',
            'action' => 'publish',
        ])->assertSessionHasNoErrors();

        $przepis = Recipe::query()->where('title', 'Zupa z zeszytu')->firstOrFail();
        $this->assertSame($ids['scan'], $przepis->source_scan_media_id);
        $this->assertSame($ids['step_3'], $przepis->steps->first()->media_id);
        $this->assertNull($przepis->steps->last()->media_id);
    }

    public function test_cudze_i_juz_przypiete_uuid_nie_wchodza_przez_ukryte_pole(): void
    {
        $autor = $this->user('basia');
        $obcy = $this->user('marek');
        $cudze = Media::factory()->create(['owner_id' => $obcy->getKey()]);

        $this->actingAs($autor)->post(route('recipes.store'), [
            'title' => 'Przepis bez cudzego zdjęcia',
            'zachowane_zdjecia' => ['hero' => $cudze->getKey()],
            'skladniki_tekst' => '',
            'przygotowanie_tekst' => 'Ugotuj.',
            'visibility' => 'public',
            'action' => 'publish',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Recipe::query()->where('title', 'Przepis bez cudzego zdjęcia')->firstOrFail()->hero_media_id);
    }

    public function test_edycja_po_bledzie_zachowuje_nowe_zdjecie_i_nie_zmienia_przepisu_przed_poprawka(): void
    {
        $autor = $this->user('basia');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Stary rosół']);
        $formularz = route('recipes.edit', $przepis);
        $dane = [
            'content_revision' => $przepis->fresh()->content_revision,
            'visibility' => 'public',
            'action' => 'draft',
            'steps' => [3 => ['instruction' => 'Gotuj powoli.']],
        ];

        $this->actingAs($autor)->from($formularz)->put(route('recipes.update', $przepis), [
            ...$dane,
            'title' => '',
            'hero_photo' => UploadedFile::fake()->image('nowe.jpg', 800, 600),
        ])->assertRedirect($formularz)->assertSessionHasErrors('title');

        $id = session()->getOldInput('zachowane_zdjecia.hero');
        $this->assertNotNull($id);
        $this->assertSame('Stary rosół', $przepis->fresh()->title);
        $this->assertNull($przepis->hero_media_id);

        $this->actingAs($autor)->put(route('recipes.update', $przepis), [
            ...$dane,
            'title' => 'Nowy rosół',
            'zachowane_zdjecia' => ['hero' => $id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($id, $przepis->fresh()->hero_media_id);
        $this->assertSame(1, Media::query()->where('owner_id', $autor->getKey())->count());
    }
}
