<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * JEDNO miejsce, które wie, ILE jest kanałów alarmowych i które z nich są
 * włączone (issue #599).
 *
 * PO CO TO POWSTAŁO
 * Do 21 września 2026 kanał alarmowy był jeden, więc osiem różnych miejsc
 * w kodzie robiło dokładnie ten sam trójskok: sprawdź
 * `config('logging.channels.blad_webhook.url')`, zapomnij poprzedni wynik,
 * napisz na kanał, zapytaj handler, czy doszło. Odkąd kanały są dwa, ósemka
 * kopii tego trójskoku byłaby ósemką okazji do rozjazdu — a rozjazd wygląda
 * tu tak, że czujka kopii nadal dzwoni tylko na webhooka, którego nie ma,
 * i nikt się o tym nie dowiaduje, bo brak alarmu wygląda dokładnie jak brak
 * awarii. To jest ten sam argument, który stoi w `AlarmujModeratora`.
 *
 * KANAŁY NIC O SOBIE NIE WIEDZĄ i to jest celowe. Ta klasa nie jest
 * „kanałem zbiorczym" ani stosem Monologa — pisze na każdy włączony kanał
 * osobno, żeby awaria jednego (odwołany webhook, leżący EmailLabs) nie
 * zabrała ze sobą drugiego.
 */
final class KanalyAlarmowe
{
    public const WEBHOOK = 'blad_webhook';

    public const POCZTA = 'blad_email';

    /**
     * Nazwa kanału → klucz w `config/logging.php`, pod którym stoi jego
     * adres. Pusty adres = kanał wyłączony; to jest umowa obu kanałów.
     */
    private const ADRESY = [
        self::WEBHOOK => 'logging.channels.blad_webhook.url',
        self::POCZTA => 'logging.channels.blad_email.adres',
    ];

    /**
     * Kanały, które są DZIŚ włączone.
     *
     * @return list<string>
     */
    public static function wlaczone(): array
    {
        $wlaczone = [];

        foreach (self::ADRESY as $kanal => $klucz) {
            if (filled(config($klucz))) {
                $wlaczone[] = $kanal;
            }
        }

        return $wlaczone;
    }

    public static function wlaczony(string $kanal): bool
    {
        return in_array($kanal, self::wlaczone(), true);
    }

    /**
     * Czy JAKIKOLWIEK kanał jest włączony.
     *
     * Wołający sprawdzają to PRZED zbudowaniem treści — nie dlatego, że
     * handlery i tak nie wyślą bez adresu (wyślą… to znaczy: nie wyślą, mają
     * własną drugą linię obrony), tylko żeby w najczęstszym stanie — a dziś
     * na produkcji najczęstszym stanem jest „żaden" — nie budować kanału
     * logowania po nic. Wymóg z #599 brzmi wprost: „gdy zmiennej nie ma, nic
     * się nie dzieje".
     */
    public static function jakikolwiekWlaczony(): bool
    {
        return self::wlaczone() !== [];
    }

    /**
     * Czysta kartka przed pomiarem. Pamięć wyniku w handlerach jest
     * STATYCZNA, czyli wspólna dla całego procesu — a w jednym przebiegu
     * harmonogramu idą po sobie czujki kopii, połączeń i kolejki. Bez
     * wyzerowania cudzy sukces sprzed chwili zostałby odczytany jako nasz.
     */
    public static function zapomnijOstatnieWysylki(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        EmailBleduHandler::zapomnijOstatniaWysylke();
    }

    /**
     * Pisze na KAŻDY włączony kanał osobno.
     *
     * Wyjątek z jednego kanału nie ma prawa zabrać drugiego — dlatego każde
     * wywołanie jest we własnym `try`. Same handlery z zasady nie rzucają
     * (patrz ich komentarze klas), ale między nimi a tym miejscem stoi
     * jeszcze budowanie kanału z konfiguracji, a zła wartość w configu nie
     * może kosztować drugiego alarmu.
     *
     * @param  array<string, mixed>  $kontekst
     */
    public static function zadzwon(string $tresc, array $kontekst = []): void
    {
        foreach (self::wlaczone() as $kanal) {
            try {
                Log::channel($kanal)->error($tresc, $kontekst);
            } catch (Throwable) {
                // Nic. Cały sens rozdzielonych kanałów to żeby awaria jednego
                // nie była awarią alarmowania.
            }
        }
    }

    /**
     * Czy KTÓRYKOLWIEK włączony kanał PRZYJĄŁ ostatnią wiadomość.
     *
     * `true` = przynajmniej jeden dzwonek zadzwonił. `false` = próbowaliśmy
     * i nie udało się na żadnym. `null` = nie było próby (żaden kanał nie
     * jest włączony).
     *
     * DLACZEGO „KTÓRYKOLWIEK", A NIE „WSZYSTKIE". Bo ta odpowiedź służy
     * wyłącznie do jednej decyzji: czy wołającemu wolno wyciszyć się na czas
     * po udanym dzwonku. Jeżeli list doszedł, a webhook nie — właściciel
     * WIE o awarii, więc cisza jest zasłużona i powtarzanie alarmu tylko po
     * to, żeby dobić drugi kanał, zasypywałoby skrzynkę. Odwrotnie byłoby
     * gorzej: wymaganie kompletu znaczyłoby, że jeden na stałe zepsuty kanał
     * kasuje wyciszanie w całym serwisie.
     *
     * Że jeden z kanałów nie dochodzi, widać osobno — w dzienniku serwera
     * (wpisy „Nie udało się…") i w `kuking:sprawdz-alarm`, który pyta
     * o KAŻDY kanał z osobna.
     */
    public static function ktorysPrzyjal(): ?bool
    {
        $wyniki = [];

        foreach (self::wlaczone() as $kanal) {
            $wyniki[] = self::przyjal($kanal);
        }

        if ($wyniki === []) {
            return null;
        }

        if (in_array(true, $wyniki, true)) {
            return true;
        }

        // Same `null`-e znaczą „żaden handler nawet nie próbował" — to nie
        // jest przyjęcie i nie ma prawa kupić ciszy.
        return false;
    }

    /** Wynik POJEDYNCZEGO kanału — potrzebny `kuking:sprawdz-alarm`, który melduje osobno o każdym. */
    public static function przyjal(string $kanal): ?bool
    {
        return match ($kanal) {
            self::WEBHOOK => WebhookBleduHandler::ostatniaWysylkaSieUdala(),
            self::POCZTA => EmailBleduHandler::ostatniaWysylkaSieUdala(),
            default => null,
        };
    }

    /** Nazwa kanału do pokazania człowiekowi. Adresów NIE wypisujemy nigdzie — są sekretami. */
    public static function ludzka(string $kanal): string
    {
        return match ($kanal) {
            self::WEBHOOK => 'webhook (`LOG_BLAD_WEBHOOK_URL`)',
            self::POCZTA => 'poczta (`LOG_BLAD_EMAIL`)',
            default => $kanal,
        };
    }
}
