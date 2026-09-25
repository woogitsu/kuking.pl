<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Recipes\ZapisPrzepisuRequest;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Walidacja zapisu przepisu wyjęta z `RecipeController` (issue #970, krok 1).
 *
 * Najważniejsze jest to, czego przeniesienie NIE MIAŁO zmienić: kolejność
 * „Policy, potem walidacja" (FormRequest waliduje się, zanim ruszy ciało
 * kontrolera) i dwie fazy walidacji, które nie mieszają swoich błędów.
 */
class ZapisPrzepisuRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_obcy_z_blednym_formularzem_dostaje_odmowe_a_nie_bledy_pol(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autor970')->getKey()]);

        $this->actingAs($this->user('obcy970'))
            ->put(route('recipes.update', $przepis), ['title' => ''])
            ->assertForbidden();
    }

    public function test_autor_z_blednym_formularzem_dostaje_bledy_pol(): void
    {
        $autor = $this->user('wlasciciel970');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        // Kontrola dodatnia do testu wyżej: to samo żądanie od autora nie
        // jest odmową, tylko komunikatem przy polu.
        $this->actingAs($autor)
            ->put(route('recipes.update', $przepis), ['title' => '', 'visibility' => 'public'])
            ->assertRedirect()
            ->assertSessionHasErrors(['title' => 'Podaj nazwę przepisu — na przykład „Rosół babci Zofii”.']);
    }

    public function test_pola_tekstowe_rozbijaja_sie_na_wiersze(): void
    {
        $dane = $this->daneZapisu([
            'title' => 'Placki 970',
            'visibility' => 'public',
            'skladniki_tekst' => "ziemniaki\ncebula",
            'przygotowanie_tekst' => "Zetrzyj ziemniaki.\n\nUsmaż placki.",
        ]);

        $this->assertSame(['ziemniaki', 'cebula'], array_column($dane['ingredients'], 'text'));
        $this->assertSame(['Zetrzyj ziemniaki.', 'Usmaż placki.'], array_column($dane['steps'], 'instruction'));
        $this->assertNull($dane['recipe']['source_type']);
    }

    public function test_pusty_opis_przygotowania_przy_publikacji_to_blad_przy_tym_polu(): void
    {
        try {
            $this->daneZapisu(['title' => 'Placki 970', 'visibility' => 'public', 'przygotowanie_tekst' => '']);
            $this->fail('Publikacja bez kroków musi wrócić z błędem przy polu przygotowania.');
        } catch (ValidationException $e) {
            $this->assertSame(['przygotowanie_tekst'], array_keys($e->errors()));
        }

        // Kontrola dodatnia: szkic z tym samym pustym polem przechodzi.
        $szkic = $this->daneZapisu(['title' => 'Placki 970', 'visibility' => 'public', 'przygotowanie_tekst' => '', 'action' => 'draft']);
        $this->assertSame([], $szkic['steps']);
    }

    public function test_zdjecia_krokow_zostaja_pod_numerami_wierszy_formularza(): void
    {
        $request = $this->request([
            'title' => 'Kotlet 970',
            'visibility' => 'public',
            'steps' => [
                3 => ['instruction' => 'Rozbij mięso.'],
                8 => ['instruction' => 'Usmaż.'],
            ],
        ], ['steps' => [3 => ['photo' => UploadedFile::fake()->image('krok.jpg', 400, 300)]]]);

        $dane = $request->daneZapisu();

        $this->assertSame([3, 8], array_keys($dane['steps']));
        $this->assertSame([3], array_keys($request->zdjeciaKrokow($dane['steps'])));
        $this->assertNull($request->przeslanyPlik('hero_photo'));
    }

    /**
     * @param  array<string, mixed>  $dane
     * @return array{recipe: array<string, mixed>, ingredients: list<array<string, mixed>>, steps: array<array-key, array<string, mixed>>}
     */
    private function daneZapisu(array $dane): array
    {
        return $this->request($dane)->daneZapisu();
    }

    /**
     * @param  array<string, mixed>  $dane
     * @param  array<string, mixed>  $pliki
     */
    private function request(array $dane, array $pliki = []): ZapisPrzepisuRequest
    {
        $request = ZapisPrzepisuRequest::create('/dodaj/przepis', 'POST', $dane, [], $pliki);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        return $request;
    }
}
