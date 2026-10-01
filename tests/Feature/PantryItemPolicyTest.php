<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PantryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * „Co mam w domu” jest listą PRYWATNĄ (D-285, #1903): UUID produktu w adresie
 * niczego nie otwiera — decyduje właściciel, bez wyjątku dla moderatora.
 */
class PantryItemPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function produkt(User $wlasciciel): PantryItem
    {
        /** @var PantryItem */
        return $wlasciciel->pantryItems()->create(['name' => 'mleko']);
    }

    public function test_cudzy_uuid_daje_403_na_edycji_i_zapisie(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $obcy = $this->user('obcy');
        $produkt = $this->produkt($wlasciciel);

        $this->actingAs($obcy)->get(route('pantry.edit', $produkt))->assertForbidden();
        $this->actingAs($obcy)->put(route('pantry.update', $produkt), ['rodzaj' => 'use_by', 'za' => '3'])->assertForbidden();

        $this->assertNull($produkt->fresh()->expires_on);
    }

    public function test_moderator_i_administrator_tez_dostaja_403(): void
    {
        $produkt = $this->produkt($this->user('wlasciciel'));

        foreach ([User::ROLE_MODERATOR, User::ROLE_ADMIN] as $rola) {
            $obsluga = $this->user('obsluga'.$rola);
            $obsluga->forceFill(['role' => $rola])->save();

            $this->actingAs($obsluga)->get(route('pantry.edit', $produkt))->assertForbidden();
            $this->actingAs($obsluga)->put(route('pantry.update', $produkt), ['rodzaj' => 'use_by', 'za' => '3'])->assertForbidden();
            $this->assertFalse(Gate::forUser($obsluga)->allows('update', $produkt));
        }
    }

    public function test_gosc_jest_odsylany_do_logowania(): void
    {
        $produkt = $this->produkt($this->user('wlasciciel'));

        $this->get(route('pantry.edit', $produkt))->assertRedirect(route('login'));
        $this->put(route('pantry.update', $produkt), ['rodzaj' => 'use_by', 'za' => '3'])->assertRedirect(route('login'));
    }

    public function test_wlasciciel_moze_edytowac_i_usuwac_a_obcy_nie_moze_usunac(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $obcy = $this->user('obcy');
        $produkt = $this->produkt($wlasciciel);

        $this->assertTrue(Gate::forUser($wlasciciel)->allows('update', $produkt));
        $this->assertTrue(Gate::forUser($wlasciciel)->allows('delete', $produkt));
        $this->assertFalse(Gate::forUser($obcy)->allows('update', $produkt));
        $this->assertFalse(Gate::forUser($obcy)->allows('delete', $produkt));
        $this->actingAs($obcy)->delete(route('pantry.destroy', $produkt))->assertForbidden();
        $this->assertDatabaseHas('pantry_items', ['id' => $produkt->getKey()]);
    }

    public function test_adres_z_niepoprawnym_uuid_to_404(): void
    {
        $this->actingAs($this->user())->get('/co-mam-w-domu/nie-uuid/termin')->assertNotFound();
    }
}
