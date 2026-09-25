<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Models\Report;
use Illuminate\Console\Command;

/**
 * DOSŁANIE PILNYCH ALARMÓW, KTÓRE NIE DOTARŁY (issue #1051).
 *
 * DLACZEGO TA KOMENDA ISTNIEJE
 * Alarm o sprawie pilnej wychodzi z `PrzeanalizujTresc`, a to zadanie jest
 * zlecane WYŁĄCZNIE przy publikacji treści. Sprawa, przy której alarm nie
 * dotarł (`zalegly` — worker zginął w szczelinie, `nieudany` — kanał
 * pocztowy rzucił wyjątkiem, `bez_adresu` — pusty `KUKING_MODEL_ALARM_EMAIL`),
 * nie miała więc drugiej drogi: te stany kończą zadanie sukcesem, bez wiersza
 * w `failed_jobs`, więc nawet `queue:retry` nie miał czego ponowić. Właściciel
 * wpisywał adres, a sprawa dalej była bez alarmu, a `/health` na zawsze
 * `degraded` — gasił to dopiero ręczny SQL.
 *
 * Ta komenda jest tą drugą drogą: co godzinę bierze otwarte sprawy
 * z `Report::pilneDoDoslania()` i woła `AlarmujModeratora::doslij()`.
 *
 * IDEMPOTENTNA — wyścig dwóch przebiegów rozstrzyga warunkowy `UPDATE`
 * w `doslij()`, nie ta pętla. Drugi przebieg widzi zajęty wiersz i nie
 * wysyła nic.
 *
 * PUSTY ADRES TO NIE PORAŻKA KOMENDY. Kod wyjścia 0, bo lokalnie i w testach
 * to stan normalny, a na produkcji krzyczy o nim `/health`
 * (`kanal_alarmowy_wylaczony`) — kod różny od zera rzucałby co godzinę ten
 * sam wyjątek harmonogramu o rzeczy, którą naprawia człowiek, nie ponowienie.
 * Kod 1 oddajemy wtedy, gdy któraś próba zlecenia listu PADŁA — to jest
 * awaria, którą harmonogram (`Harmonogram::artisan()`) ma zgłosić.
 */
class DoslijPilneAlarmy extends Command
{
    protected $signature = 'kuking:doslij-pilne-alarmy';

    protected $description = 'Dosyła alarm o pilnych sprawach moderacyjnych, o których list nie dotarł (issue #1051)';

    public function handle(AlarmujModeratora $alarm): int
    {
        $adres = config('kuking.moderation.model.alarm_email');

        if (! is_string($adres) || $adres === '') {
            $czeka = Report::query()->pilneDoDoslania()->count();
            $this->info("Adres alarmowy nie jest ustawiony (KUKING_MODEL_ALARM_EMAIL) — nie ma dokąd wysłać. Czeka spraw: {$czeka}.");

            return self::SUCCESS;
        }

        // Najstarsze pierwsze: sprawa, która czeka najdłużej, jest najbliżej
        // realnej szkody. Limit partii — powód przy kluczu w `config/kuking.php`.
        $sprawy = Report::query()
            ->pilneDoDoslania()
            ->orderBy('created_at')
            ->limit(max(1, (int) config('kuking.moderation.model.alarm_partia', 50)))
            ->get();

        $wyniki = [Report::ALARM_ZLECONY => 0, Report::ALARM_NIEUDANY => 0, AlarmujModeratora::JUZ_ZLECONY => 0, AlarmujModeratora::SUFIT => 0];

        foreach ($sprawy as $sprawa) {
            $wynik = $alarm->doslij($sprawa);
            $wyniki[$wynik] = ($wyniki[$wynik] ?? 0) + 1;
        }

        $this->info(sprintf(
            'Dosłane: %d. Nieudane: %d. Zajęte przez inny przebieg albo zamknięte: %d. Czekają na dobowy sufit alarmów (audyt B8-02): %d.',
            $wyniki[Report::ALARM_ZLECONY],
            $wyniki[Report::ALARM_NIEUDANY],
            $wyniki[AlarmujModeratora::JUZ_ZLECONY],
            $wyniki[AlarmujModeratora::SUFIT],
        ));

        return $wyniki[Report::ALARM_NIEUDANY] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
