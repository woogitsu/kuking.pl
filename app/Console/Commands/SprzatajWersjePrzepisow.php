<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneWersjePrzepisow;
use Illuminate\Console\Command;

/**
 * Retencja `recipe_versions` (decyzja właściciela z 2026-09-20):
 * `config('kuking.przepisy.version_retention_months')` miesięcy od
 * `created_at`, Z WYJĄTKIEM PIERWSZEJ WERSJI KAŻDEGO PRZEPISU — ta zostaje
 * zawsze, niezależnie od wieku. Uzasadnienie wyjątku stoi przy
 * `App\Domain\Compliance\PrzedawnioneWersjePrzepisow`.
 *
 * `--na-sucho` NIE JEST OZDOBĄ. Retencja uruchomiona PIERWSZY RAZ na danych,
 * które rosły bez żadnego sprzątania, kasuje w jednym przebiegu wszystko, co
 * przekroczyło próg przez cały ten czas — i to jest moment, w którym pomyłka
 * w liczbie miesięcy jest nieodwracalna. Dlatego pierwszy przebieg robi się
 * na sucho, a dopiero potem naprawdę: obie drogi liczą dokładnie ten sam
 * predykat, więc liczba z dry-runu jest obietnicą, a nie szacunkiem.
 */
class SprzatajWersjePrzepisow extends Command
{
    protected $signature = 'kuking:sprzataj-wersje-przepisow
                            {--miesiace= : Ile miesięcy trzymać migawkę, zanim ją skasujemy (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje migawki recipe_versions starsze niż okres retencji, poza pierwszą wersją każdego przepisu.';

    public function handle(PrzedawnioneWersjePrzepisow $sprzataj): int
    {
        $miesiace = $this->option('miesiace') !== null
            ? max(1, (int) $this->option('miesiace'))
            : (int) config('kuking.przepisy.version_retention_months');

        $naSucho = (bool) $this->option('na-sucho');

        $wynik = $sprzataj->posprzataj($miesiace, $naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$wynik['skasowano']} wersji przepisów starszych niż {$miesiace} miesięcy."
            : "Skasowano {$wynik['skasowano']} wersji przepisów starszych niż {$miesiace} miesięcy.");

        $this->line("Pominięto jako niekasowalne (pierwsza wersja przepisu — punkt odniesienia dla całej historii): {$wynik['niekasowalne']}.");

        return self::SUCCESS;
    }
}
