<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportFileNames;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Domain\Users\Exports\ExportTempDirectory;
use App\Exceptions\DataExportPhotoUnreadable;
use App\Exceptions\DataExportStorageFailure;
use App\Exceptions\DataExportTempFailure;
use App\Mail\DataExportReady;
use App\Mail\DataExportReadyInGracePeriod;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use App\Poczta\BezpiecznyKomunikat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Zbudowanie paczki ZIP z danymi jednego użytkownika (RODO art. 15 i 20).
 *
 * Dlaczego to jest w tle: paczka ze zdjęciami może mieć setki megabajtów.
 * Żadna z tych rzeczy nie zmieści się w cyklu żądanie–odpowiedź.
 *
 * Dlaczego STRUMIENIOWO, a nie w pamięci:
 * `ZipArchive` pracuje na pliku tymczasowym i czyta dodawane pliki z dysku,
 * więc archiwum o rozmiarze 800 MB nie potrzebuje 800 MB RAM-u. Zdjęcia
 * pobieramy z object storage przez `readStream`, nie przez `get` — inaczej
 * jedno zdjęcie 15 MB wpadałoby do pamięci całe.
 *
 * Dlaczego archiwum jest domykane co kilkadziesiąt plików:
 * `ZipArchive` wymaga, żeby dodane pliki istniały do momentu `close()`.
 * Przy koncie z tysiącem zdjęć oznaczałoby to tysiąc kopii na dysku
 * tymczasowym naraz. Domknięcie i otwarcie archiwum od nowa pozwala usuwać
 * kopie w partiach — zużycie dysku zostaje stałe.
 *
 * Co jest w paczce (i dlaczego akurat to):
 *  - `index.html` — TO otwiera człowiek. Reszta jest dla programów.
 *  - `przepisy/*.html` — jeden przepis na plik, do czytania przy garnku
 *    i do wydruku, bez internetu.
 *  - `wpisy.html` — wpisy, wykonania „Ugotowałem” i komentarze.
 *  - `zdjecia/*` — pliki nazwane po ludzku (`2027-03-14-rosol.webp`).
 *    Archiwum Garnek.pl jest niepełne właśnie dlatego, że zdjęć nie pobrano.
 *  - `dane.json` — format do przeniesienia danych (art. 20).
 *  - `CZYTAJ-TO-NAJPIERW.txt` — po polsku, prostym językiem.
 *
 * Przy błędzie: status `failed`, `failure_reason`, ostrzeżenie w logu.
 * Rekord NIGDY nie zostaje w `processing` — pilnuje tego zarówno `catch`
 * w `handle()`, jak i hook `failed()` (ten łapie także timeout, po którym
 * nie ma już wyjątku do przechwycenia).
 *
 * Paczka NIE powstaje dla konta wymazanego ani dla eksportu unieważnionego
 * przez `EraseAccountData` (issue #1307) — sprawdzamy to na starcie i drugi
 * raz pod blokadą wiersza konta tuż przed `ready`, patrz `finalize()`.
 *
 * Pliki pośrednie żyją w katalogu tego jednego eksportu (issue #993), patrz
 * `App\Domain\Users\Exports\ExportTempDirectory`.
 *
 * `failure_reason` to KOD z `DataExport::REASONS`, nie zdanie (audyt W7-07)
 * — patrz `reasonFor()`. Pełny `$e->getMessage()` (bywa nim SQLSTATE albo
 * ścieżka na dysku tymczasowym) zostaje wyłącznie w `Log::warning` niżej.
 */
class GenerateUserExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** 15 minut. Konto z tysiącem zdjęć to setki megabajtów z object storage. */
    public int $timeout = 900;

    /**
     * Kolejka `low` (audyt W3-05).
     *
     * Budowa paczki z danymi trwa minuty i nikt na nią nie patrzy — przychodzi
     * e-mailem. Zdjęcie z wpisu czeka natomiast człowiek, który właśnie kliknął
     * „Opublikuj" i widzi „za chwilę będzie widoczne".
     *
     * Uwaga: sama kolejność kolejek nie wywłaszczy zadania, które już ruszyło.
     * Przy jednym workerze eksport nadal zajmie go na te minuty. Rozdzielenie
     * na osobne procesy dla `media` i `low` to następny krok, gdy pojawi się
     * na to powód — dziś liczy się to, że kolejność w ogóle zaczęła działać.
     */
    private const KOLEJKA = 'low';

    /** Ponowienie po 2 i 5 minutach — awarie storage bywają chwilowe. */
    public array $backoff = [120, 300];

    /**
     * Timeout oznacza „za duże albo zawieszone”, nie „chwilowa awaria”.
     * Ponawianie takiego zadania trzy razy zajęłoby workera na 45 minut.
     */
    public bool $failOnTimeout = true;

    /** @var list<string> pliki tymczasowe do usunięcia po zakończeniu */
    private array $tempFiles = [];

    private ?string $tempZip = null;

    public function __construct(public string $dataExportId)
    {
        $this->onQueue(self::KOLEJKA);
    }

    public function handle(): void
    {
        // Pozostałości po twardo przerwanych eksportach (issue #993) — także
        // po poprzedniej próbie TEGO eksportu, której `finally` nie zdążyło.
        // PRZED każdym wczesnym powrotem niżej: przerwana próba mogła zostawić
        // tu pełną kopię konta, a konto wymazane w międzyczasie (albo eksport
        // już gotowy) kończy handle() od razu — i ta kopia czekałaby godzinę
        // na `sweepStale()` innego eksportu, o ile jakiś w ogóle przyjdzie.
        ExportTempDirectory::sweepStale();

        $export = DataExport::with('user.profile')->find($this->dataExportId);

        // Duplikat zlecenia przy ŻYWYM przebiegu tego samego eksportu —
        // katalogu nie ruszamy, bo to są JEGO pliki w trakcie pakowania.
        // Patrz `anotherRunInProgress()`.
        if ($export !== null && $this->anotherRunInProgress($export)) {
            Log::info('Eksport danych już się buduje w innym przebiegu — duplikat zlecenia pominięty', [
                'data_export_id' => $export->getKey(),
            ]);

            return;
        }

        ExportTempDirectory::remove($this->dataExportId);

        if ($export === null) {
            return;
        }

        // Paczka już gotowa (np. po ponowieniu, które przyszło za późno) —
        // nie budujemy jej drugi raz i nie wysyłamy drugiego e-maila.
        if ($export->status === DataExport::STATUS_READY) {
            return;
        }

        $user = $export->user;

        if ($user === null) {
            $this->markFailed($export, DataExport::REASON_ACCOUNT_MISSING);

            return;
        }

        // Wymazanie konta mogło wejść między zamówieniem a startem joba
        // (issue #1307). Nie budujemy wtedy kopii danych, których już nie ma.
        if ($this->revoked($export, $user)) {
            $this->markFailed($export, DataExport::REASON_ACCOUNT_MISSING);

            return;
        }

        $export->update(['status' => DataExport::STATUS_PROCESSING]);

        try {
            $generatedAt = Carbon::now();
            $photos = new ExportPhotoPlan($user);
            $data = (new CollectUserExportData)->handle($user, $photos, $generatedAt);

            $this->tempZip = $this->tempPath('zip');
            $zip = $this->openZip($this->tempZip, fresh: true);

            $this->addReadme($zip, $user, $photos, $generatedAt);
            $this->addJson($zip, $data);
            $this->addRecipePages($zip, $user, $photos, $data);
            $this->addPostsPage($zip, $data);
            $this->addIndex($zip, $user, $photos, $data, $generatedAt);
            $zip = $this->addPhotos($zip, $photos);

            $zip->close();

            $disk = (string) config('kuking.exports.disk');
            $objectKey = ExportFileNames::objectKey($export);

            $stream = fopen($this->tempZip, 'rb');

            if ($stream === false) {
                throw new DataExportStorageFailure('Nie udało się otworzyć gotowego archiwum do wysyłki.');
            }

            try {
                Storage::disk($disk)->writeStream($objectKey, $stream);
            } catch (Throwable $e) {
                // Zawinięte w typ, który `reasonFor()` rozpozna nawet po tym,
                // jak `failed()` odtworzy joba od nowa z ładunku kolejki —
                // patrz komentarz w App\Exceptions\DataExportStorageFailure.
                throw new DataExportStorageFailure('Nie udało się zapisać paczki w magazynie plików.', previous: $e);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $bytes = (int) filesize($this->tempZip);

            if ($this->finalize($export, $disk, $objectKey, $bytes, $generatedAt)) {
                $this->notifyOwner();
            }
        } catch (Throwable $e) {
            Log::warning('Nie udało się zbudować paczki z danymi użytkownika', [
                'data_export_id' => $export->getKey(),
                // `DataExportStorageFailure` zawija oryginalny wyjątek — tu, w logu,
                // ma zostać JEGO pełny komunikat (SQLSTATE, ścieżka na dysku...),
                // nie zdanie po polsku z opakowania. Do bazy idzie wyłącznie kod
                // (patrz reasonFor()), więc to jest jedyne miejsce, gdzie ten
                // szczegół w ogóle zostaje.
                'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ]);

            $this->markFailed($export, $this->reasonFor($e));

            throw $e;
        } finally {
            $this->cleanUpTempFiles();
            ExportTempDirectory::remove($this->dataExportId);
        }
    }

    /**
     * Przejście w `ready` — WARUNKOWE, pod blokadą wiersza konta (issue #1307).
     *
     * Paczka buduje się do 15 minut z danych odczytanych na starcie. W tym
     * czasie `EraseAccountData` mogło wymazać konto i przestawić `expires_at`
     * tego eksportu w przeszłość. Bezwarunkowy `update()` na modelu
     * pobranym na starcie nadpisywał to unieważnienie świeżym terminem —
     * i w magazynie zostawała pełna kopia konta po wymazaniu, z terminem,
     * przed którym nocne sprzątanie jej nie ruszy.
     *
     * DLACZEGO BLOKADA NA `users`, A NIE TYLKO NA `data_exports`:
     * `EraseAccountData` bierze `lockForUpdate()` na wierszu konta jako
     * pierwsze. Ta sama kolejność tutaj szereguje oba zapisy: albo wymazanie
     * zatwierdziło się wcześniej i widzimy je pod blokadą, albo czeka na nasz
     * commit — a wtedy jego `UPDATE ... expires_at` trafia już w `ready`
     * i paczkę sprząta `kuking:sprzataj-eksporty`. Transakcja obejmuje
     * wyłącznie ten odczyt i zapis, nie budowanie ZIP-a.
     *
     * Przegrany wyścig: plik jest już w magazynie. Wiersz dostaje `expired`
     * z ZACHOWANYM adresem i terminem w przeszłości, a dopiero potem kasujemy
     * plik. Gdy kasowanie się nie uda, adres nie ginie — tą samą drogą co
     * każdą wygasłą paczkę dokończy to `kuking:sprzataj-eksporty`.
     *
     * @return bool czy paczka jest gotowa (czy wysyłać list)
     */
    private function finalize(DataExport $export, string $disk, string $objectKey, int $bytes, Carbon $generatedAt): bool
    {
        $ready = DB::transaction(function () use ($export, $disk, $objectKey, $bytes, $generatedAt): ?bool {
            $owner = User::query()->whereKey($export->user_id)->lockForUpdate()->first();
            $current = DataExport::query()->whereKey($export->getKey())->lockForUpdate()->first();

            if ($current === null) {
                return null;
            }

            if ($this->revoked($current, $owner)) {
                $current->update([
                    'status' => DataExport::STATUS_EXPIRED,
                    'disk' => $disk,
                    'object_key' => $objectKey,
                    'bytes' => $bytes,
                    'expires_at' => $current->expires_at !== null && $current->expires_at->isPast()
                        ? $current->expires_at
                        : now()->subSecond(),
                ]);

                return false;
            }

            $current->update([
                'status' => DataExport::STATUS_READY,
                'disk' => $disk,
                'object_key' => $objectKey,
                'bytes' => $bytes,
                'completed_at' => $generatedAt,
                'expires_at' => $generatedAt->copy()->addDays((int) config('kuking.exports.ttl_days')),
                'failure_reason' => null,
            ]);

            return true;
        });

        if ($ready === true) {
            return true;
        }

        $this->discardRevokedPackage($export, $disk, $objectKey, recordKept: $ready === false);

        return false;
    }

    /**
     * Czy ten sam eksport buduje właśnie INNY przebieg (przegląd kodu
     * 23 września 2026).
     *
     * Dwa zadania na jednym rekordzie to nie teoria: „ponów” w ustawieniach
     * (`DataSettingsController::odpowiedzNaTrwajacy`) wysyła drugie zadanie
     * dla `queued` stojącego 15 minut — a pierwsze mogło po prostu czekać
     * w kolejce i ruszyć chwilę później. Bez tego strażnika drugie zaczynało
     * od `ExportTempDirectory::remove()` i kasowało pliki pierwszego
     * w połowie pakowania.
     *
     * Żywy przebieg = `processing` ustawione (albo dotknięte) nie dawniej niż
     * limit jednej próby (`$timeout`). Starsze `processing` to próba zabita
     * twardo: ponowienie z kolejki przychodzi po `retry_after` = 960 s, czyli
     * PO tym progu (`config/queue.php`, `UmowaKolejkiTest`), więc strażnik
     * go nie zatrzyma i katalog po przerwanej próbie zostanie sprzątnięty.
     *
     * Wymazane konto NIE jest chronione: żywy przebieg i tak skończy się
     * odrzuceniem w `finalize()`, a kopia wymazanego konta ma zniknąć od
     * razu (issue #993, `test_wczesny_powrot_…`).
     */
    private function anotherRunInProgress(DataExport $export): bool
    {
        return $export->status === DataExport::STATUS_PROCESSING
            && $export->updated_at !== null
            && $export->updated_at->gt(now()->subSeconds($this->timeout))
            && ! $this->revoked($export, $export->user);
    }

    /**
     * Konto wymazane albo eksport unieważniony przez `EraseAccountData`,
     * które przestawia `expires_at` w przeszłość także na `queued`
     * i `processing`. Karencja (`pending_delete`) NIE unieważnia — paczkę
     * wolno zamówić i pobrać do dnia egzekucji.
     */
    private function revoked(DataExport $export, ?User $user): bool
    {
        if ($user === null || $user->isErased() || $user->data_erased_at !== null) {
            return true;
        }

        return $export->status !== DataExport::STATUS_READY
            && $export->expires_at !== null
            && $export->expires_at->isPast();
    }

    /** Kasuje plik z przegranego wyścigu i sprawdza, czy naprawdę zniknął (jak `CleanUpDataExports`). */
    private function discardRevokedPackage(DataExport $export, string $disk, string $objectKey, bool $recordKept): void
    {
        try {
            $storage = Storage::disk($disk);
            $storage->delete($objectKey);
            $gone = ! $storage->exists($objectKey);
        } catch (Throwable $e) {
            $gone = false;
        }

        if (! $gone) {
            // Adres zostaje w wierszu (`expired`, termin w przeszłości), więc
            // nocne `kuking:sprzataj-eksporty` spróbuje ponownie. Gdy wiersza
            // już nie ma, ten wpis jest jedynym śladem — stąd `error`.
            Log::error('Nie udało się usunąć paczki z danymi zbudowanej po wymazaniu konta', [
                'data_export_id' => $export->getKey(),
                'disk' => $disk,
                'object_key' => $objectKey,
                'wiersz_zachowany' => $recordKept,
            ]);

            return;
        }

        if ($recordKept) {
            DataExport::query()->whereKey($export->getKey())->update([
                'disk' => null,
                'object_key' => null,
                'bytes' => null,
            ]);
        }
    }

    /**
     * Ostatnia linia obrony: po wyczerpaniu prób (albo po timeoucie, po którym
     * nie ma wyjątku w `handle()`) rekord nie może zostać w `processing`.
     */
    public function failed(?Throwable $e): void
    {
        $export = DataExport::find($this->dataExportId);

        if ($export === null || $export->status === DataExport::STATUS_READY) {
            return;
        }

        $this->markFailed($export, $e === null
            ? DataExport::REASON_TIMEOUT
            : $this->reasonFor($e),
        );

        $this->cleanUpTempFiles();
        // Ta instancja jest odtworzona z ładunku kolejki i nie zna listy
        // plików z `handle()` — katalog po identyfikatorze zna (issue #993).
        ExportTempDirectory::remove($this->dataExportId);
    }

    // -----------------------------------------------------------------
    // Zawartość paczki
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function addJson(ZipArchive $zip, array $data): void
    {
        $json = json_encode($data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        // Przez plik tymczasowy, nie `addFromString`: przy koncie z tysiącami
        // wpisów JSON ma kilka megabajtów, a `addFile` czyta go z dysku.
        $path = $this->tempPath('json');
        file_put_contents($path, $json);

        $zip->addFile($path, 'dane.json');
    }

    /** @param array<string, mixed> $data */
    private function addRecipePages(ZipArchive $zip, User $user, ExportPhotoPlan $photos, array $data): void
    {
        $recipes = $user->recipes()
            ->with(['ingredients.ingredient', 'ingredients.unit', 'steps'])
            ->orderBy('created_at')
            ->get();

        // Komentarze bierzemy z już przygotowanych danych — są tam przepuszczone
        // przez filtr cudzych danych osobowych i nie chcemy tego filtru
        // powtarzać (ani zapomnieć) w widoku.
        $commentsByTitle = [];

        foreach ($data['przepisy'] as $entry) {
            $commentsByTitle[$entry['plik_do_czytania']] = $entry['komentarze'];
        }

        foreach ($recipes as $recipe) {
            $file = ExportFileNames::recipeFile($recipe);

            $stepPhotos = [];

            foreach ($recipe->steps as $step) {
                $stepPhotos[$step->getKey()] = $photos->pathFor($step->media_id, '../zdjecia/');
            }

            $html = view('exports.recipe', [
                'recipe' => $recipe,
                'heroPhoto' => $photos->pathFor($recipe->hero_media_id, '../zdjecia/'),
                'scanPhoto' => $photos->pathFor($recipe->source_scan_media_id, '../zdjecia/'),
                'stepPhotos' => $stepPhotos,
                'comments' => $commentsByTitle['przepisy/'.$file] ?? [],
            ])->render();

            $zip->addFromString('przepisy/'.$file, $html);
        }
    }

    /** @param array<string, mixed> $data */
    private function addPostsPage(ZipArchive $zip, array $data): void
    {
        $posts = array_map(fn (array $post): array => [
            'tresc' => $post['tresc'],
            'data' => $post['opublikowano'] ?? $post['utworzono']
                ? Carbon::parse($post['opublikowano'] ?? $post['utworzono'])->translatedFormat('j F Y')
                : null,
            'prywatny' => $post['widocznosc'] !== 'public',
            'szkic' => $post['status'] !== 'published',
            'przepis' => $post['dotyczy_przepisu'],
            'zdjecia' => $post['zdjecia'],
            'komentarze' => $post['komentarze'],
        ], $data['wpisy']);

        $cooked = array_map(fn (array $event): array => [
            'przepis' => $event['przepis'],
            'autor' => $event['autor_przepisu'],
            'data' => $event['kiedy'] ? Carbon::parse($event['kiedy'])->translatedFormat('j F Y') : null,
            'notatka' => $event['notatka'],
            'zmiany' => $event['co_zmienilam'],
            'jeszcze_raz' => $event['zrobie_jeszcze_raz'],
            'zdjecia' => $event['zdjecia'],
        ], $data['ugotowalem']);

        $zip->addFromString('wpisy.html', view('exports.posts', [
            'posts' => $posts,
            'cooked' => $cooked,
            'ownComments' => $data['moje_komentarze'],
        ])->render());
    }

    /** @param array<string, mixed> $data */
    private function addIndex(ZipArchive $zip, User $user, ExportPhotoPlan $photos, array $data, Carbon $generatedAt): void
    {
        $recipes = array_map(fn (array $recipe): array => [
            'tytul' => $recipe['tytul'],
            'plik' => Str::after($recipe['plik_do_czytania'], 'przepisy/'),
            'szkic' => $recipe['status'] !== Recipe::STATUS_PUBLISHED,
            'data' => $recipe['opublikowano'] ?? $recipe['utworzono']
                ? Carbon::parse($recipe['opublikowano'] ?? $recipe['utworzono'])->translatedFormat('j F Y')
                : null,
        ], $data['przepisy']);

        $zip->addFromString('index.html', view('exports.index', [
            'recipes' => $recipes,
            'postCount' => count($data['wpisy']),
            'cookedCount' => count($data['ugotowalem']),
            'photoCount' => $photos->count(),
            'photosStillProcessing' => $photos->stillProcessingCount(),
            // Zdjęcia, które do paczki NIE WEJDĄ NIGDY (issue #692) — inna
            // wiadomość niż „jeszcze się przetwarzają", więc osobne zmienne,
            // a nie jedna suma: widok pisze o nich dwa różne zdania.
            'photosRejected' => $photos->rejectedCount(),
            'photosDeleted' => $photos->deletedCount(),
            'savedOtherRecipeCount' => $this->savedOtherRecipeCount($user),
            'displayName' => $user->profile?->display_name,
            'generatedAt' => $generatedAt,
        ])->render());
    }

    /**
     * Ile CUDZYCH przepisów leży w zeszycie tej osoby.
     *
     * Spis treści mówi o ograniczeniu, które dotyczy wyłącznie cudzych
     * przepisów w zeszycie (tytuł, autor, notatka i data zapisania, bez
     * składników i kroków). Bez tej liczby zdanie o ograniczeniu wychodziło
     * także na koncie z pustym zeszytem — a wtedy opisuje coś, czego
     * w paczce nie ma. To ta sama zasada, co przy katalogach: paczka mówi
     * o tym, co w niej JEST.
     *
     * Własny przepis odłożony do własnego zeszytu NIE liczy się tutaj:
     * jego pełną treść paczka niesie w katalogu `przepisy/`, więc żadne
     * ograniczenie go nie dotyczy.
     *
     * Liczymy z bazy, a nie z `dane.json`: tam autor jest nazwą wyświetlaną,
     * a dwie osoby mogą mieć tę samą nazwę.
     *
     * `whereNull('recipes.deleted_at')` jest tu KONIECZNE, nie ostrożnościowe.
     * `Recipe` ma `SoftDeletes`, a złączenie omija globalny zakres modelu —
     * bez tego warunku przepis skasowany liczyłby się tutaj, choć
     * `CollectUserExportData::collections()` (zwykły Eloquent) już go do
     * paczki nie wkłada. Zdanie o ograniczeniu wychodziłoby wtedy na koncie,
     * w którego paczce nie ma ani jednego cudzego przepisu — czyli dokładnie
     * ta usterka, którą ten warunek miał usunąć.
     */
    private function savedOtherRecipeCount(User $user): int
    {
        return (int) $user->collections()
            ->join('collection_items', 'collection_items.collection_id', '=', 'collections.id')
            ->join('recipes', 'recipes.id', '=', 'collection_items.recipe_id')
            ->where('recipes.author_id', '!=', $user->getKey())
            ->whereNull('recipes.deleted_at')
            ->count();
    }

    private function addReadme(ZipArchive $zip, User $user, ExportPhotoPlan $photos, Carbon $generatedAt): void
    {
        $text = view('exports.readme', [
            'generatedAt' => $generatedAt,
            'displayName' => $user->profile?->display_name,
            'recipeCount' => $user->recipes()->count(),
            'photoCount' => $photos->count(),
            'photosStillProcessing' => $photos->stillProcessingCount(),
            // Patrz komentarz przy `addIndex()` wyżej — oba pliki paczki
            // mówią o brakach to samo i biorą to z tego samego miejsca.
            'photosRejected' => $photos->rejectedCount(),
            'photosDeleted' => $photos->deletedCount(),
            'contactEmail' => config('kuking.community.contact_email'),
        ])->render();

        // Sygnatura UTF-8 na początku pliku. Bez niej stary Notatnik w Windowsie
        // czyta plik jako Windows-1250 i „ugotowałam” zamienia się w krzaki.
        $zip->addFromString('CZYTAJ-TO-NAJPIERW.txt',
            "\xEF\xBB\xBF".str_replace("\n", "\r\n", trim($text))."\r\n",
        );
    }

    /**
     * Zdjęcia — sedno paczki.
     *
     * Bez nich eksport jest atrapą: archiwum Garnek.pl jest niepełne właśnie
     * dlatego, że zdjęć świadomie nie pobrano.
     */
    private function addPhotos(ZipArchive $zip, ExportPhotoPlan $photos): ZipArchive
    {
        $flushEvery = max(1, (int) config('kuking.exports.photo_flush_every'));
        $sinceFlush = 0;

        foreach ($photos->photos() as $photo) {
            $name = $photos->nameFor((string) $photo->getKey());

            if ($name === null) {
                continue;
            }

            $localPath = $this->copyToTemp($photo);

            $zip->addFile($localPath, 'zdjecia/'.$name);
            $sinceFlush++;

            if ($sinceFlush >= $flushEvery) {
                // Domknięcie zapisuje dodane pliki do archiwum, więc dopiero
                // teraz wolno usunąć ich kopie z dysku tymczasowego.
                $zip->close();
                $this->cleanUpTempFiles(keepZip: true);
                $zip = $this->openZip((string) $this->tempZip, fresh: false);
                $sinceFlush = 0;
            }
        }

        return $zip;
    }

    /**
     * Kopia zdjęcia na dysk tymczasowy, strumieniowo.
     *
     * Nieodczytane zdjęcie `ready` PRZERYWA eksport (issue #1388). Plan zdjęć
     * powstaje przed kopiowaniem, więc `dane.json`, strony przepisów, spis
     * treści i README już je liczą i do niego odsyłają. Pominięcie dawało
     * paczkę `ready` z martwymi odnośnikami i fałszywą liczbą zdjęć — bez
     * słowa dla człowieka. Kolejka ponawia (`$backoff`), a po ostatniej
     * próbie ekran mówi, co zrobić (`DataExport::REASON_PHOTO_UNREADABLE`).
     *
     * DWIE RÓŻNE AWARIE, DWA RÓŻNE WYJĄTKI. Do 23 września 2026 cała metoda
     * siedziała w jednym `catch`, więc brak miejsca na NASZYM dysku
     * tymczasowym (albo nieudany `fopen`) szedł na ekran jako „nie udało się
     * pobrać jednego z Twoich zdjęć" — zdanie o czymś, co się nie stało.
     * A wynik `stream_copy_to_stream()` nie był sprawdzany wcale: odczyt
     * urwany w połowie kończy się zwykłym końcem strumienia, więc ucięta
     * kopia wchodziła do paczki `ready` jako zepsuty plik (ta sama wada co
     * #1388, tylko po cichu — zmierzone testem). Teraz:
     *
     *  - magazyn nie oddał pliku albo oddał go krótszego, niż sam deklaruje
     *    (`size()`) → `DataExportPhotoUnreadable`;
     *  - nie udało się utworzyć, zapisać albo domknąć kopii lokalnej, albo
     *    na dysku leży mniej bajtów, niż przeczytaliśmy → `DataExportTempFailure`.
     *
     * W obu przypadkach paczka NIE jest `ready`.
     */
    private function copyToTemp(Media $photo): string
    {
        [$source, $expectedBytes] = $this->openPhotoSource($photo);

        $target = false;

        try {
            $path = $this->tempPath('bin');
            $target = $this->openTempTarget($path);

            if ($target === false) {
                throw new DataExportTempFailure('Nie udało się utworzyć kopii zdjęcia '.$photo->getKey().' na dysku tymczasowym.');
            }

            $copied = 0;

            while (! feof($source)) {
                $chunk = @fread($source, 1024 * 1024);

                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw $this->photoUnreadable($photo, 'przerwany odczyt');
                }

                if ($chunk === '') {
                    break;
                }

                if (@fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new DataExportTempFailure('Nie udało się zapisać kopii zdjęcia '.$photo->getKey().' na dysku tymczasowym.');
                }

                $copied += strlen($chunk);
            }

            $flushed = @fflush($target);
            $closed = @fclose($target);
            $target = false;

            clearstatcache(true, $path);

            if (! $flushed || ! $closed || @filesize($path) !== $copied) {
                throw new DataExportTempFailure('Kopia zdjęcia '.$photo->getKey().' na dysku tymczasowym jest niepełna.');
            }

            if ($copied !== $expectedBytes) {
                throw $this->photoUnreadable($photo, "odczytano {$copied} z {$expectedBytes} bajtów");
            }

            return $path;
        } finally {
            if (is_resource($target)) {
                @fclose($target);
            }

            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * Strumień zdjęcia z magazynu razem z rozmiarem, jaki magazyn podaje.
     * Rozmiar to jedyna miara, po której da się poznać odczyt urwany w połowie:
     * strumień sieciowy kończy się wtedy zwykłym końcem pliku.
     *
     * @return array{0: resource, 1: int}
     */
    private function openPhotoSource(Media $photo): array
    {
        try {
            $disk = Storage::disk($photo->disk);
            $expectedBytes = $disk->size($photo->object_key);
            $source = $disk->readStream($photo->object_key);
        } catch (Throwable $e) {
            throw $this->photoUnreadable($photo, $e::class);
        }

        if (! is_resource($source)) {
            throw $this->photoUnreadable($photo, 'brak pliku w storage');
        }

        return [$source, $expectedBytes];
    }

    /**
     * Otwarcie kopii lokalnej. Osobna metoda, żeby test mógł podstawić
     * `/dev/full` — prawdziwy „brak miejsca na dysku" z jądra, bez
     * zapełniania dysku maszyny, na której chodzą testy.
     *
     * @return resource|false
     */
    protected function openTempTarget(string $path)
    {
        return @fopen($path, 'wb');
    }

    /**
     * Bez `previous`: `handle()` logowałby wtedy surowy komunikat magazynu
     * (ścieżka, szczegół dostawcy). Identyfikator zdjęcia i krótka przyczyna
     * wystarczą do diagnozy.
     */
    private function photoUnreadable(Media $photo, string $cause): DataExportPhotoUnreadable
    {
        return new DataExportPhotoUnreadable(
            'Nie udało się odczytać zdjęcia '.$photo->getKey().' do paczki ('.$cause.').',
        );
    }

    // -----------------------------------------------------------------
    // Narzędzia
    // -----------------------------------------------------------------

    private function openZip(string $path, bool $fresh): ZipArchive
    {
        $zip = new ZipArchive;
        $flags = $fresh ? ZipArchive::CREATE | ZipArchive::OVERWRITE : ZipArchive::CREATE;

        if ($zip->open($path, $flags) !== true) {
            throw new \RuntimeException('Nie udało się otworzyć archiwum ZIP.');
        }

        return $zip;
    }

    private function tempPath(string $extension): string
    {
        $path = ExportTempDirectory::newFile($this->dataExportId, $extension);

        $this->tempFiles[] = $path;

        return $path;
    }

    private function cleanUpTempFiles(bool $keepZip = false): void
    {
        foreach ($this->tempFiles as $index => $path) {
            if ($keepZip && $path === $this->tempZip) {
                continue;
            }

            if (is_file($path)) {
                @unlink($path);
            }

            unset($this->tempFiles[$index]);
        }
    }

    /**
     * List „paczka gotowa" — po ŚWIEŻYM odczycie eksportu i konta.
     *
     * `finalize()` sprawdza wymazanie pod blokadą, ale blokada puszcza przy
     * commicie. `EraseAccountData`, które na nią czekało, zatwierdza się
     * w następnej chwili: przestawia termin paczki w przeszłość
     * i anonimizuje adres. Do 23 września 2026 szło tu
     * `$export->refresh()` — przeładowanie razem z relacją `user` — więc
     * list wychodził na ZANONIMIZOWANY adres, z linkiem, który już nie
     * otwiera paczki. Teraz: konto wymazane albo paczka niepobieralna =
     * brak listu.
     *
     * Zostaje okno między tym odczytem a wysłaniem. Wymazanie, które wejdzie
     * właśnie w nie, NIE zmienia adresu listu (odczytany przed nim, czyli
     * prawdziwy adres właściciela) — a link i tak prowadzi do paczki, której
     * `isDownloadable()` już nie wyda. Nie ma tu wycieku danych, jest jeden
     * list za dużo.
     *
     * KTÓRY LIST: konto w karencji (`pending_delete`) dostaje
     * `DataExportReadyInGracePeriod` — „cofnij usunięcie do dnia X” — zamiast
     * zwykłego `DataExportReady`. Konto wymazane nie dostaje żadnego.
     */
    private function notifyOwner(): void
    {
        $export = DataExport::with('user.profile')->find($this->dataExportId);

        if ($export === null
            || ! $export->isDownloadable()
            || $this->revoked($export, $export->user)) {
            return;
        }

        $email = $export->user?->email;

        if ($email === null) {
            return;
        }

        // Konto w karencji nie zaloguje się, a pobranie wymaga logowania —
        // zwykły list dałby martwy przycisk „Pobierz” (decyzja właściciela
        // z 23 września 2026). Stan konta z TEGO SAMEGO świeżego odczytu co
        // `revoked()` wyżej, nie z modelu sprzed budowania paczki: karencja
        // mogła się zacząć albo skończyć cofnięciem w trakcie.
        $mail = $export->user->status === User::STATUS_PENDING_DELETE
            ? new DataExportReadyInGracePeriod($export)
            : new DataExportReady($export);

        try {
            Mail::to($email)->send($mail);
        } catch (Throwable $e) {
            // Paczka JEST gotowa i widać ją w ustawieniach — nie cofamy statusu
            // tylko dlatego, że poczta chwilowo nie działa.
            // KOMUNIKAT PRZECHODZI PRZEZ REDAKCJĘ, NIE SUROWY.
            //
            // To jest wyjątek z WYSYŁKI LISTU, więc jego komunikat buduje
            // transport, a nie my — a transport przy odrzuconym odbiorcy
            // wkleja w tekst JEGO ADRES („550 5.1.1 <basia@wp.pl>: Recipient
            // address rejected"). Dziennik aplikacji nie jest miejscem na
            // adresy (AGENTS.md §7); ta sama redakcja, którą robi
            // `ZapiszNieudanyList` na tym samym rodzaju tekstu, a `Wyslij…`
            // z `App\Domain\Security` rozwiązuje jeszcze ostrzej — samą
            // nazwą klasy.
            Log::warning('Paczka z danymi gotowa, ale e-mail nie wyszedł', [
                'data_export_id' => $export->getKey(),
                'wyjatek' => $e::class,
                'error' => BezpiecznyKomunikat::z($e->getMessage()),
            ]);
        }
    }

    private function markFailed(DataExport $export, string $reason): void
    {
        $export->update([
            'status' => DataExport::STATUS_FAILED,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Kod z `DataExport::REASONS` — NIGDY zdanie i NIGDY `$e->getMessage()`.
     * Ten drugi trafia na ekran użytkownika przez `DataExport::
     * failureReasonLabel()`, więc nie może to być „SQLSTATE[42P01]” ani
     * ścieżka na dysku tymczasowym. Techniczny szczegół zostaje wyłącznie
     * w logu (`Log::warning` w `handle()`, tuż przed wywołaniem tej metody).
     *
     * Rozpoznanie WYŁĄCZNIE po klasie wyjątku, celowo bez zaglądania w jego
     * treść: string matching po komunikacie jest dokładnie tą kruchością,
     * która już raz przepuściła surowy wyjątek na ekran (audyt W7-07).
     */
    private function reasonFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof DataExportStorageFailure => DataExport::REASON_STORAGE,
            $e instanceof DataExportPhotoUnreadable => DataExport::REASON_PHOTO_UNREADABLE,
            // Awaria NASZEGO dysku tymczasowego: nie magazyn i nie zdjęcie
            // człowieka, więc ekran nie może mówić o żadnym z nich.
            $e instanceof DataExportTempFailure => DataExport::REASON_UNKNOWN,
            default => DataExport::REASON_UNKNOWN,
        };
    }
}
