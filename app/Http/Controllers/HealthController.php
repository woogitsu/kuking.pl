<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Models\MailFailure;
use App\Poczta\PowodOdmowy;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * /health — punkt kontrolny dla Railway i monitoringu zewnętrznego.
 *
 * Sprawdza to, czego brak realnie kładzie serwis. Nie sprawdzamy rzeczy,
 * których awaria nie powinna wywalać deployu (np. poczty) — inaczej
 * healthcheck restartuje aplikację z powodu problemu, który nie dotyczy
 * jej działania.
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
 * `listy` też NIE JEST krytyczne i odpowiada na trzecie pytanie: „czy komuś
 * nie doszedł list, o którym jeszcze nie wiesz" (issue #234, D-062). Jest tu
 * z tego samego powodu co Turnstile — przepadnięcie listu niczego nie psuje
 * w serwisie i dlatego nie widzi go nikt. Ta sonda gaśnie dopiero po
 * odhaczeniu (`php artisan kuking:nieudane-listy --odhacz`), nie po
 * godzinie: alarm, który gaśnie sam, zamienia awarię z nocy w niewidzialną
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
        self::POWOD_LISTY_PRZEPADAJA,
        self::POWOD_LIMIT_POCZTY_WYCZERPANY,
        self::POWOD_SLAD_LISTOW_NIESPRAWDZALNY,
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
            'listy' => $this->check('listy', self::POWOD_SLAD_LISTOW_NIESPRAWDZALNY, fn () => $this->sprawdzNieudaneListy()),
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
     * pilnować TREŚCI odpowiedzi. Przy okazji `check()` zapisuje to jako
     * `Log::error`, więc idzie też na webhook błędów i do Sentry.
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

            return ['ok' => true];
        } catch (Throwable $e) {
            $powod = $e instanceof KontrolaZdrowiaNieprzeszla ? $e->kod : $powodDomyslny;

            // Jedyne miejsce, w którym pełna treść wyjątku ma prawo się
            // pojawić. Nie ma tu danych osobowych: sondy nie dotykają
            // niczyich wpisów ani kont, chodzą po `select 1`, po liczniku
            // migracji i po własnym pliku próbnym.
            Log::error('Kontrola /health nie przeszła.', [
                'kontrola' => $nazwa,
                'powod' => $powod,
                'wyjatek' => $e::class,
                'komunikat' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $powod];
        }
    }
}
