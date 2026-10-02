<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recipes\OstatnioOgladane;
use App\Models\RecentRecipeView;
use Illuminate\Console\Command;

/**
 * Sprzątanie prywatnej listy ostatnio oglądanych przepisów (#2553).
 *
 * Kasuje wizyty starsze niż `kuking.ostatnio_ogladane.dni`, pozycje ponad
 * `kuking.ostatnio_ogladane.limit` na osobę i wizyty osób, które wyłączyły
 * funkcję. Odczyt i tak ignoruje to wszystko sam, więc zadanie jest higieną
 * danych (minimalizacja, RODO), nie warunkiem poprawności.
 */
class SprzatajOstatnioOgladane extends Command
{
    protected $signature = 'kuking:sprzataj-ostatnio-ogladane
                            {--na-sucho : Policz, ale niczego nie kasuj}
                            {--wszystkie : Skasuj TAKŻE niewygasłe wizyty — tylko do wycofania migracji}';

    protected $description = 'Kasuje wygasłe, nadliczbowe i osierocone pozycje listy ostatnio oglądanych przepisów (#2553).';

    public function handle(OstatnioOgladane $ostatnie): int
    {
        $naSucho = (bool) $this->option('na-sucho');
        $wszystkie = (bool) $this->option('wszystkie');

        if ($wszystkie && ! $naSucho) {
            $zywe = RecentRecipeView::query()->where('viewed_at', '>', now()->subDays($ostatnie->dni()))->count();

            if ($zywe > 0 && ! $this->confirm(
                "Liczba zapisanych wizyt, które znikną z list ostatnio oglądanych: {$zywe}. Kasować?",
                default: false,
            )) {
                $this->warn('Nic nie skasowano.');

                return self::FAILURE;
            }
        }

        $ile = $ostatnie->posprzataj($naSucho, $wszystkie);

        $this->info($naSucho
            ? "Do skasowania: {$ile} pozycji ostatnio oglądanych przepisów."
            : "Skasowano {$ile} pozycji ostatnio oglądanych przepisów.");

        return self::SUCCESS;
    }
}
