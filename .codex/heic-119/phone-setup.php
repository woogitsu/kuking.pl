<?php
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.port') != 55439 || config('database.connections.pgsql.database') !== 'kuking_flota_gpt-heic-format') { throw new RuntimeException('Niewlasciwa baza'); }
$user = App\Models\User::where('email', 'heic119@example.test')->first();
if (!$user) {
    $user = App\Models\User::factory()->create(['email' => 'heic119@example.test', 'wants_weekly_digest' => false]);
    $user->profile->update(['username' => 'probaheic119', 'display_name' => 'Próba zdjęć']);
}
echo "Konto lokalne gotowe\n";