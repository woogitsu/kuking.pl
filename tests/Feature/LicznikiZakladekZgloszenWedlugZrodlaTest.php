<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LICZBA W ZAKŁADCE MÓWI, ILE POZYCJI OTWORZY JEJ KLIKNIĘCIE (issue #990).
 *
 * Zakładki „Nowe / W trakcie / Rozpatrzone" niosą bieżące `zrodlo`. Przy
 * `?zrodlo=automat` liczniki liczyły jednak zgłoszenia OD LUDZI, więc
 * moderator widział „Nowe (2)" nad trzema oznaczeniami automatu.
 *
 * Oba źródła leżą w bazie NARAZ i w różnych liczbach w każdym stanie: test
 * z jednym źródłem przeszedłby także przy ponownym pomieszaniu zbiorów.
 */
class LicznikiZakladekZgloszenWedlugZrodlaTest extends TestCase
{
    use RefreshDatabase;

    /** Ludzie: 2 nowe, 1 w trakcie, 4 rozpatrzone. Automat: 3 nowe, 5 w trakcie, 6 rozpatrzonych. */
    private const LUDZIE = [Report::STATUS_OPEN => 2, Report::STATUS_REVIEWING => 1, Report::STATUS_RESOLVED => 4];

    private const AUTOMAT = [Report::STATUS_OPEN => 3, Report::STATUS_REVIEWING => 5, Report::STATUS_RESOLVED => 6];

    private function sprawa(string $status, bool $automat): Report
    {
        return Report::create([
            'reporter_id' => $automat ? null : $this->user()->getKey(),
            'source' => $automat ? Report::SOURCE_AUTOMAT : Report::SOURCE_COMMUNITY,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'spam',
            'status' => $status,
            // `reports_resolution_complete_check` (#997): stan końcowy ma datę.
            'resolved_at' => $status === Report::STATUS_RESOLVED ? now() : null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([false => self::LUDZIE, true => self::AUTOMAT] as $automat => $liczby) {
            foreach ($liczby as $status => $ile) {
                foreach (range(1, $ile) as $_) {
                    $this->sprawa($status, (bool) $automat);
                }
            }
        }
    }

    public function test_zakladki_licza_zrodlo_ktore_pokazuje_lista(): void
    {
        $moderator = $this->moderator();

        foreach (['ludzie' => self::LUDZIE, Report::SOURCE_AUTOMAT => self::AUTOMAT] as $zrodlo => $oczekiwane) {
            $parametry = $zrodlo === 'ludzie' ? [] : ['zrodlo' => $zrodlo];

            $odpowiedz = $this->actingAs($moderator)->get(route('admin.reports', $parametry))->assertOk();

            $this->assertSame($oczekiwane, [
                Report::STATUS_OPEN => $odpowiedz->viewData('counts')['open'],
                Report::STATUS_REVIEWING => $odpowiedz->viewData('counts')['reviewing'],
                Report::STATUS_RESOLVED => $odpowiedz->viewData('counts')['resolved'],
            ], 'Liczniki zakładek dla źródła „'.$zrodlo.'".');

            $odpowiedz->assertSee('Nowe ('.$oczekiwane[Report::STATUS_OPEN].')', false)
                ->assertSee('W trakcie ('.$oczekiwane[Report::STATUS_REVIEWING].')', false)
                ->assertSee('Rozpatrzone ('.$oczekiwane[Report::STATUS_RESOLVED].')', false);

            // Kliknięcie zakładki otwiera listę o tylu pozycjach, ile
            // obiecała jej liczba.
            foreach ($oczekiwane as $status => $ile) {
                $lista = $this->actingAs($moderator)
                    ->get(route('admin.reports', $parametry + ['status' => $status]))
                    ->assertOk()
                    ->viewData('reports');

                $this->assertSame($ile, $lista->total(), 'Lista „'.$status.'" dla źródła „'.$zrodlo.'".');
            }
        }
    }

    public function test_widok_automatu_mowi_ze_liczby_dotycza_oznaczen_automatu(): void
    {
        $this->actingAs($this->moderator())
            ->get(route('admin.reports', ['zrodlo' => Report::SOURCE_AUTOMAT]))
            ->assertOk()
            ->assertSee('Liczby przy zakładkach dotyczą oznaczeń automatu.')
            ->assertDontSee('Liczby przy zakładkach dotyczą zgłoszeń od ludzi.');
    }

    public function test_licznik_w_menu_obejmuje_nadal_open_triage_i_reviewing_automatu(): void
    {
        $this->sprawa(Report::STATUS_TRIAGE, true);

        // 3 nowe + 5 w trakcie + 1 wstępnie przejrzane — rozpatrzone nie czekają.
        $this->actingAs($this->moderator())
            ->get(route('admin.reports'))
            ->assertOk()
            ->assertViewHas('sygnalow', 9);
    }
}
