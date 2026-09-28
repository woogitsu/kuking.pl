<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Push\AlarmWysylkiPush;
use App\Domain\Notifications\Push\StanWysylkiPush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Czujka: trwałe porażki Web Push i utracone ponowienia (#2053).
 *
 * Niczego nie wysyła ani nie zamyka — liczy i ewentualnie dzwoni. Szczegóły
 * i bezpieczne rozliczenie: `docs/infra/WEB_PUSH_TRWALE_PORAZKI_2053.md`.
 *
 * KOD WYJŚCIA 0 TAKŻE PRZY NIEROZLICZONYCH GRUPACH, inaczej niż
 * `kuking:sprawdz-kolejke`. `Harmonogram::artisan()` zamienia kod różny od
 * zera w wyjątek, który idzie do zgłaszania błędów co przebieg — z pominięciem
 * ciszy `EpizodAlarmu`. Stan, który trwa do ręcznego rozliczenia, dałby wtedy
 * wiadomość co godzinę. Kod 1 zostaje dla stanu `niedostepny`, czyli
 * prawdziwego błędu odczytu.
 */
class SprawdzPush extends Command
{
    protected $signature = 'kuking:sprawdz-push
                            {--bez-alarmu : Sprawdź i wypisz stan, ale nie dzwoń na webhook}';

    protected $description = 'Liczy grupy Web Push z trwałą porażką albo utraconym ponowieniem i alarmuje (issue #2053).';

    public function handle(StanWysylkiPush $stan, AlarmWysylkiPush $alarm): int
    {
        $wynik = $stan->sprawdz();

        Log::channel('pomiary')->info('kuking:sprawdz-push', $wynik);

        if ($wynik['stan'] === StanWysylkiPush::NIEDOSTEPNY) {
            $this->error('Nie udało się odczytać stanu rezerwacji Web Push.');
        } else {
            $this->table(['Co', 'Wartość'], [
                ['grupy z trwałą porażką transportu', (string) $wynik['trwale_porazki']],
                ['najstarsza porażka (s)', (string) ($wynik['najstarsza_porazka_sekundy'] ?? '—')],
                ['grupy z utraconym ponowieniem', (string) $wynik['utracone_ponowienia']],
                ['najstarsze utracone (s)', (string) ($wynik['najstarsze_utracone_sekundy'] ?? '—')],
                ['próg osierocenia (min)', (string) $wynik['prog_osierocenia_minut']],
                ['kanał wyłączony (utraconych nie liczymy)', $wynik['kanal_wylaczony'] ? 'tak' : 'nie'],
            ]);

            $wynik['stan'] === StanWysylkiPush::SPOKOJNY
                ? $this->info('Web Push: każda rezerwacja jest w toku, wysłana albo rozliczona.')
                : $this->error($alarm->tresc($wynik));
        }

        if (! $this->option('bez-alarmu')) {
            $alarm->zadzwonJesliTrzeba($wynik);
        }

        return $wynik['stan'] === StanWysylkiPush::NIEDOSTEPNY ? self::FAILURE : self::SUCCESS;
    }
}
