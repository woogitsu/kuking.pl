<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WygaslaSesjaNiePokazujeCudzychPushyTest extends TestCase
{
    use RefreshDatabase;

    public function test_nowe_konto_odlacza_tylko_subskrypcje_poprzedniego_konta_z_tej_przegladarki(): void
    {
        $poprzedni = User::factory()->create();
        $obecny = User::factory()->create();
        $wspolny = $this->subskrypcja($poprzedni, 'wspolny');
        $telefon = $this->subskrypcja($poprzedni, 'telefon');

        $odpowiedz = $this->actingAs($obecny)->postJson(route('settings.notifications.reconcile-device'), $this->dane($wspolny))
            ->assertOk()->assertJsonPath('odlaczone', true);

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $wspolny->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $telefon->id, 'user_id' => $poprzedni->id]);
        $this->assertSame(0, $obecny->pushSubscriptions()->count());
        $odpowiedz->assertDontSee($poprzedni->email);
    }

    public function test_sam_endpoint_bez_kluczy_nie_pozwala_usunac_cudzej_subskrypcji(): void
    {
        $poprzedni = User::factory()->create();
        $obecny = User::factory()->create();
        $wspolny = $this->subskrypcja($poprzedni, 'wspolny');
        $dane = $this->dane($wspolny);
        $dane['keys']['auth'] = str_repeat('X', 24);

        $this->actingAs($obecny)->postJson(route('settings.notifications.reconcile-device'), $dane)
            ->assertOk()->assertJsonPath('odlaczone', false);

        $this->assertDatabaseHas('push_subscriptions', ['id' => $wspolny->id, 'user_id' => $poprzedni->id]);
    }

    public function test_wlasna_subskrypcja_pozostaje_wlaczona(): void
    {
        $obecny = User::factory()->create();
        $wlasna = $this->subskrypcja($obecny, 'wlasna');

        $this->actingAs($obecny)->postJson(route('settings.notifications.reconcile-device'), $this->dane($wlasna))
            ->assertOk()->assertJsonPath('odlaczone', false);

        $this->assertDatabaseHas('push_subscriptions', ['id' => $wlasna->id, 'user_id' => $obecny->id]);
    }

    private function subskrypcja(User $user, string $urzadzenie): PushSubscription
    {
        $subskrypcja = new PushSubscription;
        $subskrypcja->forceFill([
            'user_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$urzadzenie,
            'klucz_p256dh' => str_repeat('A', 87),
            'klucz_auth' => str_repeat('B', 24),
            'kodowanie' => 'aes128gcm',
        ])->save();

        return $subskrypcja;
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private function dane(PushSubscription $subskrypcja): array
    {
        return [
            'endpoint' => $subskrypcja->endpoint,
            'keys' => ['p256dh' => $subskrypcja->klucz_p256dh, 'auth' => $subskrypcja->klucz_auth],
        ];
    }
}
