<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kreator przepisu (Livewire) jest pod limitem `post` — tym samym koszykiem
 * co `POST /dodaj/przepis` (audyt 30.09.2026, S-01, #2268).
 *
 * Przed poprawką 25 komponentów `recipe-wizard` z `set form.title` dawało
 * 25 szkiców, a formularz tego samego konta odpowiadał 429 przy 21. żądaniu.
 */
class KreatorPrzepisuLimitZapisuTest extends TestCase
{
    use RefreshDatabase;

    private const KOMUNIKAT = 'Zapisujesz bardzo dużo przepisów w krótkim czasie.';

    /** Limit z `config/kuking.php`; trasa czyta go przy ładowaniu, więc go nie nadpisujemy. */
    private const LIMIT = 20;

    public function test_dwudziesty_pierwszy_nowy_szkic_z_kreatora_dostaje_komunikat_a_dwadziescia_zostaje(): void
    {
        $this->assertSame(self::LIMIT.',10', config('kuking.limits.post'));
        $basia = $this->user('limitkreatora');

        for ($i = 1; $i <= self::LIMIT; $i++) {
            $this->nowySzkic($basia, "Szkic numer {$i}")->assertSet('saveState', 'saved');
        }

        $this->nowySzkic($basia, 'Szkic ponad limit')
            ->assertSet('saveState', 'error')
            ->assertSet('recipeId', null)
            ->assertSet('form.title', 'Szkic ponad limit')
            ->assertSee(self::KOMUNIKAT)
            ->assertSee('Nic nie zginęło');

        $this->assertSame(self::LIMIT, Recipe::query()->where('author_id', $basia->getKey())->count());
        $this->assertFalse(Recipe::query()->where('title', 'Szkic ponad limit')->exists());

        // Ten sam koszyk co formularz: po 20 szkicach z kreatora formularz odpowiada 429.
        $this->actingAs($basia)->post(route('recipes.store'), [])->assertStatus(429);
    }

    public function test_pelny_koszyk_z_formularza_zatrzymuje_nowy_szkic_w_kreatorze(): void
    {
        $jan = $this->user('koszykformularz');
        $this->zjedzKoszykFormularzem($jan, self::LIMIT);

        $this->nowySzkic($jan, 'Po formularzu')
            ->assertSet('saveState', 'error')
            ->assertSet('form.title', 'Po formularzu')
            ->assertSee(self::KOMUNIKAT);
        $this->assertFalse(Recipe::query()->where('author_id', $jan->getKey())->exists());
    }

    public function test_autozapis_istniejacego_szkicu_nie_zjada_limitu(): void
    {
        $basia = $this->user('autozapislimit');
        $this->zjedzKoszykFormularzem($basia, self::LIMIT - 2);

        $kreator = $this->nowySzkic($basia, 'Jeden szkic, dużo pisania')->assertSet('saveState', 'saved');
        for ($i = 1; $i <= 10; $i++) {
            $kreator->set('form.summary', "Poprawka opisu {$i}")->assertSet('saveState', 'saved');
        }
        $kreator->call('saveDraft')->assertSet('saveState', 'saved');

        $this->assertSame('Poprawka opisu 10', Recipe::sole()->summary);
        // Autozapis nie liczył się, więc zostało jeszcze jedno miejsce.
        $this->nowySzkic($basia, 'Drugi')->assertSet('saveState', 'saved');
        $this->nowySzkic($basia, 'Trzeci')->assertSet('saveState', 'error');
    }

    public function test_pelny_koszyk_zatrzymuje_publikacje_a_szkic_i_tekst_zostaja(): void
    {
        $basia = $this->user('publikacjalimit');

        $kreator = $this->nowySzkic($basia, 'Zupa na limicie')
            ->set('steps.0.instruction', 'Zagotuj wodę.')
            ->assertSet('saveState', 'saved');
        $this->zjedzKoszykFormularzem($basia, self::LIMIT - 1);

        $kreator->set('step', 4)->call('publish')
            ->assertHasErrors('publikacja')
            ->assertSee(self::KOMUNIKAT)
            ->assertSet('form.title', 'Zupa na limicie')
            ->assertSet('steps.0.instruction', 'Zagotuj wodę.')
            ->assertNoRedirect();

        $this->assertSame(Recipe::STATUS_DRAFT, Recipe::sole()->status);
    }

    public function test_publikacja_pod_limitem_przechodzi(): void
    {
        $basia = $this->user('publikacjapod');
        $this->zjedzKoszykFormularzem($basia, self::LIMIT - 2);

        $this->nowySzkic($basia, 'Zupa pod limitem')
            ->set('steps.0.instruction', 'Zagotuj wodę.')
            ->set('step', 4)->call('publish')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame(Recipe::STATUS_PUBLISHED, Recipe::sole()->status);
    }

    /** Puste wysłanie formularza przechodzi przez `throttle:post` i kończy się błędem walidacji. */
    private function zjedzKoszykFormularzem(User $kto, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $this->actingAs($kto)->post(route('recipes.store'), [])->assertStatus(302);
        }
    }

    private function nowySzkic(User $kto, string $tytul): Testable
    {
        return Livewire::actingAs($kto)->test('recipe-wizard')->set('form.title', $tytul);
    }
}
