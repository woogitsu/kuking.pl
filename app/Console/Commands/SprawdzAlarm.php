<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\WebhookBleduHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Czy kanał alarmowy NAPRAWDĘ dochodzi (issue #599).
 *
 * PIĘĆ RÓŻNYCH RZECZY, KTÓRE ŁATWO POMYLIĆ ZE SOBĄ
 * Monitoring tego serwisu składa się z pięciu warstw i każda może działać
 * albo nie działać osobno:
 *
 *   1. kod czujki                       — jest, scalony i otestowany;
 *   2. konfiguracja produkcji           — `LOG_BLAD_WEBHOOK_URL`;
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

    protected $description = 'Wysyła jedną próbną wiadomość na kanał alarmowy i mówi, czy kanał w ogóle istnieje (issue #599).';

    public function handle(): int
    {
        $adres = config('logging.channels.blad_webhook.url');

        if (blank($adres)) {
            $this->error('Kanał alarmowy jest WYŁĄCZONY — zmienna `LOG_BLAD_WEBHOOK_URL` jest pusta.');
            $this->newLine();
            $this->line('To znaczy, że nie dojdzie DZIŚ żaden alarm: ani błąd 500, ani czujka kopii,');
            $this->line('ani budżet połączeń, ani kolejka. Wszystkie one liczą i milczą.');
            $this->newLine();
            $this->line('Co zrobić: załóż webhook (Discord „Slack-Compatible Webhook" albo Slack),');
            $this->line('wpisz adres jako `LOG_BLAD_WEBHOOK_URL` w panelu Railway i ZRESTARTUJ usługę —');
            $this->line('konfiguracja jest zapiekana przy starcie kontenera. Potem uruchom tę komendę ponownie.');
            $this->line('Krok po kroku: docs/infra/MONITORING_BLEDOW.md §1 i §7.3.');

            return self::FAILURE;
        }

        // Adresu NIE wypisujemy. Webhook jest poświadczeniem — kto go zna,
        // ten może pisać na kanał właściciela. Wyjście tej komendy bywa
        // wklejane do zgłoszeń.
        $this->info('Kanał alarmowy jest skonfigurowany.');

        if ($this->option('bez-wysylki')) {
            $this->line('Nic nie wysłano (`--bez-wysylki`). Sama konfiguracja NIE jest dowodem dostarczenia.');

            return self::SUCCESS;
        }

        $znacznik = Carbon::now()->toIso8601String();

        // Pytamy o WYNIK NASZEJ wysyłki, nie o cudzą sprzed chwili w tym
        // samym procesie (pamięć handlera jest statyczna).
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        try {
            Log::channel('blad_webhook')->error($this->tresc($znacznik));
        } catch (Throwable) {
            // Bez treści wyjątku: komunikat klienta HTTP potrafi wnieść
            // w siebie cały adres webhooka razem z sekretem.
            $this->error('Wysyłka na kanał alarmowy nie powiodła się.');
            $this->line('Sprawdź w dzienniku serwera wpis „Nie udało się zadzwonić na webhook błędów".');

            return self::FAILURE;
        }

        // BRAK WYJĄTKU NIE JEST DOWODEM DOSTARCZENIA. Klient HTTP Laravela
        // bez `throw()` oddaje 404 z odwołanego webhooka i 500 z zepsutego
        // jako ZWYKŁĄ odpowiedź, a `WebhookBleduHandler::write()` z zasady
        // nigdy nie rzuca dalej — więc `catch` wyżej nie złapie ani jednego
        // z tych przypadków. Komenda, która pyta „czy alarm DOCHODZI", nie
        // ma prawa meldować sukcesu na podstawie samego braku wyjątku.
        // Handler zna kod odpowiedzi i trzeba go o niego zapytać — dokładnie
        // tak samo robi `HealthController::powiadomWebhook()`.
        if (WebhookBleduHandler::ostatniaWysylkaSieUdala() !== true) {
            $this->error('Wiadomość próbna NIE ZOSTAŁA PRZYJĘTA przez kanał alarmowy.');
            $this->newLine();
            $this->line('Żądanie wyszło, ale kanał go nie potwierdził — czyli prawdziwy alarm');
            $this->line('też NIE DOJDZIE. Powód, czyli kod HTTP albo nazwa klasy wyjątku, stoi');
            $this->line('w dzienniku serwera pod wpisem „Nie udało się zadzwonić na webhook błędów".');
            $this->newLine();
            $this->line('Najczęstsze przyczyny: literówka w `LOG_BLAD_WEBHOOK_URL`, kanał skasowany');
            $this->line('po stronie Discorda albo Slacka (401/404), brak wyjścia do sieci z kontenera.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Kanał PRZYJĄŁ wiadomość próbną (odpowiedź 2xx), znacznik: <options=bold>{$znacznik}</>");
        $this->newLine();
        $this->line('Teraz sprawdź kanał. To jest jedyny moment, w którym rozstrzyga się,');
        $this->line('czy alarm DOCHODZI — kod czujki, harmonogram i wyciszanie duplikatów');
        $this->line('są osobnymi warstwami i żadna z nich tego nie dowodzi.');
        $this->newLine();
        $this->line('Kod 2xx mówi, że kanał wiadomość PRZYJĄŁ. Czego nadal NIE mówi: czy');
        $this->line('zobaczy ją człowiek — to zależy od tego, na który kanał Discorda albo');
        $this->line('Slacka wskazuje webhook i kto go obserwuje. Tego stąd sprawdzić się nie da.');

        return self::SUCCESS;
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu „czego tu nie ma":
     * treść musi dać się sprawdzić bez stawiania kanału logowania.
     *
     * Nagłówka `[nazwa/środowisko]` tu NIE MA — dokleja go sam kanał
     * (`WebhookBleduHandler::tresc()`). Wpisany drugi raz dawał wiadomości
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
