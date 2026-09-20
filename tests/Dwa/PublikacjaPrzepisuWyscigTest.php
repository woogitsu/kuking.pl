<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Dwa procesy, bariera po MAX i pomiar blokady drugiego procesu w PostgreSQL. */
#[Group('dwa-polaczenia')]
class PublikacjaPrzepisuWyscigTest extends TestCase
{
    public static function paths(): array
    {
        return ['publikacja' => ['publish'], 'sama migawka' => ['snapshot']];
    }

    #[DataProvider('paths')]
    public function test_dwie_publikacje_maja_wlasne_spojne_migawki(string $mode): void
    {
        $database = DB::connection()->getDatabaseName();
        $this->assertTrue(str_starts_with($database, 'kuking_race') || str_starts_with($database, 'kuking_flota_'));
        $this->assertSame(0, DB::transactionLevel(), 'Dane muszą być zatwierdzone dla obu procesów.');
        $author = $this->user();
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Przepis początkowy'], [], [['instruction' => 'Zacznij.']], true);
        $directory = sys_get_temp_dir().'/kuking-895-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        try {
            foreach (['A', 'B'] as $label) {
                $process = new Process([PHP_BINARY, base_path('tests/Dwa/bin/publikacja-przepisu.php'), $recipe->id, $directory, $label, $mode], base_path(), [
                    'DB_DATABASE' => $database,
                    'DB_HOST' => (string) config('database.connections.pgsql.host'),
                    'DB_PORT' => (string) config('database.connections.pgsql.port'),
                    'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
                    'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
                    'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[$label] = $process;
                if ($label === 'A') {
                    $this->waitUntil(fn () => is_file($directory.'/ready-A'), 'Pierwszy proces nie dotarł do bariery po MAX.');
                }
            }
            $this->waitUntil(fn () => is_file($directory.'/pid-B'), 'Drugi proces nie połączył się z bazą.');
            $pidA = (int) file_get_contents($directory.'/pid-A');
            $pidB = (int) file_get_contents($directory.'/pid-B');
            $this->assertNotSame($pidA, $pidB);
            $this->waitUntil(function () use ($directory, $pidA, $pidB): bool {
                // Stary kod dochodzi do drugiego MAX; naprawiony czeka na pierwszy zapis.
                return is_file($directory.'/ready-B') || (bool) DB::selectOne('SELECT ?::int = ANY(pg_blocking_pids(?::int)) AS blocked', [$pidA, $pidB])->blocked;
            }, 'Nie zmierzono przeplotu ani blokady między uczestnikami.');
            touch($directory.'/release');
            $results = [];
            foreach ($processes as $label => $process) {
                $process->wait();
                $results[$label] = ['exit' => $process->getExitCode(), 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()];
            }
            fwrite(STDERR, "\nPomiar #895: ".json_encode(['mode' => $mode, 'backend_pids' => [$pidA, $pidB], 'processes' => $results], JSON_UNESCAPED_UNICODE)."\n");
            foreach ($results as $result) {
                $this->assertSame(0, $result['exit'], json_encode($results, JSON_UNESCAPED_UNICODE));
            }
            $versions = $recipe->versions()->reorder('version_number')->get();
            $this->assertSame([1, 2, 3], $versions->pluck('version_number')->all());
            if ($mode === 'snapshot') {
                $this->assertSame(['Przepis początkowy', 'Przepis początkowy', 'Przepis początkowy'], $versions->map(fn ($version) => $version->snapshot['title'])->all());

                return;
            }
            foreach (['A', 'B'] as $index => $label) {
                $snapshot = $versions[$index + 1]->snapshot;
                $this->assertSame('Publikacja '.$label, $snapshot['title']);
                $this->assertSame('Składnik '.$label, $snapshot['ingredients'][0]['text']);
                $this->assertSame('Krok '.$label, $snapshot['steps'][0]['instruction']);
            }
            $this->assertSame('Publikacja B', $recipe->fresh()->title);
            $this->assertSame(3, AuditLogEntry::where('subject_id', $recipe->id)->where('action', 'recipe.published')->count());
        } finally {
            touch($directory.'/release');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            AuditLogEntry::where('actor_id', $author->id)->delete();
            Recipe::whereKey($recipe->id)->forceDelete();
            $author->forceDelete();
        }
    }

    private function waitUntil(callable $condition, string $message): void
    {
        $deadline = microtime(true) + 15;
        do {
            if ($condition()) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail($message);
    }
}
