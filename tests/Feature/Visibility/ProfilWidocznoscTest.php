<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Droga 2 wycieku: LISTA (issue #41).
 *
 * Policy pilnuje wejścia na adres treści. NIE pilnuje zapytania, które buduje
 * listę — a lista to osobny kod, z osobnymi filtrami, pisany zwykle później.
 *
 * Tu sprawdzamy profil: trzy zakładki, z których każda ma własne zapytanie.
 */
class ProfilWidocznoscTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $obserwujacy;

    private User $obcy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->obserwujacy = $this->user('obserwujaca');
        $this->obcy = $this->user('obca');

        app(FollowUser::class)->handle($this->obserwujacy, $this->autor);
    }

    private function przepis(string $widocznosc): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'title' => 'Zurek '.$widocznosc,
            'slug' => 'zurek-'.$widocznosc.'-'.Str::lower(Str::random(6)),
        ]);
    }

    private function profil(?User $widz, string $zakladka = 'wszystko'): TestResponse
    {
        $adres = route('profile.show', ['username' => 'autorka']).'?zakladka='.$zakladka;

        if ($widz === null) {
            Auth::logout();

            return $this->get($adres);
        }

        return $this->actingAs($widz)->get($adres);
    }

    // -----------------------------------------------------------------
    // Wpisy
    // -----------------------------------------------------------------

    public function test_lista_wpisow_nie_pokazuje_cudzych_prywatnych(): void
    {
        Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
            'body' => 'Prywatny rosol',
        ]);

        $this->profil($this->obcy)->assertOk()->assertDontSee('Prywatny rosol');
        $this->profil($this->obserwujacy)->assertOk()->assertDontSee('Prywatny rosol');
        $this->profil(null)->assertOk()->assertDontSee('Prywatny rosol');
        $this->profil($this->autor)->assertOk()->assertSee('Prywatny rosol');
    }

    public function test_lista_wpisow_dla_obserwujacych_tylko_dla_obserwujacych(): void
    {
        Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_FOLLOWERS,
            'body' => 'Rosol dla swoich',
        ]);

        $this->profil($this->obserwujacy)->assertOk()->assertSee('Rosol dla swoich');
        $this->profil($this->obcy)->assertOk()->assertDontSee('Rosol dla swoich');
        $this->profil(null)->assertOk()->assertDontSee('Rosol dla swoich');
    }

    // -----------------------------------------------------------------
    // Przepisy — zakładka z WŁASNYM zapytaniem
    // -----------------------------------------------------------------

    public function test_lista_przepisow_nie_pokazuje_prywatnych(): void
    {
        $this->przepis('private');

        $this->profil($this->obcy, 'przepisy')->assertOk()->assertDontSee('Zurek private');
        $this->profil($this->obserwujacy, 'przepisy')->assertOk()->assertDontSee('Zurek private');
        $this->profil(null, 'przepisy')->assertOk()->assertDontSee('Zurek private');
    }

    public function test_lista_przepisow_dla_obserwujacych_tylko_dla_obserwujacych(): void
    {
        $this->przepis('followers');

        $this->profil($this->obserwujacy, 'przepisy')->assertOk()->assertSee('Zurek followers');
        $this->profil($this->obcy, 'przepisy')->assertOk()->assertDontSee('Zurek followers');
        $this->profil(null, 'przepisy')->assertOk()->assertDontSee('Zurek followers');
    }

    public function test_autor_widzi_swoje_prywatne_przepisy_na_profilu(): void
    {
        $this->przepis('private');

        $this->profil($this->autor, 'przepisy')->assertOk()->assertSee('Zurek private');
    }

    // -----------------------------------------------------------------
    // Ugotowane — widoczność idzie za przepisem
    // -----------------------------------------------------------------

    public function test_lista_ugotowanych_nie_ujawnia_prywatnego_przepisu(): void
    {
        $recipe = $this->przepis('private');

        CookedEvent::factory()->create([
            'recipe_id' => $recipe->getKey(),
            'user_id' => $this->autor->getKey(),
        ]);

        // Wykonanie samo w sobie nie jest tajne, ale ujawnia TYTUŁ przepisu,
        // który jest prywatny. To jest wyciek przez tytuł, nie przez treść.
        $this->profil($this->obcy, 'ugotowane')->assertOk()->assertDontSee('Zurek private');
        $this->profil(null, 'ugotowane')->assertOk()->assertDontSee('Zurek private');
    }

    // -----------------------------------------------------------------
    // Blokada
    // -----------------------------------------------------------------

    public function test_zablokowany_nie_oglada_profilu(): void
    {
        $zablokowany = $this->user('zablokowana');
        app(BlockUser::class)->handle($this->autor, $zablokowany);

        $this->profil($zablokowany)->assertStatus(403);
    }
}
