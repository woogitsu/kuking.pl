<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\PorzuconePlikiLivewire;
use App\Support\Odmiana;
use Illuminate\Console\Command;

class SprzatajPorzuconePlikiLivewire extends Command
{
    protected $signature = 'kuking:sprzataj-porzucone-uploady
                            {--godziny=24 : Ile godzin plik ma leżeć w katalogu Livewire, zanim go skasujemy}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje surowe pliki z katalogu tymczasowego Livewire, których nikt nie zapisał (#2178).';

    public function handle(): int
    {
        $godziny = max(PorzuconePlikiLivewire::MIN_GODZIN, (int) $this->option('godziny'));
        $naSucho = (bool) $this->option('na-sucho');

        $wynik = (new PorzuconePlikiLivewire($godziny))->posprzataj($naSucho);
        $ile = $wynik['skasowane'];

        $pliki = Odmiana::rzeczownik($ile, 'porzucony plik', 'porzucone pliki', 'porzuconych plików');

        $this->info($naSucho
            ? "Do skasowania: {$ile} {$pliki} starszych niż {$godziny} h."
            : "Skasowano {$ile} {$pliki} starszych niż {$godziny} h.");

        if ($wynik['bledy'] > 0) {
            // Kod ≠ 0 to porażka przebiegu w harmonogramie (`Harmonogram::artisan()`),
            // czyli jedyny sposób, żeby plik, którego nie udało się skasować,
            // nie przepadł po cichu. Klucza ani nazwy pliku nie wypisujemy.
            $this->error("Nie udało się skasować {$wynik['bledy']} pozycji — szczegóły w dzienniku, bez nazw plików.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
