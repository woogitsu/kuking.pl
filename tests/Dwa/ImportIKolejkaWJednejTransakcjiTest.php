<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Import\KlientLuna;
use App\Domain\Import\Pdf\ZlecImportZPdf;
use App\Domain\Import\Url\ZlecImportZAdresu;
use App\Domain\Import\ZlecImportPrzepisu;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\ImportPrzepisu;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MalyPdf;

/**
 * #2402: rzeczywiste akcje importu i kolejka database, bez Queue::fake.
 * Osobny backend PostgreSQL widzi obie części dopiero po commicie.
 * Test nie wykonuje zadań ani płatnych wywołań modelu.
 */
#[Group('dwa-polaczenia')]
final class ImportIKolejkaWJednejTransakcjiTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $imports = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
            'kuking.import.kolejka' => 'low',
            'kuking.import.zrodla.zdjecie' => true,
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
            'kuking.import.budzet.dzienny_usd' => '50',
            'kuking.import.budzet.miesieczny_usd' => '500',
        ]);

        // Celowo dostępna dla kontroli ujemnej: ten sam serwer i baza,
        // lecz INNE połączenie. To wystarczy do złamania atomowości.
        config([
            'database.connections.import_independent' => config('database.connections.pgsql'),
            'queue.connections.import_independent' => array_replace(
                config('queue.connections.database'),
                ['connection' => 'import_independent', 'after_commit' => false],
            ),
        ]);

        Storage::fake('public');
        Storage::fake((string) config('kuking.import.pdf.dysk'));
        Http::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // Tylko wiersze tego testu; bez truncate współdzielonej kolejki.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->imports as $id) {
            DB::table('jobs')->where('payload', 'like', '%'.$id.'%')->delete();
        }
        foreach ($this->konta as $id) {
            foreach (DB::table('media')->where('owner_id', $id)->pluck('id') as $mediaId) {
                DB::table('jobs')->where('payload', 'like', '%'.$mediaId.'%')->delete();
            }
        }
        DB::disconnect('import_independent');

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function sources(): array
    {
        return ['url' => ['url'], 'pdf' => ['pdf'], 'zdjecie' => ['zdjecie']];
    }

    #[DataProvider('sources')]
    public function test_worker_sees_import_and_job_only_after_commit(string $source): void
    {
        $author = $this->author();
        DB::beginTransaction();
        try {
            $import = $this->submit($author, $source);
            $this->assertQueuedInsideTransaction($import);
            $this->assertObserverCounts($import, 0);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        // Kontrola dodatnia: puste wyniki przed commitem nie wystarczają.
        $this->assertObserverCounts($import, 1);
        Http::assertNothingSent();
    }

    #[DataProvider('sources')]
    public function test_rollback_removes_both_import_and_job(string $source): void
    {
        $author = $this->author();
        DB::beginTransaction();
        try {
            $import = $this->submit($author, $source);
            $this->assertQueuedInsideTransaction($import);
            $this->assertObserverCounts($import, 0);
        } finally {
            DB::rollBack();
        }

        $this->assertObserverCounts($import, 0);
        $this->assertSame(0, DB::table('proby_importu')->where('import_id', $import->getKey())->count());
        Http::assertNothingSent();
    }

    private function author(): User
    {
        $author = $this->konto();
        app(PrzestawZgodeNaOdczytAi::class)->handle($author, true, WpisZgody::ZRODLO_EKRAN_IMPORTU);

        $this->assertNotSame(
            (int) DB::selectOne('select pg_backend_pid() as pid')->pid,
            (int) $this->odczytaj($this->obserwator, 'select pg_backend_pid()'),
        );

        return $author;
    }

    private function submit(User $author, string $source): ImportPrzepisu
    {
        $key = (string) Str::uuid();
        $pdf = UploadedFile::fake()->createWithContent('przepis.pdf', MalyPdf::bezTekstu());
        $import = match ($source) {
            'url' => app(ZlecImportZAdresu::class)->handle($author, 'https://example.org/przepis', false, $key),
            'pdf' => app(ZlecImportZPdf::class)->handle(
                $author,
                $pdf->getPathname(),
                false,
                $key,
            ),
            'zdjecie' => app(ZlecImportPrzepisu::class)->handle(
                $author, UploadedFile::fake()->image('kartka.jpg', 400, 500), $key,
            ),
            default => throw new \LogicException('Nieznane źródło testowe importu.'),
        };
        $this->imports[] = (string) $import->getKey();
        $this->assertSame(ImportPrzepisu::STATUS_OCZEKUJE, $import->status);

        return $import;
    }

    private function assertQueuedInsideTransaction(ImportPrzepisu $import): void
    {
        $this->assertSame(1, DB::table('importy_przepisow')->where('id', $import->getKey())->count());
        $this->assertSame(
            1,
            DB::table('jobs')->where('queue', 'low')->where('payload', 'like', '%'.$import->getKey().'%')->count(),
            'IMPORT_OUTBOX_JOB_IN_TRANSACTION: zadanie musi powstać w tej samej transakcji co import.',
        );
    }

    private function assertObserverCounts(ImportPrzepisu $import, int $expected): void
    {
        $query = $this->obserwator->prepare('select count(*) from importy_przepisow where id = ?');
        $query->execute([$import->getKey()]);
        $this->assertSame($expected, (int) $query->fetchColumn());

        $query = $this->obserwator->prepare("select count(*) from jobs where queue = 'low' and payload like ?");
        $query->execute(['%'.$import->getKey().'%']);
        $this->assertSame(
            $expected,
            (int) $query->fetchColumn(),
            'IMPORT_OUTBOX_WORKER_VISIBILITY: osobne połączenie workera musi widzieć job razem z importem.',
        );
    }
}
