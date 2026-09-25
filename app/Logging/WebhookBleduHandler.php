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
 * DLACZEGO TREŚĆ JEST BUDOWANA RĘCZNIE, A NIE Z `$record->context`
 * `$record->context['exception']` to PRAWDZIWY obiekt wyjątku, jaki Laravel
 * przekazuje do `Log::error()` przy raportowaniu (`Illuminate\Foundation\
 * Exceptions\Handler::report()`). Jego `getTrace()` potrafi zawierać dokładne
 * ARGUMENTY wywołań ze stosu — adres e-mail podany do funkcji, treść
 * formularza, hasło przekazane wprost. Domyślne formattery Monologa (i
 * `SlackWebhookHandler` z `'context' => true`, ustawienie Laravela) potrafią
 * to POKAZAĆ w wysyłanej wiadomości. AGENTS.md §7 zakazuje PII w logach,
 * a to jest jedyny log w całym serwisie, który wychodzi do usługi, nad którą
 * nie mamy żadnej kontroli — więc to jest najgorsze możliwe miejsce, żeby
 * zaufać cudzemu formatowaniu „na oko".
 *
 * Dlatego ten handler NIGDY nie serializuje `$record->context` ani
 * `$record->extra` w całości. Bierze z wyjątku wyłącznie: nazwę klasy,
 * komunikat, plik:linię rzucenia, wzorzec trasy HTTP (nie rzeczywisty adres —
 * ta sama zasada, co przy logowaniu 429 w `bootstrap/app.php`) i ślad stosu
 * OGRANICZONY do plik:linia + nazwa funkcji, bez ŻADNEGO argumentu.
 *
 * KOMUNIKAT WYJĄTKU NIE WYCHODZI STĄD W OGÓLE — i to jest poprawka błędu.
 * Do 9 września ta klasa wysyłała `$e->getMessage()`, opierając się na
 * założeniu wypisanym tu wprost: „komunikat wyjątku to tekst napisany przez
 * kogoś z nas w kodzie". DLA `QueryException` TO ZAŁOŻENIE JEST FAŁSZYWE.
 * Komunikat buduje sterownik i wkłada w niego SQL razem z wartościami:
 *
 *     SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value
 *     violates unique constraint "users_email_unique"
 *     DETAIL: Key (email)=(ktos@example.com) already exists.
 *     (Connection: pgsql, SQL: insert into "users" ("email","password", …)
 *      values (ktos@example.com, $2y$12$…, …))
 *
 * Czyli adres e-mail i hash hasła człowieka — w wiadomości wychodzącej do
 * usługi, nad którą nie mamy kontroli. Znalazł to audyt zewnętrzny (A6-01),
 * odtwarzając prawdziwy błąd unikalności przez trasę HTTP. Kanał nigdy nie
 * był włączony na produkcji (`LOG_BLAD_WEBHOOK_URL` nie było ustawione), więc
 * nic nie wyciekło — ale wystarczyłoby go włączyć.
 *
 * Odfiltrowywanie danych z takiego tekstu wyrażeniem regularnym byłoby
 * zgadywaniem: sterownik może zmienić format, a każdy inny pakiet może
 * zbudować komunikat po swojemu. Dlatego treść jest teraz budowana wyłącznie
 * z LISTY DOZWOLONYCH PÓL — nazwa klasy, kod błędu o bezpiecznym kształcie,
 * plik:linia, wzorzec trasy, odcisk i ślad bez argumentów.
 *
 * CO Z TEGO TRACIMY I CZYM TO NADRABIAMY
 * Webhook przestaje być raportem, a staje się DZWONKIEM: mówi „coś się
 * zepsuło, tutaj, tego rodzaju". Pełny komunikat zostaje w logu serwera,
 * który nigdzie nie wychodzi. Żeby dało się jedno z drugim zestawić,
 * wiadomość niesie ODCISK — osiem znaków z klasy, pliku i linii. Ten sam
 * błąd ma zawsze ten sam odcisk, więc przy okazji widać, czy to nowa awaria,
 * czy dziesiąte powtórzenie tej samej.
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
    /**
     * Więcej ramek i tak nie zmieści się w jednej wiadomości Discorda/Slacka
     * (limit ~4000 znaków) — a każda dodatkowa ramka to kolejna okazja, żeby
     * coś, czego nie przewidzieliśmy, znalazło się w wysyłanej treści.
     */
    private const MAKSYMALNIE_RAMEK = 8;

    /** Limit Slacka na pole `text` to 4000 znaków; zostawiamy zapas na resztę wiadomości. */
    private const MAKSYMALNIE_ZNAKOW = 3500;

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
     * (`HealthController::powiadomWebhook()`).
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
            // linia obrony, po sprawdzeniu w `bootstrap/app.php` — na wypadek,
            // gdyby ktoś kiedyś zaczął pisać na ten kanał z innego miejsca
            // i zapomniał o tamtym warunku.
            return;
        }

        try {
            $odpowiedz = Http::timeout(3)->connectTimeout(2)->post($this->url, [
                'text' => $this->tresc($record),
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

    private function tresc(LogRecord $record): string
    {
        $naglowek = sprintf('[%s/%s]', config('app.name'), config('app.env'));
        $requestId = $record->context['request_id'] ?? null;
        // Tylko własne pole o pełnym kształcie UUID v4. Nigdy cały kontekst
        // ani nagłówek żądania: mogą zawierać dane wpisane przez człowieka.
        $correlation = is_string($requestId)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestId) === 1
            ? 'żądanie: '.$requestId
            : null;
        $jobId = $record->context['job_id'] ?? null;
        $attemptId = $record->context['attempt_id'] ?? null;
        $jobCorrelation = QueueCorrelation::validId($jobId) ? 'zadanie: '.$jobId : null;
        $attemptCorrelation = QueueCorrelation::validId($attemptId) ? 'próba: '.$attemptId : null;
        $wyjatek = $record->context['exception'] ?? null;

        if (! $wyjatek instanceof Throwable) {
            // Wpis zalogowany na ten kanał bez obiektu wyjątku (np. wywołanie
            // testowe albo przyszłe `Log::channel('blad_webhook')->error(...)`
            // wprost). Reszta kontekstu rekordu NIE JEST tu dołączana
            // świadomie — mógłby nieść cokolwiek, co ktoś kiedyś doda do
            // wywołania `Log::error()`.
            return $this->przytnij(implode("\n", array_filter([
                $naglowek.' '.$this->jednalinia($record->message),
                $this->powtorzenia($record->context['pominiete_powtorzenia'] ?? null),
                $correlation,
                $jobCorrelation,
                $attemptCorrelation,
            ])));
        }

        $linie = array_filter([
            $naglowek.' '.$wyjatek::class,
            $this->kod($wyjatek),
            sprintf('%s:%d', $this->wzgledna($wyjatek->getFile()), $wyjatek->getLine()),
            $this->trasa(),
            'odcisk: '.self::odcisk($wyjatek),
            $this->powtorzenia($record->context['pominiete_powtorzenia'] ?? null),
            $correlation,
            $jobCorrelation,
            $attemptCorrelation,
        ], static fn (?string $linia): bool => $linia !== null && $linia !== '');

        return $this->przytnij(implode("\n", [
            ...$linie,
            '',
            'Treść komunikatu zostaje w logu serwera — na webhook nie wychodzi.',
            '```',
            ...$this->slad($wyjatek),
            '```',
        ]));
    }

    /**
     * Kod błędu, ale TYLKO jeśli ma bezpieczny kształt. Dla `QueryException`
     * jest to SQLSTATE (`23505` = naruszenie unikalności) i to jest
     * najcenniejsza pojedyncza informacja, jaka po usunięciu komunikatu
     * zostaje. `getCode()` nie jest jednak niczym ograniczony — biblioteka
     * może tam wstawić dowolny łańcuch — więc przepuszczamy wyłącznie krótki
     * kod z liter, cyfr i podkreślenia. Cokolwiek innego pomijamy zamiast
     * przycinać: przycięty tekst nadal mógłby nieść fragment danych.
     */
    private function kod(Throwable $wyjatek): ?string
    {
        $kod = $wyjatek->getCode();

        if (is_int($kod)) {
            return $kod === 0 ? null : 'kod: '.$kod;
        }

        return preg_match('/^[A-Za-z0-9_]{1,20}$/', (string) $kod) === 1
            ? 'kod: '.$kod
            : null;
    }

    /**
     * Osiem znaków, które identyfikują RODZAJ awarii, nie jej wystąpienie.
     * Liczone z klasy, pliku i linii — czyli z rzeczy, które i tak są
     * w wiadomości otwartym tekstem. To nie jest skrót danych osobowych
     * i nie da się z niego niczego odzyskać; ma jedno zadanie: pozwolić
     * odróżnić „nowy błąd" od „ten sam, dziesiąty raz", i odnaleźć wpis
     * w logu serwera.
     */
    public static function odcisk(Throwable $wyjatek): string
    {
        return substr(sha1($wyjatek::class.'|'.$wyjatek->getFile().'|'.$wyjatek->getLine()), 0, 8);
    }

    /**
     * Ile identycznych wystąpień `SeriaAlarmow` pominęła od poprzedniej
     * wiadomości (#599). Tylko liczba całkowita — wszystko inne pomijamy.
     */
    private function powtorzenia(mixed $pominiete): ?string
    {
        return is_int($pominiete) && $pominiete > 0
            ? sprintf('powtórzeń od poprzedniej wiadomości (nie wysłanych osobno): %d', $pominiete)
            : null;
    }

    /**
     * Wzorzec trasy (`POST /wpisy/{post}/komentarz`), NIGDY rzeczywisty adres.
     * Ta sama zasada, co przy logowaniu 429 w `bootstrap/app.php`: adres
     * z podstawionym UUID-em albo slugiem potrafi identyfikować osobę,
     * wzorzec z `{param}` — nigdy.
     */
    private function trasa(): string
    {
        if (! app()->bound('request')) {
            return 'CLI / kolejka (brak żądania HTTP)';
        }

        $request = request();

        return sprintf('%s /%s', $request->method(), $request->route()?->uri() ?? '?');
    }

    /**
     * Ślad stosu OGRANICZONY do plik:linia i nazwa funkcji — bez klucza
     * `args`. To jest dokładnie to miejsce, w którym PHP potrafi umieścić
     * w śladzie prawdziwe wartości wywołania (hasło podane wprost jako
     * argument, adres e-mail, treść formularza) — `getTraceAsString()`
     * i część formatterów Monologa te wartości POKAZUJĄ. Budujemy ślad
     * ręcznie właśnie po to, żeby argumentów tam nigdy nie było.
     *
     * @return list<string>
     */
    private function slad(Throwable $wyjatek): array
    {
        $ramki = array_slice($wyjatek->getTrace(), 0, self::MAKSYMALNIE_RAMEK);

        return array_values(array_map(function (array $ramka): string {
            $miejsce = isset($ramka['file'], $ramka['line'])
                ? sprintf('%s:%d', $this->wzgledna((string) $ramka['file']), $ramka['line'])
                : '[php internal]';

            $funkcja = isset($ramka['class'])
                ? sprintf('%s%s%s()', $ramka['class'], $ramka['type'] ?? '::', $ramka['function'])
                : sprintf('%s()', $ramka['function']);

            return $miejsce.' '.$funkcja;
        }, $ramki));
    }

    /** Ścieżka względem katalogu aplikacji — bez tego każda linia niesie pełną, niepotrzebną ścieżkę kontenera. */
    private function wzgledna(string $sciezka): string
    {
        return str_starts_with($sciezka, base_path())
            ? ltrim(substr($sciezka, strlen(base_path())), '/')
            : $sciezka;
    }

    /** Komunikat wyjątku bywa wielolinijkowy (np. z SQL-a) — tu ma być jedną linią wiadomości. */
    private function jednalinia(string $tekst): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $tekst));
    }

    private function przytnij(string $tekst): string
    {
        return mb_strlen($tekst) > self::MAKSYMALNIE_ZNAKOW
            ? mb_substr($tekst, 0, self::MAKSYMALNIE_ZNAKOW - 1).'…'
            : $tekst;
    }
}
