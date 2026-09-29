<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Issue #2050 w kreatorze przepisu (Livewire): błąd walidacji innego pola
 * nie kasuje wybranych zdjęć.
 *
 * Zwykły, serwerowy formularz gubił `<input type="file">` po `back()`
 * i dostał na to bramkę (`ZachowaneZdjeciaPrzepisu`). Kreator ma inną drogę:
 * zdjęcie idzie do `media` PRZED walidacją reszty, a przez błąd przechodzi
 * jako identyfikator w stanie komponentu (`heroMediaId`, `steps.N.mediaId`).
 * Nikt tego jednak nie pilnował testem — wystarczyłoby przenieść
 * `storePendingPhotos()` za walidację, żeby człowiek znów wybierał zdjęcia
 * po każdej literówce.
 *
 * Test pilnuje pełnej drogi: wybór → błąd innego pola → zdjęcie nadal
 * dodane (stan, widok, wiersz w `media`) → poprawka → publikacja przypina
 * TO SAMO zdjęcie, bez drugiego uploadu.
 */
class KreatorPrzepisuZachowujeZdjeciaPoBleduTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'recipe-wizard';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
        Storage::fake('public');
        Queue::fake([ProcessUploadedImage::class]);
    }

    public function test_zdjecie_dania_przezywa_brak_nazwy_i_trafia_do_przepisu_bez_drugiego_uploadu(): void
    {
        $user = $this->user('kreator_zdjecie_dania');

        $component = Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('heroPhoto', UploadedFile::fake()->image('danie.jpg', 800, 600))
            ->set('steps.0.instruction', 'Podsmaż cebulę.')
            ->call('publish')
            ->assertHasErrors(['form.title']);

        $mediaId = $component->get('heroMediaId');
        $this->assertNotNull($mediaId, 'Zdjęcie dania zniknęło po błędzie nazwy — trzeba je wybierać od nowa.');
        $this->assertNull($component->get('heroPhoto'));
        $this->assertSame(1, Media::query()->where('owner_id', $user->id)->count());
        $this->assertTrue(Media::query()->whereKey($mediaId)->where('owner_id', $user->id)->exists());
        $component->assertSee('Zdjęcie jest już dodane.')
            ->assertSee('Zmień zdjęcie');

        // Poprawka BEZ ponownego wyboru zdjęcia.
        $component->set('form.title', 'Rosół babci Zofii')->call('publish')->assertHasNoErrors();

        $recipe = Recipe::query()->where('title', 'Rosół babci Zofii')->firstOrFail();
        $this->assertSame($mediaId, $recipe->hero_media_id);
        $this->assertSame(1, Media::query()->where('owner_id', $user->id)->count(), 'Publikacja dołożyła drugie zdjęcie zamiast przypiąć wgrane.');
        Queue::assertPushed(ProcessUploadedImage::class, 1);
    }

    public function test_zdjecie_kroku_przezywa_blad_innego_pola_i_trafia_do_kroku(): void
    {
        $user = $this->user('kreator_zdjecie_kroku');

        $component = Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('form.title', 'Zupa ze zdjęciem kroku')
            ->set('steps.0.instruction', 'Obierz warzywa.')
            ->set('steps.0.photo', UploadedFile::fake()->image('warzywa.jpg', 800, 600))
            ->set('form.estimated_cost_pln', 'bardzo dużo')
            ->call('publish')
            ->assertHasErrors(['form.estimated_cost_pln']);

        $mediaId = $component->get('steps.0.mediaId');
        $this->assertNotNull($mediaId, 'Zdjęcie kroku zniknęło po błędzie innego pola.');
        $this->assertNull($component->get('steps.0.photo'));
        $this->assertSame(1, Media::query()->where('owner_id', $user->id)->count());
        $this->assertSame('Obierz warzywa.', $component->get('steps.0.instruction'), 'Poprawny tekst kroku nie może znikać.');

        $component->set('form.estimated_cost_pln', '24,50')->call('publish')->assertHasNoErrors();

        $recipe = Recipe::query()->where('title', 'Zupa ze zdjęciem kroku')->firstOrFail();
        $this->assertSame($mediaId, $recipe->steps()->orderBy('position')->value('media_id'));
        $this->assertSame(1, Media::query()->where('owner_id', $user->id)->count());
        Queue::assertPushed(ProcessUploadedImage::class, 1);
    }

    public function test_odrzucone_zdjecie_nie_zabiera_dobrego_zdjecia_dania(): void
    {
        $user = $this->user('kreator_zle_zdjecie_kroku');

        $component = Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('form.title', 'Przepis z jednym złym zdjęciem')
            ->set('heroPhoto', UploadedFile::fake()->image('danie.jpg', 800, 600));

        $dobre = $component->get('heroMediaId');
        $this->assertNotNull($dobre);

        // Plik przechodzi limity Livewire, a odpada dopiero w potoku mediów
        // (to nie jest obraz) — błąd stoi przy KROKU, a dobre zdjęcie dania zostaje.
        $component->set('steps.0.instruction', 'Podsmaż cebulę.')
            ->set('steps.0.photo', UploadedFile::fake()->create('krok.jpg', 5))
            ->assertHasErrors(['steps.0.photo'])
            ->assertSet('heroMediaId', $dobre)
            ->assertSet('steps.0.instruction', 'Podsmaż cebulę.');
    }
}
