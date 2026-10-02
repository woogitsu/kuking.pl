<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Planer\AktywneKontoPlanu;
use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Models\MealPlanEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Liczba planowanych porcji przy pozycji przepisu w planerze (V2, #2509).
 *
 * Ustawia ŻĄDANĄ wartość (pusta = wyczyść, wrócą ilości autora). Walidację i
 * zakres 1–100 daje istniejący `WyborPorcji` (ten sam parser co `?porcje=`),
 * nie osobna reguła. To tylko zapis planu osoby: nie zmienia przepisu, dnia,
 * `done_at` ani `updated_at`, nie tworzy zakupów ani wykonań.
 *
 * Konto i pozycja są czytane pod blokadą (jak `ZapiszDopisekPlanu`); cudza
 * pozycja jest dla pytającego nieistniejącą. Formularz niesie znacznik liczby,
 * którą człowiek widział — zmiana w innej karcie daje konflikt, nie nadpisanie.
 */
final class UstawPorcjePlanu
{
    public const ZASTOSOWANO = 'zastosowano';

    public const JUZ_TAK_BYLO = 'juz_tak_bylo';

    public const KONFLIKT = 'konflikt';

    public const BRAK = 'brak';

    public const NIE_DOTYCZY = 'nie_dotyczy';

    /** Wpisana wartość nie jest liczbą z zakresu. */
    public const NIEPRAWIDLOWE = 'nieprawidlowe';

    /** Przepis nie podaje liczby porcji, więc nie ma od czego liczyć. */
    public const BEZ_PODSTAWY = 'bez_podstawy';

    /**
     * @return self::ZASTOSOWANO|self::JUZ_TAK_BYLO|self::KONFLIKT|self::BRAK|self::NIE_DOTYCZY|self::NIEPRAWIDLOWE|self::BEZ_PODSTAWY
     */
    public function handle(User $user, string $idPozycji, ?string $wartosc, ?string $widzianyZnacznik): string
    {
        $wartosc = $wartosc === null ? null : trim($wartosc);
        $wartosc = $wartosc === '' ? null : $wartosc;

        return DB::transaction(function () use ($user, $idPozycji, $wartosc, $widzianyZnacznik): string {
            $swiezy = AktywneKontoPlanu::podBlokada($user);

            $wpis = MealPlanEntry::query()
                ->whereKey($idPozycji)
                ->where('user_id', $swiezy->getKey())
                ->lockForUpdate()
                ->first();

            if ($wpis === null) {
                return self::BRAK;
            }

            // Własny wpis i pozycja po przepisie, którego nie widać, nie mają
            // przepisu do przeliczenia.
            $przepis = $wpis->recipe_id === null
                ? null
                : app(PlanerTygodnia::class)->widocznePrzepisy($swiezy)->whereKey($wpis->recipe_id)->first();

            if ($wpis->label !== null || $przepis === null) {
                return self::NIE_DOTYCZY;
            }

            $nowa = null;
            if ($wartosc !== null) {
                $wybor = WyborPorcji::dla($przepis, $wartosc);

                if (! $wybor->dostepny()) {
                    return self::BEZ_PODSTAWY;
                }

                if ($wybor->odrzucone || $wybor->wybrane === null || $wybor->wybrane < WyborPorcji::NAJMNIEJ || $wybor->wybrane > WyborPorcji::NAJWIECEJ) {
                    return self::NIEPRAWIDLOWE;
                }

                $nowa = round($wybor->wybrane, 2);
            }

            if (self::rowne($wpis->planned_servings, $nowa)) {
                return self::JUZ_TAK_BYLO;
            }

            if (self::znacznik($wpis) !== ($widzianyZnacznik ?? '')) {
                return self::KONFLIKT;
            }

            // Wprost, nie przez `$fillable`: liczbę ustawia ta akcja.
            $wpis->timestamps = false;
            $wpis->planned_servings = $nowa;
            $wpis->save();

            return self::ZASTOSOWANO;
        });
    }

    /** Znacznik widzianej liczby; pusty, gdy wyboru nie ma. */
    public static function znacznik(MealPlanEntry $wpis): string
    {
        return $wpis->planned_servings === null ? '' : substr(hash('sha256', (string) $wpis->planned_servings), 0, 16);
    }

    private static function rowne(?float $a, ?float $b): bool
    {
        return $a === null || $b === null ? $a === $b : abs($a - $b) < 0.001;
    }
}
