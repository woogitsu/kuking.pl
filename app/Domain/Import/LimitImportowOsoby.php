<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\ImportPrzepisu;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wspólny limit prób OCR, URL i PDF: 5 dziennie, 30 miesięcznie (D-297/D-300).
 *
 * LICZYMY Z BAZY, NIE Z `RateLimiter`. Limit ma być dokładny (to są
 * pieniądze i obietnica „5 dziennie” na ekranie), a cache w testach
 * i lokalnie jest tablicą w pamięci. Źródłem prawdy są wiersze
 * `proby_importu` z dzisiejszego dnia i bieżącego miesiąca w strefie
 * człowieka (`Czas`), wraz ze starszymi zleceniami OCR sprzed wprowadzenia
 * księgi. Zleceń zatrzymanych na limicie nie liczymy.
 *
 * Równoległe zlecenia tej samej osoby szereguje `zablokuj()` (blokada
 * doradcza na czas transakcji), więc dwa kliknięcia naraz nie przejdą
 * obu przez ostatnie wolne miejsce.
 */
final class LimitImportowOsoby
{
    public const DZIEN = 'dzien';

    public const MIESIAC = 'miesiac';

    /** Wołać WEWNĄTRZ transakcji, przed `przekroczony()` i zapisem zlecenia. */
    public function zablokuj(User $osoba): void
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('kuking:import:' || ?))", [(string) $osoba->getKey()]);
    }

    /**
     * Rezerwuje jedno miejsce dla wszystkich źródeł. Wołać w transakcji:
     * blokada osoby, kontrola limitu i INSERT muszą być jednym działaniem.
     * Powtórzenie tego samego formularza oddaje istniejącą próbę bez kosztu.
     *
     * @return array{id: string, status: string, recipe_id: ?string, istnieje: bool}|null
     */
    public function rezerwuj(User $osoba, string $zrodlo, string $klucz, ?string $importId = null): ?array
    {
        $this->zablokuj($osoba);

        $juz = DB::table('proby_importu')->where('user_id', $osoba->getKey())
            ->where('klucz_wyslania', $klucz)->first();
        if ($juz !== null) {
            return ['id' => $juz->id, 'status' => $juz->status, 'recipe_id' => $juz->recipe_id, 'istnieje' => true];
        }

        if ($this->przekroczony($osoba) !== null) {
            return null;
        }

        $id = (string) Str::uuid7();
        DB::table('proby_importu')->insert([
            'id' => $id,
            'user_id' => $osoba->getKey(),
            'zrodlo' => $zrodlo,
            'klucz_wyslania' => $klucz,
            'import_id' => $importId,
            'status' => 'w_toku',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'status' => 'w_toku', 'recipe_id' => null, 'istnieje' => false];
    }

    public function zakoncz(string $id, bool $powodzenie, ?string $recipeId = null): void
    {
        DB::table('proby_importu')->where('id', $id)->update([
            'status' => $powodzenie ? 'gotowy' : 'nieudany',
            'recipe_id' => $recipeId,
            'updated_at' => now(),
        ]);
    }

    /** `null` = jest miejsce; inaczej który limit się skończył. */
    public function przekroczony(User $osoba): ?string
    {
        $teraz = Czas::lokalnie(now());

        if ($this->zlecen($osoba, $teraz->copy()->startOfDay()->utc()) >= max(0, (int) config('kuking.import.limity.na_osobe_dzien'))) {
            return self::DZIEN;
        }

        if ($this->zlecen($osoba, $teraz->copy()->startOfMonth()->utc()) >= max(0, (int) config('kuking.import.limity.na_osobe_miesiac'))) {
            return self::MIESIAC;
        }

        return null;
    }

    /**
     * Obecna przeszkoda do ponowienia zapisanego odczytu. To opis stanu
     * dzisiejszego, nie historyczny powód odmowy ani bramka rezerwacji.
     * Miesiąc ma pierwszeństwo, gdy wyczerpały się oba okresy.
     */
    public function obecnaBlokada(User $osoba): ?string
    {
        $teraz = Czas::lokalnie(now());

        if ($this->zlecen($osoba, $teraz->copy()->startOfMonth()->utc()) >= max(0, (int) config('kuking.import.limity.na_osobe_miesiac'))) {
            return self::MIESIAC;
        }

        if ($this->zlecen($osoba, $teraz->copy()->startOfDay()->utc()) >= max(0, (int) config('kuking.import.limity.na_osobe_dzien'))) {
            return self::DZIEN;
        }

        return null;
    }

    private function zlecen(User $osoba, \DateTimeInterface $od): int
    {
        $proby = DB::table('proby_importu')->where('user_id', $osoba->getKey())
            ->where('created_at', '>=', $od)->count();

        // Starsze zlecenia OCR oraz fixture testów nie mają jeszcze wpisu w
        // księdze. Liczymy je raz, bez podwajania prób już zarezerwowanych.
        $odczytyBezProby = ImportPrzepisu::query()
            ->where('user_id', $osoba->getKey())
            ->where('created_at', '>=', $od)
            ->where('status', '!=', ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM)
            ->whereNotIn('id', DB::table('proby_importu')->whereNotNull('import_id')->select('import_id'))
            ->count();

        return $proby + $odczytyBezProby;
    }
}
