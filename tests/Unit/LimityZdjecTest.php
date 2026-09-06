<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LimityZdjec;
use Tests\TestCase;

/**
 * Regresja przy porządkowaniu z issue #86: `komunikatZaDuzoZdjec()` odmieniało
 * liczbę zdjęć własną, prywatną kopią reguły (`odmianaZdjecia()`) — DRUGĄ
 * obok `App\Support\Odmiana::rzeczownik()`, którą issue #86 wprost każe
 * wykorzystać zamiast pisać kolejną. Ten test pilnuje, że przepisanie na
 * wspólny helper nie zmieniło ani jednego wygenerowanego zdania.
 *
 * Uses Tests\TestCase (nie gołego PHPUnit) tylko po to, żeby mieć dostęp do
 * `config()` w tym samym procesie co reszta testów Laravela.
 */
final class LimityZdjecTest extends TestCase
{
    public function test_limit_jeden_ma_osobne_zdanie_bez_liczby(): void
    {
        config(['kuking.media.max_per_post' => 1]);

        $this->assertSame(
            'Do jednej wysyłki można dodać tylko jedno zdjęcie. Pozostałe wyślij osobno.',
            LimityZdjec::komunikatZaDuzoZdjec(),
        );
    }

    public function test_limit_dwa_do_czterech_bierze_forme_zdjecia(): void
    {
        config(['kuking.media.max_per_post' => 3]);

        $this->assertSame(
            'Do jednej wysyłki można dodać maksymalnie 3 zdjęcia.',
            LimityZdjec::komunikatZaDuzoZdjec(),
        );
    }

    public function test_limit_piec_i_wiecej_bierze_forme_zdjec(): void
    {
        config(['kuking.media.max_per_post' => 6]);

        $this->assertSame(
            'Do jednej wysyłki można dodać maksymalnie 6 zdjęć.',
            LimityZdjec::komunikatZaDuzoZdjec(),
        );
    }
}
