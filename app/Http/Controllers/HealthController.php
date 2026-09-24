<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Logging\BezpiecznyBlad;
use App\Logging\WebhookBleduHandler;
use App\Models\MailFailure;
use App\Poczta\PowodOdmowy;
use App\Support\AnalitykaCloudflare;
use App\Support\Facebook;
use App\Support\Google;
use App\Support\Poczta;
use App\Support\Storage\DozwolonyHostR2;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * i świadomie nie ma trzeciej (patrz `sprawdzAnalityke()`).
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
 */
class HealthController extends Controller
{
    /**
     * Sprawdzenia, których niepowodzenie oddaje 503 i pozwala Railway
     * restartować kontener. Wszystko poza tą listą tylko raportujemy.
     */
    private const KRYTYCZNE = ['database', 'migrations'];

    /**
     * Zamknięty zbiór powodów, które WOLNO pokazać publicznie w polu `error`.
     *
     * Każdy z nich mówi operatorowi, gdzie szukać, i nie mówi nikomu innemu
     * nic o infrastrukturze: żadnego hosta, portu, nazwy bazy, ścieżki na
     * dysku ani kodu SQLSTATE. Reszta — z pełnym komunikatem wyjątku — idzie
     * do logu pod tym samym kodem, więc jedno da się połączyć z drugim.
     *
     * `HealthNieZdradzaSzczegolowTest` pilnuje, że w odpowiedzi nie pojawi
     * się nic spoza tego zbioru.
     */
    public const POWODY = [
        self::POWOD_BAZA,
        self::POWOD_BRAK_MIGRACJI,
        self::POWOD_ZDJECIA,
        self::POWOD_ZAPIS_NIEMOZLIWY,
        self::POWOD_ODCZYT_NIEZGODNY,
        self::POWOD_BRAK_DROGI_PUBLICZNEJ,
        self::POWOD_DROGA_GDZIE_INDZIEJ,
        self::POWOD_TURNSTILE_BEZ_KLUCZY,
        self::POWOD_GOOGLE_BEZ_KLUCZY,
        self::POWOD_FACEBOOK_BEZ_KLUCZY,
        self::POWOD_ANALITYKA_BEZ_TOKENU,
        self::POWOD_POCZTA_NIE_WYSYLA,
        self::POWOD_ZADANIA_NIEUDANE,
        self::POWOD_LISTY_PRZEPADAJA,
        self::POWOD_LIMIT_POCZTY_WYCZERPANY,
        self::POWOD_SLAD_LISTOW_NIESPRAWDZALNY,
        self::POWOD_CZYSZCZENIE_CDN_WYLACZONE,
        self::POWOD_MAGAZYN_ZLY_HOST,
    ];

    /** Baza nie odpowiada albo odpowiada błędem — powód domyślny obu krytycznych sprawdzeń. */
    private const POWOD_BAZA = 'baza_nie_odpowiada';

    /** Połączenie z bazą jest, ale tabela `migrations` jest pusta — deploy nie dokończył migracji. */
    private const POWOD_BRAK_MIGRACJI = 'brak_migracji';

    /** Worek na resztę awarii dysku ze zdjęciami — powód domyślny sprawdzenia `media`. */
    private const POWOD_ZDJECIA = 'zdjecia_niedostepne';

    /** Nie udało się zapisać pliku próbnego na dysku ze zdjęciami. */
    private const POWOD_ZAPIS_NIEMOZLIWY = 'zapis_niemozliwy';

    /** Zapis się udał, a odczyt zwrócił co innego albo się nie udał. */
    private const POWOD_ODCZYT_NIEZGODNY = 'odczyt_niezgodny';

    /** Brak `public/storage` — zdjęcia są na dysku, ale przeglądarka dostaje 404. */
    private const POWOD_BRAK_DROGI_PUBLICZNEJ = 'brak_drogi_publicznej';

    /** `public/storage` istnieje, ale prowadzi do innego katalogu niż dysk ze zdjęciami. */
    private const POWOD_DROGA_GDZIE_INDZIEJ = 'droga_publiczna_gdzie_indziej';

    /**
     * Turnstile jest włączony w konfiguracji, ale nie ma kluczy — na produkcji
     * to znaczy, że formularze publiczne stoją bez zapowiedzianej ochrony
     * (D-050). Kod bez nazwy zmiennej i bez fragmentu klucza: ta odpowiedź
     * jest publiczna.
     */
    private const POWOD_TURNSTILE_BEZ_KLUCZY = 'turnstile_bez_kluczy';

    /**
     * Wejście kontem Google jest włączone w konfiguracji, ale nie ma kluczy —
     * na produkcji to znaczy, że obiecanej drogi wejścia NIE MA, a nikt się
     * o tym nie dowie (D-069, issue #258). Kod bez nazwy zmiennej i bez
     * fragmentu klucza: ta odpowiedź jest publiczna.
     */
    private const POWOD_GOOGLE_BEZ_KLUCZY = 'google_bez_kluczy';

    /**
     * To samo dla Facebooka (issue #259). Osobny kod, bo osobna czynność
     * człowieka i osobny panel: Google Cloud Console to nie jest panel Meta,
     * a jeden wspólny powód „oauth_bez_kluczy" kazałby operatorowi zgadywać,
     * którego z dwóch dostawców szukać.
     */
    private const POWOD_FACEBOOK_BEZ_KLUCZY = 'facebook_bez_kluczy';

    /**
     * Polityka prywatności obiecuje analitykę odwiedzin, a tokenu nie ma —
     * czyli dokument PRAWNY opisuje przetwarzanie, którego nie ma (D-092).
     * Kod bez nazwy zmiennej i bez fragmentu tokenu: ta odpowiedź jest
     * publiczna. Sam token nie jest sekretem (stoi w HTML-u każdej strony),
     * ale nazwa zmiennej i odnośnik do runbooka to wskazówka dla kogoś, kto
     * szuka, po czym uderzyć — zostają w logu.
     */
    private const POWOD_ANALITYKA_BEZ_TOKENU = 'analityka_bez_tokenu';

