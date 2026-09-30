<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\WygasleWpisyCache;
use Illuminate\Console\Command;

/**
 * Wygasłe wiersze tabeli `cache` (#2292, F6) — głównie klucze limiterów per
 * adres, których nikt już nie odczyta. Uzasadnienie w `WygasleWpisyCache`.
 */
class SprzatajWygaslyCache extends Command
{
    protected $signature = 'kuking:sprzataj-cache
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje wygasłe wiersze tabeli cache (sterownik database), partiami — #2292.';

    public function handle(WygasleWpisyCache $sprzataj): int
    {
        $naSucho = (bool) $this->option('na-sucho');

        $ile = $sprzataj->posprzataj($naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$ile} wygasłych wierszy cache."
            : "Skasowano {$ile} wygasłych wierszy cache.");

        return self::SUCCESS;
    }
}
