<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Notifications\TerminPowiadomieniaZewnetrznego as Termin;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Etap 1 issue #35: limit dobowy i cisza nocna dla powiadomień poza serwisem,
 * sprawdzane bez przeglądarki, VAPID i kolejki.
 *
 * Wszystkie momenty podane są w UTC, tak jak liczy aplikacja; komentarz obok
 * mówi, która to godzina w Warszawie.
 *
 * @bez-kontroli-dodatniej base_path() służy tylko do wykonania pliku konfiguracji i odczytu domyślnej wartości flagi jako danych PHP; żadna asercja nie dotyczy tekstu źródła aplikacji.
 */
final class TerminPowiadomieniaZewnetrznegoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.strefa' => 'Europe/Warsaw',
            'kuking.notifications.zewnetrzne.wlaczone' => true,
            'kuking.notifications.zewnetrzne.cisza_od_godziny' => 21,
            'kuking.notifications.zewnetrzne.cisza_do_godziny' => 8,
            'kuking.notifications.zewnetrzne.dzienny_limit' => 1,
        ]);
    }

    public function test_domyslnie_flaga_jest_wylaczona_i_nic_nie_wychodzi(): void
    {
        $domyslne = require base_path('config/kuking.php');
        $this->assertFalse($domyslne['notifications']['zewnetrzne']['wlaczone']);

        config(['kuking.notifications.zewnetrzne.wlaczone' => false]);

        $wynik = Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'), 0);

        $this->assertSame(Termin::KANAL_WYLACZONY, $wynik['decyzja']);
        $this->assertNull($wynik['wyslij_od']);
    }

    public function test_w_dzien_ponizej_limitu_wychodzi_od_razu(): void
    {
        // 12:00 w Warszawie (CEST, UTC+2).
        $wynik = Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'), 0);

        $this->assertSame(Termin::TERAZ, $wynik['decyzja']);
    }

    public function test_wieczorem_w_ciszy_nocnej_czeka_do_osmej_rano_nastepnego_dnia(): void
    {
        // 23:00 w Warszawie.
        $wynik = Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-25 21:00:00', 'UTC'), 0);

        $this->assertSame(Termin::ODLOZ, $wynik['decyzja']);
        $this->assertSame('2026-09-26 06:00:00', $wynik['wyslij_od']->utc()->format('Y-m-d H:i:s'));
    }

    public function test_granice_ciszy_nocnej_liczone_sa_w_strefie_odbiorcy_nie_w_utc(): void
    {
        // 20:59 w Warszawie — jeszcze nie cisza, choć w UTC to 18:59.
        $this->assertSame(Termin::TERAZ, Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-25 18:59:00', 'UTC'), 0)['decyzja']);
        // 21:00 w Warszawie — już cisza.
        $this->assertSame(Termin::ODLOZ, Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-25 19:00:00', 'UTC'), 0)['decyzja']);
        // 7:59 w Warszawie — jeszcze cisza, do 8:00 tego samego dnia.
        $rano = Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-26 05:59:00', 'UTC'), 0);
        $this->assertSame(Termin::ODLOZ, $rano['decyzja']);
        $this->assertSame('2026-09-26 06:00:00', $rano['wyslij_od']->utc()->format('Y-m-d H:i:s'));
        // 8:00 w Warszawie — już wolno.
        $this->assertSame(Termin::TERAZ, Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-26 06:00:00', 'UTC'), 0)['decyzja']);
    }

    public function test_inna_strefa_odbiorcy_przesuwa_cisze_nocna(): void
    {
        // 19:30 UTC to 21:30 w Warszawie, ale 20:30 w Londynie (BST).
        $moment = CarbonImmutable::parse('2026-09-25 19:30:00', 'UTC');

        $this->assertSame(Termin::ODLOZ, Termin::rozstrzygnij($moment, 0)['decyzja']);
        $this->assertSame(Termin::TERAZ, Termin::rozstrzygnij($moment, 0, 'Europe/London')['decyzja']);
    }

    public function test_wyczerpany_limit_odklada_do_rana_nastepnej_doby_a_nie_kasuje(): void
    {
        // 12:00 w Warszawie, jedno już wysłane.
        $wynik = Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'), 1);

        $this->assertSame(Termin::ODLOZ, $wynik['decyzja']);
        $this->assertSame('2026-09-26 06:00:00', $wynik['wyslij_od']->utc()->format('Y-m-d H:i:s'));
    }

    public function test_wyzszy_limit_przepuszcza_kolejne_w_tej_samej_dobie(): void
    {
        config(['kuking.notifications.zewnetrzne.dzienny_limit' => 3]);
        $moment = CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC');

        $this->assertSame(Termin::TERAZ, Termin::rozstrzygnij($moment, 2)['decyzja']);
        $this->assertSame(Termin::ODLOZ, Termin::rozstrzygnij($moment, 3)['decyzja']);
    }

    public function test_limit_zero_wylacza_kanal_zamiast_odkladac_bez_konca(): void
    {
        config(['kuking.notifications.zewnetrzne.dzienny_limit' => 0]);

        $this->assertSame(Termin::KANAL_WYLACZONY, Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'), 0)['decyzja']);
    }

    public function test_doba_zaczyna_sie_o_polnocy_w_strefie_odbiorcy(): void
    {
        // 1:30 w Warszawie 26 września to jeszcze 25 września w UTC.
        $poczatek = Termin::poczatekDoby(CarbonImmutable::parse('2026-09-25 23:30:00', 'UTC'));

        $this->assertSame('2026-09-25 22:00:00', $poczatek->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $poczatek->getTimezone()->getName());
    }

    public function test_osma_rano_zostaje_osma_po_zmianie_czasu_na_zimowy(): void
    {
        // 23:00 w Warszawie 24 października (CEST); 25 października jest już CET (UTC+1).
        $wynik = Termin::rozstrzygnij(CarbonImmutable::parse('2026-10-24 21:00:00', 'UTC'), 0);

        $this->assertSame('2026-10-25 07:00:00', $wynik['wyslij_od']->utc()->format('Y-m-d H:i:s'));
    }

    public function test_rowne_godziny_ciszy_znacza_brak_ciszy(): void
    {
        config([
            'kuking.notifications.zewnetrzne.cisza_od_godziny' => 0,
            'kuking.notifications.zewnetrzne.cisza_do_godziny' => 0,
        ]);

        $this->assertSame(Termin::TERAZ, Termin::rozstrzygnij(CarbonImmutable::parse('2026-09-25 22:00:00', 'UTC'), 0)['decyzja']);
    }
}
