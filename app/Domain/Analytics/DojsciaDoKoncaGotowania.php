<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\ProductSignal;
use Carbon\CarbonImmutable;

/**
 * Ile gotowań w trybie gotowania dochodzi do ostatniego kroku bez
 * „Ugotowałem” (F1 „Jak wyszło?”, research 30.09.2026, D-333).
 *
 * Karta F1 każe to zmierzyć PRZED oceną funkcji: metryka to
 * `wykonania / gotowania doprowadzone do ostatniego kroku`, przed i po,
 * a kontrolą — odsetek „Nie teraz” (powyżej 50% zdanie jest nachalne).
 *
 * SKĄD LICZBY
 * Z czterech sygnałów w `product_signals`, które pisze wyłącznie
 * `App\Domain\Recipes\Gotowanie\JakWyszlo` — BEZ `user_id` i bez przepisu.
 * To są więc same liczniki w oknie {@see self::DNI} dni, nie pary
 * osoba–przepis:
 *
 *   - dojścia: nowe gotowanie otworzyło ostatni krok (zalogowana osoba,
 *     która może zapisać wykonanie; odświeżenie tego samego gotowania nie
 *     liczy się drugi raz),
 *   - ugotowane: to gotowanie skończyło się „Ugotowałem” — z formularza w tej
 *     samej przeglądarce albo zauważone na Starcie, gdy wykonanie zapisano
 *     gdzie indziej; `po_pytaniu` mówi, czy po „Pokaż zdjęcie”,
 *   - pokazane / „Nie teraz”: samo pytanie na Starcie.
 *
 * CZEGO TO NIE WIE (dolna granica, nie dokładna wartość)
 * Wykonanie zapisane na innym urządzeniu liczy się dopiero, gdy osoba wróci
 * na Start w przeglądarce, w której gotowała — „bez Ugotowałem” jest więc
 * oszacowaniem od góry. Liczniki są w tym samym oknie, więc dojście sprzed
 * 31 dni ugotowane wczoraj podbija licznik ugotowanych bez dojścia w oknie;
 * dlatego „bez Ugotowałem” nie schodzi poniżej zera.
 */
final class DojsciaDoKoncaGotowania
{
    public const DNI = 30;

    /** Poniżej tej liczby dojść (albo pokazań) — same liczniki, bez procentu. */
    public const MINIMUM = 20;

    /** Karta F1: powyżej tego odsetka „Nie teraz” zdanie jest nachalne. */
    public const PROG_NIE_TERAZ = 50;

    /**
     * @return array{dojscia: int, ugotowane: int, po_pytaniu: int, bez_ugotowalem: int,
     *               procent_ugotowanych: float|null, pokazane: int, nie_teraz: int, procent_nie_teraz: float|null}
     */
    public function policz(?CarbonImmutable $teraz = null): array
    {
        $teraz ??= CarbonImmutable::now();

        $wiersz = ProductSignal::query()
            ->toBase()
            ->where('occurred_at', '>', $teraz->subDays(self::DNI))
            ->where('occurred_at', '<=', $teraz)
            ->whereIn('signal_name', [
                ZapiszSygnal::COOKING_LAST_STEP_REACHED,
                ZapiszSygnal::COOKING_LAST_STEP_COOKED,
                ZapiszSygnal::COOKING_FOLLOWUP_SHOWN,
                ZapiszSygnal::COOKING_FOLLOWUP_DISMISSED,
            ])
            ->selectRaw('count(*) FILTER (WHERE signal_name = ?) AS dojscia', [ZapiszSygnal::COOKING_LAST_STEP_REACHED])
            ->selectRaw('count(*) FILTER (WHERE signal_name = ?) AS ugotowane', [ZapiszSygnal::COOKING_LAST_STEP_COOKED])
            ->selectRaw(
                "count(*) FILTER (WHERE signal_name = ? AND properties->>'po_pytaniu' = 'true') AS po_pytaniu",
                [ZapiszSygnal::COOKING_LAST_STEP_COOKED],
            )
            ->selectRaw('count(*) FILTER (WHERE signal_name = ?) AS pokazane', [ZapiszSygnal::COOKING_FOLLOWUP_SHOWN])
            ->selectRaw('count(*) FILTER (WHERE signal_name = ?) AS nie_teraz', [ZapiszSygnal::COOKING_FOLLOWUP_DISMISSED])
            ->first();

        $dojscia = (int) ($wiersz->dojscia ?? 0);
        $ugotowane = (int) ($wiersz->ugotowane ?? 0);
        $pokazane = (int) ($wiersz->pokazane ?? 0);
        $nieTeraz = (int) ($wiersz->nie_teraz ?? 0);

        return [
            'dojscia' => $dojscia,
            'ugotowane' => $ugotowane,
            'po_pytaniu' => (int) ($wiersz->po_pytaniu ?? 0),
            'bez_ugotowalem' => max(0, $dojscia - $ugotowane),
            'procent_ugotowanych' => $dojscia < self::MINIMUM ? null : round(min($ugotowane, $dojscia) / $dojscia * 100, 1),
            'pokazane' => $pokazane,
            'nie_teraz' => $nieTeraz,
            'procent_nie_teraz' => $pokazane < self::MINIMUM ? null : round(min($nieTeraz, $pokazane) / $pokazane * 100, 1),
        ];
    }
}
