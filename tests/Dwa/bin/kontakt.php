<?php

declare(strict_types=1);

use App\Domain\Contact\Actions\WyslijOdpowiedz;
use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection()->getDatabaseName();
if (! str_starts_with($database, 'kuking_flota_') && ! str_starts_with($database, 'kuking_race')) {
    throw new RuntimeException('Użyj izolowanej bazy testowej.');
}
DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '20s'");
DB::select("SELECT set_config('application_name', ?, false)", [$argv[4]]);
Mail::fake();
$reply = app(WyslijOdpowiedz::class)->handle(
    ContactMessage::findOrFail($argv[1]), User::findOrFail($argv[2]), 'Odpowiedź w wyścigu.', replyKey: $argv[3],
);
echo json_encode([
    'id' => $reply->getKey(),
    'sent' => Mail::sent(OdpowiedzNaWiadomosc::class)->count(),
    'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
], JSON_THROW_ON_ERROR);