    /**
     * `MAIL_MAILER` na produkcji to `log`/`array`/pusty, albo Laravel nie
     * potrafi w ogóle zbudować transportu — dokładnie to, o czym mówi
     * `App\Support\Poczta` (issue #234 obok liczy `failed_jobs`; to
     * sprawdzenie pyta o coś wcześniejszego: czy wysyłka ma w ogóle czym
     * ruszyć). Ten kod nigdy nie niesie treści wyjątku dostawcy — patrz
     * `sprawdzPoczte()`.
     */
    private const POWOD_POCZTA_NIE_WYSYLA = 'poczta_nie_wysyla';

    /** W `failed_jobs` leżą nieudane zadania kolejki, a nikt sam z siebie się o tym nie dowiaduje (D-057 §4). */
    private const POWOD_ZADANIA_NIEUDANE = 'zadania_nieudane';

    /**
     * W `mail_failures` leży co najmniej jeden nieodhaczony list, czyli
     * wiadomość do człowieka, która nie wyszła i nie wyjdzie (issue #234).
     */
    private const POWOD_LISTY_PRZEPADAJA = 'listy_przepadaja';

    /**
     * To samo, ale z powodu wyczerpanego dobowego limitu u dostawcy — osobny
     * kod, bo osobna czynność człowieka: nie ma czego naprawiać w kodzie,
     * trzeba poczekać do północy albo zmienić plan. Monitoring zewnętrzny
     * odróżnia więc „coś się psuje" od „skończyła się pula".
     */
    private const POWOD_LIMIT_POCZTY_WYCZERPANY = 'limit_poczty_wyczerpany';

    /**
     * Nie dało się sprawdzić śladu — najczęściej tabeli `mail_failures`
     * jeszcze nie ma, bo kod wdrożył się przed migracją. Osobny kod, żeby
     * brak tabeli nie meldował się jako „listy przepadają": alarm o awarii,
     * której nie ma, jest tak samo szkodliwy jak cisza o awarii, która jest.
     */
    private const POWOD_SLAD_LISTOW_NIESPRAWDZALNY = 'slad_listow_niesprawdzalny';

    /**
     * `CLOUDFLARE_ZONE_ID` albo `CLOUDFLARE_PURGE_TOKEN` jest pusty, więc
     * `App\Jobs\PurgePublicMediaCache` wychodzi na `return` i NIC nie czyści.
     *
     * DLACZEGO TO MUSI STAĆ TUTAJ, A NIE TYLKO W LOGU ZADANIA
     * Zadanie zapisuje wtedy `Log::warning` i kończy się SUKCESEM: nie ma
     * wpisu w `failed_jobs`, nic nie jest czerwone, a kanał alarmowy
     * (`blad_webhook`) ma poziom ustawiony na sztywno na `error`, więc
     * ostrzeżenia w ogóle nie przyjmuje. Czyszczenie wyłączone wygląda więc
     * DOKŁADNIE tak samo jak czyszczenie, które działa — a to jest ten sam
     * rodzaj cichej porażki, co `turnstile_bez_kluczy` i `analityka_bez_tokenu`
     * (D-050): konfiguracja nie kłamie o awarii, tylko o tym, że coś JEST.
     *
     * ŚWIADOMIE `degraded`, A NIE PORAŻKA ZADANIA. Wyłącznik jest legalny
     * (`config/kuking.php`, sekcja `cdn_purge`), a kasowanie zdjęcia nie ma
     * prawa się nie udać dlatego, że nie ma czym wyczyścić cudzego cache'u.
     * Jedno zdanie, które nie gaśnie samo, jest tu właściwą ceną — nie
     * wywrócone wymazywanie konta.
     */
    private const POWOD_CZYSZCZENIE_CDN_WYLACZONE = 'czyszczenie_cdn_wylaczone';

    /**
     * `AWS_ENDPOINT` któregoś dysku R2/S3 nie ma postaci
     * `https://<konto>.eu.r2.cloudflarestorage.com` (D-255). Dysk odmawia
     * wtedy budowy, więc zdjęcia, eksporty albo czujka kopii nie działają —
     * a tu widać DLACZEGO, zanim ktoś zacznie czytać ślady wyjątków. Kod bez
     * hosta: host niesie identyfikator konta Cloudflare, a `/health` czyta każdy.
     */
    private const POWOD_MAGAZYN_ZLY_HOST = 'magazyn_r2_zly_host';

