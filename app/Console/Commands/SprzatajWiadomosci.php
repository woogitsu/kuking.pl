<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use Illuminate\Console\Command;

/**
 * Retencja `contact_messages` — wiadomości z „Napisz do nas".
 *
 * `config('kuking.kontakt.retention_months')` miesięcy od ZAŁATWIENIA
 * (`handled_at`). Wiadomości otwarte nie są kasowane nigdy, niezależnie
 * od wieku — patrz `App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora`.
 */
class SprzatajWiadomosci extends Command
{
    protected $signature = 'kuking:sprzataj-wiadomosci
                            {--miesiace= : Ile miesięcy trzymać załatwioną wiadomość (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje załatwione wiadomości z „Napisz do nas" starsze niż okres retencji. Otwartych nie rusza.';

    public function handle(PrzedawnioneWiadomosciDoOperatora $sprzataj): int
    {
        $miesiace = $this->option('miesiace') !== null
            ? max(1, (int) $this->option('miesiace'))
            : (int) config('kuking.kontakt.retention_months');

        $naSucho = (bool) $this->option('na-sucho');

        $usuniete = $sprzataj->posprzataj($miesiace, $naSucho);

        $czasownik = $naSucho ? 'Do skasowania' : 'Skasowano';

        $this->info("Próg: wiadomości załatwione wcześniej niż {$miesiace} miesięcy temu.");
        $this->line("{$czasownik} wiadomości: {$usuniete}.");
        $this->line('Wiadomości jeszcze niezałatwionych nie ruszamy — niezależnie od wieku.');

        return self::SUCCESS;
    }
}
