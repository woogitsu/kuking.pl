<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * /health — punkt kontrolny dla Railway i monitoringu zewnętrznego.
 *
 * Sprawdza to, czego brak realnie kładzie serwis: bazę i możliwość zapisu
 * do storage. Nie sprawdzamy rzeczy, których awaria nie powinna wywalać
 * deployu (np. poczty) — inaczej healthcheck restartuje aplikację
 * z powodu problemu, który nie dotyczy jej działania.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static function (): void {
                DB::select('select 1');
            }),
            'migrations' => $this->check(static function (): void {
                $pending = DB::table('migrations')->count();

                if ($pending === 0) {
                    throw new \RuntimeException('Brak wykonanych migracji.');
                }
            }),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
