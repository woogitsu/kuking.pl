<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\PriorytetZuzycia;
use App\Models\PantryItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * „Dziś” w priorytecie zużycia to data w Europe/Warsaw, nie w UTC (#1903).
 *
 * Termin z opakowania to dzień kalendarzowy bez strefy. Gdyby „dziś” liczyć
 * w UTC, to przez pierwsze dwie godziny polskiej doby (jedną zimą) produkt
 * z terminem „dziś” byłby jeszcze „jutro”, a wczorajszy — „dziś”.
 */
class PriorytetZuzyciaStrefaTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function produkt(string $termin): PantryItem
    {
        return (new PantryItem)->forceFill([
            'name' => 'mleko',
            'expires_on' => CarbonImmutable::parse($termin),
            'expiry_kind' => 'use_by',
            'frozen' => false,
        ]);
    }

    public function test_latem_23_59_czasu_polskiego_to_jeszcze_ten_sam_dzien(): void
    {
        // 23:59 w Warszawie 10 lipca (UTC+2) = 21:59 UTC tego samego dnia.
        Carbon::setTestNow(Carbon::parse('2026-07-10 21:59:00', 'UTC'));

        $this->assertSame('2026-07-10', PriorytetZuzycia::dzis());
        $this->assertSame('Termin dziś.', PriorytetZuzycia::opisStanu($this->produkt('2026-07-10')));
    }

    public function test_latem_00_01_czasu_polskiego_to_juz_nastepny_dzien_choc_w_utc_jeszcze_wczoraj(): void
    {
        // 00:01 w Warszawie 11 lipca = 22:01 UTC 10 lipca.
        Carbon::setTestNow(Carbon::parse('2026-07-10 22:01:00', 'UTC'));

        $this->assertSame('2026-07-11', PriorytetZuzycia::dzis());
        $this->assertStringStartsWith('Termin minął wczoraj.', PriorytetZuzycia::opisStanu($this->produkt('2026-07-10')));
        $this->assertSame('Termin dziś.', PriorytetZuzycia::opisStanu($this->produkt('2026-07-11')));
    }

    public function test_zima_23_59_i_00_01_czasu_polskiego(): void
    {
        // Zimą UTC+1: 23:59 w Warszawie = 22:59 UTC, 00:01 = 23:01 UTC.
        Carbon::setTestNow(Carbon::parse('2026-01-10 22:59:00', 'UTC'));
        $this->assertSame('2026-01-10', PriorytetZuzycia::dzis());

        Carbon::setTestNow(Carbon::parse('2026-01-10 23:01:00', 'UTC'));
        $this->assertSame('2026-01-11', PriorytetZuzycia::dzis());
    }

    public function test_zmiana_czasu_wiosna_i_jesienia_nie_przesuwa_granicy_pilnych_o_dobe(): void
    {
        // Wiosna: 29 marca 2026 zegar skacze z 02:00 na 03:00 (doba ma 23 godziny).
        Carbon::setTestNow(Carbon::parse('2026-03-29 10:00:00', 'UTC'));
        $this->assertSame('2026-03-29', PriorytetZuzycia::dzis());
        $this->assertSame('2026-04-01', PriorytetZuzycia::granicaPilnych());
        $this->assertSame('Termin za 3 dni (1 kwietnia).', PriorytetZuzycia::opisStanu($this->produkt('2026-04-01')));

        // Jesień: 25 października 2026 zegar wraca z 03:00 na 02:00 (doba ma 25 godzin).
        Carbon::setTestNow(Carbon::parse('2026-10-25 10:00:00', 'UTC'));
        $this->assertSame('2026-10-25', PriorytetZuzycia::dzis());
        $this->assertSame('2026-10-28', PriorytetZuzycia::granicaPilnych());
        $this->assertSame('Termin jutro (26 października).', PriorytetZuzycia::opisStanu($this->produkt('2026-10-26')));

        // Noc zmiany czasu jesienią: 00:30 czasu polskiego 25.10 to jeszcze 24.10 w UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-24 22:30:00', 'UTC'));
        $this->assertSame('2026-10-25', PriorytetZuzycia::dzis());
    }
}
