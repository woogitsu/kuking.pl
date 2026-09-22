<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Polaczenia\AlarmPolaczen;
use App\Domain\Polaczenia\StanPolaczenBazy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Czujka: czy pula połączeń PostgreSQL ma jeszcze zapas (issue #598).
 *
 * DLACZEGO TO NIE JEST TO SAMO, CO `/health`
 * `/health` odpowiada na pytanie „czy da się połączyć z bazą". Odpowiada
 * na nie TWIERDZĄCO także wtedy, gdy zostało ostatnie wolne miejsce w puli —
 * bo samo to jedno połączenie właśnie dostało. Wyczerpanie puli jest awarią
 * skokową: dopóki jest jedno miejsce, wszystko wygląda dobrze, a po jego
 * zajęciu nie łączy się NIKT, łącznie z administratorem.
 *
 * Kod wyjścia jest NIEZEROWY przy ostrzeżeniu i przy stanie krytycznym —
 * żeby uruchomienie ręczne (`railway ssh -- php artisan kuking:budzet-polaczen`)
 * dało się wpleść w skrypt, a nie tylko przeczytać.
 *
 * `nieobslugiwany` zwraca SUKCES świadomie: na połączeniu innym niż pgsql
 * nie ma czego mierzyć i czerwony wynik uczyłby ignorowania czerwonego.
 */
class BudzetPolaczen extends Command
{
    protected $signature = 'kuking:budzet-polaczen
                            {--bez-alarmu : Zmierz i wypisz stan, ale nie dzwoń na webhook}';

    protected $description = 'Mierzy wykorzystanie puli połączeń PostgreSQL i alarmuje przed jej wyczerpaniem (issue #598).';

    public function handle(StanPolaczenBazy $stan, AlarmPolaczen $alarm): int
    {
        $wynik = $stan->sprawdz();

        if ($wynik['stan'] === StanPolaczenBazy::NIEOBSLUGIWANY) {
            $this->warn('Połączenie nie jest PostgreSQL-em — nie ma czego mierzyć.');

            return self::SUCCESS;
        }

        if ($wynik['stan'] === StanPolaczenBazy::NIEDOSTEPNY) {
            $this->error('Nie udało się odczytać stanu połączeń z serwera bazy.');

            if (! $this->option('bez-alarmu')) {
                $alarm->zadzwonJesliTrzeba($wynik);
            }

            return self::FAILURE;
        }

        // Nazwa bazy NIE idzie na webhook (patrz `AlarmPolaczen`), ale
        // w konsoli jest potrzebna: bez niej nie widać, czy pomiar dotyczy
        // tej bazy, o którą chodziło. Konsola nie wychodzi poza kontener.
        $this->table(['Co', 'Wartość'], [
            ['baza', (string) $wynik['baza']],
            ['max_connections (zmierzone)', (string) $wynik['max_connections']],
            ['superuser_reserved_connections', (string) $wynik['rezerwa_superusera']],
            ['reserved_connections', (string) $wynik['rezerwa_zwykla']],
            ['dostępne miejsca', (string) $wynik['dostepne']],
            ['zajęte na serwerze', (string) $wynik['zajete_serwer']],
            ['zajęte w tej bazie', (string) $wynik['zajete_baza']],
            ['  w tym aktywne', (string) $wynik['aktywne_baza']],
            ['  w tym bezczynne', (string) $wynik['bezczynne_baza']],
            ['  w tym idle in transaction', (string) $wynik['w_transakcji_baza']],
            ['budżet szczytowy (policzony)', (string) $wynik['budzet_szczytowy']],
            ['próg ostrzegawczy', (string) $wynik['prog_ostrzegawczy']],
            ['próg krytyczny', (string) $wynik['prog_krytyczny']],
        ]);

        match ($wynik['stan']) {
            StanPolaczenBazy::SPOKOJNY => $this->info(sprintf(
                'Zapas jest: %d z %d miejsc zajętych, próg ostrzegawczy %d.',
                (int) $wynik['zajete_serwer'],
                (int) $wynik['dostepne'],
                (int) $wynik['prog_ostrzegawczy'],
            )),
            StanPolaczenBazy::OSTRZEZENIE => $this->error(sprintf(
                'Zajętych %d backendów, a policzony budżet tej topologii to %d. '
                .'Coś zajmuje połączenia poza planem — sprawdź, ZANIM dołożysz replikę.',
                (int) $wynik['zajete_serwer'],
                (int) $wynik['budzet_szczytowy'],
            )),
            StanPolaczenBazy::KRYTYCZNY => $this->error(sprintf(
                'Zajętych %d z %d dostępnych miejsc — pula zmierza do wyczerpania. '
                .'Po jej wyczerpaniu nie połączy się także administrator.',
                (int) $wynik['zajete_serwer'],
                (int) $wynik['dostepne'],
            )),
            default => $this->error('Nieznany stan połączeń.'),
        };

        $this->zapiszWDzienniku($wynik);

        if (! $this->option('bez-alarmu')) {
            $alarm->zadzwonJesliTrzeba($wynik);
        }

        return $wynik['stan'] === StanPolaczenBazy::SPOKOJNY ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Jedna linia pomiaru do dziennika serwera — bez tego harmonogram mierzy
     * w próżnię.
     *
     * SKĄD TO SIĘ WZIĘŁO. Zmierzone na produkcji 17.09.2026 23:25:20 UTC:
     * harmonogram uruchamia tę komendę i melduje „DONE", ale ZMIERZONYCH
     * LICZB nie widać nigdzie. `Schedule::call()` woła ją przez
     * `Artisan::call()`, a to przechwytuje wyjście konsoli do bufora — więc
     * tabela wyżej nie trafia do dziennika i po godzinie przestaje istnieć.
     *
     * Skutek był taki, że definicji gotowości #598 („znany peak active
     * connections przy obecnej topologii") nie dało się spełnić mimo
     * działającej czujki: każdy przebieg mierzył i natychmiast zapominał.
     * Do produkcyjnego Postgresa nie ma dziś dostępu z zewnątrz (brak proxy
     * TCP, brak zalogowanego CLI), więc TO JEST jedyna droga, którą szereg
     * czasowy w ogóle może powstać.
     *
     * DLACZEGO `info`, A NIE `warning`. Zdrowy pomiar nie jest ostrzeżeniem.
     * Podniesienie poziomu tylko po to, żeby przebić się przez `LOG_LEVEL`,
     * zamieniłoby dziennik w ciąg fałszywych ostrzeżeń — a od alarmowania
     * jest `AlarmPolaczen` i osobny kanał.
     *
     * DLACZEGO OSOBNY KANAŁ `pomiary`, A NIE ZWYKŁE `Log::info()`.
     * Bo zwykłe `Log::info()` szło kanałem `stderr`, a ten bierze poziom
     * z `LOG_LEVEL` — i `.railway/railway.ts` ustawia na produkcji `warning`.
     * Pomiar był więc odrzucany, zanim dotarł do strumienia: zmierzone
     * 19.09.2026, dwa przebiegi harmonogramu (11:25:02 i 12:25:11 UTC)
     * zameldowały „DONE" i nie zostawiły ani jednej linii z liczbami.
     * Kanał `pomiary` ma poziom `info` NA SZTYWNO, tak jak `blad_webhook`
     * ma na sztywno `error` — uzasadnienie w `config/logging.php`.
     *
     * CZEGO W TEJ LINII NIE MA: nazwy bazy, hosta, użytkownika i treści
     * zapytań. Dziennik produkcyjny jest czytany także przez dostawcę
     * hostingu — to ta sama zasada, którą `AlarmPolaczen` stosuje do
     * webhooka (audyt A6-01). Same liczby i nazwa stanu.
     *
     * @param  array<string, mixed>  $wynik
     */
    private function zapiszWDzienniku(array $wynik): void
    {
        Log::channel('pomiary')->info('kuking:budzet-polaczen', [
            'stan' => $wynik['stan'],
            'zajete_serwer' => $wynik['zajete_serwer'],
            'zajete_baza' => $wynik['zajete_baza'],
            'aktywne' => $wynik['aktywne_baza'],
            'bezczynne' => $wynik['bezczynne_baza'],
            'w_transakcji' => $wynik['w_transakcji_baza'],
            'dostepne' => $wynik['dostepne'],
            'max_connections' => $wynik['max_connections'],
            'budzet_szczytowy' => $wynik['budzet_szczytowy'],
            'prog_ostrzegawczy' => $wynik['prog_ostrzegawczy'],
        ]);
    }
}
