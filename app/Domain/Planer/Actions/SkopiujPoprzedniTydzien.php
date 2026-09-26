<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Planer\PlanerTygodnia;
use App\Models\MealPlanEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * „Skopiuj poprzedni tydzień” (#27, D-310) — w takich narzędziach najczęściej
 * używana funkcja.
 *
 * Kopia DOPISUJE, nie zastępuje: to, co już stoi w tym tygodniu, zostaje.
 * Jest idempotentna — drugie kliknięcie nie mnoży pozycji (te same indeksy
 * unikalne co przy zwykłym dodawaniu). Nie przenosi:
 *
 * - przepisów, których właściciel planu dziś już nie widzi (kopia nie może
 *   wskrzesić treści zawężonej albo zablokowanej),
 * - śladów po przepisie usuniętym (nie ma czego skopiować),
 * - pozycji ponad dzienny limit.
 */
final class SkopiujPoprzedniTydzien
{
    public function __construct(private readonly PlanerTygodnia $planer = new PlanerTygodnia) {}

    /**
     * @return array{skopiowane: int, juz_byly: int, pominiete: int}
     */
    public function handle(User $user, CarbonImmutable $poniedzialek): array
    {
        $zrodlo = $this->planer->pozycje($user, $poniedzialek->subDays(7), $poniedzialek->subDay());

        return DB::transaction(function () use ($user, $zrodlo): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $wynik = ['skopiowane' => 0, 'juz_byly' => 0, 'pominiete' => 0];
            $liczniki = [];

            foreach ($zrodlo as $pozycja) {
                if (! in_array($pozycja['stan'], [PlanerTygodnia::STAN_PRZEPIS, PlanerTygodnia::STAN_WLASNY], true)) {
                    $wynik['pominiete']++;

                    continue;
                }

                $dzien = $pozycja['wpis']->day->addDays(7)->toDateString();
                $liczniki[$dzien] ??= MealPlanEntry::query()
                    ->where('user_id', $user->getKey())
                    ->where('day', $dzien)
                    ->count();

                if ($liczniki[$dzien] >= PlanerTygodnia::wpisowNaDzien()) {
                    $wynik['pominiete']++;

                    continue;
                }

                $teraz = now();
                $wstawiono = MealPlanEntry::query()->insertOrIgnore([
                    'id' => (string) Str::orderedUuid(),
                    'user_id' => $user->getKey(),
                    'day' => $dzien,
                    'recipe_id' => $pozycja['wpis']->recipe_id,
                    'label' => $pozycja['wpis']->label,
                    'created_at' => $teraz,
                    'updated_at' => $teraz,
                ]);

                if ($wstawiono === 1) {
                    $wynik['skopiowane']++;
                    $liczniki[$dzien]++;
                } else {
                    $wynik['juz_byly']++;
                }
            }

            return $wynik;
        });
    }
}
