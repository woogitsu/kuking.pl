<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kopie\AlarmKopii;
use App\Domain\Kopie\StanKopiiBazy;
use Illuminate\Console\Command;

/**
 * Czujka: czy kopia bazy poza Railwayem nadal powstaje (issue #193, D-043).
 *
 * Kopię robi OSOBNY serwis Railway w obrazie bez PHP (`docker/kopia/`) — nie
 * ta komenda. Ta komenda pilnuje tego, czego tamten serwis o sobie nie
 * powie: że przestał się uruchamiać. Uzasadnienie w `StanKopiiBazy`.
 *
 * Kod wyjścia jest NIEZEROWY przy braku świeżej kopii — żeby uruchomienie
 * ręczne (`railway ssh -- php artisan kuking:sprawdz-kopie`) dało się
 * wpleść w skrypt, a nie tylko przeczytać.
 */
class SprawdzKopieBazy extends Command
{
    protected $signature = 'kuking:sprawdz-kopie
                            {--bez-alarmu : Sprawdź i wypisz stan, ale nie dzwoń na webhook}';

    protected $description = 'Sprawdza, czy w buckecie leży świeża kopia bazy, i alarmuje, gdy nie (issue #193).';

    public function handle(StanKopiiBazy $stan, AlarmKopii $alarm): int
    {
        $wynik = $stan->sprawdz();

        match ($wynik['stan']) {
            StanKopiiBazy::WYLACZONA => $this->warn(
                'Czujka kopii jest WYŁĄCZONA: bucket nie jest skonfigurowany '
                .'(AWS_KOPIE_BUCKET). To nie znaczy, że kopie są w porządku — '
                .'znaczy, że nikt nie patrzy. Patrz docs/infra/KOPIE_I_ODTWORZENIE.md §7.3.',
            ),
            StanKopiiBazy::AKTUALNA => $this->info(sprintf(
                'Kopia jest: najnowsza ma %d h (próg %d h), w buckecie %d kopii.',
                (int) $wynik['wiek_godzin'],
                $wynik['prog_godzin'],
                $wynik['liczba'],
            )),
            StanKopiiBazy::BRAK_KOPII => $this->error('W buckecie nie ma ani jednej kopii bazy.'),
            StanKopiiBazy::PRZESTARZALA => $this->error(sprintf(
                'Najnowsza kopia ma %d h, a próg to %d h — serwis kopii prawdopodobnie nie chodzi.',
                (int) $wynik['wiek_godzin'],
                $wynik['prog_godzin'],
            )),
            default => $this->error('Nie udało się odpytać bucketu z kopiami.'),
        };

        if (! $this->option('bez-alarmu')) {
            $alarm->zadzwonJesliTrzeba($wynik);
        }

        // `WYLACZONA` zwraca sukces świadomie: dopóki bucket nie istnieje,
        // brak kopii jest stanem znanym i opisanym (#193 czeka na #120),
        // a czerwony wynik codziennie o siódmej uczyłby ignorowania czerwonego.
        return in_array($wynik['stan'], [StanKopiiBazy::AKTUALNA, StanKopiiBazy::WYLACZONA], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
