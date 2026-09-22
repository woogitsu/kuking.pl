<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #791: zdjęcie blokady świadomie NIE przywraca obserwowania sprzed
 * konfliktu (`App\Domain\Social\Actions\UnblockUser` — automatyczny powrót
 * byłby niespodzianką w prywatności). To jest już zmierzone zachowanie
 * kodu, nie pytanie produktowe do tego zgłoszenia; brakowało tylko zdania
 * na ekranie, które mówi to wprost, zamiast zostawiać człowieka
 * z domysłem po kliknięciu „Zdejmij blokadę”.
 */
class OdblokowanieNieWznawiaObserwowaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_komunikat_po_zdjeciu_blokady_mowi_ze_obserwowanie_sie_nie_wznawia(): void
    {
        $basia = $this->user('basia791');
        $marek = $this->user('marek791');

        app(BlockUser::class)->handle($basia, $marek);

        $odpowiedz = $this->actingAs($basia)->delete(route('social.unblock', 'marek791'));

        $odpowiedz->assertSessionHasNoErrors();
        $odpowiedz->assertSessionHas('status', function (string $status): bool {
            return str_contains($status, 'obserwowanie się nie wznawia samo');
        });
    }

    public function test_zdjecie_blokady_naprawde_nie_przywraca_obserwowania(): void
    {
        // Kontrola dodatnia dla samego zachowania, które komunikat opisuje —
        // gdyby kiedyś ktoś zmienił `UnblockUser`, ten test i komunikat
        // przestałyby się zgadzać, zanim zauważyłby to człowiek na ekranie.
        $basia = $this->user('basia791b');
        $marek = $this->user('marek791b');

        app(BlockUser::class)->handle($marek, $basia);
        app(BlockUser::class)->handle($basia, $marek);

        $this->actingAs($basia)->delete(route('social.unblock', 'marek791b'))->assertSessionHasNoErrors();

        $this->assertFalse($basia->fresh()->isFollowing($marek));
        $this->assertFalse($marek->fresh()->isFollowing($basia));
    }
}
