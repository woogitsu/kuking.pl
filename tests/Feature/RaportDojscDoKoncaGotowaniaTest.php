<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\DojsciaDoKoncaGotowania;
use App\Domain\Analytics\ZapiszSygnal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tryb gotowania → „Ugotowałem” w `kuking:raport` (F1 „Jak wyszło?”, D-333):
 * pomiar, który karta F1 każe mieć przed oceną funkcji. Same liczniki
 * z `product_signals`, okno 30 dni, procent dopiero od progu próby.
 */
final class RaportDojscDoKoncaGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const TERAZ = '2026-09-30 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TERAZ, 'UTC'));
    }

    public function test_liczy_dojscia_ugotowane_i_bez_ugotowalem_w_oknie(): void
    {
        $this->sygnaly(ZapiszSygnal::COOKING_LAST_STEP_REACHED, 25);
        $this->sygnaly(ZapiszSygnal::COOKING_LAST_STEP_COOKED, 3, ['po_pytaniu' => false]);
        $this->sygnaly(ZapiszSygnal::COOKING_LAST_STEP_COOKED, 2, ['po_pytaniu' => true]);
        $this->sygnaly(ZapiszSygnal::COOKING_FOLLOWUP_SHOWN, 20);
        $this->sygnaly(ZapiszSygnal::COOKING_FOLLOWUP_DISMISSED, 11);

        // KONTROLA UJEMNA: 31 dni temu — poza oknem.
        $this->travelTo(CarbonImmutable::parse(self::TERAZ, 'UTC')->subDays(31));
        $this->sygnaly(ZapiszSygnal::COOKING_LAST_STEP_REACHED, 7);
        $this->sygnaly(ZapiszSygnal::COOKING_FOLLOWUP_DISMISSED, 7);
        $this->travelTo(CarbonImmutable::parse(self::TERAZ, 'UTC'));

        $w = app(DojsciaDoKoncaGotowania::class)->policz();

        $this->assertSame(25, $w['dojscia']);
        $this->assertSame(5, $w['ugotowane']);
        $this->assertSame(2, $w['po_pytaniu']);
        $this->assertSame(20, $w['bez_ugotowalem']);
        $this->assertSame(20.0, $w['procent_ugotowanych']);
        $this->assertSame(20, $w['pokazane']);
        $this->assertSame(11, $w['nie_teraz']);
        $this->assertSame(55.0, $w['procent_nie_teraz']);

        $this->artisan('kuking:raport')
            ->expectsOutputToContain('Doszło do ostatniego kroku: 25 · zapisano „Ugotowałem”: 5 (w tym po pytaniu „Jak wyszło?”: 2) · bez „Ugotowałem”: 20 · 20,0%')
            ->expectsOutputToContain('„Nie teraz”: 11 · 55,0% (powyżej 50% zdanie jest nachalne)')
            ->assertSuccessful();
    }

    public function test_ponizej_progu_proby_same_liczniki(): void
    {
        $this->sygnaly(ZapiszSygnal::COOKING_LAST_STEP_REACHED, 3);
        $this->sygnaly(ZapiszSygnal::COOKING_LAST_STEP_COOKED, 1, ['po_pytaniu' => true]);

        $w = app(DojsciaDoKoncaGotowania::class)->policz();

        $this->assertNull($w['procent_ugotowanych']);
        $this->assertSame(2, $w['bez_ugotowalem']);

        $this->artisan('kuking:raport')
            ->expectsOutputToContain('bez „Ugotowałem”: 2 · za mało danych na procent (mniej niż 20 dojść)')
            ->assertSuccessful();
    }

    public function test_pusta_baza_mowi_ze_nie_da_sie_policzyc(): void
    {
        $this->artisan('kuking:raport')
            ->expectsOutputToContain('Nikt jeszcze nie doszedł do ostatniego kroku w trybie gotowania')
            ->assertSuccessful();
    }

    /** @param  array<string, mixed>  $wlasciwosci */
    private function sygnaly(string $nazwa, int $ile, array $wlasciwosci = []): void
    {
        for ($i = 0; $i < $ile; $i++) {
            app(ZapiszSygnal::class)->handle(null, $nazwa, $wlasciwosci);
        }
    }
}
