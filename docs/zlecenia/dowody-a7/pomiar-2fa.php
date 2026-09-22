<?php

declare(strict_types=1);

/**
 * A7-2 / e572743 — dwie równoległe próby na DWÓCH procesach PHP i DWÓCH
 * backendach PostgreSQL. To nie jest przeplot dwóch obiektów w jednym procesie.
 *
 * Uruchomienie:
 *   DB_DATABASE=kuking_test_a7_probe php docs/zlecenia/dowody-a7/pomiar-2fa.php
 */

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if ((string) DB::connection()->getDatabaseName() !== 'kuking_test_a7_probe') {
    fwrite(STDERR, "ODMOWA: ten skrypt działa wyłącznie na kuking_test_a7_probe.\n");
    exit(2);
}
if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "Brak pcntl_fork — pomiar wymaga dwóch procesów.\n");
    exit(3);
}

function nowMs(): float
{
    return hrtime(true) / 1_000_000;
}

function uruchomPare(string $scenariusz, callable $przygotuj, callable $akcjaA, callable $akcjaB, int $opoznienieBMs = 0): array
{
    $katalog = sys_get_temp_dir().'/kuking-a7-2fa-'.bin2hex(random_bytes(5));
    mkdir($katalog, 0700, true);
    $przygotuj();

    $pidy = [];
    foreach (['A' => $akcjaA, 'B' => $akcjaB] as $nazwa => $akcja) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork nie powiódł się.');
        }
        if ($pid === 0) {
            DB::disconnect();
            DB::reconnect();
            $backend = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
            file_put_contents("{$katalog}/ready-{$nazwa}", (string) $backend);
            while (! is_file("{$katalog}/go-{$nazwa}")) {
                usleep(1_000);
            }
            $start = nowMs();
            try {
                $wynik = $akcja();
                $blad = null;
            } catch (Throwable $e) {
                $wynik = null;
                $blad = get_class($e).': '.$e->getMessage();
            }
            $koniec = nowMs();
            file_put_contents("{$katalog}/result-{$nazwa}.json", json_encode([
                'backend_pid' => $backend,
                'result' => $wynik,
                'error' => $blad,
                'wall_ms' => round($koniec - $start, 3),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            exit($blad === null ? 0 : 1);
        }
        $pidy[$nazwa] = $pid;
    }

    $deadline = microtime(true) + 10;
    while ((! is_file("{$katalog}/ready-A") || ! is_file("{$katalog}/ready-B")) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file("{$katalog}/ready-A") || ! is_file("{$katalog}/ready-B")) {
        throw new RuntimeException("Procesy {$scenariusz} nie zgłosiły gotowości.");
    }

    touch("{$katalog}/go-A");
    if ($opoznienieBMs > 0) {
        usleep($opoznienieBMs * 1000);
    }
    touch("{$katalog}/go-B");

    foreach ($pidy as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $a = json_decode((string) file_get_contents("{$katalog}/result-A.json"), true, 512, JSON_THROW_ON_ERROR);
    $b = json_decode((string) file_get_contents("{$katalog}/result-B.json"), true, 512, JSON_THROW_ON_ERROR);

    foreach (glob("{$katalog}/*") ?: [] as $plik) {
        @unlink($plik);
    }
    @rmdir($katalog);

    return ['scenario' => $scenariusz, 'A' => $a, 'B' => $b];
}

$auth = app(TwoFactorAuthenticator::class);
$secret = $auth->generateSecret();
$id = (string) Str::uuid();
$teraz = now();
DB::table('users')->insert([
    'id' => $id,
    'email' => 'a7-2fa@example.invalid',
    'password' => Hash::make('nieuzywane'),
    'status' => User::STATUS_ACTIVE,
    'role' => User::ROLE_USER,
    'locale' => 'pl',
    'text_scale' => 100,
    'wants_weekly_digest' => false,
    'created_at' => $teraz,
    'updated_at' => $teraz,
]);
$user = User::query()->findOrFail($id);
$user->forceFill([
    'two_factor_secret' => $secret,
    'two_factor_confirmed_at' => now(),
    'two_factor_last_used_at' => null,
])->save();
$kod = (new Google2FA)->getCurrentOtp($secret);

$totp = uruchomPare(
    'ten_sam_TOTP',
    static function () use ($id): void {
        User::query()->whereKey($id)->update(['two_factor_last_used_at' => null]);
    },
    static function () use ($id, $secret, $kod): bool {
        $u = User::query()->findOrFail($id);

        return app(TwoFactorAuthenticator::class)->verifyCode($u, $secret, $kod);
    },
    static function () use ($id, $secret, $kod): bool {
        $u = User::query()->findOrFail($id);

        return app(TwoFactorAuthenticator::class)->verifyCode($u, $secret, $kod);
    },
);
$totp['final_last_used_at'] = User::query()->findOrFail($id)->two_factor_last_used_at;
$totp['exactly_one_accepted'] = ((bool) $totp['A']['result'] xor (bool) $totp['B']['result']);
$totp['different_pg_backends'] = $totp['A']['backend_pid'] !== $totp['B']['backend_pid'];

// Druga sonda: koszt blokady podczas pełnej pętli Hash::check.
// Osiem hashy z kosztem 12 symuluje koszt produkcyjny niezależnie od BCRYPT_ROUNDS w CI.
$kodyJawne = [];
$hashe = [];
for ($i = 1; $i <= 8; $i++) {
    $plain = sprintf('A7%02d-CODE', $i);
    $kodyJawne[] = $plain;
    $hashe[] = Hash::make($plain, ['rounds' => 12]);
}
$user = User::query()->findOrFail($id);
$user->forceFill(['two_factor_backup_codes' => $hashe])->save();

$backup = uruchomPare(
    'bledny_kod_trzyma_blokade_przed_poprawnym',
    static function () use ($id, $hashe): void {
        $u = User::query()->findOrFail($id);
        $u->forceFill(['two_factor_backup_codes' => $hashe])->save();
    },
    static function () use ($id): bool {
        $u = User::query()->findOrFail($id);

        return app(TwoFactorAuthenticator::class)->consumeBackupCode($u, 'NIE-MA-GO');
    },
    static function () use ($id, $kodyJawne): bool {
        $u = User::query()->findOrFail($id);

        return app(TwoFactorAuthenticator::class)->consumeBackupCode($u, $kodyJawne[7]);
    },
    120,
);
$backup['different_pg_backends'] = $backup['A']['backend_pid'] !== $backup['B']['backend_pid'];
$backup['limiter_config'] = (string) config('kuking.limits.two_factor');
$backup['remaining_backup_codes'] = count(User::query()->findOrFail($id)->two_factor_backup_codes ?? []);

$wynik = [
    'generated_at_utc' => gmdate('c'),
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'totp_parallel' => $totp,
    'backup_code_lock' => $backup,
    'interpretation_guard' => [
        'totp_ok' => $totp['exactly_one_accepted'] && $totp['different_pg_backends'],
        'backup_second_succeeded' => $backup['B']['result'] === true,
        'note' => 'To dwa procesy i dwa backendy PostgreSQL. Warstwa HTTP nie jest tu mierzona; mierzony jest krytyczny odcinek, który ma szeregować lockForUpdate().',
    ],
];

$sciezka = __DIR__.'/2fa-wynik.json';
file_put_contents($sciezka, json_encode($wynik, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo file_get_contents($sciezka);
