<?php

declare(strict_types=1);
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local', 'testing') || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (string) config('database.connections.pgsql.port') !== '55439'
    || config('database.connections.pgsql.database') !== 'kuking_flota_gpt-onboarding') {
    throw new RuntimeException('Użyj własnej bazy onboardingu na porcie 55439.');
}
$names = ['proba851_widz', 'proba851_halina', 'proba851_marek', 'proba851_cezary'];
if (($argv[1] ?? '') === 'read') {
    $viewer = User::findByLogin($names[0]);
    echo json_encode($viewer->following()->with('profile')->get()->pluck('profile.username')->all());
    exit;
}
if (($argv[1] ?? '') === 'cleanup') {
    foreach ($names as $name) {
        $user = User::findByLogin($name);
        if ($user !== null) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceDelete();
        }
    }
    exit;
}
foreach ($names as $name) {
    if (User::findByLogin($name)) {
        throw new RuntimeException('Usuń poprzednie dane próby przed uruchomieniem.');
    }
    $user = User::factory()->create();
    Profile::where('user_id', $user->id)->delete();
    Profile::create(['user_id' => $user->id, 'username' => $name, 'display_name' => $name]);
    if ($name === $names[1]) {
        Post::factory()->create(['author_id' => $user->id, 'visibility' => 'public', 'published_at' => now()]);
    }
}
$session = app('session')->driver();
$session->start();
Auth::login(User::findByLogin($names[0]));
$session->save();
$cookie = config('session.cookie');
$value = CookieValuePrefix::create($cookie, app('encrypter')->getKey()).$session->getId();
echo json_encode(['name' => $cookie, 'value' => app('encrypter')->encrypt($value, false)]);
