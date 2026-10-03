<?php

declare(strict_types=1);

/** S4: dokładnie jedno rzeczywiste żądanie HTTP w świeżym procesie. */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ViewErrorBag;

require __DIR__.'/../../bootstrap.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array<string, string> $argumenty */
$argumenty = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$etap = $argv[1] ?? '';

/** @param array<string, mixed> $wynik */
function meldujCookiesS4(array $wynik): never
{
    echo json_encode(array_merge([
        'ok' => false, 'wartosc' => null, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null,
    ], $wynik), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit(0);
}

try {
    $baza = DB::connection()->getDatabaseName();
    if (! str_starts_with($baza, 'kuking_race') || $baza !== kuking_nazwa_bazy_wyscigow(dirname(__DIR__, 3))
        || ($baza === 'kuking_race' && getenv('CI') !== 'true')) {
        throw new RuntimeException('Odmowa: wymagane własne kuking_race_* wyliczone z tego worktree.');
    }
    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '30s'");
    DB::statement("SET idle_in_transaction_session_timeout = '30s'");
    DB::select("SELECT set_config('application_name', ?, false)", ['cookiesS4-'.$etap]);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Notification::fake();
    app()->instance(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString('');
        }
    });

    if ($etap === 'akcja-a') {
        $hasher = Hash::getFacadeRoot();
        Hash::swap(new class($hasher) implements Hasher
        {
            private bool $zatrzymany = false;

            public function __construct(private readonly HashManager $hasher) {}

            public function info($hashedValue): array
            {
                return $this->hasher->info($hashedValue);
            }

            public function make(#[SensitiveParameter] $value, array $options = []): string
            {
                return $this->hasher->make($value, $options);
            }

            public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                $poprawne = $this->hasher->check($value, $hashedValue, $options);
                if ($poprawne && ! $this->zatrzymany) {
                    $this->zatrzymany = true;
                    // Po rzeczywistym precheck hasła A, przed zamkiem konta.
                    DB::select('SELECT pg_advisory_xact_lock(28512862, 1)');
                }

                return $poprawne;
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->hasher->needsRehash($hashedValue, $options);
            }

            public function isHashed(#[SensitiveParameter] $value): bool
            {
                return $this->hasher->isHashed($value);
            }

            public function verifyConfiguration($value): bool
            {
                return $this->hasher->verifyConfiguration($value);
            }
        });
    }

    /** @var array<string, string> $dane */
    $dane = json_decode($argumenty['dane'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
    $cookie = $argumenty['cookie'] ?? '';
    // Nie ma login($user), actingAs, setUser ani ręcznego wpisu generacji.
    // Login i TOTP też są oddzielnymi prawdziwymi żądaniami do tras.
    $request = Request::create(route($argumenty['trasa']), $argumenty['metoda'] ?? 'GET', $dane,
        $cookie === '' ? [] : [(string) config('session.cookie') => $cookie]);
    $kernel = app(HttpKernel::class);
    $odpowiedz = $kernel->handle($request);
    $kernel->terminate($request, $odpowiedz);
    $otrzymane = collect($odpowiedz->headers->getCookies())
        ->first(fn ($item): bool => $item->getName() === config('session.cookie'));
    $bledy = $request->hasSession() ? $request->session()->get('errors') : null;

    meldujCookiesS4(['ok' => true, 'wartosc' => [
        'status' => $odpowiedz->getStatusCode(), 'dokad' => $odpowiedz->headers->get('Location'),
        'cookie' => $otrzymane?->getValue() ?? '', 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
        'wspolny_store' => $request->hasSession() && app('session.store') === $request->session(),
        'bledy' => $bledy instanceof ViewErrorBag ? $bledy->all() : [],
        'old' => $request->hasSession() ? $request->session()->get('_old_input', []) : [],
        'listy' => count(Notification::sentNotifications()),
    ]]);
} catch (Throwable $e) {
    $sqlstate = $e instanceof PDOException ? (string) $e->getCode() : null;
    if ($e instanceof QueryException) {
        $sqlstate = isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : $sqlstate;
    }
    meldujCookiesS4(['wyjatek' => $e::class, 'sqlstate' => $sqlstate, 'komunikat' => $e->getMessage()]);
}
