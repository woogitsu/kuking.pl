<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Planer\PlanerTygodnia;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\RecipePolicy;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dopisanie jednej pozycji do planera (#27, D-310): przepis ALBO własny wpis.
 *
 * Reguły żyją tutaj, nie w kontrolerze — ta sama akcja obsługuje formularz na
 * stronie przepisu i formularz dnia w planerze:
 *
 * - przepis musi być widoczny dla dodającego (`RecipePolicy::view()`);
 *   identyfikator z żądania nie jest autoryzacją (AGENTS.md §7),
 * - dzień z rozsądnego okna: najwyżej 60 dni wstecz, rok do przodu,
 * - najwyżej `kuking.planer.wpisow_na_dzien` pozycji jednego dnia,
 * - ten sam przepis albo ten sam tekst drugi raz tego samego dnia NIE tworzy
 *   drugiej pozycji — zwracamy `null`, a ekran mówi, że już tam jest.
 */
final class DodajDoPlanu
{
    public const DNI_WSTECZ = 60;

    public const DNI_DO_PRZODU = 365;

    public function __construct(private readonly RecipePolicy $przepisy = new RecipePolicy) {}

    public function handle(User $user, CarbonImmutable $dzien, ?Recipe $przepis, ?string $tekst): ?MealPlanEntry
    {
        $tekst = $tekst === null ? null : trim(preg_replace('/\s+/u', ' ', $tekst) ?? '');
        $tekst = $tekst === '' ? null : $tekst;

        if (($przepis === null) === ($tekst === null)) {
            throw ValidationException::withMessages([
                'label' => 'Wpisz, co planujesz na ten dzień, np. „obiad u mamy”.',
            ]);
        }

        if ($tekst !== null && mb_strlen($tekst) > 120) {
            throw ValidationException::withMessages([
                'label' => 'Skróć wpis do 120 znaków i dodaj jeszcze raz.',
            ]);
        }

        if ($przepis !== null && ! $this->przepisy->view($user, $przepis)) {
            throw new AuthorizationException;
        }

        $dzis = CarbonImmutable::parse(Czas::dzisiajData());
        if ($dzien->lt($dzis->subDays(self::DNI_WSTECZ)) || $dzien->gt($dzis->addDays(self::DNI_DO_PRZODU))) {
            throw ValidationException::withMessages([
                'day' => 'Wybierz dzień z najbliższego roku.',
            ]);
        }

        return DB::transaction(function () use ($user, $dzien, $przepis, $tekst): ?MealPlanEntry {
            // Blokada własnego wiersza konta szereguje dwa równoległe
            // dopisania tej samej osoby — bez niej obie liczyłyby
            // „9 pozycji” i obie by weszły.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $juz = MealPlanEntry::query()
                ->where('user_id', $user->getKey())
                ->where('day', $dzien->toDateString())
                ->when($przepis !== null,
                    fn ($q) => $q->where('recipe_id', $przepis?->getKey()),
                    fn ($q) => $q->where('label', $tekst))
                ->exists();

            if ($juz) {
                return null;
            }

            $ile = MealPlanEntry::query()
                ->where('user_id', $user->getKey())
                ->where('day', $dzien->toDateString())
                ->count();

            if ($ile >= PlanerTygodnia::wpisowNaDzien()) {
                throw ValidationException::withMessages([
                    'label' => 'Ten dzień ma już '.PlanerTygodnia::wpisowNaDzien().' pozycji. Usuń którąś albo wybierz inny dzień.',
                ]);
            }

            $wpis = new MealPlanEntry([
                'day' => $dzien->toDateString(),
                'recipe_id' => $przepis?->getKey(),
                'label' => $tekst,
            ]);
            $wpis->user_id = $user->getKey();
            $wpis->save();

            return $wpis;
        });
    }
}
