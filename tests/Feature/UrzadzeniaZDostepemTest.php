<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ekran „Urządzenia z dostępem" (`/ustawienia/urzadzenia`, D-270).
 */
class UrzadzeniaZDostepemTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasciciel_widzi_swoje_urzadzenia_i_tylko_swoje(): void
    {
        $basia = $this->user('basia');
        $obca = $this->user('obca');
        $basia->createToken('Telefon Basi');
        $obca->createToken('Tablet Obcej');

        $this->actingAs($basia)->get(route('settings.devices'))
            ->assertOk()
            ->assertSee('Urządzenia z dostępem')
            ->assertSee('Telefon Basi')
            ->assertDontSee('Tablet Obcej')
            ->assertSee('Odetnij to urządzenie')
            ->assertSee('Jeszcze nieużywane.');
    }

    public function test_pusta_lista_mowi_to_wprost(): void
    {
        $this->actingAs($this->user('basia'))->get(route('settings.devices'))
            ->assertOk()
            ->assertSee('Żadne urządzenie nie ma dostępu')
            ->assertDontSee('Odetnij to urządzenie');
    }

    public function test_gosc_nie_wchodzi(): void
    {
        $this->get(route('settings.devices'))->assertRedirect(route('login'));
    }

    public function test_odciecie_jednego_urzadzenia_zostawia_pozostale(): void
    {
        $basia = $this->user('basia');
        $telefon = $basia->createToken('Telefon')->accessToken;
        $basia->createToken('Tablet');

        $this->actingAs($basia)
            ->delete(route('settings.devices.destroy', $telefon))
            ->assertRedirect(route('settings.devices'))
            ->assertSessionHas('status');

        $this->assertSame(['Tablet'], $basia->tokens()->pluck('name')->all());
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.api_token_revoked')->count());
    }

    public function test_cudzego_urzadzenia_nie_da_sie_odciac_przez_sam_identyfikator(): void
    {
        $basia = $this->user('basia');
        $obca = $this->user('obca');
        $cudzy = $obca->createToken('Telefon obcej')->accessToken;

        $status = $this->actingAs($basia)->delete(route('settings.devices.destroy', $cudzy))->getStatusCode();

        $this->assertContains($status, [403, 404]);
        $this->assertTrue(PersonalAccessToken::query()->whereKey($cudzy->getKey())->exists());
    }

    public function test_odciecie_wszystkich(): void
    {
        $basia = $this->user('basia');
        $obca = $this->user('obca');
        $basia->createToken('Telefon');
        $basia->createToken('Tablet');
        $obca->createToken('Telefon obcej');

        $this->actingAs($basia)->get(route('settings.devices'))->assertSee('Odetnij wszystkie urządzenia');

        $this->actingAs($basia)
            ->delete(route('settings.devices.destroy-all'))
            ->assertRedirect(route('settings.devices'));

        $this->assertSame(0, $basia->tokens()->count());
        $this->assertSame(1, $obca->tokens()->count());
        $this->assertTrue($this->isAuthenticated(), 'Odcięcie aplikacji wylogowało przeglądarkę.');
    }

    public function test_zawieszone_konto_moze_odciac_urzadzenie(): void
    {
        $basia = $this->user('basia');
        $telefon = $basia->createToken('Telefon')->accessToken;
        $basia->suspend(now()->addDays(3));
        // `suspend()` kasuje tokeny razem z sesjami — zakładamy jeszcze raz,
        // jakby telefon zalogował się po zawieszeniu.
        $telefon = $basia->createToken('Telefon po zawieszeniu')->accessToken;

        $this->actingAs($basia->fresh())
            ->delete(route('settings.devices.destroy', $telefon))
            ->assertRedirect(route('settings.devices'));

        $this->assertSame(0, $basia->tokens()->count());
    }

    public function test_urzadzenie_zalogowane_przez_api_pojawia_sie_na_liscie(): void
    {
        config(['kuking.api.wlaczone' => true]);
        $basia = $this->user('basia');

        $this->postJson('/api/v1/tokeny', [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
            'device_name' => 'Samsung Basi',
        ])->assertCreated();

        $this->actingAs($basia)->get(route('settings.devices'))->assertSee('Samsung Basi');
    }
}
