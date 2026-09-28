<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\DziennikWymazan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Nocna pielęgnacja dziennika wymazań spoza bazy (audyt B5, znalezisko 3):
 * dopisuje brakujące wpisy kont wymazanych w oknie kopii i kasuje wpisy
 * starsze niż najstarsza kopia, z której konto mogłoby wrócić.
 *
 * `--dopisz` (issue #2038) to ręczne wejście z linii logu „Dziennik wymazań:
 * nie udało się zapisać wpisu”, gdy przed nocnym uzupełnieniem odtworzono
 * kopię sprzed wymazania i noc nie ma już skąd wziąć wpisu
 * (`docs/infra/KOPIE_I_ODTWORZENIE.md` §3.1). Wpisuje dokładnie to, co
 * zapisałoby wymazanie — nie wymazuje niczego; to robi dalej
 * `kuking:wymaz-ponownie`.
 */
class PielegnujDziennikWymazan extends Command
{
    protected $signature = 'kuking:dziennik-wymazan
                            {--dopisz= : Identyfikator konta z linii logu o nieudanym zapisie — dopisz tylko ten wpis}
                            {--zakres= : Zakres z tej samej linii logu (minimum albo everything)}
                            {--kiedy= : Chwila wymazania z tej samej linii logu (wymazano_at)}';

    protected $description = 'Uzupełnia i przycina dziennik wymazań kont trzymany poza bazą (audyt B5).';

    public function handle(DziennikWymazan $dziennik): int
    {
        if ($this->option('dopisz') !== null) {
            return $this->dopiszZLogu($dziennik);
        }

        $dni = (int) config('kuking.dziennik_wymazan.retention_days');

        $dopisane = $dziennik->uzupelnij($dni);
        $skasowane = $dziennik->przytnij($dni);

        $this->info("Dziennik wymazań: dopisano {$dopisane}, skasowano starszych niż {$dni} dni: {$skasowane}.");

        return self::SUCCESS;
    }

    private function dopiszZLogu(DziennikWymazan $dziennik): int
    {
        $userId = (string) $this->option('dopisz');
        $zakres = (string) $this->option('zakres');

        if (! Str::isUuid($userId)) {
            $this->error('--dopisz musi być identyfikatorem konta (UUID) z linii logu, pole user_id.');

            return self::FAILURE;
        }

        if (! in_array($zakres, [User::DELETE_SCOPE_MINIMUM, User::DELETE_SCOPE_EVERYTHING], true)) {
            $this->error('--zakres musi być „'.User::DELETE_SCOPE_MINIMUM.'” albo „'.User::DELETE_SCOPE_EVERYTHING.'” — przepisz go z pola zakres w tej samej linii logu.');

            return self::FAILURE;
        }

        // Pusta wartość to brak, nie „teraz” — `parse('')` dałoby bieżącą chwilę.
        $surowe = trim((string) $this->option('kiedy'));

        try {
            $kiedy = $surowe === '' ? null : CarbonImmutable::parse($surowe);
        } catch (Throwable) {
            $kiedy = null;
        }

        if ($kiedy === null || $kiedy->isFuture()) {
            $this->error('--kiedy musi być chwilą wymazania z pola wymazano_at w tej samej linii logu (nie z przyszłości).');

            return self::FAILURE;
        }

        if (! $dziennik->zapisz($userId, $zakres, $kiedy)) {
            $this->error('Nie udało się zapisać wpisu — magazyn dziennika dalej nie odpowiada. Spróbuj ponownie za kilka minut.');

            return self::FAILURE;
        }

        $this->info("Dziennik wymazań: dopisano wpis konta {$userId} (zakres {$zakres}). Teraz uruchom kuking:wymaz-ponownie.");

        return self::SUCCESS;
    }
}
