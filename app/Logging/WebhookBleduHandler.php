<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Wysyła JEDEN wpis logu na webhook zgodny z formatem Slacka — Discord
 * przyjmuje go wprost na końcówce `.../slack` (`docs/infra/MONITORING_BLEDOW.md`).
 *
 * TREŚĆ POWSTAJE W `TrescAlarmu`, NIE TUTAJ (zmiana z 21.09.2026, issue #599).
 * Do tego dnia całe formatowanie stało prywatnie w tej klasie. Odkąd kanały
 * alarmowe są DWA — webhook i poczta (`EmailBleduHandler`) — budowanie treści
 * musi być jedno, bo drugie formatowanie obok pierwszego rozjechałoby się
 * z nim przy pierwszej zmianie, a rozjazd wygląda tu tak, że jedna droga
 * nadal niczego nie zdradza, a druga zaczyna wysyłać komunikat wyjątku
 * z e-mailem i hashem hasła w środku (audyt A6-01). Pełne uzasadnienie
 * i lista dozwolonych pól: komentarz klasy `TrescAlarmu`. Skrót: wychodzi
 * klasa wyjątku, kod o bezpiecznym kształcie, plik:linia, WZORZEC trasy,
 * odcisk i ślad bez ani jednego argumentu wywołania. KOMUNIKAT WYJĄTKU NIE
 * WYCHODZI.
 *
 * DLACZEGO WŁASNY HANDLER, A NIE `Monolog\Handler\SlackWebhookHandler`
 * Tamten łączy się przez `curl_init()` z pominięciem klienta HTTP Laravela
 * (nie da się tego przechwycić `Http::fake()` w testach, więc nie da się
 * DOWIEŚĆ testem, co wychodzi) i domyślnie dokleja do wiadomości CAŁY
 * kontekst rekordu — czyli m.in. obiekt wyjątku z argumentami wywołań ze
 * stosu.
 *
 * DLACZEGO WYSYŁKA NIGDY NIE RZUCA DALEJ
 * `write()` działa W ŚRODKU procedury raportowania wyjątku
 * (`bootstrap/app.php`, `$exceptions->report()`), czyli PRZED wyrenderowaniem
 * strony błędu dla użytkownika. Gdyby wysyłka na webhook sama rzuciła
 * (Discord nie odpowiada, DNS padł, zerwane łącze) — użytkownik zamiast
 * strony 500 dostałby nieobsłużony wyjątek z SAMEGO mechanizmu powiadamiania,
 * czyli coś gorszego niż brak powiadomienia. Dlatego cała wysyłka jest
 * w `try/catch` — a krótki timeout (3 s) chroni przed tym, żeby zawieszony
 * webhook przetrzymywał odpowiedź HTTP dla człowieka, który akurat trafił
 * na awarię.
 *
 * ALE BŁĄD WYSYŁKI JUŻ NIE GINIE PO CICHU (poprawka z 10 września 2026,
 * przy issue #33). Do tego dnia ten `catch` był pusty, a nieudane żądanie
 * i tak nie rzuca wyjątku — klient HTTP Laravela bez `throw()` oddaje HTTP
 * 401 czy 404 jako zwykłą odpowiedź. Webhook z odwołanym adresem milczał
 * więc dokładnie tak samo jak webhook sprawny, i nie było ANI JEDNEGO
 * miejsca, z którego dałoby się to zobaczyć. Teraz: fakt niedodzwonienia się
 * idzie do dziennika serwera (`zapiszNiedodzwonienie()`, kanał `single` —
 * nigdy ten kanał, bo to byłaby pętla), a wołający może o wynik zapytać
 * (`ostatniaWysylkaSieUdala()`). Rzucanie dalej nadal nie wchodzi w grę.
 */
final class WebhookBleduHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly ?string $url, Level $level)
    {
        parent::__construct($level);
    }

    /**
     * Czy OSTATNIA próba wysłania czegokolwiek na ten kanał doszła.
     *
     * `null` = w tym procesie nie próbowaliśmy jeszcze ani razu (albo kanał
     * jest wyłączony brakiem adresu). `false` = próbowaliśmy i się nie udało.
     *
     * ISTNIEJE PO TO, ŻEBY „POŁKNIĘTY BŁĄD WYSYŁKI" NIE ZNACZYŁ „NIKT SIĘ
     * NIGDY NIE DOWIE". `write()` nie ma prawa rzucić (uzasadnienie
     * w komentarzu klasy) i to zostaje bez zmian — ale wołający, który
     * WYCISZA SIĘ NA CZAS po udanym dzwonku, musi umieć odróżnić „zadzwoniło"
     * od „nie zadzwoniło". Bez tego jedna trzysekundowa niedostępność
     * Discorda kupowałaby ciszę na pół godziny
     * (`HealthController::powiadomKanalyAlarmowe()`).
     */
    private static ?bool $ostatniaWysylkaSieUdala = null;

    public static function ostatniaWysylkaSieUdala(): ?bool
    {
        return self::$ostatniaWysylkaSieUdala;
    }

    /** Do testów i do kodu, który chce zacząć pomiar od czystej kartki. */
    public static function zapomnijOstatniaWysylke(): void
    {
        self::$ostatniaWysylkaSieUdala = null;
    }

    protected function write(LogRecord $record): void
    {
        if ($this->url === null) {
            // Brak `LOG_BLAD_WEBHOOK_URL` = kanał wyłączony. To jest DRUGA
            // linia obrony, po sprawdzeniu w `KanalyAlarmowe` — na wypadek,
            // gdyby ktoś kiedyś zaczął pisać na ten kanał z innego miejsca
            // i zapomniał o tamtym warunku.
            return;
        }

        try {
            $odpowiedz = Http::timeout(3)->connectTimeout(2)->post($this->url, [
                'text' => TrescAlarmu::tresc($record),
            ]);

            // NIEUDANE ŻĄDANIE NIE RZUCA WYJĄTKU, i to jest tu ważniejsze niż
            // sam `catch`: klient HTTP Laravela bez `throw()` oddaje HTTP 404
            // czy 500 jako zwykłą odpowiedź. Discord z odwołanym webhookiem
            // odpowiada 401/404 — czyli kanał, który „działa", milczy, a nikt
            // się nie dowiaduje, że milczy.
            self::$ostatniaWysylkaSieUdala = $odpowiedz->successful();

            if (! $odpowiedz->successful()) {
                $this->zapiszNiedodzwonienie('webhook odpowiedział HTTP '.$odpowiedz->status());
            }
        } catch (Throwable $e) {
            // Wysyłka NADAL nie rzuca dalej — patrz akapit „DLACZEGO WYSYŁKA
            // NIGDY NIE RZUCA DALEJ" w komentarzu klasy. Zmieniło się tylko
            // to, że fakt niedodzwonienia się GDZIEŚ ZOSTAJE.
            self::$ostatniaWysylkaSieUdala = false;
            $this->zapiszNiedodzwonienie($e::class);
        }
    }

    /**
     * Zapisuje sam FAKT, że dzwonek nie zadzwonił — do dziennika serwera,
     * nigdy na ten kanał.
     *
     * DLACZEGO JAWNIE `single`, A NIE `Log::error()`
     * Bo domyślny stos może kiedyś zawierać ten kanał (`LOG_STACK`), a wpis
     * o nieudanej wysyłce na webhook, wysyłany na webhook, jest pętlą.
     * `single` to plik na serwerze i nic więcej.
     *
     * W TREŚCI SĄ WYŁĄCZNIE: powód (kod HTTP albo nazwa klasy wyjątku) i sam
     * fakt. Ani adresu webhooka (jest sekretem), ani treści wiadomości, która
     * nie doszła (mogła nieść cokolwiek z `$record`).
     */
    private function zapiszNiedodzwonienie(string $powod): void
    {
        try {
            Log::channel('single')->error(
                'Nie udało się zadzwonić na webhook błędów. Wiadomość przepadła.',
                ['powod' => $powod],
            );
        } catch (Throwable) {
            // Jeśli nie da się zapisać nawet do pliku, to nie jest już nasza
            // sprawa — a rzucenie stąd wywróciłoby raportowanie wyjątku.
        }
    }
}
