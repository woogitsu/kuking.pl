<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Form Request zeszytu (`ZapisZeszytuRequest`, issue #970 krok 5) trzyma
 * kolejność z kontrolera sprzed wydzielenia: Policy PRZED polami, błąd
 * po polsku z `old()` i zakres nazwy właściciela. Żądania HTTP, nie odczyt
 * źródła.
 */
class FormularzZeszytuKolejnoscSprawdzenTest extends TestCase
{
    use RefreshDatabase;

    public function test_obca_osoba_dostaje_403_zanim_zobaczy_bledy_pol(): void
    {
        $wlasciciel = $this->user('gospodyni');
        $obca = $this->user('obca');
        $zeszyt = $wlasciciel->collections()->create(['name' => 'Na święta', 'visibility' => 'private']);

        $this->actingAs($obca)->patch(route('collections.update', $zeszyt), ['name' => '', 'visibility' => 'x'])
            ->assertForbidden();

        $this->assertSame('Na święta', $zeszyt->fresh()->name);
    }

    public function test_zle_pola_wracaja_z_bledem_po_polsku_i_z_wpisanym_tekstem(): void
    {
        $osoba = $this->user('gospodyni');
        $zeszyt = $osoba->collections()->create(['name' => 'Na święta', 'visibility' => 'private']);

        $this->actingAs($osoba)->from('/zeszyt')->patch(route('collections.update', $zeszyt), [
            'name' => 'A',
            'description' => 'Zostaje w polu',
            'visibility' => 'x',
        ])
            ->assertRedirect('/zeszyt')
            ->assertSessionHasErrors([
                'name' => 'Nazwa zeszytu musi mieć co najmniej 2 znaki. Dopisz kilka liter.',
                'visibility' => 'Zaznacz, kto ma widzieć ten zeszyt: wszyscy czy tylko Ty.',
            ])
            ->assertSessionHasInput('description', 'Zostaje w polu');
    }

    public function test_niezmieniona_nazwa_wlasnego_zeszytu_nie_jest_zajeta_ale_kolizja_z_innym_tak(): void
    {
        $osoba = $this->user('gospodyni');
        $zeszyt = $osoba->collections()->create(['name' => 'Na święta', 'visibility' => 'private']);
        $inny = $osoba->collections()->create(['name' => 'Zupy', 'visibility' => 'private']);

        $this->actingAs($osoba)->patch(route('collections.update', $zeszyt), [
            'name' => 'Na święta', 'visibility' => 'public',
        ])->assertSessionHasNoErrors();
        $this->assertSame('public', $zeszyt->fresh()->visibility);

        $this->actingAs($osoba)->from('/zeszyt')->patch(route('collections.update', $inny), [
            'name' => 'Na święta', 'visibility' => 'private',
        ])->assertSessionHasErrors('name');
        $this->assertSame('Zupy', $inny->fresh()->name);
    }

    public function test_zalozenie_zeszytu_waliduje_i_zapisuje_dla_zalogowanej(): void
    {
        $osoba = $this->user('gospodyni');

        $this->actingAs($osoba)->from('/zeszyt')->post(route('collections.store'), ['name' => '', 'visibility' => 'private'])
            ->assertRedirect('/zeszyt')
            ->assertSessionHasErrors(['name' => 'Podaj nazwę zeszytu — na przykład „Na święta”.']);
        $this->assertSame(0, Collection::query()->where('owner_id', $osoba->getKey())->where('name', 'Ciasta')->count());

        $this->actingAs($osoba)->post(route('collections.store'), ['name' => 'Ciasta', 'visibility' => 'private'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Collection::query()->where('owner_id', $osoba->getKey())->where('name', 'Ciasta')->exists());
    }
}
