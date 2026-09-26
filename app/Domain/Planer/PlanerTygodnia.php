<?php

declare(strict_types=1);

namespace App\Domain\Planer;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Odczyt planera tygodnia (#27, D-310) i reguły wspólne dla ekranu, akcji
 * i paczki danych.
 *
 * TYDZIEŃ ZACZYNA SIĘ W PONIEDZIAŁEK W STREFIE CZŁOWIEKA (`Czas`), nie w UTC
 * — inaczej w niedzielę po 22:00 „ten tydzień” byłby już następnym.
 *
 * PRZEPIS POKAZUJEMY TYLKO WTEDY, GDY WŁAŚCICIEL PLANU WCIĄŻ GO WIDZI.
 * Plan nie jest furtką do treści: przepis, który autor zawęził, usunął albo
 * który odciął blokadą, zostaje w planie jako „Przepis jest już
 * niedostępny.” — bez tytułu i bez linku. Ta sama reguła co na liście
 * zeszytu (`WidocznaZawartoscZeszytu::przepisy()`); własny przepis omija
 * filtr autora jak w eksporcie zeszytu (karencja usuwania konta).
 */
final class PlanerTygodnia
{
    public const STAN_PRZEPIS = 'przepis';

    public const STAN_WLASNY = 'wlasny';

    public const STAN_NIEDOSTEPNY = 'niedostepny';

    public const STAN_USUNIETY = 'usuniety';

    public static function wpisowNaDzien(): int
    {
        return (int) config('kuking.planer.wpisow_na_dzien');
    }

    /**
     * Poniedziałek tygodnia z parametru `?tydzien=RRRR-MM-DD` (dowolny dzień
     * tego tygodnia). Zły albo pusty parametr = bieżący tydzień, bez błędu:
     * to jest nawigacja, nie formularz.
     */
    public static function poniedzialek(?string $tydzien): CarbonImmutable
    {
        $dzis = CarbonImmutable::parse(Czas::dzisiajData());

        if (is_string($tydzien) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tydzien) === 1) {
            try {
                $dzien = CarbonImmutable::createFromFormat('!Y-m-d', $tydzien);
            } catch (\Throwable) {
                $dzien = false;
            }

            if ($dzien instanceof CarbonImmutable && $dzien->format('Y-m-d') === $tydzien) {
                return $dzien->startOfWeek(CarbonInterface::MONDAY);
            }
        }

        return $dzis->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * „poniedziałek, 28 września”.
     *
     * Przez `Czas`, nie przez gołe `translatedFormat()` — tak jak każda data
     * pokazywana człowiekowi (#746). Sam dzień planu jest datą kalendarzową
     * (kolumna `date`), więc przeliczenie strefy go nie przesuwa; pomocnik
     * jest tu po to, żeby nie było w serwisie drugiej drogi formatowania dat.
     */
    public static function nazwaDnia(CarbonInterface $dzien): string
    {
        return Czas::data($dzien, 'l, j F');
    }

    /** „28 września – 4 października” albo „5–11 października” w jednym miesiącu. */
    public static function zakresTygodnia(CarbonImmutable $poniedzialek): string
    {
        $niedziela = $poniedzialek->addDays(6);

        return $poniedzialek->month === $niedziela->month
            ? Czas::data($poniedzialek, 'j').'–'.Czas::data($niedziela, 'j F')
            : Czas::data($poniedzialek, 'j F').' – '.Czas::data($niedziela, 'j F');
    }

    /**
     * Przepisy, które ten człowiek dziś widzi.
     *
     * @return Builder<Recipe>
     */
    public function widocznePrzepisy(User $user): Builder
    {
        return Recipe::query()
            ->widoczneDla($user)
            ->where(fn (Builder $q) => $q
                ->where('recipes.author_id', $user->getKey())
                ->orWhereHas('author', fn ($autor) => $autor->dostepnyJakoAutor()));
    }

    /**
     * Pozycje z podanego zakresu dni, każda ze stanem i — gdy wolno — przepisem.
     * Dwa zapytania niezależnie od liczby pozycji.
     *
     * @return list<array{wpis: MealPlanEntry, stan: string, przepis: ?Recipe}>
     */
    public function pozycje(User $user, CarbonImmutable $od, CarbonImmutable $do): array
    {
        $wpisy = $user->mealPlanEntries()
            ->whereBetween('day', [$od->toDateString(), $do->toDateString()])
            ->orderBy('day')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $idPrzepisow = $wpisy->pluck('recipe_id')->filter()->unique()->values();
        $widoczne = $idPrzepisow->isEmpty()
            ? collect()
            : $this->widocznePrzepisy($user)->whereIn('recipes.id', $idPrzepisow)->get()->keyBy('id');

        return $wpisy->map(function (MealPlanEntry $wpis) use ($widoczne): array {
            if ($wpis->recipe_id !== null) {
                $przepis = $widoczne->get($wpis->recipe_id);

                return [
                    'wpis' => $wpis,
                    'stan' => $przepis !== null ? self::STAN_PRZEPIS : self::STAN_NIEDOSTEPNY,
                    'przepis' => $przepis,
                ];
            }

            return [
                'wpis' => $wpis,
                'stan' => $wpis->label !== null ? self::STAN_WLASNY : self::STAN_USUNIETY,
                'przepis' => null,
            ];
        })->values()->all();
    }

    /**
     * Siedem dni tygodnia, każdy ze swoimi pozycjami.
     *
     * @return array<string, array{dzien: CarbonImmutable, pozycje: list<array{wpis: MealPlanEntry, stan: string, przepis: ?Recipe}>}>
     */
    public function tydzien(User $user, CarbonImmutable $poniedzialek): array
    {
        $dni = [];
        for ($i = 0; $i < 7; $i++) {
            $dzien = $poniedzialek->addDays($i);
            $dni[$dzien->toDateString()] = ['dzien' => $dzien, 'pozycje' => []];
        }

        foreach ($this->pozycje($user, $poniedzialek, $poniedzialek->addDays(6)) as $pozycja) {
            $dni[$pozycja['wpis']->day->toDateString()]['pozycje'][] = $pozycja;
        }

        return $dni;
    }
}
