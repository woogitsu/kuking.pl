<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnionePotwierdzeniaRodo;
use Illuminate\Console\Command;

/**
 * Retencja `potwierdzenia_zadan_rodo`
 * (`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §6):
 * `config('kuking.potwierdzenia_rodo.retention_months')` miesięcy od
 * `zakonczono`, z pominięciem wierszy z obowiązującym `wstrzymanie_do`.
 *
 * Sprawy W TOKU nie są kandydatem w ogóle — uzasadnienie przy
 * `App\Domain\Compliance\PrzedawnionePotwierdzeniaRodo`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KASOWANIE JEST DZIŚ WYŁĄCZONE — DECYZJA WŁAŚCICIELA (D-233)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Komenda ISTNIEJE i jest w pełni przetestowana, ale bez
 * `kuking.potwierdzenia_rodo.retencja_wlaczona` NIE KASUJE NICZEGO i mówi
 * o tym wprost. Powód i droga włączenia stoją przy kluczu w
 * `config/kuking.php`; nie powtarzamy ich tutaj, żeby nie rozjechały się
 * z oryginałem.
 *
 * `--na-sucho` DZIAŁA MIMO WYŁĄCZENIA i to jest celowe: właśnie tym
 * właściciel ma przygotować dane historyczne, zanim prawnik potwierdzi
 * okres. Dry-run nie wykonuje żadnego `DELETE`.
 *
 * Nie ma tej komendy w `routes/console.php` — też celowo. Zadanie nieobecne
 * w harmonogramie nie wystartuje nawet przy przypadkowo ustawionej zmiennej,
 * więc wyłączenie ma dwie niezależne bariery, nie jedną.
 */
class SprzatajPotwierdzeniaRodo extends Command
{
    protected $signature = 'kuking:sprzataj-potwierdzenia-rodo
                            {--miesiace= : Ile miesięcy trzymać potwierdzenie od zakończenia obsługi (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj — działa także przy wyłączonej retencji}';

    protected $description = 'Kasuje potwierdzenia obsługi żądań RODO starsze niż okres retencji, poza wstrzymanymi udokumentowaną sprawą. Kasowanie jest domyślnie WYŁĄCZONE (D-233).';

    public function handle(PrzedawnionePotwierdzeniaRodo $sprzataj): int
    {
        $naSucho = (bool) $this->option('na-sucho');
        $wlaczona = (bool) config('kuking.potwierdzenia_rodo.retencja_wlaczona');

        // Okres z opcji wygrywa nad konfiguracją — dry-run ma dać się policzyć
        // dla dowolnego rozważanego progu, zanim ktokolwiek go utrwali.
        $zOpcji = $this->option('miesiace');
        $zConfigu = config('kuking.potwierdzenia_rodo.retention_months');

        $miesiace = $zOpcji !== null
            ? max(1, (int) $zOpcji)
            : ($zConfigu !== null ? max(1, (int) $zConfigu) : null);

        if ($miesiace === null) {
            $this->error('Okres retencji nie jest ustalony.');
            $this->line('Nie zgadujemy go: `kuking.potwierdzenia_rodo.retention_months` jest puste, dopóki prawnik nie potwierdzi okresu (D-233).');
            $this->line('Do policzenia danych historycznych podaj próg wprost, np.: --na-sucho --miesiace=36');

            return self::FAILURE;
        }

        if (! $wlaczona && ! $naSucho) {
            // NIE `FAILURE`. Gdyby ta ścieżka wychodziła błędem, a ktoś mimo
            // wszystko wpiąłby komendę w harmonogram, czujka kolejki
            // zgłaszałaby co noc awarię, której nie ma. Wyłączona retencja
            // to stan zamierzony, nie usterka.
            $this->warn('Retencja potwierdzeń RODO jest WYŁĄCZONA (decyzja właściciela, D-233). Nic nie skasowano.');
            $this->line('Kasowanie jest twardym DELETE, nieodwracalnym, a okres nie został jeszcze potwierdzony przez prawnika.');
            $this->line("Żeby policzyć bez kasowania: kuking:sprzataj-potwierdzenia-rodo --na-sucho --miesiace={$miesiace}");
            $this->line('Żeby włączyć na stałe: patrz `config/kuking.php`, klucz `potwierdzenia_rodo`.');

            return self::SUCCESS;
        }

        $wynik = $sprzataj->posprzataj($miesiace, $naSucho);

        if ($naSucho && ! $wlaczona) {
            $this->warn('Retencja jest wyłączona (D-233) — to jest wyłącznie policzenie, żaden wiersz nie został skasowany.');
        }

        $this->info($naSucho
            ? "Do skasowania: {$wynik['skasowano']} potwierdzeń zamkniętych wcześniej niż {$miesiace} miesięcy temu."
            : "Skasowano {$wynik['skasowano']} potwierdzeń zamkniętych wcześniej niż {$miesiace} miesięcy temu.");

        $this->line("Pominięto z powodu udokumentowanego wstrzymania (wstrzymanie_do w przyszłości): {$wynik['wstrzymane']}.");

        return self::SUCCESS;
    }
}
