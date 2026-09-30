<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pierwszy `railway config apply` (#595) nie włącza niczego po cichu (#2296, IN-04).
 *
 * `railway.ts` ustawia niektóre wyłączniki `KUKING_*` DOSŁOWNIE na `"true"`,
 * a `config/*.php` ma dla nich domyślne `false`. Dopóki apply nie ruszył,
 * wartość z pliku na produkcji NIE obowiązuje — tak D-269 („życzenia mailem
 * włączone na produkcji”) okazało się nieprawdą: zmiennej w panelu nie było.
 * Po apply ta sama wartość zmieni zachowanie produkcji jednym ruchem.
 *
 * Dlatego każdy taki wyłącznik ma być wymieniony z nazwy w runbooku #595
 * (`docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md`), żeby czytający plan
 * wiedział, że to zmiana oczekiwana, a nie powód do „stop” — albo odwrotnie.
 * Nowy wyłącznik dopisany do `railway.ts` bez wpisu w runbooku oblewa test.
 */
final class RunbookApplyNazywaWlaczniki595Test extends TestCase
{
    private const RUNBOOK = 'docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md';

    /** @return list<string> */
    private function wlacznikiDoslowneWRailwayTs(): array
    {
        $nazwy = [];

        foreach (explode("\n", (string) file_get_contents(base_path('.railway/railway.ts'))) as $linia) {
            if (preg_match('#^\s*//#', $linia) === 1) {
                continue;
            }

            if (preg_match('/^\s+(KUKING_[A-Z0-9_]+):\s*(?:isProduction\s*\?\s*)?"true"/', $linia, $m) === 1) {
                $nazwy[] = $m[1];
            }
        }

        return array_values(array_unique($nazwy));
    }

    private function domyslnieWylaczonyWConfig(string $nazwa): bool
    {
        foreach (glob(base_path('config/*.php')) ?: [] as $plik) {
            if (preg_match('/env\(\s*\''.preg_quote($nazwa, '/').'\'\s*,\s*false\s*\)/', (string) file_get_contents($plik)) === 1) {
                return true;
            }
        }

        return false;
    }

    public function test_kazdy_wlacznik_wlaczany_doslownie_przez_apply_jest_w_runbooku_595(): void
    {
        $wlaczniki = array_values(array_filter($this->wlacznikiDoslowneWRailwayTs(), $this->domyslnieWylaczonyWConfig(...)));

        // Zmierzone 30.09.2026: KUKING_QUESTIONS_ENABLED i KUKING_URODZINY_MAIL_WLACZONY.
        $this->assertGreaterThanOrEqual(2, count($wlaczniki), 'Skan nie widzi wyłączników włączanych dosłownie w .railway/railway.ts — zepsuty wzorzec? Znalezione: '.implode(', ', $wlaczniki));

        $runbook = (string) file_get_contents(base_path(self::RUNBOOK));
        $brak = array_values(array_filter($wlaczniki, fn (string $nazwa): bool => ! str_contains($runbook, $nazwa)));

        $this->assertSame(
            [],
            $brak,
            'Pierwszy apply (#595) włączy te wyłączniki (w railway.ts "true", w config domyślnie false), '
            .'a runbook '.self::RUNBOOK.' ich nie wymienia — dopisz wiersz w tabeli planu (krok 2), '
            .'czy to oczekiwana zmiana zachowania (#2296): '.implode(', ', $brak),
        );
    }
}
