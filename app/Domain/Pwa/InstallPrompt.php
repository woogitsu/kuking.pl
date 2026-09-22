<?php

declare(strict_types=1);

namespace App\Domain\Pwa;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Stan jednej zachęty na konto; metryki i rozpoznanie nawigacji należą do wywołującego. */
final class InstallPrompt
{
    public const ELIGIBLE = 'eligible';

    public const OFFERED = 'offered';

    public const REQUESTED = 'requested';

    public const DISMISSED = 'dismissed';

    public const INSTALLED = 'installed';

    /**
     * Poprzednia aktywność pochodzi z serwera, sprzed jej bieżącej aktualizacji.
     * Sam wiek konta ani pierwsza wizyta nie są dowodem powrotu.
     */
    public function qualify(User $user, ?CarbonInterface $previousSeen): bool
    {
        if ($previousSeen === null || $previousSeen->greaterThan(now()->utc()->subHours(24))) {
            return false;
        }

        return DB::transaction(fn (): bool => $this->account($user)->whereNull('pwa_prompt_state')
            ->update(['pwa_prompt_state' => self::ELIGIBLE]) === 1);
    }

    /** Rezerwacja jednej próby pokazania, nie dowód wyrenderowania komunikatu. */
    public function offer(User $user): bool
    {
        return $this->transition($user, [self::ELIGIBLE], self::OFFERED);
    }

    /** Wybranie instalacji nie oznacza jej ukończenia. */
    public function requestInstallation(User $user): bool
    {
        return $this->transition($user, [self::OFFERED], self::REQUESTED);
    }

    /** Zamknięcie naszej zachęty lub odmowa w natywnym oknie kończy ponawianie. */
    public function dismiss(User $user): bool
    {
        return $this->transition($user, [self::OFFERED, self::REQUESTED], self::DISMISSED);
    }

    /**
     * Wyłącznie po wiarygodnym sygnale instalacji. Może dotrzeć po zamknięciu
     * komunikatu; późna odmowa nie może natomiast cofnąć stanu installed.
     */
    public function installed(User $user): bool
    {
        return $this->transition($user, [self::OFFERED, self::REQUESTED, self::DISMISSED], self::INSTALLED);
    }

    /** @param list<string> $from */
    private function transition(User $user, array $from, string $to): bool
    {
        // Warunek i zapis to jedno UPDATE. Dwa nieodświeżone modele nie mogą
        // zarezerwować dwóch zachęt ani nadpisać nowszej decyzji w bazie.
        // Savepoint chroni transakcję nadrzędną: wywołujący może obsłużyć
        // awarię tej pobocznej funkcji bez zatrucia połączenia PostgreSQL.
        return DB::transaction(fn (): bool => $this->account($user)->whereIn('pwa_prompt_state', $from)
            ->update(['pwa_prompt_state' => $to]) === 1);
    }

    private function account(User $user): Builder
    {
        return DB::table('users')->where('id', $user->getKey())
            ->whereNotIn('status', User::STATUSY_ZAMKNIETEGO_KONTA);
    }
}
