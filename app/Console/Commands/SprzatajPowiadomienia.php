<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnionePowiadomienia;
use Illuminate\Console\Command;

/**
 * Retencja `notifications` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.2, §5.6):
 * `config('kuking.notifications.retention_months')` miesięcy od `created_at`,
 * niezależnie od `read_at` (jeden wiek dla wszystkich — wariant A z ADR §6) —
 * Z WYJĄTKIEM typów z `App\Models\Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`,
 * które żyją do upływu WŁASNEGO terminu odwołania
 * (`ModerationAction::appealDeadline()`), nie wg tej liczby miesięcy —
 * kolizja z sześciomiesięcznym terminem z DSA art. 20 ust. 1.
 */
class SprzatajPowiadomienia extends Command
{
    protected $signature = 'kuking:sprzataj-powiadomienia
                            {--miesiace= : Ile miesięcy trzymać powiadomienie, zanim je skasujemy (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje powiadomienia starsze niż okres retencji, poza powiadomieniami moderacyjnymi chronionymi terminem odwołania (issue #19).';

    public function handle(PrzedawnionePowiadomienia $sprzataj): int
    {
        $miesiace = $this->option('miesiace') !== null
            ? max(1, (int) $this->option('miesiace'))
            : (int) config('kuking.notifications.retention_months');

        $naSucho = (bool) $this->option('na-sucho');

        $raport = $sprzataj->posprzataj($miesiace, $naSucho);

        $czasownik = $naSucho ? 'Do skasowania' : 'Skasowano';

        $this->info("Próg: powiadomienia starsze niż {$miesiace} miesięcy (poza wyjątkiem niżej).");
        $this->line("{$czasownik} zwykłych powiadomień: {$raport->usunieteZwykle}.");
        $this->line("{$czasownik} powiadomień moderacyjnych po upływie terminu odwołania: {$raport->usunieteModeracyjne}.");
        $this->line("Zatrzymano powiadomień moderacyjnych terminem odwołania (jeszcze nie minął): {$raport->zatrzymaneTerminemOdwolania}.");

        if ($raport->bezPowiazanejDecyzji > 0) {
            // `warn`, nie `line`: powinno być zero, patrz komentarz klasy.
            $this->warn("Pominięto {$raport->bezPowiazanejDecyzji} powiadomień moderacyjnych bez ustalalnej decyzji — nie skasowano, szczegóły w logu.");
        }

        return self::SUCCESS;
    }
}
