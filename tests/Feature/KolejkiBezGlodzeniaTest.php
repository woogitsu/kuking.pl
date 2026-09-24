<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Żadna kolejka z producentem nie głoduje za zaległością innej (issue #1030).
 *
 * Test jedzie na PRAWDZIWYM sterowniku `database` i prawdziwym
 * `Illuminate\Queue\Worker` — tym samym, który uruchamia `queue:work`.
 * Procesy z `docker/entrypoint.sh` (lista `QUEUE_WORKERS`) symulujemy
 * na zmianę: każdy obrót to jedno `runNextJob()` na proces, a przed każdym
 * obrotem kolejka `default` jest dopełniana, więc nigdy nie pustoszeje.
 *
 * Kontrola ujemna jest w tym samym pliku: dawny pojedynczy worker
 * `high,default,media,low` przy tej samej zaległości nie bierze ani zdjęcia,
 * ani eksportu. Kontrola dodatnia (zmierzona 24.09.2026): wpisanie w
 * entrypoincie `QUEUE_WORKERS:-high,default,media,low` wywraca
 * test_media_i_low_ruszaja_mimo_stalej_zaleglosci_default.
 */
class KolejkiBezGlodzeniaTest extends TestCase
{
    use RefreshDatabase;

    /** Ile obrotów wszystkich procesów wolno czekać na zdjęcie i eksport. */
    private const OBROTY = 3;

    /** Tyle zadań `default` czeka zawsze przed każdym obrotem. */
    private const ZALEGLOSC = 5;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        ZadanieZnacznik::$wykonane = [];
    }

    /** @return list<string> */
    private function procesyZEntrypointu(): array
    {
        $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));
        $this->assertSame(1, preg_match('/QUEUE_WORKERS:-([a-z, ]+)\}/', $entrypoint, $trafienie));

        return preg_split('/ +/', trim($trafienie[1]));
    }

    /**
     * @param  list<string>  $procesy
     * @return array<string, int> kolejka => w którym obrocie ruszyła (0 = nigdy)
     */
    private function obracaj(array $procesy, int $obroty): array
    {
        Queue::pushOn('media', new ZadanieZnacznik('media'));
        Queue::pushOn('low', new ZadanieZnacznik('low'));

        $worker = $this->app->make('queue.worker');
        $opcje = new WorkerOptions(sleep: 0, maxTries: 1, timeout: 0);
        $pierwszy = ['media' => 0, 'low' => 0];

        for ($obrot = 1; $obrot <= $obroty; $obrot++) {
            $brakuje = self::ZALEGLOSC - DB::table('jobs')->where('queue', 'default')->count();
            for ($i = 0; $i < $brakuje; $i++) {
                Queue::pushOn('default', new ZadanieZnacznik('default'));
            }

            foreach ($procesy as $lista) {
                /** @var Worker $worker */
                $worker->runNextJob('database', $lista, $opcje);
            }

            foreach (['media', 'low'] as $kolejka) {
                if ($pierwszy[$kolejka] === 0 && in_array($kolejka, ZadanieZnacznik::$wykonane, true)) {
                    $pierwszy[$kolejka] = $obrot;
                }
            }
        }

        $this->assertGreaterThan(
            0,
            DB::table('jobs')->where('queue', 'default')->count(),
            'Kontrola: zaległość `default` ma trwać przez cały test.',
        );

        return $pierwszy;
    }

    public function test_media_i_low_ruszaja_mimo_stalej_zaleglosci_default(): void
    {
        $pierwszy = $this->obracaj($this->procesyZEntrypointu(), self::OBROTY);

        foreach ($pierwszy as $kolejka => $obrot) {
            $this->assertGreaterThan(
                0,
                $obrot,
                "Zadanie z kolejki `{$kolejka}` nie ruszyło w ".self::OBROTY.' obrotach przy stałej zaległości `default` — głodzenie (#1030).',
            );
        }
        $this->assertContains('default', ZadanieZnacznik::$wykonane, 'Kontrola: `default` też ma być obsługiwany.');
    }

    public function test_kontrola_ujemna_jeden_worker_ze_scislym_priorytetem_glodzi(): void
    {
        $pierwszy = $this->obracaj(['high,default,media,low'], self::OBROTY * 10);

        $this->assertSame(
            ['media' => 0, 'low' => 0],
            $pierwszy,
            'Dawny pojedynczy worker powinien głodzić `media` i `low` przy stałej zaległości `default` — '
            .'jeśli nie głodzi, ten test nie mierzy już tego, co twierdzi.',
        );
    }
}

/** Zadanie, które tylko zapisuje, z jakiej kolejki przyszło. */
class ZadanieZnacznik implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<string> */
    public static array $wykonane = [];

    public function __construct(public string $znacznik) {}

    public function handle(): void
    {
        self::$wykonane[] = $this->znacznik;
    }
}
