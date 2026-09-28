<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Monitoring\AlarmZaleglychPaczekDanych;
use Illuminate\Console\Command;

/**
 * Czujka sprzątania paczek z danymi (issue #1331).
 *
 * `kuking:sprzataj-eksporty` przy porażce kończy się błędem, ale komenda,
 * która w ogóle nie chodzi, nie może o sobie donieść. Ta czujka patrzy na
 * stan w bazie — pełne uzasadnienie w `AlarmZaleglychPaczekDanych`.
 */
class SprawdzSprzatanieEksportow extends Command
{
    protected $signature = 'kuking:sprawdz-sprzatanie-eksportow
                            {--bez-alarmu : Sprawdź i wypisz stan, ale nie dzwoń na webhook}';

    protected $description = 'Alarmuje, gdy wygasłe paczki z danymi nadal leżą w storage (issue #1331)';

    public function handle(AlarmZaleglychPaczekDanych $alarm): int
    {
        $wynik = $alarm->sprawdz();

        if (! $this->option('bez-alarmu')) {
            $alarm->zadzwonJesliTrzeba($wynik);
        }

        if ($wynik['zalegle'] === 0) {
            $this->info('Żadna wygasła paczka z danymi nie leży w storage dłużej niż '
                .AlarmZaleglychPaczekDanych::PROG_GODZIN.' h po terminie.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Wygasłe paczki z danymi nadal w storage: %d (najstarsza %d h po terminie). '
            .'Sprawdź dziennik `kuking:sprzataj-eksporty`, napraw storage i uruchom sprzątanie ponownie.',
            $wynik['zalegle'],
            $wynik['najstarsza_godzin'],
        ));

        return self::FAILURE;
    }
}
