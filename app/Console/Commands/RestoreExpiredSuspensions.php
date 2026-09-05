<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Przywracanie kont po odsiedzeniu kary (issue #40).
 *
 * DLACZEGO TO MUSI BYĆ AUTOMAT
 * `docs/legal/MODERATION_PLAYBOOK.md` przewiduje blokady czasowe („7 dni”),
 * ale przy jednym moderatorze (D-012) nikt nie odklika ich ręcznie po
 * tygodniu. Bez tej komendy każda kara czasowa staje się w praktyce trwała —
 * czyli playbook obiecuje coś, czego system nie robi.
 *
 * Komenda jest bezpieczna do wielokrotnego uruchomienia: bierze wyłącznie
 * konta `suspended` z terminem w przeszłości. Konto zbanowane nie ma terminu
 * (CHECK w bazie tego pilnuje), więc nie da się go tędy przywrócić — nawet
 * gdyby ktoś ustawił kolumnę ręcznie.
 *
 * Nie jest to jedyna droga powrotu: middleware `EnsureAccountIsActive`
 * przywraca konto od razu, gdy karany użytkownik wejdzie na stronę po
 * upływie terminu. Ta komenda pilnuje kont, które po prostu nie wracają —
 * żeby stan w bazie zgadzał się z rzeczywistością także dla moderacji,
 * statystyk i widoku listy użytkowników.
 */
class RestoreExpiredSuspensions extends Command
{
    protected $signature = 'kuking:zdejmij-wygasle-kary
                            {--dry-run : Pokaż, komu skończyła się kara, i nic nie zmieniaj}';

    protected $description = 'Przywraca konta, którym minął termin zawieszenia';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = User::query()
            ->where('status', User::STATUS_SUSPENDED)
            ->whereNotNull('status_expires_at')
            ->where('status_expires_at', '<=', now())
            ->orderBy('status_expires_at')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Nie ma kar, którym minął termin.');

            return self::SUCCESS;
        }

        foreach ($expired as $user) {
            if ($dryRun) {
                $this->line("[dry-run] {$user->email} — kara minęła {$user->status_expires_at->format('Y-m-d H:i')}");

                continue;
            }

            $user->reinstate();

            // Wpis do dziennika audytu: przywrócenie dostępu jest decyzją
            // moderacyjną tak samo jak jego odebranie, nawet gdy wykonuje ją
            // zegar. Bez tego w historii konta zostaje samo „zawieszony”
            // i nie widać, że kara się skończyła zgodnie z terminem.
            AuditLogEntry::record('account.suspension_expired', null, $user);

            $this->line("Przywrócono: {$user->email}");
        }

        $this->info($dryRun
            ? 'Kar z minionym terminem: '.$expired->count().' (nic nie zmieniono).'
            : 'Przywrócono kont: '.$expired->count().'.');

        return self::SUCCESS;
    }
}
