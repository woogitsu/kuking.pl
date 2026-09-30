<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneWersjePrzepisow;
use App\Support\Odmiana;
use Illuminate\Console\Command;

/**
 * Retencja `recipe_versions` (#2024, D-333). Reguła i uzasadnienie:
 * `App\Domain\Compliance\PrzedawnioneWersjePrzepisow`.
 *
 * Pierwszy przebieg na danych, które rosły bez sprzątania, warto zrobić
 * z `--na-sucho`: liczba z dry-runu to ten sam predykat co przebieg prawdziwy.
 */
class SprzatajWersjePrzepisow extends Command
{
    protected $signature = 'kuking:sprzataj-wersje-przepisow
                            {--miesiace= : Ile miesięcy trzymać wersję (domyślnie z konfiguracji)}
                            {--ostatnie= : Ile najnowszych wersji przepisu zostaje bez względu na wiek (domyślnie z konfiguracji, co najmniej 2)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje wersje przepisów starsze niż okres retencji, poza kilkoma najnowszymi każdego przepisu (#2024).';

    public function handle(PrzedawnioneWersjePrzepisow $sprzataj): int
    {
        $miesiace = $this->option('miesiace') !== null
            ? max(1, (int) $this->option('miesiace'))
            : (int) config('kuking.przepisy.version_retention_months');

        $ostatnie = $this->option('ostatnie') !== null
            ? max(2, (int) $this->option('ostatnie'))
            : (int) config('kuking.przepisy.version_keep_latest');

        $naSucho = (bool) $this->option('na-sucho');

        $w = $sprzataj->posprzataj($miesiace, $ostatnie, $naSucho);

        $wersje = Odmiana::rzeczownik($w['skasowano'], 'wersję', 'wersje', 'wersji');
        $okres = Odmiana::rzeczownik($w['miesiace'], 'miesiąc', 'miesiące', 'miesięcy');

        $this->info(($naSucho ? 'Do skasowania: ' : 'Skasowano ')
            ."{$w['skasowano']} {$wersje} przepisów starszych niż {$w['miesiace']} {$okres} "
            ."(zostaje {$w['ostatnie']} najnowsze z każdego przepisu).");

        if ($w['bledy'] > 0) {
            $wierszy = Odmiana::rzeczownik($w['bledy'], 'wiersza', 'wierszy', 'wierszy');
            $this->error("Nie udało się skasować {$w['bledy']} {$wierszy} — szczegóły w logu, następny przebieg spróbuje ponownie.");

            return self::FAILURE;
        }

        if ($w['zostaje'] > 0) {
            $this->warn("Zostaje na następny przebieg: {$w['zostaje']} (limit jednego przebiegu).");
        }

        return self::SUCCESS;
    }
}
