<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\CookedEvent;
use App\Models\Notification;

/**
 * Zasięg „Ugotowałem" — SEDNO produktu (AGENTS.md, część 1: „«Ugotowałem»
 * jest ważniejsze niż lajk … i to on generuje najcenniejsze powiadomienie
 * w całym serwisie"), nie dodatek do statystyk WAC/D7/D30.
 *
 * DWIE LICZBY, NIE JEDNA, I DLACZEGO OSOBNO
 * „Ile przepisów dostało choć jedno »Ugotowałem«" mówi o ZASIĘGU treści —
 * ile przepisów w ogóle komuś wyszło. „Ilu autorów dostało to powiadomienie"
 * mówi o LUDZIACH po drugiej stronie — ilu realnych osobom przyszedł ten
 * najważniejszy w serwisie komunikat. Jeden autor z pięcioma ugotowanymi
 * przepisami zasila pierwszą liczbę pięć razy, a drugą tylko raz — obie
 * liczby są prawdziwe i żadna nie zastępuje drugiej.
 *
 * ŚWIADOMIE BEZ WYKLUCZEŃ `CookEligibility`
 * WAC/D7/D30 (`AktywniWTygodniu`, `PowrotPoDniach`, `WeeklyActiveCooks`)
 * wykluczają gospodarza i konta testowe/zalążkowe, bo mierzą, czy ROŚNIE
 * ŻYWA SPOŁECZNOŚĆ, a gwarantowana aktywność jednego konta zniekształcałaby
 * porównanie w czasie. Ta klasa mierzy coś innego — czy MECHANIZM produktu
 * (ugotowanie → powiadomienie autora) w ogóle działa i jak daleko sięga —
 * i tu każde prawdziwe wykonanie w bazie jest prawdziwym faktem o tym
 * mechanizmie, niezależnie od tego, czyje konto je zrobiło. Wykluczanie
 * kont tutaj ukrywałoby część działania funkcji, którą ten raport ma
 * pokazać wprost.
 *
 * BEZ FILTRA `recipe_id`/`user_id` PRZEZ `Recipe`/`User`
 * Wystarczy indeks na `cooked_events.recipe_id` (`cooked_events_recipe_idx`,
 * `docs/DATABASE.md`) i na `notifications` — dwa `COUNT(DISTINCT …)` bez
 * złączenia. Przepis skasowany razem z kontem autora (`cascadeOnDelete()`
 * na `cooked_events.recipe_id`, `docs/DATABASE.md`) i tak zabiera ze sobą
 * swoje wiersze `cooked_events` — nie ma więc czego dodatkowo odfiltrowywać
 * po stronie przepisu. Przepis ukryty moderacyjnie (miękkie usunięcie)
 * ZOSTAJE policzony: „ktoś to kiedyś ugotował" jest faktem historycznym,
 * którego moderacja nie unieważnia.
 */
final class ZasiegUgotowalem
{
    /** @return array{przepisy_z_ugotowalem: int, autorzy_powiadomieni: int} */
    public function policz(): array
    {
        return [
            'przepisy_z_ugotowalem' => CookedEvent::query()->distinct()->count('recipe_id'),
            'autorzy_powiadomieni' => Notification::query()
                ->where('type', Notification::TYPE_COOKED)
                ->distinct()
                ->count('user_id'),
        ];
    }
}