    /**
     * Ile minut milczymy na webhooku o TEJ SAMEJ nazwanej kontroli, zanim
     * wyślemy kolejne powiadomienie. Bez tego zewnętrzny monitoring odpytujący
     * `/health` co kilka minut zamieniłby jedną trwającą awarię w dzwonek
     * bez końca, aż ktoś wyciszy cały kanał (patrz `powiadomWebhook()`).
     */
    private const WEBHOOK_ODSTEP_MINUT = 30;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', self::POWOD_BAZA, static function (): void {
                DB::select('select 1');
            }),
            'migrations' => $this->check('migrations', self::POWOD_BAZA, static function (): void {
                $pending = DB::table('migrations')->count();

                if ($pending === 0) {
                    throw new KontrolaZdrowiaNieprzeszla(
                        self::POWOD_BRAK_MIGRACJI,
                        'Tabela `migrations` jest pusta — deploy nie dokończył migracji.',
                    );
                }
            }),
            'media' => $this->check('media', self::POWOD_ZDJECIA, fn () => $this->sprawdzDyskZeZdjeciami()),
            'turnstile' => $this->check('turnstile', self::POWOD_TURNSTILE_BEZ_KLUCZY, fn () => $this->sprawdzTurnstile()),
            'google' => $this->check('google', self::POWOD_GOOGLE_BEZ_KLUCZY, fn () => $this->sprawdzWejscieGoogle()),
            'facebook' => $this->check('facebook', self::POWOD_FACEBOOK_BEZ_KLUCZY, fn () => $this->sprawdzWejscieFacebooka()),
            'analityka' => $this->check('analityka', self::POWOD_ANALITYKA_BEZ_TOKENU, fn () => $this->sprawdzAnalityke()),
            'poczta' => $this->check('poczta', self::POWOD_POCZTA_NIE_WYSYLA, fn () => $this->sprawdzPoczte()),
            'kolejka' => $this->check('kolejka', self::POWOD_ZADANIA_NIEUDANE, fn () => $this->sprawdzKolejke()),
            'listy' => $this->check('listy', self::POWOD_SLAD_LISTOW_NIESPRAWDZALNY, fn () => $this->sprawdzNieudaneListy()),
            'cdn' => $this->check('cdn', self::POWOD_CZYSZCZENIE_CDN_WYLACZONE, fn () => $this->sprawdzCzyszczenieCdn()),
            'magazyn' => $this->check('magazyn', self::POWOD_MAGAZYN_ZLY_HOST, fn () => $this->sprawdzHostMagazynu()),
        ];

        $krytyczneOk = ! in_array(
            false,
            array_column(array_intersect_key($checks, array_flip(self::KRYTYCZNE)), 'ok'),
            true,
        );

        $wszystkoOk = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $wszystkoOk ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $krytyczneOk ? 200 : 503);
    }

    /**
     * Czy jakiś list przepadł, a właściciel jeszcze o tym nie wie
     * (issue #234, D-062).
     *
     * PO CO TO TU JEST
     * Bo do 10 września 2026 przepadnięcie listu wyglądało DOKŁADNIE jak
     * sukces: worker wyczerpywał trzy próby w sześć minut, zadanie lądowało
     * w `failed_jobs`, kolejka wracała do zera, a `/health` świecił na
     * zielono. Dotyczyło to potwierdzeń rejestracji i przypomnień hasła,
     * czyli listów, na które ktoś czeka przed ekranem. Ta sonda jest
     * pierwszym miejscem, w którym taka awaria mówi o sobie sama.
     *
     * BEZ OKNA CZASOWEGO — I TO JEST SEDNO
     * Nie pytamy „czy coś przepadło w ostatniej godzinie", tylko „czy
     * cokolwiek czeka na przeczytanie". Alarm z oknem czasowym gaśnie sam po
     * godzinie, czyli awaria z nocy jest o ósmej rano znowu niewidoczna —
     * a to jest ta sama cicha porażka, tylko o godzinę późniejsza. Gaśnie
     * dopiero wtedy, gdy człowiek odhaczy: `php artisan kuking:nieudane-listy
     * --odhacz`.
     *
     * KONSEKWENCJA, PRZYJĘTA ŚWIADOMIE: `/health` może stać w `degraded`
     * przez wiele godzin. Jest to cena za to, żeby jeden przepadły list nie
     * przeszedł niezauważony — a odhaczenie jest jedną komendą, po
     * przeczytaniu. `listy` nie są na liście `KRYTYCZNE`, więc trasa oddaje
     * dalej HTTP 200 i Railway nie restartuje z tego powodu niczego.
     *
     * DLACZEGO `Log::error` NIE ROBI TU SZUMU: sondy `/health` logują tylko
     * przy porażce, a monitoring odpytuje trasę co kilka minut — więc wpis
     * powstaje przy każdym odpytaniu, dopóki alarm trwa. To znaczy: dziennik
     * powie „od 02:14 do 08:30 listy przepadały", i taki zapis jest właśnie
     * tym, czego przy poszukiwaniu przyczyny brakuje najczęściej.
     */
    private function sprawdzNieudaneListy(): void
    {
        $nieodhaczone = MailFailure::query()->nieodhaczone()->count();

        if ($nieodhaczone === 0) {
            return;
        }

        // Kategoria z NAJŚWIEŻSZEJ porażki: przy wyczerpanej puli wszystkie
        // wpisy z danej doby mają ten sam powód, a właściciela interesuje
        // to, co dzieje się TERAZ.
        $najswiezszy = MailFailure::query()
            ->nieodhaczone()
            ->orderByDesc('failed_at')
            ->first();

        $limit = $najswiezszy?->powod === PowodOdmowy::LIMIT_DOBOWY;

        throw new KontrolaZdrowiaNieprzeszla(
            $limit ? self::POWOD_LIMIT_POCZTY_WYCZERPANY : self::POWOD_LISTY_PRZEPADAJA,
            'Nieodhaczonych nieudanych listów: '.$nieodhaczone.'. '
            .'Najświeższy powód: '.($najswiezszy?->powod->value ?? 'nieznany').'. '
            .'Przeczytaj: php artisan kuking:nieudane-listy',
        );
    }

    /**
     * Turnstile: czy to, co obiecuje konfiguracja, ma czym działać (D-050).
     *
     * PO CO TO TU JEST, SKORO BRAK KLUCZY NICZEGO NIE PSUJE
     * Właśnie dlatego. Bez kluczy widget się nie renderuje, walidacja nikogo
     * nie zatrzymuje i wszystko wygląda dobrze — a `/register`,
     * `/nie-pamietam-hasla`, `/napisz-do-nas` i `/zglos-nielegalna-tresc`
     * stoją bez ochrony, którą konfiguracja właśnie zapowiedziała. To jest ta
     * sama klasa awarii co `MAIL_MAILER=log`, martwy `kuking.media_disk`
     * i limit `upload` niepodpięty do żadnej trasy: narzędzie melduje sukces,
     * nie robiąc nic. Jedyna obrona to twardy, zewnętrznie widoczny sygnał.
     *
     * DLACZEGO `degraded`, A NIE 503
     * Bo `turnstile` NIE JEST na liście `KRYTYCZNE`, i to jest decyzja, nie
     * przeoczenie. Healthcheck oddający 503 już raz położył ten serwis
     * (patrz komentarz na górze klasy). Serwis działający bez captchy jest
     * o wiele lepszy niż serwis w pętli restartów — a monitoring i tak ma
     * pilnować TREŚCI odpowiedzi. Przy okazji `check()` woła `Log::error`
     * (log serwera, zawsze) i — jeśli `LOG_BLAD_WEBHOOK_URL` jest ustawiony —
     * `powiadomWebhook()` (Discord/Slack, z odstępem `WEBHOOK_ODSTEP_MINUT`,
     * żeby trwająca awaria nie zalała kanału). Sentry nie ma dziś w kodzie
     * wcale (D-041) — to zdanie było nieprawdziwe do 10 września 2026, kiedy
     * ten komentarz to zauważył i poprawił.
     *
     * DLACZEGO TYLKO NA PRODUKCJI I TYLKO GDY KTOŚ O TURNSTILE POPROSIŁ
     * Lokalnie, w CI i w testach kluczy nie ma i mieć nie musi — stały
     * `degraded` w tych środowiskach byłby szumem, który uczy ignorować to
     * pole. A jeśli właściciel świadomie wyłączy WSZYSTKIE miejsca
     * w `config/kuking.php`, to konfiguracja nie kłamie i nie ma o czym
     * krzyczeć.
     */
    private function sprawdzTurnstile(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! Turnstile::ktoresMiejsceWlaczone()) {
            return;
        }

        if (Turnstile::skonfigurowany()) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            self::POWOD_TURNSTILE_BEZ_KLUCZY,
            Turnstile::komunikatBrakuKluczy(),
        );
    }

    /**
     * Wejście kontem Google: czy droga, którą konfiguracja właśnie obiecała,
     * ma czym działać (D-069, issue #258).
     *
     * PO CO TO TU JEST, SKORO BRAK KLUCZY NICZEGO NIE PSUJE
     * Dokładnie ten sam wywód co przy Turnstile wyżej i to samo zdanie
     * z runbooka: **cicha, nieistniejąca droga wejścia jest gorsza niż
     * wyłączona**. Bez kluczy przycisku „Wejdź kontem Google" po prostu nie
     * ma na ekranie — a to wygląda identycznie jak poprawne wdrożenie, na
     * którym właściciel świadomie tej drogi nie chciał. Jedyną różnicą jest
     * to, czy konfiguracja nadal ją obiecuje. Dlatego pytamy o obietnicę,
     * nie o obecność kluczy samą w sobie.
     *
     * SKĄD SIĘ WZIĄŁ TEN SYGNAŁ
     * Był zaplanowany w D-069 i świadomie odłożony („`HealthController`
     * przerabia równolegle inne zlecenie"), a potem stał w issue #258 i #259
     * jako jedyna luka w kodzie obu tych funkcji. Do chwili jego dołożenia
     * jedynym sprawdzeniem było „wejdź na /login i zobacz, czy jest
     * przycisk" — czyli czynność, której nikt nie robi co pięć minut.
     *
     * DLACZEGO `degraded`, A NIE 503
     * Bo `google` NIE JEST na liście `KRYTYCZNE`. Serwis bez jednej
     * z trzech dróg wejścia działa (hasło i link e-mail stoją tam, gdzie
     * stały); serwis w pętli restartów nie działa wcale.
     *
     * DLACZEGO TYLKO NA PRODUKCJI I TYLKO GDY FUNKCJA JEST WŁĄCZONA
     * Lokalnie, w CI i w testach kluczy nie ma i mieć nie musi — to jest
     * stan domyślny opisany w `.env.example`. A `KUKING_WEJSCIE_GOOGLE=false`
     * znaczy „nie chcę tej drogi": konfiguracja wtedy nie kłamie i nie ma
     * o czym krzyczeć. Ta sama lekcja co przy Turnstile: stały `degraded`
     * uczy ignorować to pole.
     */
    private function sprawdzWejscieGoogle(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! (bool) config('kuking.google.wlaczone', true)) {
            return;
        }

        if (Google::skonfigurowany()) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            self::POWOD_GOOGLE_BEZ_KLUCZY,
            Google::komunikatBrakuKluczy(),
        );
    }

    /**
     * Wejście kontem Facebooka — ten sam wywód co przy Google wyżej
     * (issue #259), z jedną różnicą, przez którą ten sygnał jest tu bardziej
     * potrzebny niż tam.
     *
     * RÓŻNICA: DROGA DO KLUCZY JEST DŁUŻSZA, WIĘC ŁATWIEJ JĄ ZOSTAWIĆ
     * NIEDOKOŃCZONĄ. Google to pięć minut w jednym panelu. U Meta właściciel
     * przechodzi kilkanaście czynności w trzech miejscach panelu
     * (`docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md` §12), a ostatnia z nich —
     * przestawienie aplikacji w tryb **Live** — ma się wydarzyć DOPIERO po
     * wdrożeniu kodu. Między jednym a drugim jest okno, w którym wdrożenie
     * wygląda na zdrowe, a droga wejścia nie istnieje. To okno jest dokładnie
     * tym, co ten sygnał ma oświetlić.
     *
     * Zdanie dla właściciela (z nazwami zmiennych i odnośnikiem do runbooka
     * Meta) idzie WYŁĄCZNIE do logu — patrz `check()`. Na zewnątrz wychodzi
     * sam kod `facebook_bez_kluczy`, bo ta odpowiedź jest publiczna.
     */
    private function sprawdzWejscieFacebooka(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! (bool) config('kuking.facebook.wlaczone', true)) {
            return;
        }

        if (Facebook::skonfigurowany()) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            self::POWOD_FACEBOOK_BEZ_KLUCZY,
            Facebook::komunikatBrakuKluczy(),
        );
    }

    /**
     * Analityka odwiedzin: czy rzecz, którą właśnie obiecaliśmy w DOKUMENCIE
     * PRAWNYM, ma czym działać (D-092).
     *
     * TEN SAM WYWÓD CO PRZY GOOGLE I FACEBOOKU, Z JEDNĄ RÓŻNICĄ, KTÓRA
     * PRZEWAŻA. Tamte dwie obietnice stoją w konfiguracji i widać je okiem:
     * przycisku „Wejdź kontem Google" albo nie ma na `/login`, i ktoś kiedyś
     * to zauważy. Tę obietnicę złożyliśmy w polityce prywatności — zdaniem
     * w czasie teraźniejszym, z datą — a jej niespełnienia NIE WIDAĆ NIGDZIE:
     * strona wygląda normalnie, w dzienniku serwera nie ma nic, przeglądarka
     * nie zgłasza usterki, bo skryptu po prostu nie ma w HTML-u. Właściciel,
     * który raz założył serwis w panelu Cloudflare, ma wszelkie powody sądzić,
     * że analityka działa. Do 12 września 2026 nie było ani jednego miejsca,
     * z którego dałoby się dowiedzieć, że nie działa.
     *
     * PYTAMY O ROZJAZD, NIE O BRAK TOKENU. Pusty token sam w sobie jest
     * poprawnym stanem: tak chodzi CI, tak chodzą testy, tak chodzi każde
     * środowisko preview i tak może chodzić produkcja, jeśli właściciel
     * analityki nie chce. Awarią jest dopiero para: dokument prawny obiecuje
     * + tokenu nie ma. Dlatego warunkiem jest treść polityki prywatności
     * (`AnalitykaCloudflare::obiecanaWDokumencie()`), a nie osobny przełącznik.
     *
     * I DLATEGO ŚWIADOMIE NIE MA TU WYŁĄCZNIKA W RODZAJU `KUKING_ANALITYKA=false`.
     * Przy Google i Facebooku wyłącznik znaczy „nie chcę tej drogi wejścia"
     * i nikogo nie okłamuje — dokument prawny o nich wtedy nie mówi. Tutaj
     * wyłącznik znaczyłby „niech polityka prywatności dalej opisuje
     * przetwarzanie, którego nie ma, tylko niech przestanie o tym mówić
     * healthcheck", czyli uczyłby uciszania sygnału zamiast prostowania
     * dokumentu. Uciszyć ten sygnał wolno DWOMA sposobami i oba są uczciwe:
     * wpisać token albo wykreślić obietnicę z polityki (wtedy trzeba też
     * usunąć beacon z layoutu — pilnuje tego `DokumentyPrawneNieKlamiaTest`
     * z drugiej strony).
     *
     * DLACZEGO `degraded`, A NIE 503. Bo `analityka` NIE JEST na liście
     * `KRYTYCZNE` i być nie może: serwis bez statystyki odwiedzin działa
     * w całości, a healthcheck oddający 503 już raz położył ten serwis.
     * Monitoring pilnuje TREŚCI odpowiedzi.
     *
     * DLACZEGO TYLKO NA PRODUKCJI. Lokalnie, w testach, w CI i na preview
     * pusty token jest stanem domyślnym i opisanym w `.env.example` — stały
     * `degraded` byłby tam szumem, który uczy ignorować to pole. To ta sama
     * lekcja co przy Turnstile.
     *
     * CZEGO TEN SYGNAŁ NIE ZŁAPIE — I TO JEST GRANICA, NIE PRZEOCZENIE.
     * Nie powie, czy token jest PRAWDZIWY (Cloudflare po cichu odrzuca
     * zdarzenia z nieznanym tokenem) ani czy w panelu wybrano wariant
     * zbierania danych obejmujący Unię Europejską. Obie te rzeczy dzieją się
     * w cudzym panelu i z kontenera nie da się ich zmierzyć — dlatego mówi
     * o nich zdanie z `AnalitykaCloudflare::komunikatBrakuTokenu()`
     * i sprawdzenie w KROKU 8F runbooka, które patrzy na realne liczby
     * w panelu, a nie na stan naszej konfiguracji.
     */
    /**
     * Czy czyszczenie cache CDN jest w ogóle włączone (audyt G-03).
     *
     * CO TA SONDA MIERZY, A CZEGO NIE
     * Mierzy JEDNO: czy `App\Jobs\PurgePublicMediaCache` ma z czym pójść do
     * Cloudflare. Nie mierzy, czy przed zdjęciami stoi CDN, ani czy on
     * cokolwiek trzyma — to żyje w panelu Cloudflare, nie w repozytorium
     * (issue #120), i z tego kontenera nie da się tego sprawdzić.
     *
     * DLACZEGO TO WYSTARCZY, ŻEBY BYŁO WARTO
     * Bo bez tych dwóch zmiennych czyszczenie NIE ZADZIAŁA NIGDY, niezależnie
     * od odpowiedzi na tamte pytania — a dowiedzieć się o tym dziś nie ma
     * skąd. `MediaController` wysyła dla treści publicznej `Cache-Control:
     * public, max-age=...` (dziś 150 s) i na przekierowaniu, i — przez
     * `ResponseCacheControl` — na odpowiedzi z bajtami, czyli WPROST zaprasza
     * pośrednika do trzymania kopii. Kasowanie zdjęcia po decyzji moderacyjnej
     * albo żądaniu z RODO liczy na to, że ktoś tę kopię potem usunie.
     *
     * TYLKO PRODUKCJA. Lokalnie i w testach nie ma żadnego CDN-u i pusta
     * konfiguracja jest tam stanem poprawnym — mówi o tym wprost komentarz
     * przy `kuking.media.cdn_purge`. Sygnał, który świeci wszędzie, jest
     * szumem uczącym ignorować całe pole `checks` (ta sama lekcja co przy
     * Turnstile i analityce).
     */
    private function sprawdzCzyszczenieCdn(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $zona = (string) config('kuking.media.cdn_purge.zone_id');
        $token = (string) config('kuking.media.cdn_purge.token');

        if ($zona !== '' && $token !== '') {
            return;
        }

        // Stary, JEDYNY dysk zdjęć z publicznym adresem (`r2_legacy`). Gdy jest
        // w użyciu, wyłączone czyszczenie znaczy co innego niż zwykle: tam
        // adres pliku nie ma podpisu i nie wygasa, więc kopia w cache nie jest
        // ograniczona przez `max-age` żadnego przekierowania. Ten stan wymaga
        // innej czynności człowieka (dokończyć `kuking:przenies-zdjecia` i
        // zdjąć domenę ze starego bucketu — issue #120), więc dopisujemy go do
        // komunikatu, choć kod powodu zostaje jeden: naprawa zaczyna się tak
        // samo, od panelu Cloudflare.
        $staryBucketPubliczny = filled(config('filesystems.disks.r2_legacy.bucket'))
            && filled(config('filesystems.disks.r2_legacy.url'));

        // KOMUNIKAT IDZIE DO LOGU I NA WEBHOOK, NIE DO ODPOWIEDZI. W JSON-ie
        // publicznym zostaje sam kod — tak jak przy wszystkich pozostałych
        // sondach, bo `/health` czyta każdy.
        throw new KontrolaZdrowiaNieprzeszla(
            self::POWOD_CZYSZCZENIE_CDN_WYLACZONE,
            'Czyszczenie cache CDN po skasowaniu zdjęcia jest WYŁĄCZONE '
            .'(brak CLOUDFLARE_ZONE_ID albo CLOUDFLARE_PURGE_TOKEN). '
            .'Skasowane zdjęcia mogą się dalej otwierać z cache Cloudflare, '
            .'a zadanie czyszczące kończy się sukcesem i nie zostawia śladu. '
            .($staryBucketPubliczny
                ? 'UWAGA: skonfigurowany jest jeszcze stary, PUBLICZNY bucket '
                  .'`r2_legacy` — tam adres pliku nie wygasa, więc okna narażenia '
                  .'nie zamyka żaden `max-age`. Dokończ `kuking:przenies-zdjecia` '
                  .'i zdejmij domenę ze starego bucketu (issue #120). '
                : 'Stary publiczny bucket `r2_legacy` nie jest skonfigurowany, '
                  .'więc dotyczy to wyłącznie odpowiedzi trasy `media.show` '
                  .'i ich `max-age`. ')
            .'Albo uzupełnij obie zmienne, albo świadomie zostaw wyłączone — '
            .'ale wtedy wiedz, że „skasowane" znaczy „skasowane z bucketu".',
        );
    }

    private function sprawdzAnalityke(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! AnalitykaCloudflare::obiecanaWDokumencie()) {
            return;
        }

        if (AnalitykaCloudflare::wlaczona()) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            self::POWOD_ANALITYKA_BEZ_TOKENU,
            AnalitykaCloudflare::komunikatBrakuTokenu(),
        );
    }

    /**
     * Czy wysyłka poczty ma w ogóle czym ruszyć — `App\Support\Poczta` jest
     * TU JEDYNYM źródłem prawdy (ta sama klasa decyduje na ekranie „Nie
     * pamiętam hasła" i w `kuking:sprawdz-poczte`), żeby te trzy miejsca nie
     * mogły się rozjechać.
     *
     * DLACZEGO TYLKO NA PRODUKCJI
     * `MAIL_MAILER=array` jest domyślnym ustawieniem całej suity testów
     * (`phpunit.xml`), a `log` jest domyślną wartością w `.env.example` do
     * pierwszego zielonego deployu (`docs/infra/DEPLOYMENT_RUNBOOK.md`,
     * KROK 8). Sprawdzanie tego poza produkcją dawałoby stały `degraded`
     * wszędzie poza nią — szum, który uczy ignorować to pole, dokładnie ta
     * sama lekcja co przy Turnstile wyżej.
     *
     * DLACZEGO `Poczta::przeszkoda()`, A NIE PUBLICZNY KOD Z JEJ TREŚCI
     * `przeszkoda()` mówi wprost w swoim komentarzu: „NIE POKAZUJ TEGO
     * UŻYTKOWNIKOWI i nie wysyłaj na webhook" — bo ostatni fragment zdania
     * bywa komunikatem wyjątku CUDZEJ biblioteki transportu i nie jest niczym
     * ograniczony (ta sama klasa ryzyka co `$e->getMessage()` w
     * `WebhookBleduHandler`, audyt A6-01). Dlatego trafia wyłącznie do `$doLogu`
     * `KontrolaZdrowiaNieprzeszla` — do serwerowego logu, którego `/health`
     * nigdy nie pokazuje światu (patrz `check()`); na zewnątrz i na webhook
     * idzie tylko zamknięty kod `POWOD_POCZTA_NIE_WYSYLA`.
     */
    private function sprawdzPoczte(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $przeszkoda = Poczta::przeszkoda();

        if ($przeszkoda === null) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(self::POWOD_POCZTA_NIE_WYSYLA, $przeszkoda);
    }

    /**
     * Czy w `failed_jobs` leżą nieudane zadania kolejki, o których dziś nie
     * dowiaduje się nikt sam z siebie.
     *
     * D-057 §4 (`docs/DECISIONS.md`) ustaliło to WPROST przy okazji sufitu
     * tygodniowego podsumowania: „Jedyne miejsce, które w ogóle liczy
     * `failed_jobs`, to `kuking:sprawdz-poczte`, uruchamiane ręcznie."
     * Zdanie było prawdziwe do tego sprawdzenia — teraz przynajmniej
     * ZEWNĘTRZNY monitoring `/health` (i webhook błędów, przez `check()`)
     * może to zauważyć bez logowania się na serwer.
     *
     * CZEGO TO NIE ROBI (ŚWIADOMIE)
     * Nie mówi, KTÓRE zadanie padło ani dlaczego — treść `failed_jobs.exception`
     * bywa pełnym śladem stosu z argumentami wywołań, czyli dokładnie tym,
     * czego `WebhookBleduHandler` i `check()` unikają gdzie indziej. Diagnozę
     * daje `php artisan queue:failed` z powłoki serwera, nie trasa publiczna.
     *
     * POWŁOKI SERWERA NA RAILWAY NIE MA — i dlatego to zdanie było przez
     * dziesięć dni ślepym zaułkiem: `/health` mówił `degraded`, a jedyna
     * odpowiedź na pytanie „które zadanie" stała za ścianą. Od issue #599
     * jest druga droga, TEŻ nie publiczna: `/admin/kolejka`, za rolą `admin`
     * (`UserPolicy::diagnozujKolejke`). Ona także nie pokazuje ładunku ani
     * treści wyjątku — tylko nazwy klas i liczby.
     * To sprawdzenie ma jedno zadanie: powiedzieć „coś tam leży, zajrzyj" —
     * publiczna odpowiedź niesie tylko kod, nigdy liczbę ani treść.
     *
     * DOKĄD ODESŁAĆ CZŁOWIEKA, KTÓRY TO ZOBACZY
     * `queue:failed` mówi, ŻE coś padło, i nic więcej — a najczęstszy odruch
     * po jego przeczytaniu, czyli `queue:retry`, jest przy liście z żetonem
     * ODPOWIEDZIĄ ZŁĄ: żeton resetu hasła żyje `config/auth.php` → `expire`
     * minut od WYSTAWIENIA, więc ponowienie po dniach wysyła człowiekowi
     * martwy link. Dlatego komunikat niżej (widoczny w dzienniku serwera,
     * nie w publicznej odpowiedzi) prowadzi do `kuking:martwe-zadania`, która
     * rozdziela żetony żywe od martwych i bez jawnego przełącznika niczego
     * nie kasuje.
     *
     * DLACZEGO CZYTAMY TABELĘ, A NIE RUSZAMY KOLEJKI
     * Wyłącznie `SELECT COUNT(*)` — bez `queue:retry`, bez kasowania, bez
     * dotykania `app/Jobs` ani `app/Mail`. Naprawa cichej utraty listów to
     * osobna praca (issue #234); to sprawdzenie tylko CZYTA to, co tamta
     * praca też czyta.
     */
    private function sprawdzKolejke(): void
    {
        try {
            $nieudane = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            // Nie zgadujemy: gdy samo ZAPYTANIE się nie udaje, prawdziwą
            // przyczyną jest niemal na pewno ta sama awaria bazy, którą i tak
            // zgłasza sprawdzenie `database` — powód `zadania_nieudane`
            // (poniżej) mówiłby wtedy o czymś, czego wcale nie zmierzyliśmy.
            throw new KontrolaZdrowiaNieprzeszla(self::POWOD_BAZA, $e->getMessage(), $e);
        }

        if ($nieudane === 0) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            self::POWOD_ZADANIA_NIEUDANE,
            "W tabeli `failed_jobs` jest {$nieudane} nieudanych zadań kolejki. "
                .'KTÓRE to zadania i co je przewróciło, widać bez powłoki serwera: '
                .'panel moderacji → „Kolejka zadań" (`/admin/kolejka`, rola `admin`). '
                .'Co to jest i kogo dotyczy: `php artisan kuking:martwe-zadania` '
                .'(niczego nie kasuje bez `--skasuj`). Do kogo nie doszedł list: '
                .'`php artisan kuking:kto-nie-dostal-listu`.',
        );
    }

    /**
     * Czy da się zapisać i odczytać plik na dysku ze zdjęciami — i czy droga
     * publiczna do niego prowadzi tam, gdzie powinna.
     *
     * Sam zapis nie wystarcza: dokładnie tak wyglądała poprzednia awaria.
     * `ProcessUploadedImage` kończył się powodzeniem, plik leżał na dysku,
     * a przeglądarka dostawała 404, bo `public/storage` był martwym linkiem
     * albo katalog zniknął razem z kontenerem.
     */
    private function sprawdzDyskZeZdjeciami(): void
    {
        $nazwaDysku = (string) config('kuking.media.disk');

        // Nazwa z kropką na początku i losowym sufiksem: nie zderzy się
        // z niczyim plikiem i nie trafi do listingów.
        $probka = '.health/'.Str::uuid()->toString();

        try {
            // `Storage::disk()` jest TUTAJ, a nie wyżej, bo dla dysku lokalnego
            // to ono tworzy katalog główny — awaria woluminu bez prawa zapisu
            // wychodzi więc już na tej linijce, a nie dopiero na `put()`.
            $dysk = Storage::disk($nazwaDysku);
            $dysk->put($probka, 'kuking');
        } catch (Throwable $e) {
            // Bez `finally` z kasowaniem: skoro zapis się nie udał, nie ma
            // czego kasować, a `delete()` na zepsutym dysku rzuciłby drugi
            // wyjątek i przykrył ten prawdziwy.
            throw new KontrolaZdrowiaNieprzeszla(self::POWOD_ZAPIS_NIEMOZLIWY, $e->getMessage(), $e);
        }

        try {
            if ($dysk->get($probka) !== 'kuking') {
                throw new KontrolaZdrowiaNieprzeszla(
                    self::POWOD_ODCZYT_NIEZGODNY,
                    'Zapis się udał, ale odczyt zwrócił co innego.',
                );
            }
        } catch (KontrolaZdrowiaNieprzeszla $e) {
            // Nasz własny wyjątek ma już kod — przepuszczamy go bez zmian,
            // inaczej gałąź niżej owinęłaby go po raz drugi.
            throw $e;
        } catch (Throwable $e) {
            throw new KontrolaZdrowiaNieprzeszla(self::POWOD_ODCZYT_NIEZGODNY, $e->getMessage(), $e);
        } finally {
            $dysk->delete($probka);
        }

        $this->sprawdzDrogePubliczna($nazwaDysku);
    }

    /**
     * Czy każdy dysk R2/S3 z konfiguracji ma adres, pod który wolno wysłać
     * klucz (D-255). Ta sama kontrola, którą `DyskR2` robi przy budowie —
     * tu bez budowania, więc sonda niczego nie wysyła.
     */
    private function sprawdzHostMagazynu(): void
    {
        $zle = [];

        foreach ((array) config('filesystems.disks') as $nazwa => $dysk) {
            if (! is_array($dysk) || ! in_array($dysk['driver'] ?? null, ['r2', 's3'], true)) {
                continue;
            }

            $adres = (string) ($dysk['endpoint'] ?? '');

            // Dysk bez adresu i bez klucza nie ma czego wysłać — to produkcja
            // na dysku lokalnym, bez R2. Pusty adres Z kluczem to już awaria
            // (AWS SDK poszedłby do Amazona) i tę łapie kontrola niżej.
            if ($adres === '' && blank($dysk['key'] ?? null)) {
                continue;
            }

            $powod = DozwolonyHostR2::powod($adres);

            if ($powod !== null) {
                $zle[] = "`{$nazwa}` (".DozwolonyHostR2::opisHosta($adres).", {$powod})";
            }
        }

        if ($zle === []) {
            return;
        }

        // Host (z identyfikatorem konta) idzie wyłącznie do logu; publicznie
        // i na webhook — sam kod.
        throw new KontrolaZdrowiaNieprzeszla(
            self::POWOD_MAGAZYN_ZLY_HOST,
            'AWS_ENDPOINT nie ma postaci https://<identyfikator konta>.eu.r2.cloudflarestorage.com '
            .'dla dysków: '.implode(', ', $zle).'. Te dyski się nie zbudują (D-255).',
        );
    }

    /**
     * Dla dysku lokalnego droga publiczna to symlink `public/storage`.
     * Przy R2 pliki idą prosto z CDN-u i ten link nie ma znaczenia —
     * sprawdzanie go zgłaszałoby wtedy awarię, której nie ma.
     */
    private function sprawdzDrogePubliczna(string $nazwaDysku): void
    {
        if (config("filesystems.disks.{$nazwaDysku}.driver") !== 'local') {
            return;
        }

        $link = public_path('storage');
        $cel = (string) config("filesystems.disks.{$nazwaDysku}.root");

        if (! is_dir($link)) {
            throw new KontrolaZdrowiaNieprzeszla(
                self::POWOD_BRAK_DROGI_PUBLICZNEJ,
                "Brak drogi publicznej do zdjęć: {$link} nie prowadzi do katalogu. "
                .'Uruchom `php artisan storage:link`.',
            );
        }

        // `realpath` rozwija symlink. Porównanie celów łapie przypadek,
        // w którym link istnieje, ale wskazuje na poprzedni katalog —
        // np. sprzed zamontowania woluminu.
        if (realpath($link) !== realpath($cel)) {
            throw new KontrolaZdrowiaNieprzeszla(
                self::POWOD_DROGA_GDZIE_INDZIEJ,
                "Droga publiczna do zdjęć (`{$link}`) prowadzi gdzie indziej "
                ."niż dysk `{$nazwaDysku}` (`{$cel}`).",
            );
        }
    }

    /**
     * Uruchamia jedną sondę i zamienia jej awarię na KOD, nigdy na komunikat.
     *
     * `$powodDomyslny` dotyczy wyjątków, których sonda nie rozpoznała sama —
     * np. `QueryException` z sondy bazy. Zgadywanie powodu z treści takiego
     * komunikatu byłoby kruche dokładnie tak, jak w audycie W7-07, więc
     * zamiast tego każde sprawdzenie z góry deklaruje, co znaczy jego
     * „coś poszło nie tak".
     *
     * @return array{ok: bool, error?: string}
     */
    private function check(string $nazwa, string $powodDomyslny, callable $probe): array
    {
        try {
            $probe();

            // Powrót do zdrowia kasuje odstęp webhooka — kolejna awaria TEJ
            // SAMEJ kontroli (nawet chwilę później) ma prawo zadzwonić od
            // razu, zamiast czekać do końca okna z poprzedniego incydentu.
            // Warunek pomija zapis do cache'a na NAJCZĘSTSZEJ ścieżce (zdrowy
            // serwis, kanał wyłączony) — każde wywołanie `/health` sprawdza
            // dziesięć kontroli, a bez tego warunku każda zdrowa odpowiedź
            // dokładałaby dziesięć zbędnych zapisów do tabeli `cache`.
            if (filled(config('logging.channels.blad_webhook.url'))) {
                Cache::forget($this->kluczOdstepuWebhooka($nazwa));
            }

            return ['ok' => true];
        } catch (Throwable $e) {
            $powod = $e instanceof KontrolaZdrowiaNieprzeszla ? $e->kod : $powodDomyslny;

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
        if (blank(config('logging.channels.blad_webhook.url'))) {
            return;
        }

        if (! Cache::add($this->kluczOdstepuWebhooka($nazwa), true, now()->addMinutes(self::WEBHOOK_ODSTEP_MINUT))) {
            return;
        }

        // Czysta kartka przed pomiarem: w jednym żądaniu `/health` dzwonimy
        // nawet kilka razy (osobny odstęp na kontrolę), a bez tego drugi
        // dzwonek odczytałby wynik pierwszego.
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        Log::channel('blad_webhook')->error("/health: kontrola „{$nazwa}” nie przeszła (powód: {$powod}).");

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
        if (WebhookBleduHandler::ostatniaWysylkaSieUdala() === false) {
            Cache::forget($this->kluczOdstepuWebhooka($nazwa));
        }
    }

    private function kluczOdstepuWebhooka(string $nazwa): string
    {
        return 'health:webhook_odstep:'.$nazwa;
    }
}
