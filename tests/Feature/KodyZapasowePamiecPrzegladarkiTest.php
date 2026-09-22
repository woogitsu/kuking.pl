<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KodyZapasowePamiecPrzegladarkiTest extends TestCase
{
    use RefreshDatabase;

    // Nagłówek nie dowodzi zachowania historii: osobny pomiar w prawdziwej przeglądarce.
    public function test_odpowiedz_z_kodami_zabrania_przechowywania_i_nie_wydaje_ich_drugi_raz(): void
    {
        $user = $this->user();
        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['TEST-TEST']));
        $this->actingAs($user)->post(route('settings.two_factor.regenerate'), ['password' => 'haslo-testowe-123'])->assertRedirect(route('settings.two_factor.codes'));
        $response = $this->get(route('settings.two_factor.codes'))->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), 'Odpowiedź zawierająca kody musi mieć no-store.');
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertSame(8, substr_count($response->getContent(), '<li class="py-2">'));
        $this->get(route('settings.two_factor.codes'))->assertRedirect(route('settings.two_factor.edit'));
        $this->get(route('settings.two_factor.edit'))->assertOk()->assertSee('wygeneruj nowy komplet', false);
    }
}
