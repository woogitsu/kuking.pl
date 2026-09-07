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

    /**
     * Budżet zdjęć KROKÓW przepisu (audyt zewnętrzny T12/T24).
     *
     * Nie jest osobną liczbą obok `max_per_post` — jest z niego policzony,
     * bo to jest ten sam budżet bajtów jednego żądania, który
     * `UploadLimitsAgreementTest` porównuje z `post_max_size`
     * z `docker/php.ini`. Minus dwa, bo formularz przepisu ma zawsze dwa
     * pola plikowe poza krokami: zdjęcie dania i zdjęcie kartki.
     */
    public function test_budzet_zdjec_krokow_zostawia_miejsce_na_danie_i_kartke(): void
    {
        config(['kuking.media.max_per_post' => 6]);

        $this->assertSame(4, LimityZdjec::maksZdjecKrokowNaZapis());

        // KONTROLNA: cały formularz mieści się w tym samym budżecie, który
        // pilnuje zgody z `docker/php.ini` — 4 kroki + danie + kartka = 6.
        $this->assertSame(
            (int) config('kuking.media.max_per_post'),
            LimityZdjec::maksZdjecKrokowNaZapis() + 2,
        );
    }

    public function test_budzet_zdjec_krokow_nigdy_nie_spada_do_zera(): void
    {
        // Przy limicie mniejszym niż dwa odjęcie dwóch dałoby zero albo
        // liczbę ujemną, czyli formularz z polem pliku, którego NIGDY nie da
        // się użyć — i komunikat „można dodać najwyżej 0 zdjęć". Wolimy
        // jedno zdjęcie na zapis niż pole, które zawsze kończy się błędem.
        config(['kuking.media.max_per_post' => 1]);

        $this->assertSame(1, LimityZdjec::maksZdjecKrokowNaZapis());
    }

    public function test_komunikat_o_zbyt_wielu_zdjeciach_krokow_mowi_co_zrobic(): void
    {
        config(['kuking.media.max_per_post' => 6]);

        $this->assertSame(
            'Za jednym razem można dodać najwyżej 4 zdjęcia do kroków. '
            .'Zapisz przepis z tymi zdjęciami, a potem dodaj kolejne — '
            .'zdjęcia już zapisane zostaną przy swoich krokach.',
            LimityZdjec::komunikatZaDuzoZdjecKrokow(),
        );

        // Odmiana liczebnika idzie z `Odmiana::rzeczownik()`, nie z drugiej
        // kopii tej reguły obok (issue #86).
        config(['kuking.media.max_per_post' => 7]);

        $this->assertStringStartsWith(
            'Za jednym razem można dodać najwyżej 5 zdjęć do kroków.',
            LimityZdjec::komunikatZaDuzoZdjecKrokow(),
        );
    }
}
