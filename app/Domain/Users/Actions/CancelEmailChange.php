<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Models\AuditLogEntry;
use App\Models\PendingEmailChange;
use App\Models\User;

/**
 * Unieważnienie zamówionej zmiany adresu e-mail (issue #195).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO OSOBNA AKCJA NA JEDEN `DELETE`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo woła ją TRZY drogi i wszystkie trzy muszą znaczyć to samo:
 *
 *  1. przycisk „Anuluj zmianę adresu" na `/ustawienia/e-mail`;
 *  2. ZMIANA HASŁA (`SecuritySettingsController::updatePassword`);
 *  3. RESET HASŁA (`PasswordResetController::reset`).
 *
 * Punkty 2 i 3 są tu ważniejsze niż punkt 1 i to jest sedno tej klasy.
 * List ostrzegawczy do starego adresu mówi: „jeśli to nie Ty — natychmiast
 * zmień hasło". Gdyby zmiana hasła NIE kasowała oczekującego żądania, ta
 * rada byłaby nieprawdziwa: napastnik, który zdążył zamówić zmianę,
 * dokończyłby ją swoim linkiem mimo nowego hasła i przejąłby konto osobie,
 * która właśnie zrobiła dokładnie to, o co prosiliśmy.
 *
 * Jedno miejsce, jedna reguła — inaczej czwarta droga do hasła (a jest ich
 * już trzy) o tym zapomni.
 *
 * Wpis do dziennika audytu powstaje tylko wtedy, gdy naprawdę było co
 * kasować: „nie było żądania" nie jest zdarzeniem bezpieczeństwa i zaśmieca
 * dziennik przy każdej zmianie hasła w serwisie.
 */
final class CancelEmailChange
{
    /** Człowiek nacisnął „Anuluj zmianę adresu". */
    public const POWOD_RECZNIE = 'reczne_anulowanie';

    /** Hasło zmienione w ustawieniach — patrz komentarz klasy. */
    public const POWOD_ZMIANA_HASLA = 'zmiana_hasla';

    /** Hasło ustawione linkiem z „Nie pamiętam hasła" — ten sam powód. */
    public const POWOD_RESET_HASLA = 'reset_hasla';

    /**
     * @param  string  $powod  krótki, techniczny znacznik do dziennika audytu:
     *                         kto i czym unieważnił żądanie
     * @return bool czy było co unieważniać
     */
    public function handle(User $user, string $powod, ?string $ip = null): bool
    {
        $bylo = PendingEmailChange::query()
            ->where('user_id', $user->getKey())
            ->delete() > 0;

        if (! $bylo) {
            return false;
        }

        AuditLogEntry::record(
            'account.email_change_cancelled',
            $user,
            $user,
            metadata: ['powod' => $powod],
            ip: $ip,
        );

        return true;
    }
}
