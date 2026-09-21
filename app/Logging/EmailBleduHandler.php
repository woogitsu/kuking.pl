<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Wysyła JEDEN wpis logu LISTEM — drugi, równoległy kanał alarmowy (issue #599).
 *
 * PO CO, SKORO JEST WEBHOOK
 * Bo webhooka nie ma. Na produkcji `LOG_BLAD_WEBHOOK_URL` nie jest ustawione
 * (potwierdzone odczytem panelu 21.09.2026), a pusty adres = całkowita cisza
 * — świadome zachowanie kodu. Skutek: błąd 500, awaria bazy, niedziałająca
 * poczta, `/health` w stanie `degraded` i nieudane zadania w kolejce są
 * poprawnie policzone i zalogowane, i NIKOGO NIE BUDZĄ. Właściciel nie
 * używa Discorda ani Slacka i nie zamierza zakładać konta tylko po to, żeby
 * dostawać alarmy — a poczta (EmailLabs) jest w tym serwisie skonfigurowana
 * i realnie działa. Ten kanał zamienia „mam adres webhooka albo nic" na
 * „mam skrzynkę".
 *
 * UMOWA JEST DOKŁADNIE TA SAMA, CO PRZY WEBHOOKU: ustawiony `LOG_BLAD_EMAIL`
 * = alarmy idą, pusty = kanał jest CAŁKOWICIE martwy (nic nie wysyła, nic nie
 * rzuca). Oba kanały mogą działać naraz i nic o sobie nie wiedzą.
 *
 * TREŚĆ JEST TA SAMA CO NA WEBHOOKU, BUDOWANA TĄ SAMĄ METODĄ (`TrescAlarmu`)
 * — i to nie jest oszczędność kodu, tylko warunek bezpieczeństwa. Drugie
 * formatowanie obok pierwszego rozjechałoby się przy pierwszej zmianie,
 * a rozjazd wygląda tu tak, że webhook nadal niczego nie zdradza, a list
 * zaczyna nieść komunikat `QueryException` z adresem e-mail i hashem hasła
 * w środku (audyt A6-01). Ten handler NIE MA własnego formatowania ani
 * jednej linii; jedyne, co dokłada, to temat listu — też z `TrescAlarmu`
 * i też z zamkniętej listy dozwolonych pól.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  1. PĘTLA ZWROTNA: ALARM O AWARII POCZTY, WYSYŁANY POCZTĄ
 * ──────────────────────────────────────────────────────────────────────────
 * To jest usterka, którą ten kanał wnosi, a której webhook nie miał. Wysyłka
 * listu potrafi rzucić (EmailLabs nie odpowiada, brak transportu, odrzucony
 * nadawca) — a najbardziej prawdopodobną przyczyną alarmu jest właśnie
 * awaria poczty. Naiwna wersja próbowałaby zaalarmować o błędzie wysyłki
 * listem, ten znów by padł, i tak w kółko, aż do wyczerpania stosu albo
 * limitu 300 listów dziennie.
 *
 * Pętla jest przecięta TRZEMA niezależnymi rzeczami, w tej kolejności:
 *
 *   a) `self::$wSrodkuWysylki` — zapora ponownego wejścia. Póki trwa wysyłka
 *      alarmu, KAŻDY kolejny wpis na ten kanał jest cicho porzucany. To jest
 *      zabezpieczenie decydujące, bo działa nawet wtedy, gdy pętla biegnie
 *      przez kod, o którym nic nie wiemy: transport poczty woła `Log::error()`
 *      na domyślnym stosie, a ktoś kiedyś dopisze ten kanał do `LOG_STACK`.
 *   b) błąd wysyłki NIE JEST RZUCANY DALEJ (ta sama zasada, co przy
 *      webhooku — `write()` działa w środku `$exceptions->report()`), więc
 *      nie ma z czego zrobić drugiego raportu;
 *   c) fakt niedodzwonienia się idzie do dziennika serwera JAWNIE kanałem
 *      `single` — plik na dysku, nigdy ten kanał i nigdy kanał domyślny.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  2. ZALEW SKRZYNKI: TYSIĄC BŁĘDÓW 500 TO NIE TYSIĄC LISTÓW
 * ──────────────────────────────────────────────────────────────────────────
 * Webhook ma limit ZNAKÓW i jedno okno rozmowy, w którym powtórki po prostu
 * lecą obok siebie. Poczta ma inny problem: tysiąc błędów na minutę to
 * tysiąc listów, czyli skrzynka nie do użycia, wyczerpany dzienny limit
 * EmailLabs (300 listów, dzielone z potwierdzeniami rejestracji —
 * `config/kuking.php`) i właściciel, który po pierwszym takim dniu zakłada
 * regułę „do archiwum". Alarm, którego się nie czyta, to brak alarmu.
 *
 * CZY DA SIĘ UŻYĆ ISTNIEJĄCEJ PAMIĘCI WYCISZANIA — NIE, BO KANAŁ JEJ NIE MA.
 * Sprawdzone: wyciszanie w tym repozytorium żyje po stronie WOŁAJĄCYCH, nie
 * kanału. `HealthController` ma własne okno per nazwa kontroli
 * (`Cache::add`, 30 min), `AlarmKolejki` i `AlarmPolaczen` mają własne,
 * bogatsze pamięci z powrotem do normy, `AlarmKopii` nie ma żadnej. Żadna
 * z nich nie jest wspólnym mechanizmem, po który może sięgnąć handler
 * Monologa — a droga, o którą tu chodzi (`$exceptions->report()`, błąd 500)
 * nie przechodzi przez ŻADNĄ z nich. Dlatego okno jest tutaj.
 *
 * NAJPROSTSZE ROZWIĄZANIE, JAKIE ZAŁATWIA SPRAWĘ: okno `Cache::add` na
 * ODCISK. Odcisk (klasa + plik + linia) już istnieje i już jest w treści —
 * jego zadaniem od początku było odróżnić „nowy błąd" od „ten sam, dziesiąty
 * raz". Tysiąc powtórzeń tej samej awarii to jeden list na
 * `OKNO_WYCISZENIA_MINUT`; DRUGA, INNA awaria w tej samej minucie idzie
 * natychmiast i osobno. To jest ważniejsze niż sama liczba listów: globalny
 * limit „N listów na godzinę" zagłuszyłby nową awarię tylko dlatego, że
 * stara jeszcze trwa.
 *
 * WYCISZENIE NALEŻY SIĘ ZA LIST, KTÓRY POSZEDŁ. Przy nieudanej wysyłce okno
 * jest oddawane (`Cache::forget`) — inaczej jedna sekunda niedostępności
 * EmailLabs kupowałaby kwadrans ciszy o trwającej awarii. Ta sama poprawka,
 * co w `HealthController` po issue #33.
 *
 * REKORD BEZ WYJĄTKU NIE PODLEGA WYCISZANIU I NIE ZAPISUJE PAMIĘCI. Odcisku
 * nie ma z czego policzyć, ale przede wszystkim: tą drogą idą wpisy, które
 * mają SWOJE wyciszanie po stronie wołającego (`/health`, czujki) albo nie
 * mają go mieć w ogóle (`kuking:sprawdz-alarm`). Gdyby próba kanału
 * zapisywała tu okno, zagłuszyłaby prawdziwy alarm idący zaraz po niej —
 * czyli komenda sprawdzająca, czy alarm dochodzi, sama by go zepsuła.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  3. SYNCHRONICZNIE, NIE KOLEJKĄ
 * ──────────────────────────────────────────────────────────────────────────
 * Kolejka byłaby wydajniejsza i nie przetrzymywałaby odpowiedzi HTTP dla
 * człowieka, który trafił na awarię. Mimo to alarm idzie SYNCHRONICZNIE,
 * bo kolejka jest jedną z rzeczy, o których ten alarm ma zawiadamiać:
 * „nieudane zadania w kolejce" i „kolejka nie mieli" są na liście awarii
 * z #599. Alarm wrzucony do kolejki, która stoi, to alarm, który nie
 * wyjdzie — i to dokładnie wtedy, kiedy jest najbardziej potrzebny. Kanał
 * alarmowy nie ma prawa zależeć od żadnego elementu, który sam monitoruje.
 *
 * Kosztem jest czas w ścieżce błędu 500. Jest on ograniczony: wysyłka idzie
 * jednym żądaniem HTTP do EmailLabs z własnym timeoutem transportu, a okno
 * wyciszania sprawia, że przy fali błędów płaci za nią JEDNO żądanie na
 * kwadrans, a nie każde.
 *
 * ──────────────────────────────────────────────────────────────────────────
 *  4. NADAWCA I ODBIORCA
 * ──────────────────────────────────────────────────────────────────────────
 * ODBIORCA to NOWA zmienna `LOG_BLAD_EMAIL`, osobna od istniejącego
 * `KUKING_MODEL_ALARM_EMAIL`. Ujednolicanie byłoby błędem: tamten adres to
 * skrzynka MODERATORA i niesie treści zgłoszone przez ludzi (`AlarmujModeratora`,
 * `kuking:podsumowanie-automatu`), ten niesie awarie infrastruktury i nie
 * niesie ani jednego znaku od użytkownika. Mają różnych adresatów w czasie
 * (moderatorów przybędzie, operator zostanie jeden), różną pilność i różny
 * los w skrzynce — reguła „moderacja → do folderu", założona pierwszego dnia
 * po fali zgłoszeń, zjadłaby razem z nimi alarm o leżącej bazie. Rozdzielone
 * zmienne można w każdej chwili ustawić na ten sam adres; sklejonych nie da
 * się rozdzielić bez zmiany kodu.
 *
 * NADAWCA to istniejący `MAIL_FROM_ADDRESS` (`config/mail.php`) i NIE
 * dostaje własnej zmiennej. EmailLabs wysyła wyłącznie z zweryfikowanej
 * domeny nadawcy; osobny, niezweryfikowany adres nadawcy dla alarmów
 * znaczyłby tyle, że alarmy — i tylko alarmy — byłyby odrzucane, a dowiedzieć
 * się o tym można by dopiero z alarmu, który nie przyszedł.
 */
