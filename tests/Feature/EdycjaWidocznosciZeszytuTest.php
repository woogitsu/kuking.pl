<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publicznego zeszytu nie dało się cofnąć do prywatnego bez usunięcia całego
 * zeszytu (issue #777) — jedyną widoczną drogą było `collections.destroy`,
 * czyli utrata nazwy, opisu i wszystkich zapisów razem z samą widocznością.
 *
 * `CollectionPolicy::update()` istniało od dawna i już wtedy pozwalało tylko
 * właścicielowi — brakowało wyłącznie TRASY i WIDOKU, które by z niego
 * skorzystały.
 */
final class EdycjaWidocznosciZeszytuTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasciciel_zamyka_publiczny_zeszyt_bez_utraty_zawartosci(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $obcy = $this->user('obcy');
        $przepis = Recipe::factory()->create();

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Na święta',
            'description' => 'Rodzinne przepisy',
            'visibility' => 'public',
        ]);
        $zeszyt->recipes()->attach($przepis->getKey(), ['note' => 'Ulubione', 'created_at' => now()]);

        // Przed zmianą: zeszyt jest dostępny dla zalogowanego obcego.
        $this->actingAs($obcy)->get(route('collections.show', $zeszyt))->assertOk();

        $this->actingAs($wlasciciel)
            ->patch(route('collections.update', $zeszyt), [
                'name' => 'Na święta',
                'description' => 'Rodzinne przepisy',
                'visibility' => 'private',
            ])
            ->assertRedirect(route('collections.show', $zeszyt));

        $zeszyt->refresh();
        $this->assertSame('private', $zeszyt->visibility);

        // Zawartość i opis PRZEŻYŁY zmianę widoczności.
        $this->assertSame('Na święta', $zeszyt->name);
        $this->assertSame('Rodzinne przepisy', $zeszyt->description);
        $pivot = $zeszyt->recipes()->whereKey($przepis->getKey())->first()->pivot;
        $this->assertSame('Ulubione', $pivot->note);

        // Po zmianie: stary bezpośredni adres odmawia dostępu obcemu.
        $this->actingAs($obcy)->get(route('collections.show', $zeszyt))->assertForbidden();

        // Właściciel nadal widzi swój zeszyt.
        $this->actingAs($wlasciciel)->get(route('collections.show', $zeszyt))->assertOk();
    }

    public function test_ponowne_upublicznienie_jest_jawne_i_dziala(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $obcy = $this->user('obcy');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Prywatny', 'visibility' => 'private']);

        $this->actingAs($wlasciciel)
            ->patch(route('collections.update', $zeszyt), [
                'name' => 'Prywatny',
                'visibility' => 'public',
            ])
            ->assertRedirect(route('collections.show', $zeszyt))
            ->assertSessionHas('status', 'Zeszyt jest teraz widoczny dla wszystkich.');

        $zeszyt->refresh();
        $this->assertSame('public', $zeszyt->visibility);
        $this->actingAs($obcy)->get(route('collections.show', $zeszyt))->assertOk();
    }

    public function test_cudzy_identyfikator_nie_moze_edytowac_ani_zapisac(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $obcy = $this->user('obcy');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Cudzy', 'visibility' => 'public']);

        $this->actingAs($obcy)->get(route('collections.edit', $zeszyt))->assertForbidden();

        $this->actingAs($obcy)
            ->patch(route('collections.update', $zeszyt), ['name' => 'Przejęty', 'visibility' => 'private'])
            ->assertForbidden();

        $zeszyt->refresh();
        $this->assertSame('Cudzy', $zeszyt->name);
        $this->assertSame('public', $zeszyt->visibility);
    }

    public function test_blad_walidacji_zostawia_formularz_wypelniony_i_nie_zmienia_widocznosci(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Obiady', 'visibility' => 'public']);

        $this->actingAs($wlasciciel)
            ->from(route('collections.edit', $zeszyt))
            ->patch(route('collections.update', $zeszyt), [
                'name' => 'x',
                'visibility' => 'public',
            ])
            ->assertRedirect(route('collections.edit', $zeszyt))
            ->assertSessionHasErrors('name')
            ->assertSessionHas('_old_input.name', 'x');

        // Widoczność NIE zmieniła się w wyniku błędu innego pola.
        $this->assertSame('public', $zeszyt->fresh()->visibility);

        $html = $this->actingAs($wlasciciel)->get(route('collections.edit', $zeszyt))->assertOk()->getContent();
        $this->assertStringContainsString('value="x"', $html);
    }

    public function test_wlasna_niezmieniona_nazwa_nie_jest_dla_siebie_zajeta(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);

        $this->actingAs($wlasciciel)
            ->patch(route('collections.update', $zeszyt), [
                'name' => 'Obiady',
                'visibility' => 'public',
            ])
            ->assertSessionDoesntHaveErrors('name')
            ->assertRedirect(route('collections.show', $zeszyt));
    }
}
