<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\KanalyAlarmowe;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Czy kanał alarmowy NAPRAWDĘ dochodzi (issue #599).
 *
 * PIĘĆ RÓŻNYCH RZECZY, KTÓRE ŁATWO POMYLIĆ ZE SOBĄ
 * Monitoring tego serwisu składa się z pięciu warstw i każda może działać
 * albo nie działać osobno:
 *
 *   1. kod czujki                       — jest, scalony i otestowany;
 *   2. konfiguracja produkcji           — `LOG_BLAD_WEBHOOK_URL`, `KUKING_ALARM_EMAIL`;
 *   3. faktyczne wywołanie              — harmonogram uruchamia komendę;
 *   4. **odebranie wiadomości**         — ← TA KOMENDA;
 *   5. wyciszenie duplikatów i powrót   — `AlarmPolaczen`, `AlarmKolejki`.
 *
 * Do 18.09.2026 warstwy 1, 3 i 5 były sprawdzone, a warstwa 4 na produkcji
 * nie była sprawdzona ani razu — i nie dało się tego zrobić inaczej niż
 * doprowadzając do prawdziwej awarii albo zaniżając próg czujki na żywym
 * serwisie. Jedno i drugie jest złym pomysłem na produkcji.
 *
 * Ta komenda robi dokładnie jedną rzecz: wysyła JEDNĄ wiadomość na ten sam
 * kanał, którym poszedłby prawdziwy alarm, jawnie oznaczoną jako próba.
 * Jeżeli dojdzie — warstwa 4 jest zamknięta. Jeżeli nie dojdzie, wiadomo,
 * że nie dojdzie też prawdziwy alarm.
 *
 * DLACZEGO OSOBNA KOMENDA, A NIE PRZEŁĄCZNIK W CZUJCE
 * Bo przełącznik „udawaj awarię" w czujce jest bronią wycelowaną w siebie:
 * zostaje w kodzie, ktoś go kiedyś uruchomi na produkcji i wywoła prawdziwe
 * działania naprawcze. Ta komenda niczego nie mierzy i nie zmienia żadnego
 * stanu — nie dotyka bazy, nie czyta kolejki i nie zapisuje pamięci
 * wyciszania alarmów.
 *
 * CZEGO NIE WYSYŁA: niczego, co przyszło od użytkownika. Treść jest stałą
 * z tego pliku plus znacznik czasu i nazwa środowiska. To ten sam rygor,
 * co w `AlarmPolaczen` i `AlarmKopii` po audycie A6-01 — kanał wychodzi do
 * Discorda albo Slacka, czyli do usługi, nad którą nie mamy kontroli.
 */
class SprawdzAlarm extends Command
{
    protected $signature = 'kuking:sprawdz-alarm
                            {--bez-wysylki : Tylko powiedz, czy kanał jest skonfigurowany — nic nie wysyłaj}
                            {--przez-wyjatek : Wyślij próbę drogą prawdziwego błędu 500: report() → obsługa wyjątków → kanał}';

    protected $description = 'Wysyła jedną próbną wiadomość na każdy kanał alarmowy (Discord, poczta) i mówi, który ją przyjął (issue #599).';

    public function handle(): int
    {
        $wlaczone = KanalyAlarmowe::wlaczone();

        // Stan KAŻDEGO kanału osobno (#599). Adresów NIE wypisujemy: webhook
        // jest poświadczeniem, adres skrzynki — danymi właściciela, a wyjście
        // tej komendy bywa wklejane do zgłoszeń.
        foreach (KanalyAlarmowe::wszystkie() as $kanal) {
            in_array($kanal, $wlaczone, true)
                ? $this->info('Kanał '.KanalyAlarmowe::nazwa($kanal).': skonfigurowany.')
                : $this->line('Kanał '.KanalyAlarmowe::nazwa($kanal).': wyłączony (pusta zmienna).');
        }

        if ($wlaczone === []) {
            $this->newLine();
            $this->error('Kanał alarmowy jest WYŁĄCZONY — zmienne `LOG_BLAD_WEBHOOK_URL` i `KUKING_ALARM_EMAIL` są puste.');
            $this->newLine();
            $this->line('To znaczy, że nie dojdzie DZIŚ żaden alarm: ani błąd 500, ani czujka kopii,');
            $this->line('ani budżet połączeń, ani kolejka. Wszystkie one liczą i milczą.');
            $this->newLine();
            $this->line('Co zrobić: załóż webhook (Discord „Slack-Compatible Webhook" albo Slack),');
            $this->line('wpisz adres jako `LOG_BLAD_WEBHOOK_URL` w panelu Railway i ZRESTARTUJ usługę —');
            $this->line('konfiguracja jest zapiekana przy starcie kontenera. Drugi kanał to adres skrzynki');
            $this->line('w `KUKING_ALARM_EMAIL`. Potem uruchom tę komendę ponownie.');
            $this->line('Krok po kroku: docs/infra/MONITORING_BLEDOW.md §1 i §7.3.');

            return self::FAILURE;
        }

        if ($this->option('bez-wysylki')) {
            $this->line('Nic nie wysłano (`--bez-wysylki`). Sama konfiguracja NIE jest dowodem dostarczenia.');

            return self::SUCCESS;
        }

        $znacznik = Carbon::now()->toIso8601String();

        if ($this->option('przez-wyjatek')) {
            return $this->przezWyjatek($znacznik, $wlaczone);
        }

        // BRAK WYJĄTKU NIE JEST DOWODEM DOSTARCZENIA. Handler Discorda zna kod
        // odpowiedzi (404 z odwołanego webhooka wraca jako ZWYKŁA odpowiedź),
        // handler poczty wie, czy transport przyjął list. `KanalyAlarmowe`
        // pyta każdy z nich osobno, od czystej kartki.
        KanalyAlarmowe::zadzwon($this->tresc($znacznik));

        $wszystkiePrzyjely = $this->zameldujKanaly($wlaczone, KanalyAlarmowe::wyniki(), 'Wiadomość próbna');

        $this->newLine();
        $this->line("Znacznik próby: <options=bold>{$znacznik}</>");
        $this->line('Teraz sprawdź kanał Discorda i skrzynkę. To jest jedyny moment, w którym rozstrzyga się,');
        $this->line('czy alarm DOCHODZI. „Przyjął" znaczy: Discord odpowiedział 2xx albo dostawca poczty');
        $this->line('przyjął list — nie to, że zobaczył go człowiek. Tego stąd sprawdzić się nie da.');

        return $wszystkiePrzyjely ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Ta sama próba, ale drogą, którą idzie prawdziwy błąd 500 (issue #2223).
     *
     * Zwykła próba pisze prosto na kanały (`KanalyAlarmowe`) i sprawdza same kanały.
     * Prawdziwy błąd idzie dłużej: `report()` → wywołanie zwrotne
     * w `bootstrap/app.php` → `SeriaAlarmow` → kanał. Do 29.09.2026 tę drogę
     * sprawdzał tylko `php artisan tinker --execute="report(…)"`
     * (`MONITORING_BLEDOW.md` §1), a tinkera w obrazie produkcyjnym już nie
     * ma (D-333).
     *
     * Treść wyjątku NIE wychodzi na kanał (Discord ani list): `WebhookBleduHandler::tresc()` bierze z niego
     * tylko klasę, plik i linię. Odcisk to więc zawsze ta linia tego pliku —
     * seria (`SeriaAlarmow`) przepuszcza JEDNĄ taką próbę na okno
     * `kuking.monitoring.seria_okno_minut` i nie wycisza żadnego innego błędu.
     */
    /**
     * @param  list<string>  $wlaczone
     */
    private function przezWyjatek(string $znacznik, array $wlaczone): int
    {
        // Czysta kartka PRZED `report()`: seria pominięta przez okno nie woła
        // `KanalyAlarmowe::zadzwon()`, więc bez tego `wyniki()` oddałoby
        // wynik cudzej, wcześniejszej próby w tym procesie (#599).
        KanalyAlarmowe::zapomnijWyniki();

        report(new RuntimeException($this->tresc($znacznik)));

        $wyniki = KanalyAlarmowe::wyniki();

        if ($wyniki === []) {
            $okno = (int) config('kuking.monitoring.seria_okno_minut');
            $this->newLine();
            $this->error('Próba drogą błędu 500 NIE DOSZŁA do kanału.');
            $this->newLine();
            $this->line("Najczęściej to znaczy, że taka sama próba poszła w ciągu ostatnich {$okno} minut:");
            $this->line('seria identycznych błędów daje jedną wiadomość na okno. Spróbuj ponownie po tym czasie.');
            $this->line('Jeśli to pierwsza próba, przyczyna stoi w dzienniku serwera przy wpisie o tym wyjątku.');

            return self::FAILURE;
        }

        $wszystkiePrzyjely = $this->zameldujKanaly($wlaczone, $wyniki, 'Próba drogą błędu 500');

        $this->newLine();
        $this->line("Znacznik próby drogą błędu 500: <options=bold>{$znacznik}</>");
        $this->line('Na kanale zobaczysz „RuntimeException” z plikiem SprawdzAlarm.php — to ta próba, nie awaria.');

        if (! $wszystkiePrzyjely) {
            $this->line('Prawdziwy błąd 500 tym kanałem, który nie przyjął próby, też NIE DOJDZIE.');
        }

        return $wszystkiePrzyjely ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Wynik próby dla KAŻDEGO włączonego kanału osobno (#599). Zwraca, czy
     * przyjęły wszystkie — komenda, która sprawdza, czy alarm dochodzi, nie
     * melduje sukcesu, gdy jeden z dwóch kanałów milczy.
     *
     * @param  list<string>  $wlaczone
     * @param  array<string, bool|null>  $wyniki
     */
    private function zameldujKanaly(array $wlaczone, array $wyniki, string $co): bool
    {
        $this->newLine();
        $wszystkiePrzyjely = true;

        foreach ($wlaczone as $kanal) {
            if (($wyniki[$kanal] ?? null) === true) {
                $this->info('Kanał '.KanalyAlarmowe::nazwa($kanal).' PRZYJĄŁ: '.mb_strtolower($co).'.');

                continue;
            }

            $wszystkiePrzyjely = false;
            $this->error($co.' NIE ZOSTAŁA PRZYJĘTA przez kanał '.KanalyAlarmowe::nazwa($kanal).' — prawdziwy alarm tym kanałem też NIE DOJDZIE.');
            $this->line($kanal === KanalyAlarmowe::DISCORD
                ? 'Powód (kod HTTP albo klasa wyjątku) stoi w dzienniku serwera pod wpisem „Nie udało się zadzwonić na webhook błędów". Najczęściej: literówka w adresie, kanał skasowany po stronie Discorda (401/404), brak wyjścia do sieci.'
                : 'Powód (klasa wyjątku albo wyczerpany sufit listów) stoi w dzienniku serwera pod wpisem „Nie udało się wysłać listu z alarmem". Sprawdź `php artisan kuking:sprawdz-poczte` i adres w `KUKING_ALARM_EMAIL`.');
        }

        return $wszystkiePrzyjely;
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu „czego tu nie ma":
     * treść musi dać się sprawdzić bez stawiania kanału logowania.
     *
     * Nagłówka `[nazwa/środowisko]` tu NIE MA — dokleja go sam kanał
     * (`WebhookBleduHandler::tresc()`, także w liście). Wpisany drugi raz dawał wiadomości
     * zaczynające się od „[Kuking/production] [Kuking/production] …",
     * co wyszło dopiero na prawdziwym odbiorniku (17.09.2026).
     */
    public function tresc(string $znacznik): string
    {
        return implode(' ', [
            'PRÓBA KANAŁU ALARMOWEGO — to nie jest awaria.',
            'Wysłane ręcznie przez `php artisan kuking:sprawdz-alarm`,',
            'znacznik '.$znacznik.'.',
            'Jeżeli to czytasz, kanał alarmowy DOCHODZI i wiersz 18 w docs/OTWARCIE.md',
            'można zamknąć z dzisiejszą datą.',
        ]);
    }
}