final class EmailBleduHandler extends AbstractProcessingHandler
{
    /**
     * Ile trwa cisza po liście o TEJ SAMEJ awarii. Kwadrans to kompromis:
     * dość długo, żeby trwająca awaria nie zapchała skrzynki, i dość krótko,
     * żeby przy niezauważonym pierwszym liście przyszedł drugi.
     */
    private const OKNO_WYCISZENIA_MINUT = 15;

    private const PRZEDROSTEK_KLUCZA = 'alarm:email:odstep:';

    /**
     * ZAPORA PONOWNEGO WEJŚCIA — patrz punkt 1 w komentarzu klasy.
     * Statyczna, nie polowa, bo pętla może przebiec przez DRUGI egzemplarz
     * handlera (kanał bywa budowany na nowo po `Log::forgetChannel()`).
     */
    private static bool $wSrodkuWysylki = false;

    private static ?bool $ostatniaWysylkaSieUdala = null;

    public function __construct(private readonly ?string $adres, Level $level)
    {
        parent::__construct($level);
    }

    /**
     * Czy OSTATNI list z tego kanału poszedł. Ta sama umowa, co
     * `WebhookBleduHandler::ostatniaWysylkaSieUdala()`: `null` = nie było
     * próby w tym procesie, `false` = była i się nie udała.
     */
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
        if ($this->adres === null) {
            // Brak `LOG_BLAD_EMAIL` = kanał wyłączony. DRUGA linia obrony,
            // po sprawdzeniu w `KanalyAlarmowe`.
            return;
        }

