<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneSesje;
use Illuminate\Console\Command;

/**
 * Retencja tabeli `sessions` (RZ-01, 21.09.2026).
 *
 * `config('kuking.sessions.retention_days')` dni od ostatniej aktywności,
 * ale NIGDY mniej niż `SESSION_LIFETIME` — uzasadnienie bariery stoi przy
 * `App\Domain\Compliance\PrzedawnioneSesje`.
 *
 * Komenda nie sprawdza, jaki jest `SESSION_DRIVER`. Tabela istnieje
 * niezależnie od sterownika (zakłada ją migracja `0001_01_01_000001`),
 * a wiersze zapisane w czasie, gdy sterownikiem był `database`, nie
 * przestają być danymi osobowymi po zmianie zmiennej środowiskowej.
 */
class SprzatajSesje extends Command
{
    protected $signature = 'kuking:sprzataj-sesje
                            {--dni= : Ile dni od ostatniej aktywności trzymać sesję (domyślnie z konfiguracji)}
                            {--na-sucho : Policz, ale niczego nie kasuj}';

    protected $description = 'Kasuje wygasłe sesje z tabeli sessions — twarda retencja zamiast loterii frameworka (RZ-01).';

    public function handle(PrzedawnioneSesje $sprzataj): int
    {
        $dni = $this->option('dni') !== null
            ? max(1, (int) $this->option('dni'))
            : (int) config('kuking.sessions.retention_days');

        $naSucho = (bool) $this->option('na-sucho');

        $wynik = $sprzataj->posprzataj($dni, $naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$wynik['skasowano']} sesji bez aktywności od ponad {$wynik['dni']} dni."
            : "Skasowano {$wynik['skasowano']} sesji bez aktywności od ponad {$wynik['dni']} dni.");

        if ($wynik['podniesiony']) {
            // To nie jest błąd, tylko zadziałanie bariery — ale przemilczane
            // wyglądałoby na to, że ustawiona liczba dni obowiązuje, a nie
            // obowiązuje. Komunikat mówi, co zrobić, zgodnie z regułą
            // o błędach z AGENTS.md.
            $this->line(
                "Próg podniesiono do {$wynik['dni']} dni, bo SESSION_LIFETIME jest dłuższy niż ustawiona retencja. "
                .'Krótszy próg kasowałby żywe sesje, czyli wylogowywał ludzi w środku pracy. '
                .'Żeby sprzątać częściej, skróć najpierw SESSION_LIFETIME.',
            );
        }

        return self::SUCCESS;
    }
}
