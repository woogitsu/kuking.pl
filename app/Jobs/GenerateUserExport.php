<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportFileNames;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
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
 */
class GenerateUserExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** 15 minut. Konto z tysiącem zdjęć to setki megabajtów z object storage. */
    public int $timeout = 900;

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

    public function __construct(public string $dataExportId) {}

    public function handle(): void
    {
        $export = DataExport::with('user.profile')->find($this->dataExportId);

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
            $this->markFailed($export, 'Konto nie istnieje.');

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
                throw new \RuntimeException('Nie udało się otworzyć gotowego archiwum.');
            }

            try {
                Storage::disk($disk)->writeStream($objectKey, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $bytes = (int) filesize($this->tempZip);

            $export->update([
                'status' => DataExport::STATUS_READY,
                'disk' => $disk,
                'object_key' => $objectKey,
                'bytes' => $bytes,
                'completed_at' => $generatedAt,
                'expires_at' => $generatedAt->copy()->addDays((int) config('kuking.exports.ttl_days')),
                'failure_reason' => null,
            ]);

            $this->notifyOwner($export->refresh());
        } catch (Throwable $e) {
            Log::warning('Nie udało się zbudować paczki z danymi użytkownika', [
                'data_export_id' => $export->getKey(),
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($export, $this->reasonFor($e));

            throw $e;
        } finally {
            $this->cleanUpTempFiles();
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
            ? 'Przygotowanie paczki przerwane (przekroczony limit czasu).'
            : $this->reasonFor($e),
        );

        $this->cleanUpTempFiles();
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
            'displayName' => $user->profile?->display_name,
            'generatedAt' => $generatedAt,
        ])->render());
    }

    private function addReadme(ZipArchive $zip, User $user, ExportPhotoPlan $photos, Carbon $generatedAt): void
    {
        $text = view('exports.readme', [
            'generatedAt' => $generatedAt,
            'displayName' => $user->profile?->display_name,
            'recipeCount' => $user->recipes()->count(),
            'photoCount' => $photos->count(),
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

            if ($localPath === null) {
                continue;
            }

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
     * Brak jednego pliku w storage nie może wywalić całego eksportu —
     * lepiej wydać paczkę bez jednego zdjęcia niż nie wydać żadnej.
     */
    private function copyToTemp(Media $photo): ?string
    {
        try {
            $source = Storage::disk($photo->disk)->readStream($photo->object_key);

            if ($source === null || $source === false) {
                throw new \RuntimeException('Brak pliku w storage.');
            }

            $path = $this->tempPath('bin');
            $target = fopen($path, 'wb');

            if ($target === false) {
                throw new \RuntimeException('Nie udało się utworzyć pliku tymczasowego.');
            }

            try {
                stream_copy_to_stream($source, $target);
            } finally {
                fclose($target);

                if (is_resource($source)) {
                    fclose($source);
                }
            }

            return $path;
        } catch (Throwable $e) {
            Log::warning('Pominięto zdjęcie w paczce z danymi', [
                'media_id' => $photo->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
        $path = sys_get_temp_dir().'/kuking-eksport-'.Str::uuid()->toString().'.'.$extension;

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

    private function notifyOwner(DataExport $export): void
    {
        $email = $export->user?->email;

        if ($email === null) {
            return;
        }

        try {
            Mail::to($email)->send(new DataExportReady($export));
        } catch (Throwable $e) {
            // Paczka JEST gotowa i widać ją w ustawieniach — nie cofamy statusu
            // tylko dlatego, że poczta chwilowo nie działa.
            Log::warning('Paczka z danymi gotowa, ale e-mail nie wyszedł', [
                'data_export_id' => $export->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markFailed(DataExport $export, string $reason): void
    {
        $export->update([
            'status' => DataExport::STATUS_FAILED,
            'failure_reason' => Str::limit($reason, 480, ''),
        ]);
    }

    /**
     * Powód po polsku — trafia na ekran użytkownika, więc nie może to być
     * „SQLSTATE[42P01]”. Techniczny szczegół zostaje w logu.
     */
    private function reasonFor(Throwable $e): string
    {
        return 'Nie udało się przygotować paczki: '.Str::limit($e->getMessage(), 300, '');
    }
}
