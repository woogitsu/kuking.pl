<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Planer\AktywneKontoPlanu;
use App\Models\MealPlanEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * „Zmień tekst” przy własnej pozycji Planera (#2454, V2).
 *
 * Zmienia WYŁĄCZNIE `label` rzeczywiście ręcznej pozycji (`recipe_id` puste,
 * `label` niepuste): ten sam rekord, bez DELETE + INSERT, więc UUID, dzień
 * i `created_at` zostają. Pozycja przepisu i neutralna pozostałość po twardo
 * usuniętym przepisie nie zamieniają się tą drogą we własny tekst.
 *
 * Te same reguły tekstu co przy dopisywaniu (`DodajDoPlanu`): białe znaki
 * ściśnięte do jednej spacji, niepusty, do 120 znaków; ten sam tekst innej
 * pozycji tego samego dnia to duplikat i odrzuca zmianę (nic nie jest łączone
 * ani kasowane). Edycja nie jest dopisaniem, więc działa też przy pełnym dniu.
 *
 * Pod blokadą konta wspólną z dopisaniem, przeniesieniem i kopiowaniem.
 * Konflikt kart: formularz niesie znacznik tekstu, który człowiek widział;
 * gdy w międzyczasie ktoś zmienił tekst na inny, nic się nie zapisuje.
 */
final class ZmienTekstPozycjiPlanu
{
    public const ZASTOSOWANO = 'zastosowano';

    public const JUZ_TAK_BYLO = 'juz_tak_bylo';

    public const KONFLIKT = 'konflikt';

    public const BRAK = 'brak';

    public const NIE_WLASNY = 'nie_wlasny';

    public const MAX_ZNAKOW = 120;

    /** Znacznik tekstu widzianego w formularzu (skrót, nie sam tekst). */
    public static function znacznik(MealPlanEntry $wpis): string
    {
        return hash('sha256', (string) $wpis->label);
    }

    /** Odstępy ściśnięte do jednej spacji, bez spacji na brzegach. */
    public static function normalizuj(string $tekst): string
    {
        return trim(preg_replace('/\s+/u', ' ', $tekst) ?? '');
    }

    /**
     * @return self::ZASTOSOWANO|self::JUZ_TAK_BYLO|self::KONFLIKT|self::BRAK|self::NIE_WLASNY
     *
     * @throws ValidationException pusty, za długi albo powtórzony tekst
     */
    public function handle(User $user, string $idPozycji, string $nowyTekst, string $widzianyZnacznik): string
    {
        $tekst = self::normalizuj($nowyTekst);

        if ($tekst === '') {
            throw ValidationException::withMessages([
                'label' => 'Wpisz, co planujesz na ten dzień, np. „obiad u mamy”.',
            ]);
        }

        if (mb_strlen($tekst) > self::MAX_ZNAKOW) {
            throw ValidationException::withMessages([
                'label' => 'Skróć wpis do '.self::MAX_ZNAKOW.' znaków i zapisz jeszcze raz.',
            ]);
        }

        return DB::transaction(function () use ($user, $idPozycji, $tekst, $widzianyZnacznik): string {
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

            if ($wpis->recipe_id !== null || $wpis->label === null) {
                return self::NIE_WLASNY;
            }

            if ($wpis->label === $tekst) {
                return self::JUZ_TAK_BYLO;
            }

            if (! hash_equals(self::znacznik($wpis), $widzianyZnacznik)) {
                return self::KONFLIKT;
            }

            $duplikat = MealPlanEntry::query()
                ->where('user_id', $swiezy->getKey())
                ->where('day', $wpis->day->toDateString())
                ->where('label', $tekst)
                ->whereKeyNot($wpis->getKey())
                ->exists();

            if ($duplikat) {
                throw ValidationException::withMessages([
                    'label' => 'Taki wpis już jest w planie na ten dzień. Wpisz inny tekst — nic nie zostało zmienione.',
                ]);
            }

            // Zwykłe `save()` ustawia `updated_at`; dzień, `created_at`,
            // `done_at` i reszta zostają bez zmian.
            $wpis->label = $tekst;
            $wpis->save();

            return self::ZASTOSOWANO;
        });
    }
}
