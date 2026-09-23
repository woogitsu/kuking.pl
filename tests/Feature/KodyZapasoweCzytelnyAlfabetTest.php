<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class KodyZapasoweCzytelnyAlfabetTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_uzywa_jednoznacznego_alfabetu_i_dziesieciu_znakow(): void
    {
        // Stary generator dostaje deterministycznie znaki mylące; nowy własny strumień testowy.
        Str::createRandomStringsUsing(fn (int $length): string => substr(str_repeat('0O1I', $length), 0, $length));
        try {
            $totp = new TwoFactorAuthenticator(new Randomizer(new Mt19937(875)));
            $codes = $totp->generateBackupCodes();
            $this->assertCount(8, $codes);
            foreach ($codes as $code) {
                $this->assertTrue(preg_match('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/D', $code) === 1, 'Kod ma mieć dwie grupy po pięć jednoznacznych znaków.');
            }
            $this->assertCount(8, array_unique($codes));
            $this->assertTrue(10 * log(32, 2) > 8 * log(36, 2));
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    public function test_stary_i_nowy_kod_dzialaja_raz_bez_zamiany_znakow(): void
    {
        $totp = app(TwoFactorAuthenticator::class);
        $user = $this->user();
        $new = $totp->generateBackupCodes()[0];
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['0O1I-ABCD', $new]));
        $this->assertFalse($totp->consumeBackupCode($user, 'OOII-ABCD'));
        $this->assertTrue($totp->consumeBackupCode($user, ' 0o1i-abcd '));
        $this->assertFalse($totp->consumeBackupCode($user, '0O1I-ABCD'));
        $this->assertTrue($totp->consumeBackupCode($user, strtolower($new)));
        $this->assertFalse($totp->consumeBackupCode($user, $new));
    }
}
