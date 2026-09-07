<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
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
