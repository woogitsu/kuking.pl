<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\PodgladOdRazu;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Wiersz `media` i zadanie przetwarzania powstają razem albo wcale (issue #1456).
 *
 * `ProcessUploadedImage` jest jedyną drogą zdjęcia z `pending` do końcowego
 * stanu i jest zlecane raz, przy wgraniu. Gdy zapis do `jobs` padał PO
 * `Media::create()`, zostawał wiersz `pending` bez zadania i pliki bez
 * kompensacji.
 *
 * Kolejka jest tu PRAWDZIWA (`database`), bez `Queue::fake()`. Awaria jest
 * fizyczna: wyzwalacz PostgreSQL odmawia INSERT-u do `jobs` — dokładnie
 * w miejscu, w którym pada zerwane połączenie albo pełny dysk bazy.
 * Wyzwalacz powstaje w transakcji testu, więc `RefreshDatabase` go cofa.
 */
class ZlecenieZdjeciaWTransakcjiTest extends TestCase
{
    use RefreshDatabase;

    private ?User $wlasciciel = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('oryginal1456');
        Storage::fake('wariant1456');
        config([
            'queue.default' => 'database',
            'kuking.media.disk' => 'oryginal1456',
            'kuking.media.public_disk' => 'wariant1456',
        ]);
    }

    /**
     * KONTROLA DODATNIA: bez awarii jest dokładnie jeden wiersz i dokładnie
     * jedno zadanie z JEGO identyfikatorem. Bez tego „nic nie zostało" niżej
     * przechodziłoby też wtedy, gdy zadanie nie powstaje nigdy.
     */
    public function test_udane_wgranie_zostawia_jeden_wiersz_i_jedno_zadanie_z_jego_id(): void
    {
        $media = $this->wgraj();

        $this->assertSame(1, Media::query()->count());
        $this->assertSame([$media->getKey()], $this->idZdjecWKolejce());
        Storage::disk('oryginal1456')->assertExists($media->object_key);
        Storage::disk('wariant1456')->assertExists(Media::kluczPublicznegoWariantu($media->object_key, PodgladOdRazu::NAZWA));
    }

    public function test_odmowa_zapisu_do_jobs_nie_zostawia_wiersza_ani_plikow(): void
    {
        $this->kolejkaOdmawiaZapisu();

        $blad = null;

        try {
            $this->wgraj();
        } catch (\Throwable $e) {
            $blad = $e;
        }

        $this->assertNotNull($blad, 'Wgranie miało paść na zapisie zadania.');
        $this->assertSame(0, Media::query()->count(), 'Został wiersz `pending`, którego nie przetworzy żaden worker.');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame([], Storage::disk('oryginal1456')->allFiles(), 'Oryginał został w buckecie bez wiersza `media`.');
        $this->assertSame([], Storage::disk('wariant1456')->allFiles(), 'Publiczny podgląd został w buckecie bez wiersza `media`.');

        $this->assertInstanceOf(BladDlaCzlowieka::class, $blad, 'Człowiek dostałby stronę błędu zamiast komunikatu, co zrobić.');
        $this->assertStringContainsString('wyślij je jeszcze raz', $blad->getMessage());
        $this->assertNotNull($blad->getPrevious(), 'Pierwotny błąd kolejki zginął — operator nie zobaczy przyczyny.');
    }

    public function test_ponowienie_po_awarii_tworzy_jedno_poprawnie_zlecone_zdjecie(): void
    {
        $this->kolejkaOdmawiaZapisu();

        try {
            $this->wgraj();
        } catch (\Throwable) {
            // Treść błędu sprawdza test wyżej; tu liczy się tylko to, co zostało.
        }

        $this->kolejkaZnowuDziala();

        $media = $this->wgraj();

        $this->assertSame([$media->getKey()], Media::query()->pluck('id')->all());
        $this->assertSame([$media->getKey()], $this->idZdjecWKolejce());
    }

    /**
     * Człowiek widzi, co się stało i co zrobić — przy polu zdjęć, z tekstem
     * wpisu na miejscu — a nie stronę błędu po uploadzie, który w połowie
     * się udał.
     */
    public function test_formularz_wpisu_mowi_prawde_i_nie_gubi_tekstu(): void
    {
        $this->kolejkaOdmawiaZapisu();

        $this->actingAs($this->user('kucharz1456'))
            ->from(route('posts.create'))
            ->post(route('posts.store'), [
                'body' => 'Pierogi z kapustą na niedzielę.',
                'visibility' => 'public',
                'photos' => [$this->zdjecie()],
            ])
            ->assertRedirect(route('posts.create'))
            ->assertSessionHasErrors(['photos' => 'Nie udało się teraz zapisać zdjęcia. Nic się nie zapisało — wyślij je jeszcze raz za chwilę.'])
            ->assertSessionHasInput('body', 'Pierogi z kapustą na niedzielę.');

        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, Media::query()->count());
    }

    /**
     * UMOWA, NA KTÓREJ STOI TA TRANSAKCJA. Zadanie wchodzi do transakcji
     * wiersza tylko wtedy, gdy kolejka jest bazodanowa, na tym samym
     * połączeniu co `media` i NIE odkłada zapisu za commit. Zmiana którejś
     * z tych trzech rzeczy ma oblać ten test, żeby ktoś świadomie dobrał
     * inną strategię (outbox, reconciler) — zamiast po cichu otworzyć okno.
     */
    public function test_kolejka_zdjec_jest_bazodanowa_na_polaczeniu_media_i_bez_after_commit(): void
    {
        // Testy chodzą na `sync` (phpunit.xml), więc o produkcyjnym domyślnym
        // mówią plik konfiguracji i wzorzec środowiska, nie `config()`.
        $this->assertStringContainsString(
            "'default' => env('QUEUE_CONNECTION', 'database')",
            (string) file_get_contents(config_path('queue.php')),
        );
        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=database$/m', (string) file_get_contents(base_path('.env.example')));

        $kolejka = config('queue.connections.database');

        $this->assertSame('database', $kolejka['driver']);
        $this->assertFalse($kolejka['after_commit'], '`after_commit` przenosi zapis zadania za commit — wraca okno z issue #1456.');
        $this->assertSame(
            (new Media)->getConnection()->getName(),
            DB::connection($kolejka['connection'] ?? null)->getName(),
            'Kolejka na innym połączeniu niż `media` nie wchodzi do tej samej transakcji.',
        );

        $zadanie = new ProcessUploadedImage('00000000-0000-0000-0000-000000000000');

        $this->assertNull($zadanie->connection, 'Zadanie zdjęcia nie może mieć własnego połączenia kolejki.');
        $this->assertNotTrue($zadanie->afterCommit ?? null, 'Zadanie zdjęcia nie może czekać na commit.');
    }

    private function kolejkaOdmawiaZapisu(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION odmowa_jobs_1456() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'kolejka odmawia zapisu (test #1456)';
            END
            $$;
            CREATE TRIGGER odmowa_jobs_1456 BEFORE INSERT ON jobs
                FOR EACH ROW EXECUTE FUNCTION odmowa_jobs_1456();
            SQL);
    }

    private function kolejkaZnowuDziala(): void
    {
        DB::unprepared('DROP TRIGGER odmowa_jobs_1456 ON jobs; DROP FUNCTION odmowa_jobs_1456();');
    }

    /**
     * @return list<string>
     */
    private function idZdjecWKolejce(): array
    {
        return DB::table('jobs')->pluck('payload')
            ->map(function (string $payload): string {
                $dane = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(ProcessUploadedImage::class, $dane['displayName']);

                return unserialize($dane['data']['command'])->mediaId;
            })
            ->all();
    }

    private function wgraj(): Media
    {
        $this->wlasciciel ??= $this->user('kucharz1456wgr');

        return app(StoreUploadedImage::class)->handle(
            owner: $this->wlasciciel,
            file: $this->zdjecie(),
        );
    }

    private function zdjecie(): UploadedFile
    {
        $obraz = imagecreatetruecolor(400, 300);
        imagefill($obraz, 0, 0, imagecolorallocate($obraz, 180, 90, 30));

        $sciezka = tempnam(sys_get_temp_dir(), 'wgranie1456').'.jpg';
        imagejpeg($obraz, $sciezka, 90);
        imagedestroy($obraz);

        return new UploadedFile($sciezka, 'obiad.jpg', 'image/jpeg', null, true);
    }
}
