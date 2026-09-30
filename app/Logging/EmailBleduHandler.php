<?php

declare(strict_types=1);

namespace App\Logging;

use App\Mail\AlarmOperacyjny;
use App\Poczta\DziennyBudzetListow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Wysyła JEDEN wpis logu listem — drugi kanał alarmowy obok Discorda (#599).
 *
 * Umowa jest ta sama co przy `WebhookBleduHandler`: ustawiony
 * `KUKING_ALARM_EMAIL` = alarmy idą, pusty = kanał całkowicie martwy. Na
 * kanał pisze `KanalyAlarmowe`, osobno od Discorda.
 *
 * TREŚĆ: `WebhookBleduHandler::tresc()`. Ten handler nie ma własnego
 * formatowania. Z rekordu wychodzą wyłącznie pola z listy dozwolonych:
 * klasa, kod o bezpiecznym kształcie, plik:linia, wzorzec trasy, odcisk,
 * ślad bez argumentów, identyfikatory korelacji. Komunikat wyjątku, SQL,
 * adres e-mail i dane z żądania nie wychodzą (A6-01, AGENTS.md §7).
 *
 * 1. PĘTLA ZWROTNA. Najbardziej prawdopodobną przyczyną alarmu bywa awaria
 *    samej poczty. Pętlę przecinają trzy rzeczy: zapora ponownego wejścia
 *    (`$wSrodkuWysylki` — wpis z WNĘTRZA wysyłki alarmu nie idzie pocztą),
 *    `write()` nigdy nie rzuca (nie ma z czego zrobić drugiego raportu),
 *    a fakt niedodzwonienia się idzie jawnie na `stderr` — nigdy na ten
 *    kanał i nigdy na kanał domyślny.
 *
 * 2. ZALEW SKRZYNKI. Okno serii jest wspólne z Discordem (`SeriaAlarmow`
 *    i odstępy czujek po stronie wołających, przez `KanalyAlarmowe`).
 *    Ostatnią zaporą jest dobowy sufit `poczta.alarm_operacyjny_na_dobe`
 *    w puli poczty (`DziennyBudzetListow::dlaAlarmuOperacyjnego()`), więc
 *    wiele różnych awarii jednego dnia nie zje listów rejestracji. Pamięć
 *    podręczna niedostępna (np. leży baza, na której stoi cache) =
 *    rezerwacja się nie udaje = list NIE wychodzi, a Discord idzie dalej.
 *    To świadomy kierunek pomyłki: bez licznika każdy błąd 500 burzy byłby
 *    osobnym listem, synchronicznie, w żądaniu człowieka.
 *
 * 3. BEZ KOLEJKI. Kolejka jest jedną z rzeczy, o których alarm zawiadamia.
 *    Koszt: czas żądania do EmailLabs w ścieżce błędu, ograniczony limitem
 *    czasu transportu i oknem serii (jeden list na okno, nie na błąd).
 *
 * 4. NADAWCA to `MAIL_FROM_ADDRESS` — EmailLabs wysyła wyłącznie ze
 *    zweryfikowanej domeny. Adres odbiorcy nie wychodzi do żadnego dziennika
 *    ani na wyjście komendy: jest sekretem tak samo jak adres webhooka.
 */
final class EmailBleduHandler extends AbstractProcessingHandler
{
    private const MAKSYMALNIE_ZNAKOW_TEMATU = 150;

    /**
     * Klucz kontekstu, którym wołający wybiera PULĘ listu (decyzja
     * właściciela 30.09.2026).
     *
     * Domyślnie (klucza brak) każdy wpis to alarm o awarii i idzie spod
     * sufitu `poczta.alarm_operacyjny_na_dobe`. Powiadomienie o wiadomości
     * z „Napisz do nas" (`DzwonekOperatora`) przychodzi z wartością
     * `self::PULA_KONTAKT` i idzie spod WŁASNEGO, niższego sufitu
     * `poczta.kontakt_operatora_na_dobe`: formularz wypełnia każdy, więc bez
     * osobnej puli dwadzieścia wiadomości od ludzi zjadłoby wszystkie listy
     * alarmowe doby i prawdziwa awaria przyszłaby już tylko na Discorda.
     *
     * Wartość spoza zamkniętej listy = pula alarmowa (bezpieczny kierunek:
     * nieznana pula nie dostaje własnego, nieograniczonego licznika).
     * Ten klucz nie wchodzi do treści listu — `WebhookBleduHandler::tresc()`
     * bierze z kontekstu wyłącznie własną listę dozwolonych pól.
     */
    public const KONTEKST_PULA = 'pula_poczty';

    public const PULA_KONTAKT = 'kontakt';

    /** Statyczna: pętla może przebiec przez drugi egzemplarz kanału. */
    private static bool $wSrodkuWysylki = false;

    private static ?bool $ostatniaWysylkaSieUdala = null;

    public function __construct(private readonly ?string $adres, Level $level)
    {
        parent::__construct($level);
    }

    /**
     * `null` = nie było próby w tym procesie (albo zapora ponownego
     * wejścia), `false` = była i list nie wyszedł (także: sufit dobowy),
     * `true` = transport przyjął list.
     */
    public static function ostatniaWysylkaSieUdala(): ?bool
    {
        return self::$ostatniaWysylkaSieUdala;
    }

    public static function zapomnijOstatniaWysylke(): void
    {
        self::$ostatniaWysylkaSieUdala = null;
    }

    protected function write(LogRecord $record): void
    {
        if ($this->adres === null || self::$wSrodkuWysylki) {
            return;
        }

        self::$wSrodkuWysylki = true;
        $budzet = null;

        try {
            $kontakt = ($record->context[self::KONTEKST_PULA] ?? null) === self::PULA_KONTAKT;
            $budzet = $kontakt
                ? DziennyBudzetListow::dlaDzwonkaKontaktu()
                : DziennyBudzetListow::dlaAlarmuOperacyjnego();

            if (! $budzet->sprobujZarezerwowac()) {
                $budzet = null;
                self::$ostatniaWysylkaSieUdala = false;
                $this->zapiszNiedodzwonienie($kontakt
                    ? 'dobowy sufit listów o wiadomościach z „Napisz do nas" albo pula poczty wyczerpane'
                    : 'dobowy sufit alarmów pocztą albo pula poczty wyczerpane');

                return;
            }

            $tresc = WebhookBleduHandler::tresc($record);

            Mail::to($this->adres)->send(new AlarmOperacyjny($this->temat($tresc, $kontakt ? 'Kontakt' : 'Alarm'), $tresc));

            self::$ostatniaWysylkaSieUdala = true;
        } catch (Throwable $e) {
            self::$ostatniaWysylkaSieUdala = false;

            // List nie wyszedł — miejsce w puli wraca.
            try {
                $budzet?->zwolnij();
            } catch (Throwable) {
                // Licznik zawyżony o jeden: pomyłka w stronę „o list mniej".
            }

            $this->zapiszNiedodzwonienie($e::class);
        } finally {
            // `finally`: zapora zamknięta na stałe zamknęłaby kanał bez śladu.
            self::$wSrodkuWysylki = false;
        }
    }

    /**
     * Pierwsza linia treści (nagłówek `[nazwa/środowisko]` i klasa albo
     * zdanie czujki) — ta sama, którą widać na Discordzie. Bez znaków nowej
     * linii: nagłówek `Subject` nie może ich nieść. Przedrostek „Kontakt"
     * zamiast „Alarm" przy wiadomości z formularza: to nie jest awaria,
     * a skrzynka ma dać się filtrować po temacie.
     */
    private function temat(string $tresc, string $przedrostek): string
    {
        $pierwsza = trim((string) preg_replace('/\s+/u', ' ', strtok($tresc, "\n") ?: ''));
        $temat = $przedrostek.': '.$pierwsza;

        return mb_strlen($temat) > self::MAKSYMALNIE_ZNAKOW_TEMATU
            ? mb_substr($temat, 0, self::MAKSYMALNIE_ZNAKOW_TEMATU - 1).'…'
            : $temat;
    }

    /**
     * Sam FAKT, że list nie wyszedł — na `stderr` (to widać w panelu
     * Railway), nigdy na ten kanał. Wyłącznie powód: nazwa klasy wyjątku
     * albo stałe zdanie. Ani adresu odbiorcy, ani komunikatu wyjątku
     * transportu (potrafi nieść adres i odpowiedź serwera poczty), ani
     * treści listu.
     */
    private function zapiszNiedodzwonienie(string $powod): void
    {
        try {
            Log::channel('stderr')->error(
                'Nie udało się wysłać listu z alarmem. Wiadomość przepadła (Discord dostaje ją osobno).',
                ['powod' => $powod],
            );
        } catch (Throwable) {
            // Rzucenie stąd wywróciłoby raportowanie wyjątku.
        }
    }
}
