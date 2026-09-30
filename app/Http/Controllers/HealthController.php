<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Logging\BezpiecznyBlad;
use App\Logging\KanalyAlarmowe;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;
use App\Support\Zdrowie\Sondy\SondaAnalityki;
use App\Support\Zdrowie\Sondy\SondaBazy;
use App\Support\Zdrowie\Sondy\SondaCiasteczkaSesji;
use App\Support\Zdrowie\Sondy\SondaCzyszczeniaCdn;
use App\Support\Zdrowie\Sondy\SondaFacebooka;
use App\Support\Zdrowie\Sondy\SondaGoogle;
use App\Support\Zdrowie\Sondy\SondaHostaMagazynu;
use App\Support\Zdrowie\Sondy\SondaKolejki;
use App\Support\Zdrowie\Sondy\SondaMagazynuZdjec;
use App\Support\Zdrowie\Sondy\SondaMigracji;
use App\Support\Zdrowie\Sondy\SondaNieudanychListow;
use App\Support\Zdrowie\Sondy\SondaPilnychAlarmow;
use App\Support\Zdrowie\Sondy\SondaPoczty;
use App\Support\Zdrowie\Sondy\SondaTrybuDebug;
use App\Support\Zdrowie\Sondy\SondaTurnstile;
use App\Support\Zdrowie\Sondy\SondaZaleglychCzyszczenCdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * /health — punkt kontrolny dla Railway i monitoringu zewnętrznego.
 *
 * Sprawdza to, czego brak realnie kładzie serwis. Awaria poczty, kolejki
 * czy Turnstile NIGDY nie oddaje 503 — inaczej healthcheck restartowałby
 * kontener z powodu problemu, którego restart nie naprawia (dokładnie tak,
 * jak niżej opisana historia z dyskiem zdjęć). Te sprawdzenia idą do pola
 * `checks` i do `status: degraded`, nie do kodu HTTP.
 *
 * DWA POZIOMY, I TO NIE JEST OZDOBNIK
 * `database` i `migrations` są KRYTYCZNE: bez nich nie da się wyświetlić
 * niczego, więc ich awaria oddaje HTTP 503 i Railway ma prawo restartować.
 *
 * `media` NIE JEST krytyczne — i to jest decyzja podjęta świadomie, po tym,
 * jak healthcheck oddający 503 potrafił już położyć ten serwis. Serwis
 * z działającymi wpisami i połamanymi zdjęciami jest o wiele lepszy niż
 * serwis w pętli restartów. Dlatego awaria dysku daje HTTP 200 z polem
 * `status: degraded` — monitoring ma pilnować TREŚCI odpowiedzi, nie tylko
 * kodu HTTP.
 *
 * `turnstile` jest tu z tego samego, NIEKRYTYCZNEGO powodu i odpowiada na
 * inne pytanie niż pozostałe dwa: nie „czy coś padło", a „czy konfiguracja
 * nie kłamie" (D-050). Brak kluczy Turnstile nic nie psuje — i właśnie
 * dlatego bez tego sygnału nikt by go nie zauważył.
 *
 * `google` i `facebook` zadają DOKŁADNIE TO SAMO pytanie o dwie dodatkowe
 * drogi wejścia (issue #258 i #259): funkcja włączona w konfiguracji plus
 * brak kluczy = przycisku nie ma na ekranie i wygląda to identycznie jak
 * poprawne wdrożenie bez tej drogi. Cicha, nieistniejąca droga wejścia jest
 * gorsza niż wyłączona — dlatego oba sygnały są osobne, po jednym na
 * dostawcę, bo naprawa każdego z nich to inny panel i inna czynność.
 *
 * `analityka` pyta o to samo co te dwie wyżej, tylko o rzecz, której NIE DA
 * SIĘ zobaczyć okiem na żadnym ekranie — i o rozjazd z dokumentem PRAWNYM,
 * nie z konfiguracją. Polityka prywatności mówi czytelnikowi w czasie
 * teraźniejszym, że statystykę odwiedzin prowadzi Cloudflare Web Analytics;
 * bez `CLOUDFLARE_ANALYTICS_TOKEN` beacona nie ma w HTML-u wcale, panel
 * Cloudflare świeci zerami, a dokument opisuje przetwarzanie, którego nie ma.
 * Brak przycisku Google widać na `/login`; tego nie widać nigdzie i dlatego
 * ten sygnał jest tu potrzebny bardziej niż tamte. Uciszają go dwie uczciwe
 * czynności — wpisanie tokenu albo wykreślenie obietnicy z polityki —
 * i świadomie nie ma trzeciej (patrz `SondaAnalityki`).
 *
 * `poczta`, `kolejka` i `listy` są tu z tego samego, NIEKRYTYCZNEGO powodu
 * i pytają o trzy RÓŻNE rzeczy — kolejność jest od najwcześniejszej:
 *
 *  - `poczta` — „czy wysyłka ma w ogóle czym ruszyć" (`MAIL_MAILER` na
 *    produkcji, `App\Support\Poczta`). Awaria SPRZED pierwszego listu;
 *  - `kolejka` — „czy w `failed_jobs` cokolwiek leży" (D-057 §4). Dotyczy
 *    WSZYSTKICH zadań: zdjęć, eksportów, listów;
 *  - `listy` — „czy komuś nie doszedł LIST, o którym jeszcze nie wiesz"
 *    (issue #234, D-062, tabela `mail_failures`).
 *
 * DWIE OSTATNIE ZAPALAJĄ SIĘ RAZEM PRZY PRZEPADŁYM LIŚCIE I TAK MA BYĆ.
 * Jeden przegrany list zostawia wiersz w `failed_jobs` (zapala `kolejka`)
 * ORAZ wiersz w `mail_failures` (zapala `listy`). Nie jest to dublowanie,
 * bo te dwie sondy gasną w innych momentach i to jest cała różnica:
 * `queue:retry` albo `queue:flush` czyści `failed_jobs`, więc `kolejka`
 * robi się zielona od razu — a `listy` świecą, dopóki człowiek nie odhaczy
 * (`php artisan kuking:nieudane-listy --odhacz`). Świadomie BEZ okna
 * czasowego: alarm, który gaśnie sam, zamienia awarię z nocy w niewidzialną
 * awarię o świcie.
 *
 * PO CO W OGÓLE SPRAWDZAĆ ZDJĘCIA
 * Katalog ze zdjęciami stał kiedyś na dysku kontenera, który Railway kasuje
 * przy każdym wdrożeniu. Wszystkie wgrane zdjęcia przepadły, a `/health`
 * meldował „ok", bo baza była cała. Awaria dotyczyła GŁÓWNEJ akcji produktu
 * („zdjęcie + kilka słów") i nie zauważył jej żaden automat — dopiero
 * człowiek, który zobaczył ikony zepsutych obrazków.
 *
 * TA ODPOWIEDŹ JEST PUBLICZNA
 * Trasa nie ma `auth` i mieć nie może: Railway odpytuje ją z zewnątrz, zanim
 * cokolwiek się zaloguje. Wszystko, co tu wkładamy, czyta więc dowolna osoba
 * w internecie — łącznie z osobą, która właśnie szuka, po czym uderzyć.
 * Dlatego pole `error` to KOD z `POWODY`, a nie `$e->getMessage()`; ten drugi
 * przy awarii bazy zawiera adres hosta, port, nazwę bazy i nazwę użytkownika
 * z komunikatu PDO. Szczegół techniczny zostaje w logu (`Log::error` niżej),
 * gdzie ma dostęp do niego wyłącznie właściciel.
 *
 * OD AUDYTU A5-05 PUBLICZNIE WYCHODZI TYLKO KOD HTTP I `status`
 * Nawet same KODY z `POWODY` mówiły za dużo: `turnstile_bez_kluczy` albo
 * `limit_poczty_wyczerpany` to dokładnie chwila, w której warto uderzyć
 * w formularze. Pole `checks` dostaje więc tylko zapytanie z tokenem
 * w nagłówku `NAGLOWEK_TOKENU` (`config/kuking.php`, `health.token`).
 * Kod 200/503 i `status` zostają dla wszystkich — z nich korzysta Railway,
 * test dymny wdrożenia i `scripts/sprawdz-wdrozenie.sh`. Do tego limit
 * zapytań po adresie (`limits.health`) i krótka pamięć udanej próbki
 * magazynu (`health.probka_magazynu_sekund`), żeby pętla `curl` nie
 * zamieniała się w zapisy do R2. Pilnuje tego `HealthSzczegolyTylkoZTokenemTest`.
 *
 * KONTROLER SKŁADA, SONDY MIERZĄ (issue #2212)
 * Każda z szesnastu kontroli to osobna klasa z jednym kontraktem
 * (`App\Support\Zdrowie\Sonda`, katalog `App\Support\Zdrowie\Sondy`), a kody
 * powodów żyją w `App\Support\Zdrowie\Powody`. Tu zostaje to, co wspólne:
 * limit zapytań, KOLEJNOŚĆ kluczy `checks`, kod HTTP, token, `check()`
 * (awaria na kod, nigdy na komunikat) i webhook z odstępem. Kształt odpowiedzi
 * zamraża `HealthKontraktOdpowiedziTest`.
 */
class HealthController extends Controller
{
    /**
     * Sprawdzenia, których niepowodzenie oddaje 503 i pozwala Railway
     * restartować kontener. Wszystko poza tą listą tylko raportujemy.
     */
    private const KRYTYCZNE = ['database', 'migrations'];

    /**
     * Zamknięty zbiór powodów, które WOLNO pokazać publicznie w polu `error`
     * (definicje i uzasadnienie: `App\Support\Zdrowie\Powody`).
     *
     * `HealthNieZdradzaSzczegolowTest` pilnuje, że w odpowiedzi nie pojawi
     * się nic spoza tego zbioru.
     */
    public const POWODY = Powody::WSZYSTKIE;

    /**
     * Ile minut milczymy na webhooku o TEJ SAMEJ nazwanej kontroli, zanim
     * wyślemy kolejne powiadomienie. Bez tego zewnętrzny monitoring odpytujący
     * `/health` co kilka minut zamieniłby jedną trwającą awarię w dzwonek
     * bez końca, aż ktoś wyciszy cały kanał (patrz `powiadomWebhook()`).
     */
    private const WEBHOOK_ODSTEP_MINUT = 30;

    /** Nagłówek z tokenem, który odsłania pole `checks` (audyt A5-05). */
    public const NAGLOWEK_TOKENU = 'X-Kuking-Health-Token';

    public function __invoke(Request $request): JsonResponse
    {
        $zaDuzo = $this->limitPrzekroczony($request);

        if ($zaDuzo !== null) {
            return response()->json(
                ['message' => 'Za dużo zapytań. Spróbuj ponownie za chwilę.'],
                429,
                ['Retry-After' => (string) $zaDuzo],
            );
        }

        $checks = [];

        foreach ($this->sondy() as $sonda) {
            $checks[$sonda->nazwa()] = $this->check($sonda);
        }

        $krytyczneOk = ! in_array(
            false,
            array_column(array_intersect_key($checks, array_flip(self::KRYTYCZNE)), 'ok'),
            true,
        );

        $wszystkoOk = ! in_array(false, array_column($checks, 'ok'), true);

        $odpowiedz = [
            'status' => $wszystkoOk ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'time' => now()->toIso8601String(),
        ];

        // Kod HTTP i `status` dla każdego, `checks` tylko z tokenem (A5-05).
        if ($this->maTokenSzczegolow($request)) {
            $odpowiedz['checks'] = $checks;
        }

        return response()->json($odpowiedz, $krytyczneOk ? 200 : 503);
    }

    /**
     * Sekundy do ponowienia, gdy ten adres pyta za często — albo null.
     *
     * Licznik leży w cache w bazie. Gdy baza nie odpowiada, liczenie się nie
     * uda i pytanie PRZECHODZI: healthcheck ma wtedy oddać 503 z `status`,
     * a nie 500 z wyjątku limitera (`config/kuking.php`, `limits.health`).
     */
    private function limitPrzekroczony(Request $request): ?int
    {
        [$ile, $minut] = array_map('intval', explode(',', (string) config('kuking.limits.health')));
        $klucz = 'health|'.$request->ip();

        try {
            if (RateLimiter::tooManyAttempts($klucz, $ile)) {
                return max(1, RateLimiter::availableIn($klucz));
            }

            RateLimiter::hit($klucz, $minut * 60);
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function maTokenSzczegolow(Request $request): bool
    {
        $token = config('kuking.health.token');
        $podany = $request->header(self::NAGLOWEK_TOKENU);

        return is_string($token) && $token !== ''
            && is_string($podany) && hash_equals($token, $podany);
    }

    /**
     * Sondy w KOLEJNOŚCI, w jakiej trafiają do `checks` (kolejność jest częścią
     * kontraktu odpowiedzi — pilnuje jej `HealthKontraktOdpowiedziTest`).
     *
     * @return list<Sonda>
     */
    private function sondy(): array
    {
        return [
            new SondaBazy,
            new SondaMigracji,
            new SondaMagazynuZdjec,
            new SondaTurnstile,
            new SondaGoogle,
            new SondaFacebooka,
            new SondaAnalityki,
            new SondaPoczty,
            new SondaKolejki,
            new SondaNieudanychListow,
            new SondaCzyszczeniaCdn,
            new SondaPilnychAlarmow,
            new SondaZaleglychCzyszczenCdn,
            new SondaHostaMagazynu,
            new SondaTrybuDebug,
            new SondaCiasteczkaSesji,
        ];
    }

    /**
     * Uruchamia jedną sondę i zamienia jej awarię na KOD, nigdy na komunikat.
     *
     * `Sonda::powodDomyslny()` dotyczy wyjątków, których sonda nie rozpoznała sama —
     * np. `QueryException` z sondy bazy. Zgadywanie powodu z treści takiego
     * komunikatu byłoby kruche dokładnie tak, jak w audycie W7-07, więc
     * zamiast tego każda sonda z góry deklaruje, co znaczy jego
     * „coś poszło nie tak".
     *
     * @return array{ok: bool, error?: string}
     */
    private function check(Sonda $sonda): array
    {
        $nazwa = $sonda->nazwa();

        try {
            $sonda->sprawdz();

            // Powrót do zdrowia kasuje odstęp webhooka — kolejna awaria TEJ
            // SAMEJ kontroli (nawet chwilę później) ma prawo zadzwonić od
            // razu, zamiast czekać do końca okna z poprzedniego incydentu.
            // Warunek pomija zapis do cache'a na NAJCZĘSTSZEJ ścieżce (zdrowy
            // serwis, kanał wyłączony) — każde wywołanie `/health` sprawdza
            // dziesięć kontroli, a bez tego warunku każda zdrowa odpowiedź
            // dokładałaby dziesięć zbędnych zapisów do tabeli `cache`.
            if (KanalyAlarmowe::wlaczony()) {
                Cache::forget($this->kluczOdstepuWebhooka($nazwa));
            }

            return ['ok' => true];
        } catch (Throwable $e) {
            $powod = $e instanceof KontrolaZdrowiaNieprzeszla ? $e->kod : $sonda->powodDomyslny();

            // Kiedyś tu szła pełna treść wyjątku („sondy nie dotykają
            // niczyich danych"). Ale komunikat buduje sterownik bazy, klient
            // storage albo transport poczty — z hostem, użytkownikiem bazy,
            // kluczem pliku próbnego — i idzie to na stderr, który czyta
            // Railway (#973). Zostaje kod powodu, klasa, klasy przyczyn
            // i odcisk; powód szczegółowy i tak niesie `powod`.
            Log::error('Kontrola /health nie przeszła.', [
                'kontrola' => $nazwa,
                'powod' => $powod,
                'wyjatek' => $e::class,
                'blad' => BezpiecznyBlad::kontekst($e),
            ]);

            $this->powiadomWebhook($nazwa, $powod);

            return ['ok' => false, 'error' => $powod];
        }
    }

    /**
     * Dzwoni na kanał `blad_webhook` (`config/logging.php`, D-041) o awarii
     * WYKRYTEJ TYLKO PRZEZ `/health` — Turnstile bez kluczy, poczta bez
     * transportu, zadania w `failed_jobs`, brak migracji — czyli o rzeczach,
     * które (w odróżnieniu od 500-tki na żywej trasie) nie rzucają wyjątku,
     * którego złapałby `$exceptions->report()` w `bootstrap/app.php`. Bez
     * tego wywołania jedynym sposobem, żeby ktoś się o nich dowiedział, było
     * ręczne otwarcie `/health` albo logów Railway.
     *
     * DLACZEGO Z ODSTĘPEM, A NIE PRZY KAŻDYM WYWOŁANIU
     * `/health` odpytuje zewnętrzny monitoring co kilka minut z założenia
     * (`docs/infra/INFRA_DECISION.md`) — bez ograniczenia trwająca dobę
     * awaria wysłałaby setki identycznych wiadomości, aż ktoś wyciszyłby
     * cały kanał (ta sama krzywda, przed którą Sentry broni grupowaniem —
     * a Sentry'ego w tym projekcie nie ma, D-041). `Cache::add()` zwraca
     * `true` tylko za pierwszym razem w oknie `WEBHOOK_ODSTEP_MINUT` — każde
     * kolejne wywołanie w tym oknie jest ciche. Klucz jest per NAZWA kontroli,
     * nie per treść: dwie różne awarie tej samej kontroli w krótkim odstępie
     * nadal liczą się jako jedna.
     *
     * DLACZEGO BEZPIECZNIE MILCZY BEZ SKONFIGUROWANEGO ADRESU
     * Ten sam warunek co w `bootstrap/app.php` — bez `LOG_BLAD_WEBHOOK_URL`
     * `Cache::add()` w ogóle się nie woła, więc healthcheck (odpytywany dużo
     * częściej niż realne błędy 500) nie dokłada zbędnego zapisu do cache'a
     * w najczęstszej, zdrowej ścieżce.
     *
     * Treść, która wychodzi na zewnątrz, to WYŁĄCZNIE nazwa kontroli i kod
     * z zamkniętego zbioru `POWODY` — ten sam kod, co w publicznej odpowiedzi
     * JSON. Żadnego komunikatu wyjątku, żadnej treści z `failed_jobs`.
     */
    private function powiadomWebhook(string $nazwa, string $powod): void
    {
        if (! KanalyAlarmowe::wlaczony()) {
            return;
        }

        if (! Cache::add($this->kluczOdstepuWebhooka($nazwa), true, now()->addMinutes(self::WEBHOOK_ODSTEP_MINUT))) {
            return;
        }

        // Discord i poczta (#599), każdy osobno; wynik = czy KTÓRYKOLWIEK
        // przyjął. `zadzwon()` zaczyna od czystej kartki: w jednym żądaniu
        // `/health` dzwonimy nawet kilka razy (osobny odstęp na kontrolę).
        $przyjeto = KanalyAlarmowe::zadzwon("/health: kontrola „{$nazwa}” nie przeszła (powód: {$powod}).");

        // ────────────────────────────────────────────────────────────────
        //  CISZA NA POŁ GODZINY NALEŻY SIĘ ZA DZWONEK, KTÓRY ZADZWONIŁ
        // ────────────────────────────────────────────────────────────────
        //
        // `WebhookBleduHandler::write()` świadomie NIE RZUCA, gdy wysyłka się
        // nie uda (uzasadnienie w jego komentarzu klasy), a nieudane żądanie
        // HTTP i tak nie rzuca samo — 401 z odwołanego webhooka Discorda
        // wraca jako zwykła odpowiedź. Bez tego warunku wyglądałoby to więc
        // tak: pierwsza próba wpada w trzysekundową niedostępność kanału,
        // wiadomość przepada, a odstęp jest już zajęty — czyli JEDNA sekunda
        // pecha kupuje pół godziny ciszy o trwającej awarii. To jest ta sama
        // klasa usterki co cały ten endpoint miał naprawiać.
        //
        // Dlatego przy nieudanym dzwonku oddajemy odstęp: następne odpytanie
        // `/health` (monitoring pyta co kilka minut) zadzwoni jeszcze raz.
        // Sam fakt niedodzwonienia się zostaje w dzienniku serwera — zapisuje
        // go handler.
        if (! $przyjeto) {
            Cache::forget($this->kluczOdstepuWebhooka($nazwa));
        }
    }

    private function kluczOdstepuWebhooka(string $nazwa): string
    {
        return 'health:webhook_odstep:'.$nazwa;
    }
}