        if (self::$wSrodkuWysylki) {
            // Punkt 1: jesteśmy wywołani Z WNĘTRZA wysyłki alarmu. Cokolwiek
            // to jest, nie pojedzie pocztą. Bez tego wiersza awaria poczty
            // alarmowałaby o sobie pocztą, w kółko.
            return;
        }

        $klucz = $this->kluczWyciszenia($record);

        if ($klucz !== null && ! $this->wolnoZadzwonic($klucz)) {
            // Punkt 2: ta sama awaria już dziś dzwoniła. Cisza jest tu
            // POPRAWNYM zachowaniem, więc `$ostatniaWysylkaSieUdala` zostaje
            // nietknięte — wyciszony duplikat to nie jest nieudana wysyłka.
            return;
        }

        self::$wSrodkuWysylki = true;

        try {
            // BEZ KOLEJKI (punkt 3). `Mail::raw()` wysyła tu i teraz.
            // Treść jest CZYSTYM TEKSTEM, nie widokiem Blade: widok
            // wymagałby działającego renderowania i katalogu `storage`,
            // czyli rzeczy, które w czasie awarii bywają właśnie zepsute,
            // a przy okazji dawałby komuś kiedyś okazję wstawić do szablonu
            // `$record->context`.
            Mail::raw(TrescAlarmu::tresc($record), function (Message $list) use ($record): void {
                $list->to($this->adres)->subject(TrescAlarmu::temat($record));
            });

            self::$ostatniaWysylkaSieUdala = true;
        } catch (Throwable $e) {
            self::$ostatniaWysylkaSieUdala = false;

            // WYCISZENIE NALEŻY SIĘ ZA LIST, KTÓRY POSZEDŁ. Ten nie poszedł,
            // więc oddajemy okno — następne wystąpienie tej awarii zadzwoni
            // jeszcze raz, zamiast wpaść w kwadrans ciszy kupiony przez
            // sekundę niedostępności EmailLabs.
            if ($klucz !== null) {
                $this->oddajOkno($klucz);
            }

            $this->zapiszNiedodzwonienie($e::class);
        } finally {
            // `finally`, nie koniec `try`: gdyby kiedyś ktoś dołożył tu
            // `throw`, zapora zostałaby zamknięta na zawsze w tym procesie
            // i kanał zamilkłby bez śladu.
            self::$wSrodkuWysylki = false;
        }
    }

    /**
     * `null` = ten rekord NIE PODLEGA wyciszaniu (brak obiektu wyjątku:
     * próba kanału, `/health`, czujki — patrz punkt 2 w komentarzu klasy).
     */
    private function kluczWyciszenia(LogRecord $record): ?string
    {
        $odcisk = TrescAlarmu::odciskRekordu($record);

        return $odcisk === null ? null : self::PRZEDROSTEK_KLUCZA.$odcisk;
    }

    /**
     * `Cache::add()` oddaje `true` tylko za PIERWSZYM razem w oknie — to jest
     * cały mechanizm.
     *
     * AWARIA SAMEGO CACHE'A PRZEPUSZCZA LIST, a nie go blokuje. To jest
     * świadomy wybór kierunku pomyłki: cache bywa jedną z rzeczy, które
     * właśnie padły, a wtedy „nie wiem, czy już dzwoniłem" musi znaczyć
     * „dzwoń". Zbyt wiele listów jest usterką; brak listu o leżącym serwisie
     * jest tym, przed czym cały ten kanał ma bronić.
     */
    private function wolnoZadzwonic(string $klucz): bool
    {
        try {
            return Cache::add($klucz, true, now()->addMinutes(self::OKNO_WYCISZENIA_MINUT));
        } catch (Throwable) {
            return true;
        }
    }

    private function oddajOkno(string $klucz): void
    {
        try {
            Cache::forget($klucz);
        } catch (Throwable) {
            // Cache nie działa — patrz `wolnoZadzwonic()`. Nie ma tu nic
            // do uratowania i na pewno nie ma czego rzucać.
        }
    }

    /**
     * Zapisuje sam FAKT, że list nie poszedł — do dziennika serwera, NIGDY
     * na kanał alarmowy.
     *
     * DLACZEGO JAWNIE `single`, A NIE `Log::error()`: bo domyślny stos może
     * kiedyś zawierać ten kanał (`LOG_STACK`), a wpis o nieudanej wysyłce
     * listu, wysyłany listem, jest pętlą z punktu 1. `single` to plik na
     * serwerze i nic więcej.
     *
     * W TREŚCI JEST WYŁĄCZNIE nazwa klasy wyjątku. Ani adresu skrzynki (jest
     * sekretem tak samo jak adres webhooka), ani komunikatu wyjątku
     * transportu (potrafi wnieść w siebie adres, poświadczenia SMTP i całą
     * odpowiedź serwera poczty), ani treści listu, który nie doszedł.
     */
    private function zapiszNiedodzwonienie(string $powod): void
    {
        try {
            Log::channel('single')->error(
                'Nie udało się wysłać listu z alarmem. Wiadomość przepadła.',
                ['powod' => $powod],
            );
        } catch (Throwable) {
            // Jeśli nie da się zapisać nawet do pliku, to nie jest już nasza
            // sprawa — a rzucenie stąd wywróciłoby raportowanie wyjątku.
        }
    }
}
