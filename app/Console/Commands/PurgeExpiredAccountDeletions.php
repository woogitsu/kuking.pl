<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Egzekutor 30-dniowej karencji po zgłoszeniu usunięcia konta (audyt A8).
 *
 * PROBLEM, KTÓRY TO ZAMYKA
 * `docs/legal/COMPLIANCE.md` obiecuje trwałe usunięcie/anonimizację danych
 * po karencji, a `DataSettingsController` obiecuje to samo wprost człowiekowi
 * na ekranie. Bez tej komendy nic nigdy tej obietnicy nie egzekwowało — konto
 * zostawało `pending_delete` na zawsze, czyli albo dane leżą w nieskończoność
 * (problem prawny, nie kosmetyczny), albo ktoś musiałby to robić ręcznie
 * w psql, co przy dwuosobowym zespole (D-012) po prostu by się nie działo.
 *
 * Sama anonimizacja mieszka w `EraseAccountData` (Domain/Users) — ta komenda
 * tylko WYBIERA komu minęła karencja i RAPORTUJE wynik. Podział jest celowy:
 * logikę „co znaczy usunąć dane" da się przetestować bez harmonogramu,
 * a komendę — bez wchodzenia w szczegóły anonimizacji.
 *
 * IDEMPOTENCJA: `EraseAccountData::handle()` sam sprawdza pod blokadą, czy
 * konto nie zostało już obsłużone (albo cofnięte w międzyczasie) i w takim
 * razie nic nie robi — dwa uruchomienia obok siebie albo jedno uruchomione
 * dwa razy nie psują nic ani nie liczą się podwójnie w audycie.
 *
 * ODPORNOŚĆ NA PRZERWANIE: każde konto jest osobną transakcją (patrz
 * `EraseAccountData`). Przerwanie w połowie listy zostawia już obsłużone
 * konta w pełni anonimowe, a resztę — nietkniętą i do podjęcia przy
 * następnym uruchomieniu (harmonogram, `routes/console.php`, chodzi codziennie).
 *
 * `Schedule::call()`, nie `Schedule::command()` — `proc_open` jest wyłączony
 * w `docker/php.ini` (patrz uzasadnienie przy innych zadaniach w
 * `routes/console.php`).
 */
class PurgeExpiredAccountDeletions extends Command
{
    protected $signature = 'kuking:usun-wygasle-konta
                            {--dry-run : Pokaż, którym kontom minęła karencja, i nic nie zmieniaj}';

    protected $description = 'Trwale usuwa/anonimizuje dane kont, którym minęła 30-dniowa karencja po zgłoszeniu usunięcia';

    public function __construct(private readonly EraseAccountData $usunDane)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $graceDays = (int) config('kuking.account.delete_grace_days');
        $termin = now()->subDays($graceDays);

        $doWykonania = User::query()
            ->where('status', User::STATUS_PENDING_DELETE)
            ->whereNull('data_erased_at')
            ->whereNotNull('delete_requested_at')
            ->where('delete_requested_at', '<=', $termin)
            ->orderBy('delete_requested_at')
            ->get();

        if ($doWykonania->isEmpty()) {
            $this->info('Nie ma kont, którym minęła karencja na usunięcie.');

            return self::SUCCESS;
        }

        $usuniete = 0;

        foreach ($doWykonania as $user) {
            if ($dryRun) {
                $this->line("[dry-run] {$user->getKey()} — zgłoszono ".$user->delete_requested_at->format('Y-m-d H:i'));

                continue;
            }

            $wykonano = $this->usunDane->handle($user);

            if (! $wykonano) {
                // Ktoś cofnął usunięcie albo inny proces już to obsłużył
                // między SELECT-em wyżej a tym wywołaniem — nie błąd, tylko
                // wyścig, którego `EraseAccountData` sam pilnuje.
                $this->line("Pominięto (obsłużone w międzyczasie): {$user->getKey()}");

                continue;
            }

            $usuniete++;

            // Wpis do audytu z aktorem `null` — decyzję podjął zegar, nie
            // moderator ani sam użytkownik, tak samo jak przy wygasłych
            // zawieszeniach (`kuking:zdejmij-wygasle-kary`).
            AuditLogEntry::record('account.data_erased', null, $user);

            $this->line("Usunięto dane konta: {$user->getKey()}");
        }

        $this->info($dryRun
            ? 'Kont z minioną karencją: '.$doWykonania->count().' (nic nie zmieniono).'
            : 'Usunięto dane kont: '.$usuniete.'.');

        return self::SUCCESS;
    }
}
