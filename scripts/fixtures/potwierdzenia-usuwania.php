<?php

declare(strict_types=1);

// Dane tylko dla osobnej, jednorazowej bazy testu przeglądarkowego.
use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$baza = (string) DB::connection()->getDatabaseName();
$port = (string) DB::connection()->getConfig('port');
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'pgsql'
    || DB::connection()->getConfig('host') !== '127.0.0.1'
    || ! preg_match('/^kuking_port_confirm_[a-z0-9_]+$/', $baza)
    || ! ctype_digit($port) || (int) $port === 5432 || $port !== getenv('CONFIRM_BROWSER_DB_PORT')) {
    throw new RuntimeException('Fixture wymaga własnej bazy kuking_port_confirm_* na jawnym lokalnym porcie PostgreSQL 18+.');
}
if (DB::selectOne('select current_setting(\'server_version_num\')::integer as v')->v < 180000) {
    throw new RuntimeException('Fixture wymaga PostgreSQL 18+.');
}

$akcja = $argv[1] ?? '';
$stanPath = $argv[2] ?? '';
if (! str_starts_with($stanPath, sys_get_temp_dir().DIRECTORY_SEPARATOR.'kuking-confirm-')) {
    throw new RuntimeException('Stan fixture musi być w prywatnym katalogu tymczasowym.');
}

if ($akcja === 'przygotuj') {
    $haslo = bin2hex(random_bytes(24));
    $konto = User::factory()->create(['password' => Hash::make($haslo)]);
    $zakup = new ShoppingListItem(['text' => 'Mleko do naleśników']);
    $zakup->user_id = $konto->getKey();
    $zakup->source = ShoppingListItem::SOURCE_MANUAL;
    $zakup->position = 0;
    $zakup->save();
    $produkt = $konto->pantryItems()->create(['name' => 'Śmietanka', 'quantity_note' => 'pół kartonu']);
    $produkt->forceFill([
        'expires_on' => now('Europe/Warsaw')->addDays(8)->toDateString(),
        'expiry_kind' => 'use_by',
        'frozen' => true,
    ])->save();
    $plan = new MealPlanEntry(['day' => now('Europe/Warsaw')->toDateString(), 'label' => 'Obiad u mamy']);
    $plan->user_id = $konto->getKey();
    $plan->save();
    $stan = [
        'user' => $konto->getKey(), 'email' => $konto->email, 'password' => $haslo,
        'zakupy' => $zakup->getKey(), 'spizarnia' => $produkt->getKey(), 'planer' => $plan->getKey(),
        'paths' => [
            'zakupy' => '/lista-zakupow',
            'spizarnia' => '/co-mam-w-domu',
            'planer' => '/planer',
        ],
        'names' => [
            'zakupy' => 'Mleko do naleśników', 'spizarnia' => 'Śmietanka', 'planer' => 'Obiad u mamy',
        ],
    ];
    file_put_contents($stanPath, json_encode($stan, JSON_THROW_ON_ERROR));
    chmod($stanPath, 0600);
    echo json_encode(['ids' => array_intersect_key($stan, array_flip(['zakupy', 'spizarnia', 'planer']))], JSON_THROW_ON_ERROR);
    exit;
}

$stan = json_decode(file_get_contents($stanPath), true, flags: JSON_THROW_ON_ERROR);
if ($akcja === 'sprawdz') {
    echo json_encode([
        'zakupy' => ShoppingListItem::find($stan['zakupy'])?->only(['text', 'source', 'position']),
        'spizarnia' => PantryItem::find($stan['spizarnia'])?->only(['name', 'quantity_note', 'expires_on', 'expiry_kind', 'frozen']),
        'planer' => MealPlanEntry::find($stan['planer'])?->only(['day', 'label']),
    ], JSON_THROW_ON_ERROR);
    exit;
}
if ($akcja === 'posprzataj') {
    ShoppingListItem::whereKey($stan['zakupy'])->delete();
    PantryItem::whereKey($stan['spizarnia'])->delete();
    MealPlanEntry::whereKey($stan['planer'])->delete();
    DB::table('sessions')->where('user_id', $stan['user'])->delete();
    User::whereKey($stan['user'])->delete();
    exit;
}
throw new InvalidArgumentException('Nieznana akcja fixture.');
