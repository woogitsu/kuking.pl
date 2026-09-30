<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jedno miejsce, które wie, ILE jest kanałów alarmowych operatora i które
 * z nich są włączone (#599).
 *
 * Do 29.09.2026 kanał był jeden (`blad_webhook`, Discord, D-041/D-333),
 * a kilkanaście miejsc robiło ten sam trójskok: sprawdź adres webhooka,
 * zapomnij poprzedni wynik, napisz na kanał, zapytaj handler, czy doszło.
 * Od #599 obok Discorda stoi poczta (`blad_email`, `KUKING_ALARM_EMAIL`).
 * Kilkanaście kopii trójskoku przy dwóch kanałach to kilkanaście okazji do
 * rozjazdu — a rozjazd wygląda tu tak, że jedna czujka dzwoni tylko na jeden
 * kanał i nikt się o tym nie dowiaduje.
 *
 * KANAŁY NIC O SOBIE NIE WIEDZĄ. To nie jest stos Monologa: każdy włączony
 * kanał dostaje wpis OSOBNO, we własnym `try`, najpierw Discord, potem
 * poczta. Odwołany webhook nie zabiera listu, a leżący EmailLabs nie zabiera
 * Discorda. Dziennik serwera (kanał domyślny) pisze Laravel niezależnie od
 * tej klasy — ani jeden, ani drugi kanał go nie blokuje.
 *
 * OKNO SERII JEST WSPÓLNE. Wołający, który wycisza się na czas
 * (`SeriaAlarmow`, `EpizodAlarmu`, odstęp `/health`), dostaje JEDNĄ
 * odpowiedź: czy KTÓRYKOLWIEK kanał przyjął wiadomość. Poczta nie ma więc
 * własnej burzy: sto identycznych błędów 500 to jedna wiadomość na Discordzie
 * i jeden list na okno `kuking.monitoring.seria_okno_minut`. Twardy sufit
 * listów na dobę pilnuje dodatkowo `EmailBleduHandler`.
 */
final class KanalyAlarmowe
{
    public const DISCORD = 'blad_webhook';

    public const POCZTA = 'blad_email';

    /**
     * Kanał → klucz konfiguracji z jego adresem. Pusty adres = kanał
     * wyłączony (umowa obu kanałów). Kolejność = kolejność wysyłki.
     */
    private const ADRESY = [
        self::DISCORD => 'logging.channels.blad_webhook.url',
        self::POCZTA => 'logging.channels.blad_email.adres',
    ];

    /** @var array<string, bool|null> wynik ostatniego `zadzwon()` per kanał */
    private static array $wyniki = [];

    /** @return list<string> */
    public static function wlaczone(): array
    {
        $wlaczone = [];

        foreach (self::ADRESY as $kanal => $klucz) {
            $adres = config($klucz);

            if (is_string($adres) && trim($adres) !== '') {
                $wlaczone[] = $kanal;
            }
        }

        return $wlaczone;
    }

    /**
     * Czy JAKIKOLWIEK kanał jest włączony. Wołający sprawdzają to przed
     * zbudowaniem treści: przy obu pustych zmiennych nie dzieje się nic.
     */
    public static function wlaczony(): bool
    {
        return self::wlaczone() !== [];
    }

    /**
     * Pisze na KAŻDY włączony kanał osobno i mówi, czy KTÓRYKOLWIEK przyjął.
     *
     * „Którykolwiek", nie „wszystkie": odpowiedź służy jednej decyzji —
     * czy wołającemu wolno wyciszyć się na czas. Gdy Discord przyjął, a list
     * nie wyszedł, właściciel WIE o awarii. Wymaganie kompletu znaczyłoby,
     * że jeden trwale zepsuty kanał kasuje wyciszanie w całym serwisie
     * i drugi kanał dostaje burzę. Że jeden z kanałów nie dochodzi, widać
     * w `stderr` (wpisy „Nie udało się…”) i w `kuking:sprawdz-alarm`.
     *
     * @param  array<string, mixed>  $kontekst  wyłącznie pola, które handlery
     *                                          umieją bezpiecznie przepuścić
     *                                          (`exception`, `pominiete_powtorzenia`,
     *                                          identyfikatory korelacji)
     */
    public static function zadzwon(string $tresc, array $kontekst = []): bool
    {
        // Lokalnie, a do pola dopiero na końcu: wysyłka listu potrafi
        // wywołać `zadzwon()` zagnieżdżone (ostrzeżenie o kończącej się puli
        // poczty w `DziennyBudzetListow`), które nadpisałoby wyniki w trakcie.
        $wyniki = [];

        foreach (self::wlaczone() as $kanal) {
            // Czysta kartka: pamięć wyniku w handlerach jest statyczna,
            // a w jednym przebiegu harmonogramu dzwoni kilka czujek po sobie.
            self::zapomnij($kanal);

            try {
                // `error()`, bo oba kanały mają poziom na sztywno `error`.
                Log::channel($kanal)->error($tresc, $kontekst);
                $wyniki[$kanal] = self::wynikHandlera($kanal);
            } catch (Throwable) {
                // Handlery same nie rzucają, ale między nimi a tym miejscem
                // stoi budowanie kanału z konfiguracji. Awaria jednego kanału
                // nie jest awarią alarmowania.
                $wyniki[$kanal] = false;
            }
        }

        self::$wyniki = $wyniki;

        return in_array(true, $wyniki, true);
    }

    /**
     * Wynik ostatniego `zadzwon()` per kanał: `true` przyjął, `false` nie
     * przyjął, `null` nie próbował (np. zapora ponownego wejścia poczty).
     * Kanału wyłączonego tu nie ma.
     *
     * @return array<string, bool|null>
     */
    public static function wyniki(): array
    {
        return self::$wyniki;
    }

    /**
     * Czysta kartka przed próbą, która dzwoni NIE WPROST (np. `report()`
     * przez `SeriaAlarmow`): pominięta przez okno seria nie woła `zadzwon()`,
     * więc bez tego `wyniki()` oddałoby wynik cudzej, wcześniejszej próby.
     */
    public static function zapomnijWyniki(): void
    {
        self::$wyniki = [];
    }

    /** Nazwa dla człowieka. Adresów nie wypisujemy nigdzie — są sekretami. */
    public static function nazwa(string $kanal): string
    {
        return match ($kanal) {
            self::DISCORD => 'Discord (LOG_BLAD_WEBHOOK_URL)',
            self::POCZTA => 'poczta (KUKING_ALARM_EMAIL)',
            default => $kanal,
        };
    }

    /** @return list<string> */
    public static function wszystkie(): array
    {
        return array_keys(self::ADRESY);
    }

    private static function zapomnij(string $kanal): void
    {
        match ($kanal) {
            self::DISCORD => WebhookBleduHandler::zapomnijOstatniaWysylke(),
            self::POCZTA => EmailBleduHandler::zapomnijOstatniaWysylke(),
            default => null,
        };
    }

    private static function wynikHandlera(string $kanal): ?bool
    {
        return match ($kanal) {
            self::DISCORD => WebhookBleduHandler::ostatniaWysylkaSieUdala(),
            self::POCZTA => EmailBleduHandler::ostatniaWysylkaSieUdala(),
            default => null,
        };
    }
}
