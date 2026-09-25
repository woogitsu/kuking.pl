<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneWpisyAudytu;
use Illuminate\Console\Command;

/**
 * Retencja `audit_log` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.1):
 * `config('kuking.audit_log.retention_months')` miesięcy od `created_at`,
 * Z WYJĄTKIEM kategorii z `App\Models\AuditLogEntry::NIGDY_NIE_KASUJ` — te
 * dokumentują wykonanie praw RODO/DSA (dowód usunięcia konta, dowód że ktoś
 * zgłosił i cofnął usunięcie) i NIE SĄ kandydatem do usunięcia, niezależnie
 * od wieku.
 */
class SprzatajAudyt extends Command
{
    protected $signature = 'kuking:sprzataj-audyt
                            {--miesiace= : Ile miesięcy trzymać wpis, zanim go skasujemy (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje wpisy audit_log starsze niż okres retencji, poza kategoriami dowodowymi RODO/DSA (issue #19).';

    public function handle(PrzedawnioneWpisyAudytu $sprzataj): int
    {
        $miesiace = $this->option('miesiace') !== null
            ? max(1, (int) $this->option('miesiace'))
            : (int) config('kuking.audit_log.retention_months');

        $naSucho = (bool) $this->option('na-sucho');

        $wynik = $sprzataj->posprzataj($miesiace, $naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$wynik['skasowano']} wpisów audytu starszych niż {$miesiace} miesięcy."
            : "Skasowano {$wynik['skasowano']} wpisów audytu starszych niż {$miesiace} miesięcy.");

        $this->line("Pominięto jako niekasowalne (dowód RODO/DSA — AuditLogEntry::NIGDY_NIE_KASUJ): {$wynik['niekasowalne']}.");
        $this->line(($naSucho ? 'Do wyczyszczenia' : 'Wyczyszczono')." skrót IP we wpisach dowodowych starszych niż {$miesiace} miesięcy: {$wynik['wyczyszczono_ip']}.");

        return self::SUCCESS;
    }
}
