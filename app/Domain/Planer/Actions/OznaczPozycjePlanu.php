<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Planer\AktywneKontoPlanu;
use App\Models\MealPlanEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Prywatne „Zrobione” przy pozycji planera (#2593, V2).
 *
 * Ustawia ŻĄDANY stan, nie przełącza: to samo żądanie wysłane dwa razy daje
 * ten sam wynik. Pozycja i konto są czytane pod blokadą, po świeżej kontroli
 * konta i własności — także przy wołaniu wprost z domeny, nie tylko z trasy.
 *
 * Konflikt kart: formularz niesie znacznik stanu, który człowiek widział
 * (wartość `done_at` albo pusty). Gdy stan jest już inny niż żądany i inny
 * niż widziany, nowsza decyzja wygrywa, a żądanie jest odrzucane z
 * komunikatem. Akcja nie tworzy `CookedEvent`, powiadomienia ani żadnego
 * sygnału „ugotowano”.
 */
final class OznaczPozycjePlanu
{
    public const ZASTOSOWANO = 'zastosowano';

    public const JUZ_TAK_BYLO = 'juz_tak_bylo';

    public const KONFLIKT = 'konflikt';

    public const BRAK = 'brak';

    /** Format znacznika stanu w formularzu (mikrosekundy odróżniają dwie decyzje w jednej sekundzie). */
    public const FORMAT_ZNACZNIKA = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @return self::ZASTOSOWANO|self::JUZ_TAK_BYLO|self::KONFLIKT|self::BRAK
     */
    public function handle(User $user, string $idPozycji, bool $zrobione, ?string $widzianyZnacznik): string
    {
        return DB::transaction(function () use ($user, $idPozycji, $zrobione, $widzianyZnacznik): string {
            $swiezy = AktywneKontoPlanu::podBlokada($user);

            // Cudza pozycja jest dla pytającego tym samym, co nieistniejąca.
            $wpis = MealPlanEntry::query()
                ->whereKey($idPozycji)
                ->where('user_id', $swiezy->getKey())
                ->lockForUpdate()
                ->first();

            if ($wpis === null) {
                return self::BRAK;
            }

            if (($wpis->done_at !== null) === $zrobione) {
                return self::JUZ_TAK_BYLO;
            }

            if (self::znacznik($wpis) !== ($widzianyZnacznik ?? '')) {
                return self::KONFLIKT;
            }

            // Wprost, nie przez `$fillable`: oznaczenie jest decyzją tej akcji.
            // `updated_at` bez zmian — to nie edycja treści pozycji.
            $wpis->timestamps = false;
            $wpis->done_at = $zrobione ? now() : null;
            $wpis->save();

            return self::ZASTOSOWANO;
        });
    }

    public static function znacznik(MealPlanEntry $wpis): string
    {
        return $wpis->done_at?->utc()->format(self::FORMAT_ZNACZNIKA) ?? '';
    }
}
