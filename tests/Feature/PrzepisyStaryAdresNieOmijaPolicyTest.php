<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stary adres przepisu przechodzi przez tę samą Policy co nowy.
 *
 * CO BYŁO ZEPSUTE
 * `RecipeController::show()` obsługuje `recipe_slug_redirects`, żeby link
 * zapisany w zakładkach nie umarł po zmianie tytułu. Ta gałąź czytała wiersz
 * przekierowania, brała przepis przez `findOrFail()` i odsyłała `301` —
 * i robiła to PRZED `authorize('view')`, bo autoryzacja stała dopiero
 * w gałęzi „przepis znaleziony pod tym slugiem".
 *
 * Zmierzone przed poprawką (widz obcy, przepis `visibility = private`):
 *
 *     HTTP: 301
 *     Location: http://localhost:8000/przepisy/nalewka-na-ziolach-babci-wandy
 *
 * Slug powstaje z tytułu, więc sam nagłówek `Location` oddawał tytuł przepisu,
 * którego ten człowiek nie ma prawa zobaczyć — i potwierdzał, że przepis
 * istnieje oraz pod jakim adresem stoi. To samo dla przepisu autora
 * zbanowanego, którego strona daje 403. Treść była znów mniej dostępna przez
 * drzwi frontowe niż przez okno.
 *
 * ROZSTRZYGNIĘCIE: RACJĘ MA POLICY
 * Przekierowanie jest tylko drugim adresem tej samej treści (AGENTS.md §7:
 * „UUID w adresie NIE JEST autoryzacją"), więc bramka jest ta sama.
 *
 * DLACZEGO 404, A NIE 403
 * Odmowa pod NOWYM adresem to 403 i tak zostaje. Pod starym odpowiadamy 404 —
 * identycznie jak na slug, którego w bazie nie ma. 403 potwierdzałoby, że ten
 * stary adres jest znanym przekierowaniem, czyli że przepis o takim tytule
 * kiedyś istniał. 404 nie mówi nic.
 *
 * UWAGA DO STANU DZISIEJSZEGO: tej tabeli NIC dziś nie zapisuje —
 * `PublishRecipe` zmienia slug wyłącznie dla szkicu i nie zostawia wiersza
 * przekierowania. Luka jest więc uśpiona, a nie otwarta; testy wstawiają
 * wiersz wprost. Naprawiona teraz, bo dopisanie zapisu do tej tabeli jest
 * jednolinijkową zmianą, przy której nikt nie będzie szukał bramki
 * w kontrolerze.
 */
class PrzepisyStaryAdresNieOmijaPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_stary_adres_przepisu_prywatnego_nie_zdradza_nowego(): void
    {
        $autor = $this->user('autorka');
        $obcy = $this->user('obca');

        $prywatny = $this->przepis($autor, 'nalewka-na-ziolach-babci-wandy', ['visibility' => 'private']);
        $this->przekierowanie('stary-tytul-nalewki', $prywatny);

        $odpowiedz = $this->actingAs($obcy)->get('/przepisy/stary-tytul-nalewki');

        $odpowiedz->assertNotFound();
        $this->assertNull(
            $odpowiedz->headers->get('Location'),
            'Stary adres nie może odsyłać do przepisu, którego widz nie ma prawa zobaczyć.',
        );
    }

    public function test_stary_adres_przepisu_zbanowanego_autora_nie_zdradza_nowego(): void
    {
        $autor = $this->user('autorka2');
        $obcy = $this->user('obca2');

        $przepis = $this->przepis($autor, 'schab-w-sosie-wlasnym');
        $this->przekierowanie('stary-tytul-schabu', $przepis);
        $autor->ban();

        $this->actingAs($obcy)
            ->get('/przepisy/stary-tytul-schabu')
            ->assertNotFound();
    }

    public function test_stary_adres_przepisu_dla_obserwujacych_nie_dziala_dla_obcego(): void
    {
        $autor = $this->user('autorka3');
        $obcy = $this->user('obca3');

        $przepis = $this->przepis($autor, 'sernik-babci', ['visibility' => 'followers']);
        $this->przekierowanie('stary-tytul-sernika', $przepis);

        $this->actingAs($obcy)
            ->get('/przepisy/stary-tytul-sernika')
            ->assertNotFound();
    }

    /**
     * ASERCJA KONTROLNA — bez niej „404 na starym adresie" przechodziłoby
     * także po zepsuciu przekierowań w ogóle.
     *
     * Przepis publiczny: stary adres MUSI dalej odsyłać na nowy, bo po to ta
     * tabela istnieje. Ktoś wysłał ten link rodzinie i on ma działać.
     */
    public function test_kontrola_stary_adres_przepisu_publicznego_dalej_odsyla(): void
    {
        $autor = $this->user('autorka4');
        $obcy = $this->user('obca4');

        $przepis = $this->przepis($autor, 'rosol-niedzielny');
        $this->przekierowanie('stary-tytul-rosolu', $przepis);

        $this->actingAs($obcy)
            ->get('/przepisy/stary-tytul-rosolu')
            ->assertStatus(301)
            ->assertRedirect(route('recipes.show', 'rosol-niedzielny'));
    }

    /**
     * Druga połowa kontroli: autor SWOJEGO prywatnego przepisu ma prawo go
     * zobaczyć, więc jego stary link musi zadziałać. Naprawa, która zamyka
     * przekierowanie wszystkim, oblałaby ten test.
     */
    public function test_kontrola_autor_dalej_trafia_starym_adresem_na_swoj_prywatny_przepis(): void
    {
        $autor = $this->user('autorka5');

        $prywatny = $this->przepis($autor, 'moje-zapiski-kuchenne', ['visibility' => 'private']);
        $this->przekierowanie('stary-tytul-zapiskow', $prywatny);

        $this->actingAs($autor)
            ->get('/przepisy/stary-tytul-zapiskow')
            ->assertStatus(301)
            ->assertRedirect(route('recipes.show', 'moje-zapiski-kuchenne'));
    }

    public function test_nieznany_stary_adres_dalej_daje_404(): void
    {
        $this->get('/przepisy/nigdy-takiego-nie-bylo')->assertNotFound();
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function przepis(User $autor, string $slug, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'slug' => $slug,
            ...$atrybuty,
        ]);
    }

    private function przekierowanie(string $starySlug, Recipe $cel): void
    {
        // Klucz główny tej tabeli to sam `slug` — nie ma kolumny `id`.
        DB::table('recipe_slug_redirects')->insert([
            'slug' => $starySlug,
            'recipe_id' => $cel->getKey(),
            'created_at' => now(),
        ]);
    }
}
