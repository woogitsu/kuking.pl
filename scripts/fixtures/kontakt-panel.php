<?php

declare(strict_types=1);

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'kuking_flota_gpt-kontakt-panel'
    || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (string) config('database.connections.pgsql.port') !== '55439' || config('mail.default') !== 'array') {
    throw new RuntimeException('Użyj własnej bazy floty na porcie 55439 i atrapy poczty.');
}
$path = $argv[2];
if (! str_starts_with($path, sys_get_temp_dir().'/kuking-kontakt-')) {
    throw new RuntimeException('Plik stanu musi leżeć w prywatnym katalogu tymczasowym.');
}
if ($argv[1] === 'cleanup') {
    $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    DB::table('audit_log')->where('subject_id', $state['message'])->delete();
    ContactMessage::whereKey($state['message'])->delete();
    DB::table('sessions')->where('user_id', $state['user'])->delete();
    User::whereKey($state['user'])->delete();
    exit;
}
$password = bin2hex(random_bytes(24));
$user = User::factory()->create(['role' => User::ROLE_MODERATOR, 'password' => Hash::make($password)]);
$secret = app(TwoFactorAuthenticator::class)->generateSecret();
$user->beginTwoFactorSetup($secret);
$user->confirmTwoFactor([]);
$message = ContactMessage::factory()->create(['contact_email' => 'fixture@example.test', 'message' => 'Syntetyczna wiadomość do pomiaru panelu.']);
file_put_contents($path, json_encode(['user' => $user->id, 'email' => $user->email, 'password' => $password, 'secret' => $secret, 'message' => $message->id], JSON_THROW_ON_ERROR));
chmod($path, 0600);
