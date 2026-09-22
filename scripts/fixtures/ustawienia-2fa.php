<?php

declare(strict_types=1);

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Wyłącznie lokalny fixture tego pomiaru, bez danych ani poczty produkcyjnej.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.host') !== '127.0.0.1' || (int) config('database.connections.pgsql.port') !== 55439 || config('database.connections.pgsql.database') !== 'kuking_flota_gpt-2fa-ustawienia') {
    throw new RuntimeException('Odmowa: pomiar wymaga własnej lokalnej bazy.');
}
$user = User::where('email', 'pomiar2fa@example.test')->first();
if (! $user) {
    $user = User::factory()->create(['email' => 'pomiar2fa@example.test']);
    Profile::firstOrCreate(['user_id' => $user->id], ['username' => 'pomiar2fa', 'display_name' => 'Pomiar lokalny']);
}
$user->assignPassword('haslo-lokalnego-pomiaru')->save();
$totp = app(TwoFactorAuthenticator::class);
$user->beginTwoFactorSetup($totp->generateSecret());
$user->confirmTwoFactor($totp->hashBackupCodes(['TEST-TEST']));
echo "Fixture gotowy.\n";
