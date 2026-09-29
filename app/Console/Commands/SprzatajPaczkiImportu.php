<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Import\MagazynPaczek;
use Illuminate\Console\Command;

/**
 * Porzucone pliki tymczasowe wczytywania własnej paczki eksportu (#1985).
 *
 * Człowiek wybiera ZIP, widzi podgląd i może zamknąć kartę, nie klikając ani
 * „Wczytaj”, ani „Odrzuć”. Plik czeka wtedy w prywatnym magazynie osoby
 * (`MagazynPaczek`) i dotąd znikał dopiero przy następnym wejściu KTÓREJKOLWIEK
 * osoby w ekran wczytywania — czyli przy braku ruchu nie znikał wcale, a leżą
 * w nim dane osobowe (przepisy, wpisy). To zadanie robi to co noc, niezależnie
 * od ruchu: kasuje pliki starsze niż `kuking.import_paczki.przechowanie_godzin`.
 */
class SprzatajPaczkiImportu extends Command
{
    protected $signature = 'kuking:sprzataj-paczki-importu';

    protected $description = 'Kasuje porzucone, przeterminowane pliki tymczasowe wczytywania własnej paczki eksportu (#1985).';

    public function handle(MagazynPaczek $magazyn): int
    {
        $skasowane = $magazyn->sprzatnijPrzeterminowane();

        $this->info("Skasowano porzuconych plików wczytywania paczki: {$skasowane}.");

        return self::SUCCESS;
    }
}
