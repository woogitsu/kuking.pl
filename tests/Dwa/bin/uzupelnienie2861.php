<?php

declare(strict_types=1);

/** #2861: prawdziwe żądania HTTP i bariera między potwierdzeniem a odwołaniem sesji. */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\Response;

require __DIR__.'/../../bootstrap.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array<string, string> $argumenty */
$argumenty = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$etap = $argv[1] ?? '';

/** @param array<string, mixed> $wynik */
function melduj2861(array $wynik): never
{
    echo json_encode(array_merge([
        'ok' => false, 'wartosc' => null, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null,
    ], $wynik), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit(0);
}

/**
 * @param  array<string, string>  $dane
 * @return array{0: Request, 1: Response, 2: string}
 */
function zadanie2861(string $trasa, string $metoda = 'GET', array $dane = [], string $cookie = ''): array
{
    // Każde żądanie odtwarza uwierzytelnienie wyłącznie z literalnego cookie.
    // Nie ma actingAs, setUser ani wkładania dowodu 2FA do sesji.
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $request = Request::create(route($trasa), $metoda, $dane,
        $cookie === '' ? [] : [(string) config('session.cookie') => $cookie]);
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $otrzymane = collect($response->headers->getCookies())
        ->first(fn ($item): bool => $item->getName() === config('session.cookie'));

    return [$request, $response, $otrzymane?->getValue() ?? ''];
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
    DB::select("SELECT set_config('application_name', ?, false)", ['uzupelnienie2861-'.$etap]);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Notification::fake();
    app()->instance(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString('');
        }
    });

    if ($etap === 'przygotuj') {
        $konto = User::query()->findOrFail($argumenty['konto']);
        [, $login, $cookie] = zadanie2861('login', 'POST', ['login' => $konto->email, 'password' => $argumenty['haslo']]);
        [, $wyzwanie, $cookie] = zadanie2861('login.two_factor.store', 'POST', ['code' => $argumenty['kod']], $cookie);
        [, $panel, $cookie] = zadanie2861('admin.reports', cookie: $cookie);

        // Moderator najpierw otwiera panel rzeczywistym logowaniem 2FA.
        // Dopiero potem legalnie wyłącza 2FA i przygotowuje nowy składnik.
        [, $wylaczenie, $cookie] = zadanie2861('settings.two_factor.disable', 'POST', ['password' => $argumenty['haslo']], $cookie);
        [, $przygotowanie, $cookie] = zadanie2861('settings.two_factor.enable', cookie: $cookie);
        [, $ustawienia, $cookie] = zadanie2861('settings.security', cookie: $cookie);
        melduj2861(['ok' => true, 'wartosc' => [
            'login' => $login->getStatusCode(), 'wyzwanie' => $wyzwanie->getStatusCode(),
            'panel' => $panel->getStatusCode(), 'wylaczenie' => $wylaczenie->getStatusCode(),
            'przygotowanie' => $przygotowanie->getStatusCode(), 'ustawienia' => $ustawienia->getStatusCode(),
            'cookie' => $cookie,
        ]]);
    }

    if ($etap === 'odczyt') {
        [, $ustawienia] = zadanie2861('settings.security', cookie: $argumenty['cookie']);
        [, $panel] = zadanie2861('admin.reports', cookie: $argumenty['cookie']);
        melduj2861(['ok' => true, 'wartosc' => [
            'ustawienia' => $ustawienia->getStatusCode(), 'ustawienia_dokad' => $ustawienia->headers->get('Location'),
            'panel' => $panel->getStatusCode(), 'panel_dokad' => $panel->headers->get('Location'),
        ]]);
    }

    if ($etap !== 'wlacz') {
        throw new RuntimeException('Nieznany etap przyrządu #2861.');
    }

    $poziom = null;
    $potwierdzone = null;
    if ($argumenty['bariera'] === 'przed') {
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
                if (! $this->zatrzymany) {
                    $this->zatrzymany = true;
                    DB::select('SELECT pg_advisory_xact_lock(2861, 17)');
                }

                return $this->hasher->make($value, $options);
            }

            public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                return $this->hasher->check($value, $hashedValue, $options);
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
    } elseif ($argumenty['bariera'] === 'okno') {
        $zatrzymany = false;
        DB::connection()->beforeExecuting(static function (string $sql, array $bindings, Connection $connection) use (&$zatrzymany, &$poziom, &$potwierdzone, $argumenty): void {
            if ($zatrzymany || ! str_starts_with(strtolower(trim($sql)), 'update users set remember_token')) {
                return;
            }
            $zatrzymany = true;
            $poziom = $connection->transactionLevel();
            $potwierdzone = $connection->table('users')->where('id', $argumenty['konto'])->value('two_factor_confirmed_at') !== null;
            // Zapytanie odwołania sesji jeszcze nie weszło do PostgreSQL.
            // Potwierdzenie 2FA zostało wykonane przez prawdziwą akcję.
            $connection->getPdo()->query('SELECT pg_advisory_xact_lock(2861, 17)');
        });
    }

    [$request, $odpowiedz, $cookieA] = zadanie2861('settings.two_factor.confirm', 'POST', [
        'password' => $argumenty['haslo'], 'code' => $argumenty['kod'],
    ], $argumenty['cookie']);
    melduj2861(['ok' => true, 'wartosc' => [
        'status' => $odpowiedz->getStatusCode(), 'dokad' => $odpowiedz->headers->get('Location'),
        'cookie' => $cookieA, 'old' => $request->session()->get('_old_input', []),
        'listy' => count(Notification::sentNotifications()), 'poziom' => $poziom, 'potwierdzone' => $potwierdzone,
    ]]);
} catch (Throwable $e) {
    $poprzedni = $e->getPrevious();
    melduj2861([
        'komunikat' => $e->getMessage(), 'wyjatek' => $e::class,
        'sqlstate' => $e instanceof PDOException ? (string) $e->getCode()
            : ($poprzedni instanceof PDOException ? (string) $poprzedni->getCode() : null),
    ]);
}
