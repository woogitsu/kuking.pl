<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\KanalyAlarmowe;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Czy kanał alarmowy NAPRAWDĘ dochodzi (issue #599).
 *
 * PIĘĆ RÓŻNYCH RZECZY, KTÓRE ŁATWO POMYLIĆ ZE SOBĄ
 * Monitoring tego serwisu składa się z pięciu warstw i każda może działać
 * albo nie działać osobno:
 *
 *   1. kod czujki                       — jest, scalony i otestowany;
 *   2. konfiguracja produkcji           — `LOG_BLAD_WEBHOOK_URL`, `LOG_BLAD_EMAIL`;
 *   3. faktyczne wywołanie              — harmonogram uruchamia komendę;
 *   4. **odebranie wiadomości**         — ← TA KOMENDA;
 *   5. wyciszenie duplikatów i powrót   — `AlarmPolaczen`, `AlarmKolejki`.
 *
 * Do 18.09.2026 warstwy 1, 3 i 5 były sprawdzone, a warstwa 4 na produkcji
 * nie była sprawdzona ani razu — i nie dało się tego zrobić inaczej niż
 * doprowadzając do prawdziwej awarii albo zaniżając próg czujki na żywym
 * serwisie. Jedno i drugie jest złym pomysłem na produkcji.
 *
 * Ta komenda robi dokładnie jedną rzecz: wysyła JEDNĄ wiadomość na KAŻDY
 * włączony kanał alarmowy, tą samą drogą, którą poszedłby prawdziwy alarm,
 * jawnie oznaczoną jako próba. Jeżeli dojdzie — warstwa 4 jest zamknięta.
 * Jeżeli nie dojdzie, wiadomo, że nie dojdzie też prawdziwy alarm.
 *
 * OD #599 KANAŁY SĄ DWA i komenda mówi WPROST o każdym z osobna, który jest
 * włączony, a który nie. Do tego dnia pytała wyłącznie o webhooka — czyli
 * na produkcji, gdzie webhooka nie ma, a poczta jest, meldowałaby „kanał
 * alarmowy WYŁĄCZONY" o działającym monitoringu. Meldunek, który myli się
 * co do tego, czy alarm dojdzie, jest gorszy niż brak meldunku.
 *
 * DLACZEGO OSOBNA KOMENDA, A NIE PRZEŁĄCZNIK W CZUJCE
 * Bo przełącznik „udawaj awarię" w czujce jest bronią wycelowaną w siebie:
 * zostaje w kodzie, ktoś go kiedyś uruchomi na produkcji i wywoła prawdziwe
 * działania naprawcze. Ta komenda niczego nie mierzy i nie zmienia żadnego
 * stanu — nie dotyka bazy, nie czyta kolejki i NIE ZAPISUJE PAMIĘCI
 * WYCISZANIA ALARMÓW.
 *
 * TO OSTATNIE ZOSTAŁO ZACHOWANE PRZY DOKŁADANIU POCZTY, i nie stało się to
 * samo. `EmailBleduHandler` ma własne okno wyciszania duplikatów, ale liczy
 * je z ODCISKU WYJĄTKU — a rekord bez obiektu wyjątku, czyli dokładnie taki,
 * jaki wysyła ta komenda, nie podlega wyciszaniu i nie zapisuje pamięci.
 * Gdyby było inaczej, próba zagłuszyłaby prawdziwy alarm idący zaraz po
 * niej: człowiek sprawdza kanał, widzi „doszło", a pierwsza prawdziwa awaria
 * w następnym kwadransie ginie w ciszy kupionej przez próbę.
 *
 * CZEGO NIE WYSYŁA: niczego, co przyszło od użytkownika. Treść jest stałą
 * z tego pliku plus znacznik czasu i nazwa środowiska. To ten sam rygor,
 * co w `AlarmPolaczen` i `AlarmKopii` po audycie A6-01.
 */
class SprawdzAlarm extends Command
{
    protected $signature = 'kuking:sprawdz-alarm
                            {--bez-wysylki : Tylko powiedz, które kanały są skonfigurowane — nic nie wysyłaj}';

    protected $description = 'Wysyła jedną próbną wiadomość na każdy włączony kanał alarmowy i mówi, które kanały w ogóle istnieją (issue #599).';

    public function handle(): int
    {
        $wlaczone = KanalyAlarmowe::wlaczone();

        // ZAWSZE WYMIENIAMY OBA KANAŁY, także ten wyłączony. Lista samych
        // włączonych odpowiadałaby na pytanie „czy coś działa", a pytanie
        // brzmi „czy alarm dojdzie" — i wtedy trzeba widzieć także to, czego
        // nie ma.
        $this->stanKanalow($wlaczone);

        if ($wlaczone === []) {
            $this->newLine();
            $this->error('ŻADEN kanał alarmowy nie jest włączony.');
            $this->newLine();
            $this->line('To znaczy, że nie dojdzie DZIŚ żaden alarm: ani błąd 500, ani czujka kopii,');
            $this->line('ani budżet połączeń, ani kolejka. Wszystkie one liczą i milczą.');
            $this->newLine();
            $this->line('Wystarczy JEDEN z dwóch — oba mogą też działać naraz:');
            $this->line('  • poczta: wpisz adres skrzynki jako `LOG_BLAD_EMAIL`. Poczta (EmailLabs)');
            $this->line('    jest już skonfigurowana i działa, więc to jest droga bez zakładania');
            $this->line('    konta gdziekolwiek indziej.');
            $this->line('  • webhook: załóż go (Discord „Slack-Compatible Webhook" albo Slack)');
            $this->line('    i wpisz adres jako `LOG_BLAD_WEBHOOK_URL`.');
            $this->newLine();
            $this->line('Zmienne wpisuje się w panelu Railway, po czym trzeba ZRESTARTOWAĆ usługę —');
            $this->line('konfiguracja jest zapiekana przy starcie kontenera. Potem uruchom tę komendę ponownie.');
            $this->line('Krok po kroku: docs/infra/MONITORING_BLEDOW.md §1 i §7.3.');

            return self::FAILURE;
        }

        if ($this->option('bez-wysylki')) {
            $this->newLine();
            $this->line('Nic nie wysłano (`--bez-wysylki`). Sama konfiguracja NIE jest dowodem dostarczenia.');

            return self::SUCCESS;
        }

        $znacznik = Carbon::now()->toIso8601String();

        // Pytamy o WYNIK NASZEJ wysyłki, nie o cudzą sprzed chwili w tym
        // samym procesie (pamięć handlerów jest statyczna).
        KanalyAlarmowe::zapomnijOstatnieWysylki();

        try {
            KanalyAlarmowe::zadzwon($this->tresc($znacznik));
        } catch (Throwable) {
            // Bez treści wyjątku: komunikat klienta HTTP potrafi wnieść
            // w siebie cały adres webhooka razem z sekretem, a komunikat
            // transportu poczty — adres skrzynki i poświadczenia SMTP.
            $this->error('Wysyłka na kanał alarmowy nie powiodła się.');
            $this->line('Sprawdź w dzienniku serwera wpisy „Nie udało się…".');

            return self::FAILURE;
        }

        // BRAK WYJĄTKU NIE JEST DOWODEM DOSTARCZENIA. Klient HTTP Laravela
        // bez `throw()` oddaje 404 z odwołanego webhooka i 500 z zepsutego
        // jako ZWYKŁĄ odpowiedź, a handlery kanałów alarmowych z zasady
        // nigdy nie rzucają dalej — więc `catch` wyżej nie złapie ani jednego
        // z tych przypadków. Komenda, która pyta „czy alarm DOCHODZI", nie
        // ma prawa meldować sukcesu na podstawie samego braku wyjątku.
        // Handlery znają wynik i trzeba je o niego zapytać — KAŻDY OSOBNO,
        // bo „któryś doszedł" nie jest tu wystarczającą odpowiedzią: ta
        // komenda istnieje po to, żeby wskazać zepsuty kanał palcem.
        $wynik = self::SUCCESS;

        $this->newLine();

        foreach ($wlaczone as $kanal) {
            if (KanalyAlarmowe::przyjal($kanal) === true) {
                $this->line('  ✓ '.KanalyAlarmowe::ludzka($kanal).' — PRZYJĄŁ wiadomość próbną.');

                continue;
            }

            $this->line('  ✗ '.KanalyAlarmowe::ludzka($kanal).' — NIE PRZYJĄŁ wiadomości próbnej.');
            $wynik = self::FAILURE;
        }

        if ($wynik === self::FAILURE) {
            $this->newLine();
            // ZDANIE ZOSTAJE DOSŁOWNIE TAKIE, JAKIE BYŁO PRZED #599.
            // `ProbaKanaluPotwierdzaPrzyjecieTest` pilnuje go od #682 —
            // a to jest ten komunikat, po którym człowiek pozna, że narzędzie
            // NIE melduje sukcesu, nie dodzwoniwszy się. KTÓRY kanał zawiódł,
            // mówi lista ✓/✗ wypisana wyżej.
            $this->error('Wiadomość próbna NIE ZOSTAŁA PRZYJĘTA przez kanał alarmowy.');
            $this->newLine();
            $this->line('Żądanie wyszło, ale kanał go nie potwierdził — czyli prawdziwy alarm');
            $this->line('też NIE DOJDZIE tą drogą. Powód, czyli kod HTTP albo nazwa klasy');
            $this->line('wyjątku, stoi w dzienniku serwera pod wpisem „Nie udało się…".');
            $this->newLine();
            $this->line('Najczęstsze przyczyny przy webhooku: literówka w `LOG_BLAD_WEBHOOK_URL`,');
            $this->line('kanał skasowany po stronie Discorda albo Slacka (401/404), brak wyjścia');
            $this->line('do sieci z kontenera.');
            $this->line('Przy poczcie: literówka w `LOG_BLAD_EMAIL`, wyczerpany dobowy limit');
            $this->line('EmailLabs, odrzucony nadawca (`MAIL_FROM_ADDRESS` spoza zweryfikowanej domeny).');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Znacznik wysłanej próby: <options=bold>{$znacznik}</>");
        $this->newLine();
        $this->line('Teraz sprawdź kanał. To jest jedyny moment, w którym rozstrzyga się,');
        $this->line('czy alarm DOCHODZI — kod czujki, harmonogram i wyciszanie duplikatów');
        $this->line('są osobnymi warstwami i żadna z nich tego nie dowodzi.');
        $this->newLine();
        $this->line('„Przyjął" mówi, że usługa po drugiej stronie wiadomość PRZYJĘŁA. Czego');
        $this->line('nadal NIE mówi: czy zobaczy ją człowiek — przy webhooku zależy to od tego,');
        $this->line('kto obserwuje kanał Discorda albo Slacka, a przy poczcie od tego, czy list');
        $this->line('nie wylądował w spamie. Tego stąd sprawdzić się nie da.');

        return self::SUCCESS;
    }

    /**
     * Wypisuje OBA kanały — włączony i wyłączony.
     *
     * ADRESÓW NIE WYPISUJEMY. Webhook jest poświadczeniem: kto go zna, ten
     * może pisać na kanał właściciela. Adres skrzynki alarmowej też nie ma
     * po co stąd wychodzić — wyjście tej komendy bywa wklejane do zgłoszeń.
     * Dlatego w meldunku jest wyłącznie NAZWA ZMIENNEJ i słowo „włączony"
     * albo „wyłączony".
     *
     * @param  list<string>  $wlaczone
     */
    private function stanKanalow(array $wlaczone): void
    {
        $this->line('Kanały alarmowe:');

        foreach ([KanalyAlarmowe::WEBHOOK, KanalyAlarmowe::POCZTA] as $kanal) {
            $this->line(sprintf(
                '  %s %s — %s',
                in_array($kanal, $wlaczone, true) ? '●' : '○',
                KanalyAlarmowe::ludzka($kanal),
                in_array($kanal, $wlaczone, true) ? 'WŁĄCZONY' : 'wyłączony (zmienna pusta)',
            ));
        }
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu „czego tu nie ma":
     * treść musi dać się sprawdzić bez stawiania kanału logowania.
     *
     * Nagłówka `[nazwa/środowisko]` tu NIE MA — dokleja go sama treść
     * alarmu (`App\Logging\TrescAlarmu::tresc()`). Wpisany drugi raz dawał
     * wiadomości zaczynające się od „[Kuking/production] [Kuking/production] …",
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
