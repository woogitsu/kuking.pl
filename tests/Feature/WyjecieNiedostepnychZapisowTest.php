<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wyjęcie z zeszytu samych niedostępnych zapisów, bez kasowania zeszytu (#773).
 */
final class WyjecieNiedostepnychZapisowTest extends TestCase
{
    use RefreshDatabase;

    public function test_wyjmuje_tylko_niedostepne_z_tego_zeszytu_i_nie_rusza_tresci(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $inny = $this->zeszyt($wlasciciel, 'Desery');

        $widoczny = $this->przepis($autor, 'public');
        $usuniety = $this->przepis($autor, 'public');
        $prywatny = $this->przepis($autor, 'private');
        $widocznyWpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $usunietyWpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $zeszyt->recipes()->attach([$widoczny->id, $usuniety->id, $prywatny->id]);
        $zeszyt->posts()->attach([$widocznyWpis->id, $usunietyWpis->id]);
        $inny->recipes()->attach([$usuniety->id]);
        $usuniety->delete();
        $usunietyWpis->delete();

        $html = $this->strona($wlasciciel, $zeszyt);
        $this->assertStringContainsString('3 zapisy nie są dla Ciebie dostępne', $html);
        $this->assertStringContainsString('Wyjąć z tego zeszytu 3 niedostępne zapisy?', $html);
        $this->assertStringContainsString('Nie wrócą same', $html);
        // Pytanie nie ujawnia, CO jest niedostępne.
        $this->assertStringNotContainsString($prywatny->title, $html);
        $this->assertStringNotContainsString($usuniety->title, $html);

        $this->actingAs($wlasciciel)
            ->delete(route('collections.unavailable.destroy', $zeszyt), ['zakres' => $this->odcisk($zeszyt, $wlasciciel)])
            ->assertRedirect(route('collections.show', $zeszyt))
            ->assertSessionHas('status', 'Wyjęliśmy z tego zeszytu 3 niedostępne zapisy. Reszta zeszytu została bez zmian.');

        $this->assertSame([$widoczny->id], $zeszyt->recipes()->withTrashed()->pluck('recipes.id')->all());
        $this->assertSame([$widocznyWpis->id], $zeszyt->posts()->withTrashed()->pluck('posts.id')->all());
        // Inny zeszyt tej samej osoby zostaje nietknięty.
        $this->assertDatabaseHas('collection_items', ['collection_id' => $inny->id, 'recipe_id' => $usuniety->id]);
        // Treść nie znika — znikają tylko powiązania.
        $this->assertNotNull(Recipe::withTrashed()->find($usuniety->id));
        $this->assertNotNull(Recipe::query()->find($prywatny->id));

        $html = $this->strona($wlasciciel, $zeszyt);
        $this->assertStringNotContainsString('dla Ciebie dostępn', $html);
        $this->assertStringNotContainsString('Wyjmij niedostępne zapisy', $html);
        $this->assertStringContainsString($widoczny->title, $html);
    }

    public function test_dziala_w_zeszycie_domyslnym(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $domyslny = $wlasciciel->defaultCollection();
        $prywatny = $this->przepis($autor, 'private');
        $domyslny->recipes()->attach($prywatny->id);

        $this->assertStringContainsString('Wyjmij niedostępne zapisy', $this->strona($wlasciciel, $domyslny));

        $this->actingAs($wlasciciel)
            ->delete(route('collections.unavailable.destroy', $domyslny), ['zakres' => $this->odcisk($domyslny, $wlasciciel)])
            ->assertSessionHas('status', 'Wyjęliśmy z tego zeszytu 1 niedostępny zapis. Reszta zeszytu została bez zmian.');

        $this->assertSame(0, $domyslny->recipes()->withTrashed()->count());
        $this->assertNotNull($domyslny->fresh());
    }

    public function test_cudzy_zeszyt_odmawia_i_nie_pokazuje_przycisku(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $obcy = $this->user('obcy');
        $autor = $this->user('autor');
        $zeszyt = $this->zeszyt($wlasciciel, 'Publiczny', 'public');
        $prywatny = $this->przepis($autor, 'private');
        $zeszyt->recipes()->attach($prywatny->id);

        $this->assertStringNotContainsString('Wyjmij niedostępne zapisy', $this->strona($obcy, $zeszyt));

        $this->actingAs($obcy)
            ->delete(route('collections.unavailable.destroy', $zeszyt), ['zakres' => $this->odcisk($zeszyt, $obcy)])
            ->assertForbidden();

        $this->assertDatabaseHas('collection_items', ['collection_id' => $zeszyt->id, 'recipe_id' => $prywatny->id]);
    }

    public function test_powtorzone_zadanie_niczego_nie_wyjmuje(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $zeszyt->recipes()->attach($this->przepis($autor, 'private')->id);
        $odcisk = $this->odcisk($zeszyt, $wlasciciel);

        $this->actingAs($wlasciciel)->delete(route('collections.unavailable.destroy', $zeszyt), ['zakres' => $odcisk]);

        $this->actingAs($wlasciciel)
            ->delete(route('collections.unavailable.destroy', $zeszyt), ['zakres' => $odcisk])
            ->assertRedirect(route('collections.show', $zeszyt))
            ->assertSessionHas('status', 'W tym zeszycie nie ma już niedostępnych zapisów. Niczego nie wyjęliśmy.');
    }

    public function test_tresc_ponownie_dostepna_przed_potwierdzeniem_nie_jest_wyjmowana(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $wraca = $this->przepis($autor, 'private');
        $zostaje = $this->przepis($autor, 'private');
        $zeszyt->recipes()->attach([$wraca->id, $zostaje->id]);
        $odcisk = $this->odcisk($zeszyt, $wlasciciel);

        // Między otwarciem strony a potwierdzeniem autor udostępnia przepis.
        $wraca->update(['visibility' => 'public']);

        $this->actingAs($wlasciciel)
            ->from(route('collections.show', $zeszyt))
            ->delete(route('collections.unavailable.destroy', $zeszyt), ['zakres' => $odcisk])
            ->assertRedirect(route('collections.show', $zeszyt))
            ->assertSessionHasErrors('zakres');

        $this->assertSame(2, $zeszyt->recipes()->count());

        $html = $this->actingAs($wlasciciel)->followingRedirects()
            ->delete(route('collections.unavailable.destroy', $zeszyt), ['zakres' => $odcisk])
            ->assertOk()->getContent();
        $html = (string) preg_replace('/\s+/u', ' ', (string) $html);
        $this->assertStringContainsString('Niczego nie wyjęliśmy. Sprawdź nową liczbę poniżej i potwierdź jeszcze raz.', $html);
        $this->assertStringContainsString('1 zapis nie jest dla Ciebie dostępny', $html);
        $this->assertStringContainsString($wraca->title, $html);
    }

    public function test_zamiana_przy_tej_samej_liczbie_tez_odmawia(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $wraca = $this->przepis($autor, 'private');
        $znika = $this->przepis($autor, 'public');
        $zeszyt->recipes()->attach([$wraca->id, $znika->id]);
        $odcisk = $this->odcisk($zeszyt, $wlasciciel);

        // Nadal „1 niedostępny", ale to już INNY zapis niż potwierdzony.
        $wraca->update(['visibility' => 'public']);
        $znika->update(['visibility' => 'private']);

        $this->actingAs($wlasciciel)
            ->delete(route('collections.unavailable.destroy', $zeszyt), ['zakres' => $odcisk])
            ->assertSessionHasErrors('zakres');

        $this->assertSame(2, $zeszyt->recipes()->count());
    }

    public function test_bez_odcisku_niczego_nie_wyjmuje(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zeszyt = $this->zeszyt($wlasciciel, 'Obiady');
        $zeszyt->recipes()->attach($this->przepis($autor, 'private')->id);

        $this->actingAs($wlasciciel)
            ->delete(route('collections.unavailable.destroy', $zeszyt))
            ->assertSessionHasErrors('zakres');

        $this->assertSame(1, $zeszyt->recipes()->count());
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc = 'private'): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => $widocznosc]);
    }

    private function przepis(User $autor, string $widocznosc): Recipe
    {
        return Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => $widocznosc]);
    }

    private function odcisk(Collection $zeszyt, User $kto): string
    {
        $zawartosc = new WidocznaZawartoscZeszytu;

        return $zawartosc->odcisk($zeszyt, $zawartosc->niedostepne($zeszyt, $kto));
    }

    private function strona(User $kto, Collection $zeszyt): string
    {
        $html = (string) $this->actingAs($kto)->get(route('collections.show', $zeszyt))->assertOk()->getContent();

        return (string) preg_replace('/\s+/u', ' ', html_entity_decode($html));
    }
}
