<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\KanalyAlarmowe;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
                            {--bez-wysylki : Tylko powiedz, czy kanał jest skonfigurowany — nic nie wysyłaj}';

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

        // BRAK WYJĄTKU NIE JEST DOWODEM DOSTARCZENIA. Handler Discorda zna kod
        // odpowiedzi (404 z odwołanego webhooka wraca jako ZWYKŁA odpowiedź),
        // handler poczty wie, czy transport przyjął list. `KanalyAlarmowe`
        // pyta każdy z nich osobno, od czystej kartki.
        KanalyAlarmowe::zadzwon($this->tresc($znacznik));
        $wyniki = KanalyAlarmowe::wyniki();

        $this->newLine();
        $wszystkiePrzyjely = true;

        foreach ($wlaczone as $kanal) {
            if (($wyniki[$kanal] ?? null) === true) {
                $this->info('Kanał '.KanalyAlarmowe::nazwa($kanal).' PRZYJĄŁ wiadomość próbną.');

                continue;
            }

            $wszystkiePrzyjely = false;
            $this->error('Wiadomość próbna NIE ZOSTAŁA PRZYJĘTA przez kanał '.KanalyAlarmowe::nazwa($kanal).' — prawdziwy alarm tym kanałem też NIE DOJDZIE.');
            $this->line($kanal === KanalyAlarmowe::DISCORD
                ? 'Powód (kod HTTP albo klasa wyjątku) stoi w dzienniku serwera pod wpisem „Nie udało się zadzwonić na webhook błędów". Najczęściej: literówka w adresie, kanał skasowany po stronie Discorda (401/404), brak wyjścia do sieci.'
                : 'Powód (klasa wyjątku albo wyczerpany sufit listów) stoi w dzienniku serwera pod wpisem „Nie udało się wysłać listu z alarmem". Sprawdź `php artisan kuking:sprawdz-poczte` i adres w `KUKING_ALARM_EMAIL`.');
        }

        $this->newLine();
        $this->line("Znacznik próby: <options=bold>{$znacznik}</>");
        $this->line('Teraz sprawdź kanał Discorda i skrzynkę. To jest jedyny moment, w którym rozstrzyga się,');
        $this->line('czy alarm DOCHODZI. „Przyjął" znaczy: Discord odpowiedział 2xx albo dostawca poczty');
        $this->line('przyjął list — nie to, że zobaczył go człowiek. Tego stąd sprawdzić się nie da.');

        return $wszystkiePrzyjely ? self::SUCCESS : self::FAILURE;
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
