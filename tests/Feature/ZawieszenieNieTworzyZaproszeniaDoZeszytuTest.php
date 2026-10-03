<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ZawieszenieNieTworzyZaproszeniaDoZeszytuTest extends TestCase
{
    use RefreshDatabase;

    public function test_stary_model_w_domenie_nie_tworzy_zaproszenia_ani_linku_po_zawieszeniu(): void
    {
        $wlasciciel = $this->user('zapraszajacy');
        $adresat = $this->user('zapraszany');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);

        // Stary model przechodzi początkową Policy, mimo że decyzja w bazie
        // zapadła później. To odtwarza żądanie rozpoczęte przed sankcją.
        $stary = $wlasciciel->fresh();
        $wlasciciel->suspend();

        foreach (['nazwa', 'link'] as $droga) {
            try {
                if ($droga === 'nazwa') {
                    app(ZaprosDoZeszytu::class)->poNazwie($stary, $zeszyt, (string) $adresat->profile->username);
                } else {
                    app(ZaprosDoZeszytu::class)->linkiem($stary, $zeszyt);
                }
                $this->fail('ZAPROSZENIE_2835_SWIEZA_POLICY: stary model dopuścił nowe zaproszenie.');
            } catch (BladDlaCzlowieka $blad) {
                $this->assertSame(ZaprosDoZeszytu::BRAK_PRAWA, $blad->getMessage(), 'ZAPROSZENIE_2835_SWIEZA_POLICY');
            }
        }

        $this->assertSame(0, CollectionInvitation::query()->where('collection_id', $zeszyt->getKey())->count(), 'ZAPROSZENIE_2835_SWIEZA_POLICY');
        $this->assertSame(0, Notification::query()->where('user_id', $adresat->getKey())->count(), 'ZAPROSZENIE_2835_SWIEZA_POLICY');
    }

    public function test_http_odmawia_po_zawieszeniu_ale_wczesniejsze_zaproszenie_zostaje(): void
    {
        $wlasciciel = $this->user('zapraszajacy');
        $adresat = $this->user('zapraszany');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $wczesniejsze = app(ZaprosDoZeszytu::class)->poNazwie($wlasciciel, $zeszyt, (string) $adresat->profile->username);
        $wlasciciel->suspend();

        $this->actingAs($wlasciciel)->post(route('collections.invitations.link', $zeszyt))
            ->assertRedirect()->assertSessionHasErrors(['konto' => EnsureAccountIsActive::komunikatZawieszenia($wlasciciel)]);
        $this->actingAs($wlasciciel)->post(route('collections.invitations.store', $zeszyt), ['nazwa' => $adresat->profile->username])
            ->assertRedirect()->assertSessionHasErrors(['konto' => EnsureAccountIsActive::komunikatZawieszenia($wlasciciel)]);
        $this->actingAs($wlasciciel)->get(route('collections.sharing', $zeszyt))->assertOk();

        $this->assertSame(CollectionInvitation::STATUS_PENDING, $wczesniejsze->fresh()?->status);
        $this->assertSame(1, CollectionInvitation::query()->where('collection_id', $zeszyt->getKey())->count());
    }
}
