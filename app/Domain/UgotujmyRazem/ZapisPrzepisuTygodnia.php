<?php

declare(strict_types=1);

namespace App\Domain\UgotujmyRazem;

use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WeeklyRecipePick;
use App\Policies\RecipePolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gospodarz wybiera (albo zmienia, albo zdejmuje) przepis tygodnia (F3).
 *
 * Wybór i wpis dziennika audytu w JEDNEJ transakcji (D-249, klasa 1): to jest
 * decyzja redakcyjna widoczna dla wszystkich, więc ślad ma stać albo paść
 * razem z nią.
 *
 * Czego ta akcja świadomie NIE robi: nie wybiera niczego sama (żadnego
 * awansu z popularności, D-275), nie wysyła powiadomień (autor dostaje
 * zwykłe „Ugotowałem” od osób, które ugotują) i nie pozwala przepisać
 * zakończonego tygodnia — archiwum ma mówić, co naprawdę gotowaliśmy.
 */
final class ZapisPrzepisuTygodnia
{
    /**
     * @throws ValidationException
     */
    public function wybierz(User $gospodarz, TydzienGotowania $tydzien, Recipe $recipe, ?string $ip): WeeklyRecipePick
    {
        if (TydzienGotowania::biezacy()->poczatek()->greaterThan($tydzien->poczatek())) {
            throw ValidationException::withMessages([
                'tydzien' => 'Ten tydzień już minął. Wybierz bieżący albo jeden z następnych tygodni.',
            ]);
        }

        // Zaproszenie jest dla wszystkich, więc przepis musi być widoczny dla
        // KAŻDEGO — pytamy politykę tak, jak zapytałaby o gościa.
        if (! $recipe->isPublished() || $recipe->visibility !== 'public' || ! app(RecipePolicy::class)->view(null, $recipe)) {
            throw ValidationException::withMessages([
                'przepis' => 'Ten przepis nie jest publiczny, więc nie każdy go zobaczy. Wybierz opublikowany przepis widoczny dla wszystkich.',
            ]);
        }

        try {
            return DB::transaction(function () use ($gospodarz, $tydzien, $recipe, $ip): WeeklyRecipePick {
                $pick = WeeklyRecipePick::query()
                    ->whereDate('week_starts_on', $tydzien->dzienStartu())
                    ->lockForUpdate()
                    ->first();

                $poprzedni = $pick?->recipe_id;

                $pick ??= new WeeklyRecipePick;
                $pick->forceFill([
                    'week_starts_on' => $tydzien->dzienStartu(),
                    'recipe_id' => $recipe->getKey(),
                    'chosen_by' => $gospodarz->getKey(),
                ])->save();

                AuditLogEntry::record(
                    action: 'ugotujmy_razem.chosen',
                    actor: $gospodarz,
                    subject: $recipe,
                    metadata: array_filter([
                        'tydzien' => $tydzien->iso(),
                        'poprzedni_przepis' => $poprzedni !== $recipe->getKey() ? $poprzedni : null,
                    ]),
                    ip: $ip,
                );

                return $pick;
            });
        } catch (UniqueConstraintViolationException) {
            // Dwóch gospodarzy wybrało pierwszy przepis na ten sam tydzień
            // w tej samej chwili; `UNIQUE (week_starts_on)` przepuścił jednego.
            throw ValidationException::withMessages([
                'tydzien' => 'Ktoś przed chwilą wybrał przepis na ten tydzień. Sprawdź listę niżej i w razie potrzeby wybierz jeszcze raz.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function usun(User $gospodarz, WeeklyRecipePick $pick, ?string $ip): void
    {
        $tydzien = $pick->tydzien();

        if (TydzienGotowania::biezacy()->poczatek()->greaterThan($tydzien->poczatek())) {
            throw ValidationException::withMessages([
                'tydzien' => 'Ten tydzień już minął i należy do archiwum. Zakończonego tygodnia nie usuwamy.',
            ]);
        }

        DB::transaction(function () use ($gospodarz, $pick, $tydzien, $ip): void {
            $pick->delete();

            AuditLogEntry::record(
                action: 'ugotujmy_razem.removed',
                actor: $gospodarz,
                subject: $pick->recipe,
                metadata: ['tydzien' => $tydzien->iso()],
                ip: $ip,
            );
        });
    }
}
