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

        // DRUGI PRZEBIEG — kategorie dowodowe, domyślnie WYŁĄCZONY.
        // Dopóki właściciel nie wpisze liczby miesięcy, ta ścieżka mówi
        // GŁOŚNO, że bezterminowy wyjątek dalej obowiązuje. Milczenie byłoby
        // gorsze: raport "skasowano N" bez ani słowa o kategoriach dowodowych
        // sugerowałby, że `audit_log` ma już komplet terminów, a nie ma.
        $miesiaceDowodowe = config('kuking.audit_log.retencja_kategorii_dowodowych_miesiace');
        $miesiaceDowodowe = $miesiaceDowodowe === null ? null : (int) $miesiaceDowodowe;

        $wynikDowodowe = $sprzataj->posprzatajKategorieDowodowe($miesiaceDowodowe, $naSucho);

        if (! $wynikDowodowe['wlaczone']) {
            $this->line('Retencja kategorii dowodowych: WYŁĄCZONA (brak decyzji właściciela — '
                .'KUKING_AUDIT_LOG_RETENCJA_DOWODOWYCH_MIESIACE nie jest ustawione). '
                .'Te wpisy leżą bezterminowo, wbrew RODO art. 5 ust. 1 lit. e — '
                .'patrz docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md §C.');

            return self::SUCCESS;
        }

        $this->info($naSucho
            ? "Do skasowania w kategoriach dowodowych: {$wynikDowodowe['skasowano']} wpisów z {$wynikDowodowe['grup']} spraw starszych niż {$miesiaceDowodowe} miesięcy."
            : "Skasowano w kategoriach dowodowych: {$wynikDowodowe['skasowano']} wpisów z {$wynikDowodowe['grup']} spraw starszych niż {$miesiaceDowodowe} miesięcy.");

        return self::SUCCESS;
    }
}
