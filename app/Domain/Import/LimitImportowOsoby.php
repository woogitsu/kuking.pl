<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\ImportPrzepisu;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Support\Facades\DB;

/**
 * Limit odczytów na osobę: 5 dziennie, 30 miesięcznie (D-297).
 *
 * LICZYMY Z BAZY, NIE Z `RateLimiter`. Limit ma być dokładny (to są
 * pieniądze i obietnica „5 dziennie” na ekranie), a cache w testach
 * i lokalnie jest tablicą w pamięci. Źródłem prawdy są wiersze
 * `importy_przepisow` z dzisiejszego dnia i bieżącego miesiąca w strefie
 * człowieka (`Czas`), z pominięciem zleceń zatrzymanych na limicie —
 * te do modelu nie poszły.
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

    private function zlecen(User $osoba, \DateTimeInterface $od): int
    {
        return ImportPrzepisu::query()
            ->where('user_id', $osoba->getKey())
            ->where('created_at', '>=', $od)
            ->where('status', '!=', ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM)
            ->count();
    }
}
