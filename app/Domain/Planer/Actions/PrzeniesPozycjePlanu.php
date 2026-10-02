<?php

declare(strict_types=1);

namespace App\Domain\Planer\Actions;

use App\Domain\Planer\AktywneKontoPlanu;
use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Planer\ZakresDatPlanu;
use App\Models\MealPlanEntry;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * „Przenieś na inny dzień” przy jednej pozycji planera (#2447, V2).
 *
 * Zmienia WYŁĄCZNIE `day` istniejącego rekordu: bez DELETE + INSERT, więc
 * UUID, właściciel, przepis albo tekst i `created_at` zostają. Nie dotyka
 * innych pozycji ani listy zakupów.
 *
 * Konto, a potem pozycja są czytane pod blokadą — tą samą blokadą konta, którą
 * biorą dopisanie i kopiowanie tygodnia — więc równoległe operacje nie
 * przekroczą dziennego limitu ani nie zrobią duplikatu.
 *
 * Konflikt kart: formularz niesie dzień, który człowiek widział. Gdy pozycja
 * stoi już gdzie indziej, niż widział, i gdzie indziej, niż chce ją mieć,
 * operacja jest odrzucana (nowsza decyzja nie ginie po cichu). Ponowne wysłanie
 * tego samego żądania, gdy pozycja jest już na dniu docelowym, nic nie zmienia.
 *
 * Pełny dzień, duplikat i dzień poza zakresem odrzucają całość — pozycja
 * zostaje na dawnym dniu, nic się nie łączy i nie kasuje. Komunikaty dla
 * duplikatu nie zdradzają tytułu przepisu (może być już niedostępny).
 */
final class PrzeniesPozycjePlanu
{
    public const ZASTOSOWANO = 'zastosowano';

    public const JUZ_TAK_BYLO = 'juz_tak_bylo';

    public const KONFLIKT = 'konflikt';

    public const BRAK = 'brak';

    /**
     * @param  string  $widzianyDzien  dzień (Y-m-d), na którym człowiek widział pozycję
     * @return self::ZASTOSOWANO|self::JUZ_TAK_BYLO|self::KONFLIKT|self::BRAK
     *
     * @throws ValidationException dzień poza zakresem, duplikat albo pełny dzień
     */
    public function handle(User $user, string $idPozycji, CarbonImmutable $nowyDzien, string $widzianyDzien): string
    {
        return DB::transaction(function () use ($user, $idPozycji, $nowyDzien, $widzianyDzien): string {
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

            $docelowy = $nowyDzien->toDateString();
            $obecny = $wpis->day->toDateString();

            if ($obecny === $docelowy) {
                return self::JUZ_TAK_BYLO;
            }

            if ($obecny !== $widzianyDzien) {
                return self::KONFLIKT;
            }

            if (! ZakresDatPlanu::obejmuje($nowyDzien)) {
                throw ValidationException::withMessages([
                    'day' => 'Wybierz dzień w zakresie planera: '.ZakresDatPlanu::opis().'. Pozycja zostaje tam, gdzie była.',
                ]);
            }

            // Ten sam przepis albo ten sam tekst co już stojąca pozycja tego
            // dnia. Pozycja bez przepisu i bez tekstu (ślad po przepisie
            // usuniętym na twardo) nie ma czym kolidować.
            if ($wpis->recipe_id !== null || $wpis->label !== null) {
                $duplikat = MealPlanEntry::query()
                    ->where('user_id', $swiezy->getKey())
                    ->where('day', $docelowy)
                    ->when($wpis->recipe_id !== null,
                        fn ($q) => $q->where('recipe_id', $wpis->recipe_id),
                        fn ($q) => $q->where('label', $wpis->label))
                    ->exists();

                if ($duplikat) {
                    throw ValidationException::withMessages([
                        'day' => 'Taka pozycja jest już w planie na '.PlanerTygodnia::naDzien($nowyDzien).'. Wybierz inny dzień — nic nie zostało przeniesione.',
                    ]);
                }
            }

            $ile = MealPlanEntry::query()
                ->where('user_id', $swiezy->getKey())
                ->where('day', $docelowy)
                ->count();

            if ($ile >= PlanerTygodnia::wpisowNaDzien()) {
                throw ValidationException::withMessages([
                    'day' => 'Ten dzień ma już '.PlanerTygodnia::wpisowNaDzien().' pozycji. Wybierz inny dzień albo usuń którąś pozycję z tego dnia — nic nie zostało przeniesione.',
                ]);
            }

            // Zwykłe `save()` ustawia `updated_at`; `created_at`, `done_at`
            // i reszta pól zostają bez zmian.
            $wpis->day = Carbon::instance($nowyDzien);
            $wpis->save();

            return self::ZASTOSOWANO;
        });
    }
}
