<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Planer\AktywneKontoPlanu;
use App\Models\MealPlanEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Krótki prywatny dopisek przy pozycji z przepisem w planerze (#2549, V2).
 *
 * Ustawia ŻĄDANĄ wartość (pusta = wyczyść), nie dokleja. Konto i pozycja są
 * czytane pod blokadą, po świeżej kontroli konta i własności — także przy
 * wołaniu wprost z domeny. Cudza pozycja jest dla pytającego nieistniejącą.
 *
 * Konflikt kart: formularz niesie znacznik dopisku, który człowiek widział.
 * Gdy dopisek zmienił się w innej karcie, żądanie jest odrzucane i nic nie
 * nadpisuje nowszego tekstu. Akcja nie rusza tytułu, dnia, przepisu ani
 * `done_at`; `updated_at` zostaje (to nie zmiana pozycji w planie).
 */
final class ZapiszDopisekPlanu
{
    public const MAX_ZNAKOW = 80;

    public const ZASTOSOWANO = 'zastosowano';

    public const JUZ_TAK_BYLO = 'juz_tak_bylo';

    public const KONFLIKT = 'konflikt';

    public const BRAK = 'brak';

    public const NIE_DOTYCZY = 'nie_dotyczy';

    public const ZA_DLUGI = 'za_dlugi';

    /**
     * @return self::ZASTOSOWANO|self::JUZ_TAK_BYLO|self::KONFLIKT|self::BRAK|self::NIE_DOTYCZY|self::ZA_DLUGI
     */
    public function handle(User $user, string $idPozycji, ?string $tekst, ?string $widzianyZnacznik): string
    {
        $nowy = self::normalizuj($tekst);
        if ($nowy !== null && mb_strlen($nowy) > self::MAX_ZNAKOW) {
            return self::ZA_DLUGI;
        }

        return DB::transaction(function () use ($user, $idPozycji, $nowy, $widzianyZnacznik): string {
            $swiezy = AktywneKontoPlanu::podBlokada($user);

            $wpis = MealPlanEntry::query()
                ->whereKey($idPozycji)
                ->where('user_id', $swiezy->getKey())
                ->lockForUpdate()
                ->first();

            if ($wpis === null) {
                return self::BRAK;
            }

            // Własny wpis (`label`) jest samodzielnym tekstem, nie przepisem.
            if ($wpis->label !== null) {
                return self::NIE_DOTYCZY;
            }

            if ($wpis->note === $nowy) {
                return self::JUZ_TAK_BYLO;
            }

            if (self::znacznik($wpis) !== ($widzianyZnacznik ?? '')) {
                return self::KONFLIKT;
            }

            // Wprost, nie przez `$fillable`: dopisek ustawia ta akcja.
            $wpis->timestamps = false;
            $wpis->note = $nowy;
            $wpis->save();

            return self::ZASTOSOWANO;
        });
    }

    /** Przycięty, z jedną spacją między słowami; pusty tekst to brak dopisku. */
    public static function normalizuj(?string $tekst): ?string
    {
        if ($tekst === null) {
            return null;
        }

        $zwarty = trim((string) preg_replace('/\s+/u', ' ', $tekst));

        return $zwarty === '' ? null : $zwarty;
    }

    /** Znacznik widzianego dopisku; pusty, gdy dopisku nie ma. */
    public static function znacznik(MealPlanEntry $wpis): string
    {
        return $wpis->note === null ? '' : substr(hash('sha256', $wpis->note), 0, 16);
    }
}
