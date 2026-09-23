<?php

declare(strict_types=1);
use App\Models\LoginLinkToken;
use App\Models\RegistrationInvite;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Wyłącznie lokalny przyrząd: prawdziwy kernel, sesja, kontrolery i CSRF.
// Nie publikuje tras testowych. Atrapy odcinają pocztę, kolejkę i obce HTTP.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$db = config('database.connections.'.config('database.default'));
if (! app()->environment('local') || ($db['driver'] ?? '') !== 'pgsql'
    || ($db['host'] ?? '') !== '127.0.0.1'
    || ! preg_match('/^kuking_port_[a-z0-9_]+$/D', $db['database'] ?? '')
    || ! getenv('DB_PORT')) {
    throw new RuntimeException('REFERRER: wymagane local, własna baza kuking_port_* i jawny port');
}
config([
    'kuking.analytics.cloudflare.token' => 'synthetic-1052-analytics',
    'kuking.login_link.wlaczone' => true,
    'kuking.login_link.zaproszenia.wlaczone' => true,
    'kuking.account.registration_open' => true,
    'mail.default' => 'smtp',
    'kuking.turnstile.klucz_publiczny' => '',
    'kuking.turnstile.sekret' => '',
]);
Mail::fake();
Notification::fake();
Queue::fake();
Http::preventStrayRequests();
Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'fixture') {
        $user = User::factory()->create(['email' => 'referrer-'.Str::uuid().'@example.test']);
        $reset = Password::createToken($user);
        $link = LoginLinkToken::nowyToken();
        (new LoginLinkToken)->forceFill([
            'user_id' => $user->getKey(), 'token_hash' => LoginLinkToken::skrot($link),
            'created_at' => now(), 'expires_at' => now()->addMinutes(10),
        ])->save();
        $invite = RegistrationInvite::nowyToken();
        $inviteEmail = 'invite-'.Str::uuid().'@example.test';
        (new RegistrationInvite)->forceFill([
            'email' => $inviteEmail,
            'token_hash' => RegistrationInvite::skrot($invite),
            'created_at' => now(), 'expires_at' => now()->addDay(),
        ])->save();
        echo json_encode(['id' => $user->getKey(), 'email' => $user->email, 'reset' => $reset, 'link' => $link, 'invite' => $invite, 'inviteEmail' => $inviteEmail], JSON_THROW_ON_ERROR);
    } elseif (($argv[1] ?? '') === 'state') {
        $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $user = User::findOrFail($input['id']);
        echo json_encode([
            'password_changed' => Hash::check($input['password'], $user->password),
            'link_used' => ! LoginLinkToken::where('user_id', $user->getKey())->exists(),
        ], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('REFERRER: nieznana czynność fixture');
    }

    return;
}

$file = realpath(public_path(rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))));
if ($file !== false && str_starts_with($file, public_path().DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
