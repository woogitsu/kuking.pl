<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;
use Illuminate\Support\Facades\DB;

/**
 * Sonda `database` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaBazy implements Sonda
{
    public function nazwa(): string
    {
        return 'database';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_BAZA;
    }

    public function sprawdz(): void
    {
        DB::select('select 1');
    }
}
