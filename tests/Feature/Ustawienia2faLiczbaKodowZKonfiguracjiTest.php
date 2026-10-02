<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Zdanie o regeneracji kodów bierze liczbę z konfiguracji (#2666). */
class Ustawienia2faLiczbaKodowZKonfiguracjiTest extends TestCase
{
    use RefreshDatabase;

    private function zDwuetapowa(): User
    {
        $totp = app(TwoFactorAuthenticator::class);
        $user = $this->user('basia', ['email' => 'basia@example.com']);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['ABCDE-00001']));

        return $user->refresh();
    }

    /** @return array<string, array{int, string}> */
    public static function liczby(): array
    {
        return [
            '8 kod.' => [8, 'Nowy komplet 8 kodów.'],
            '5 kod.' => [5, 'Nowy komplet 5 kodów.'],
            '2 kod.' => [2, 'Nowy komplet 2 kodów.'],
            '1 kod.' => [1, 'Nowy komplet 1 kodu.'],
            '12 kod.' => [12, 'Nowy komplet 12 kodów.'],
        ];
    }

    #[DataProvider('liczby')]
    public function test_zdanie_o_nowym_komplecie_ma_liczbe_z_konfiguracji(int $ile, string $zdanie): void
    {
        config(['kuking.two_factor.recovery_codes' => $ile]);

        $html = $this->actingAs($this->zDwuetapowa())
            ->get(route('settings.two_factor.edit'))->assertOk()->getContent();

        $this->assertStringContainsString($zdanie, $html);
        $this->assertStringNotContainsString('ośmiu kodów', $html);
        $this->assertStringContainsString('Stare kody przestaną wtedy działać', $html);
    }

    public function test_kontrola_ujemna_inna_liczba_nie_pojawia_sie_w_zdaniu(): void
    {
        config(['kuking.two_factor.recovery_codes' => 5]);

        $html = $this->actingAs($this->zDwuetapowa())
            ->get(route('settings.two_factor.edit'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Nowy komplet 8 kodów', $html);
    }

    public function test_strona_z_nowymi_kodami_nie_twierdzi_o_osmiu_na_sztywno(): void
    {
        config(['kuking.two_factor.recovery_codes' => 5]);
        $user = $this->zDwuetapowa();

        $this->actingAs($user)
            ->post(route('settings.two_factor.regenerate'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.two_factor.codes'));

        $html = $this->actingAs($user)->get(route('settings.two_factor.codes'))->assertOk()->getContent();

        $this->assertStringContainsString('razem 5', $html);
        $this->assertStringNotContainsString('osiem kodów', $html);
    }
}
