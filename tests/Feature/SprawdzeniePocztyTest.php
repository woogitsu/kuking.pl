<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `kuking:sprawdz-poczte` — komenda ma mówić prawdę, zwłaszcza gdy prawda
 * jest niewygodna.
 *
 * PO CO TEN PLIK
 * Cała wartość tej komendy leży w jednym zachowaniu: przy sterowniku, który
 * nie dostarcza (`log`, `array`), ma się skończyć PORAŻKĄ, a nie zielonym
 * napisem. Komenda diagnostyczna, która kłamie, jest gorsza niż jej brak —
 * bo po niej właściciel przestaje szukać przyczyny.
 *
 * Testy nie sprawdzają, czy list doszedł. Tego nie da się sprawdzić z suity
 * i komenda też tego nie twierdzi (patrz „CZEGO ŚWIADOMIE NIE ROBIMY”
 * w `App\Console\Commands\SprawdzPoczte`).
 */
final class SprawdzeniePocztyTest extends TestCase
{
    /** KONTROLA. Suita chodzi na sterowniku `array`, czyli takim, który nie dostarcza. */
    public function test_kontrola_suita_chodzi_na_sterowniku_bez_dostawy(): void
    {
        $this->assertSame('array', config('mail.default'));
    }

    public function test_zly_adres_konczy_sie_bledem(): void
    {
        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'to-nie-jest-adres'])
            ->expectsOutputToContain('nie wygląda na adres e-mail')
            ->assertFailed();
    }

    /**
     * NAJWAŻNIEJSZA ASERCJA W TYM PLIKU. Sterownik `array` niczego nie
     * dostarcza, więc komenda nie ma prawa zakończyć się sukcesem.
     */
    public function test_sterownik_bez_dostawy_konczy_komende_porazka(): void
    {
        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'basia@example.com'])
            ->expectsOutputToContain('Poczta nie wychodzi')
            ->assertFailed();
    }

    /**
     * `log` to stan, w którym serwis jest dziś na produkcji (runbook,
     * `.env.example`). Komunikat musi nazwać skutek wprost, a nie zostawić
     * właściciela z nazwą sterownika.
     */
    public function test_sterownik_log_mowi_wprost_ze_nikt_nic_nie_dostanie(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'basia@example.com'])
            ->expectsOutputToContain('zapisuje wiadomość do dziennika')
            ->expectsOutputToContain('POCZTA_URUCHOMIENIE.md')
            ->assertFailed();
    }

    /** Nazwa sterownika spoza `config/mail.php` pada tu, a nie przy czyjejś rejestracji. */
    public function test_nieznany_sterownik_nie_udaje_ze_dziala(): void
    {
        config(['mail.default' => 'emaillabs']);

        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'basia@example.com'])
            ->expectsOutputToContain('nie ma sterownika o nazwie')
            ->assertFailed();
    }

    /**
     * KONTROLA DRUGIEJ STRONY. Gdy sterownik dostarcza, komenda ma wysłać
     * i powiedzieć o tym bez zastrzeżeń — inaczej „naprawa” mogłaby polegać
     * na tym, że nic nigdy nie przechodzi.
     */
    public function test_dzialajacy_sterownik_wysyla_synchronicznie(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'basia@example.com'])
            ->expectsOutputToContain('przyjął wiadomość bez błędu')
            ->assertSuccessful();
    }

    /**
     * `failover` z `log` na liście przechodzi przez `Poczta::dziala()`, bo
     * sterownik nazywa się inaczej — a przy awarii pierwszego transportu
     * listy zaczynają cicho wpadać do dziennika. Komenda musi to nazwać.
     */
    public function test_ostrzega_gdy_zapasowym_transportem_jest_dziennik(): void
    {
        Mail::fake();
        config(['mail.default' => 'failover']);

        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'basia@example.com'])
            ->expectsOutputToContain('NIC NIE DOSTARCZA')
            ->assertSuccessful();
    }

    /** Nadawca `noreply@` przechodzi technicznie, ale łamie `docs/brand/BRAND_EXTENDED.md`. */
    public function test_ostrzega_o_nadawcy_ktory_nie_przyjmuje_odpowiedzi(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'noreply@kuking.pl',
        ]);

        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'basia@example.com'])
            ->expectsOutputToContain('BRAND_EXTENDED.md')
            ->assertSuccessful();
    }

    /**
     * Wysyłka przez kolejkę nie ma prawa udawać, że list wyszedł — bez
     * chodzącego workera zadanie leży w tabeli `jobs` i nikt go nie ruszy.
     */
    public function test_wysylka_przez_kolejke_nie_udaje_doreczenia(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->artisan('kuking:sprawdz-poczte', [
            'adres' => 'basia@example.com',
            '--kolejka' => true,
        ])
            ->expectsOutputToContain('Zadanie trafiło do kolejki')
            ->expectsOutputToContain('to jeszcze nie znaczy, że list wyszedł')
            ->assertSuccessful();
    }
}
