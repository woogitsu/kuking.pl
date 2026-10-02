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
 *  WŁĄCZONE DECYZJĄ WŁAŚCICIELA Z 2.10.2026 (#2708, odsyła do D-233)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do 2.10.2026 kasowanie było wyłączone (D-233: okresu nie potwierdził
 * prawnik). Właściciel uznał analizę z 2.10.2026 (pytanie 7) za potwierdzenie
 * okresu: 36 miesięcy od zamknięcia sprawy. Komenda jest w
 * `routes/console.php` (02:15). `kuking.potwierdzenia_rodo.retencja_wlaczona`
 * zostaje wyłącznikiem awaryjnym: po jego wyłączeniu komenda nic nie kasuje
 * i mówi o tym wprost; `--na-sucho` działa zawsze.
 *
 * Pominięte są wiersze z `wstrzymanie_do` w przyszłości oraz konta
 * z zabezpieczonym dowodem (ścieżka CSAM). Wpisy `audit_log` `account.*` nie
 * są tu ruszane — dla nich bramką jest backfill
 * (`kuking:przenies-potwierdzenia-rodo`).
 */
class SprzatajPotwierdzeniaRodo extends Command
{
    protected $signature = 'kuking:sprzataj-potwierdzenia-rodo
                            {--miesiace= : Ile miesięcy trzymać potwierdzenie od zakończenia obsługi (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj — działa także przy wyłączonej retencji}';

    protected $description = 'Kasuje potwierdzenia obsługi żądań RODO starsze niż okres retencji, poza wstrzymanymi udokumentowaną sprawą. Wyłącznik awaryjny: kuking.potwierdzenia_rodo.retencja_wlaczona.';

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
            $this->line('Nie zgadujemy go: `kuking.potwierdzenia_rodo.retention_months` jest puste (domyślnie 36, decyzja z 2.10.2026).');
            $this->line('Do policzenia danych historycznych podaj próg wprost, np.: --na-sucho --miesiace=36');

            return self::FAILURE;
        }

        if (! $wlaczona && ! $naSucho) {
            // NIE `FAILURE`. Gdyby ta ścieżka wychodziła błędem, a ktoś mimo
            // wszystko wpiąłby komendę w harmonogram, czujka kolejki
            // zgłaszałaby co noc awarię, której nie ma. Wyłączona retencja
            // to stan zamierzony, nie usterka.
            $this->warn('Retencja potwierdzeń RODO jest WYŁĄCZONA wyłącznikiem awaryjnym. Nic nie skasowano.');
            $this->line('Kasowanie jest twardym DELETE, nieodwracalnym; włącz je, usuwając KUKING_POTWIERDZENIA_RODO_RETENCJA_WLACZONA=false.');
            $this->line("Żeby policzyć bez kasowania: kuking:sprzataj-potwierdzenia-rodo --na-sucho --miesiace={$miesiace}");

            return self::SUCCESS;
        }

        $wynik = $sprzataj->posprzataj($miesiace, $naSucho);

        if ($naSucho && ! $wlaczona) {
            $this->warn('Retencja jest wyłączona wyłącznikiem awaryjnym — to jest wyłącznie policzenie, żaden wiersz nie został skasowany.');
        }

        $this->info($naSucho
            ? "Do skasowania: {$wynik['skasowano']} potwierdzeń zamkniętych wcześniej niż {$miesiace} miesięcy temu."
            : "Skasowano {$wynik['skasowano']} potwierdzeń zamkniętych wcześniej niż {$miesiace} miesięcy temu.");

        $this->line("Pominięto z powodu udokumentowanego wstrzymania (wstrzymanie_do w przyszłości): {$wynik['wstrzymane']}.");

        return self::SUCCESS;
    }
}
