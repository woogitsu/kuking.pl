<?php

declare(strict_types=1);

/**
 * A7-2 — dwie niezależne sondy:
 * - 9ec662f: co zostaje w bazie, gdy TrescZalazkowaSeeder padnie po kontach;
 * - 2e1b882: kalendarzowe odejmowanie miesięcy w Europe/Warsaw przy DST.
 *
 * Uruchomienie wyłącznie na osobnej bazie:
 *   DB_DATABASE=kuking_test_a7_probe php docs/zlecenia/dowody-a7/pomiar-seed-retencja.php
 */

use Carbon\CarbonImmutable;
use Database\Seeders\TrescZalazkowaSeeder;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if ((string) DB::connection()->getDatabaseName() !== 'kuking_test_a7_probe') {
    fwrite(STDERR, "ODMOWA: ten skrypt działa wyłącznie na kuking_test_a7_probe.\n");
    exit(2);
}

function fresh(): void
{
    Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
}

// -------------------------------------------------------------------------
// 1. Seeder: wymuszamy awarię na pierwszym INSERT-cie przepisu. Konta są
// tworzone wcześniej. Jeżeli cały seeder jest atomowy, po wyjątku ma zostać 0.
// -------------------------------------------------------------------------
fresh();
DB::statement('ALTER TABLE recipes ADD CONSTRAINT a7_wymuszona_awaria CHECK (false)');
$blad = null;
try {
    app(TrescZalazkowaSeeder::class)->run();
} catch (Throwable $e) {
    $blad = get_class($e).': '.$e->getMessage();
}

$partial = [
    'exception' => $blad,
    'seeded_users_after_failure' => (int) DB::table('users')->where('is_seeded', true)->count(),
    'profiles_after_failure' => (int) DB::table('profiles')->count(),
    'recipes_after_failure' => (int) DB::table('recipes')->count(),
    'posts_after_failure' => (int) DB::table('posts')->count(),
    'transaction_open_after_failure' => DB::transactionLevel(),
];

// -------------------------------------------------------------------------
// 2. Idempotencja, gdy po pierwszym przebiegu konto przykładowe zniknie,
// a przed drugim prawdziwy użytkownik zajmie tę samą nazwę "basia".
// -------------------------------------------------------------------------
fresh();
app(TrescZalazkowaSeeder::class)->run();
$przed = [
    'seeded_users' => (int) DB::table('users')->where('is_seeded', true)->count(),
    'recipes' => (int) DB::table('recipes')->count(),
    'posts' => (int) DB::table('posts')->count(),
    'comments' => (int) DB::table('comments')->count(),
];

$basiaSeed = DB::table('profiles')->whereRaw('lower(username) = ?', ['basia'])->first();
if ($basiaSeed === null) {
    throw new RuntimeException('Pierwszy przebieg nie utworzył profilu basia.');
}
DB::table('users')->where('id', $basiaSeed->user_id)->delete();

$humanId = (string) Str::uuid();
$teraz = now();
DB::table('users')->insert([
    'id' => $humanId,
    'email' => 'prawdziwa-basia@example.invalid',
    'password' => Hash::make('nieuzywane'),
    'status' => 'active',
    'role' => 'user',
    'is_seeded' => false,
    'locale' => 'pl',
    'text_scale' => 100,
    'wants_weekly_digest' => false,
    'created_at' => $teraz,
    'updated_at' => $teraz,
]);
DB::table('profiles')->insert([
    'user_id' => $humanId,
    'username' => 'basia',
    'display_name' => 'Prawdziwa Basia A7',
    'created_at' => $teraz,
    'updated_at' => $teraz,
]);

$bladDrugiego = null;
try {
    app(TrescZalazkowaSeeder::class)->run();
} catch (Throwable $e) {
    $bladDrugiego = get_class($e).': '.$e->getMessage();
}

$po = [
    'exception' => $bladDrugiego,
    'profiles_username_basia' => (int) DB::table('profiles')->whereRaw('lower(username) = ?', ['basia'])->count(),
    'basia_is_human' => DB::table('users')->where('id', $humanId)->where('is_seeded', false)->exists(),
    'seeded_users' => (int) DB::table('users')->where('is_seeded', true)->count(),
    'recipes' => (int) DB::table('recipes')->count(),
    'posts' => (int) DB::table('posts')->count(),
    'comments' => (int) DB::table('comments')->count(),
];

// -------------------------------------------------------------------------
// 3. Europe/Warsaw: przed i po obu zmianach czasu oraz koniec miesiąca.
// Oczekiwanie: kalendarzowy dzień i godzina lokalna zostają zachowane,
// a nieistniejący dzień miesiąca jest cofnięty do ostatniego istniejącego.
// -------------------------------------------------------------------------
$przypadki = [
    ['2026-05-31 12:00:00', 3, '2026-02-28 12:00:00'],
    ['2026-03-29 01:30:00', 3, '2025-12-29 01:30:00'],
    ['2026-03-29 03:30:00', 3, '2025-12-29 03:30:00'],
    ['2026-10-25 01:30:00', 3, '2026-07-25 01:30:00'],
    ['2026-10-25 03:30:00', 3, '2026-07-25 03:30:00'],
    ['2028-02-29 12:00:00', 12, '2027-02-28 12:00:00'],
];
$retencja = [];
foreach ($przypadki as [$wejscie, $miesiace, $oczekiwane]) {
    $start = CarbonImmutable::parse($wejscie, 'Europe/Warsaw');
    $wynik = $start->subMonthsNoOverflow($miesiace);
    $retencja[] = [
        'input_local' => $start->format('Y-m-d H:i:s P T'),
        'months' => $miesiace,
        'result_local' => $wynik->format('Y-m-d H:i:s P T'),
        'expected_wall_time' => $oczekiwane,
        'wall_time_ok' => $wynik->format('Y-m-d H:i:s') === $oczekiwane,
        'input_utc' => $start->utc()->format('Y-m-d H:i:s P'),
        'result_utc' => $wynik->utc()->format('Y-m-d H:i:s P'),
    ];
}

$wynik = [
    'generated_at_utc' => gmdate('c'),
    'postgres_version' => (string) DB::selectOne('select version() as v')->v,
    'seed_partial_failure' => $partial,
    'seed_human_takes_username_between_runs' => [
        'before' => $przed,
        'after' => $po,
        'second_run_completed' => $bladDrugiego === null,
        'human_preserved' => $po['profiles_username_basia'] === 1 && $po['basia_is_human'] === true,
    ],
    'retention_europe_warsaw' => [
        'all_wall_times_ok' => ! in_array(false, array_column($retencja, 'wall_time_ok'), true),
        'cases' => $retencja,
    ],
];

$sciezka = __DIR__.'/seed-retencja-wynik.json';
file_put_contents($sciezka, json_encode($wynik, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo file_get_contents($sciezka);
