<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnionePotwierdzeniaRodo;
use Illuminate\Console\Command;

/**
 * Retencja `potwierdzenia_zadan_rodo`
 * (`docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C,
 * `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §5 punkt 4):
 * `config('kuking.potwierdzenia_rodo.retention_months')` miesięcy od
 * `zakonczono`, z pominięciem wierszy z obowiązującym `wstrzymanie_do`.
 *
 * Sprawy W TOKU nie są kandydatem w ogóle — uzasadnienie przy
 * `App\Domain\Compliance\PrzedawnionePotwierdzeniaRodo`.
 */
class SprzatajPotwierdzeniaRodo extends Command
{
    protected $signature = 'kuking:sprzataj-potwierdzenia-rodo
                            {--miesiace= : Ile miesięcy trzymać potwierdzenie od zakończenia obsługi (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje potwierdzenia obsługi żądań RODO starsze niż okres retencji, poza wstrzymanymi udokumentowaną sprawą.';

    public function handle(PrzedawnionePotwierdzeniaRodo $sprzataj): int
    {
        $miesiace = $this->option('miesiace') !== null
            ? max(1, (int) $this->option('miesiace'))
            : (int) config('kuking.potwierdzenia_rodo.retention_months');

        // KASOWANIE WYŁĄCZONE = LICZYMY, ALE NIE KASUJEMY (decyzja właściciela,
        // `config/kuking.php` → `potwierdzenia_rodo.kasowanie_wlaczone`).
        //
        // Nie kończymy tu wcześniej i nie pomijamy zadania w harmonogramie:
        // przebieg „na sucho" codziennie pokazuje w logu, ile wierszy czekałoby
        // na skasowanie, więc w dniu opinii prawnej widać skalę, a włączenie
        // jest zmianą jednej zmiennej środowiskowej, nie wdrożeniem kodu.
        //
        // `--na-sucho` nadal działa jak dawniej i nadal wygrywa.
        $kasowanieWlaczone = (bool) config('kuking.potwierdzenia_rodo.kasowanie_wlaczone');
        $naSucho = (bool) $this->option('na-sucho') || ! $kasowanieWlaczone;

        if (! $kasowanieWlaczone) {
            $this->warn(
                'Kasowanie potwierdzeń RODO jest WYŁĄCZONE do czasu opinii prawnej '
                .'(KUKING_POTWIERDZENIA_RODO_KASOWANIE). Poniższe liczby są wyłącznie policzone.',
            );
        }

        $wynik = $sprzataj->posprzataj($miesiace, $naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$wynik['skasowano']} potwierdzeń zamkniętych wcześniej niż {$miesiace} miesięcy temu."
            : "Skasowano {$wynik['skasowano']} potwierdzeń zamkniętych wcześniej niż {$miesiace} miesięcy temu.");

        $this->line("Pominięto z powodu udokumentowanego wstrzymania (wstrzymanie_do w przyszłości): {$wynik['wstrzymane']}.");

        return self::SUCCESS;
    }
}
