<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #1958: dodanie produktu do listy „Co mam w domu”
 * PRAWDZIWYM żądaniem HTTP `POST /co-mam-w-domu`.
 *
 * Cały stos: middleware (łącznie z `throttle`), walidacja żądania,
 * `PantryController::store()` i `CoMamWDomu::dodaj()`. Melduje to, co
 * zobaczyłby człowiek — kod odpowiedzi, błąd pola `nazwa` i komunikat
 * o powodzeniu z sesji — żeby test sprawdzał „polski komunikat, bez 500”,
 * a nie tylko klasę wyjątku z akcji domenowej.
 *
 * Osobny plik, a nie nowy przypadek we wspólnym `scenariusz.php`, żeby
 * nie kolidować z innymi gałęziami, które dopisują tam swoje scenariusze.
 *
 * Melduje jednym wierszem JSON-a (ten sam kształt co `scenariusz.php`):
 *
 *     {"ok":true,"wartosc":{...},"sqlstate":null,"komunikat":"","wyjatek":null}
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';

/**
 * @param  array<string, mixed>  $dane
 */
function zameldujPantry(array $dane): never
{
    echo json_encode(array_merge(
        ['ok' => false, 'wartosc' => null, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null],
        $dane,
    ), JSON_UNESCAPED_UNICODE);

    exit(0);
}

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Ten sam bezpiecznik co w `scenariusz.php`: bez poprawnej zmiennej
// `DB_DATABASE` proces pisałby do bazy deweloperskiej.
/** @var mixed $baza sterownik bywa bez nazwy — wtedy odmawiamy, zamiast się przewrócić */
$baza = DB::connection()->getDatabaseName();
if (! is_string($baza) || ! str_starts_with($baza, 'kuking_race')) {
    zameldujPantry(['komunikat' => 'Odmowa: połączenie wskazuje na bazę "'.(is_string($baza) ? $baza : '?').'", a wolno wyłącznie na kuking_race_*.']);
}

DB::statement("SET lock_timeout = '".(getenv('KUKING_LOCK_TIMEOUT') ?: '10s')."'");
DB::statement("SET statement_timeout = '".(getenv('KUKING_STATEMENT_TIMEOUT') ?: '30s')."'");
DB::statement("SET idle_in_transaction_session_timeout = '".(getenv('KUKING_STATEMENT_TIMEOUT') ?: '30s')."'");

/** @var array{kto: string, nazwa: string} $argumenty */
$argumenty = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

try {
    if (($argv[1] ?? '') !== 'dodaj-http') {
        throw new InvalidArgumentException('Nieznany scenariusz: '.($argv[1] ?? ''));
    }

    $konto = User::query()->whereKey($argumenty['kto'])->firstOrFail();
    Auth::guard('web')->setUser($konto);

    $zadanie = Request::create(
        url('/co-mam-w-domu'),
        'POST',
        ['nazwa' => $argumenty['nazwa']],
        [],
        [],
        ['HTTP_REFERER' => url('/co-mam-w-domu')],
    );

    $odpowiedz = app(HttpKernel::class)->handle($zadanie);
    $sesja = $zadanie->hasSession() ? $zadanie->session() : null;
    $bledy = $sesja?->get('errors');

    zameldujPantry(['ok' => true, 'wartosc' => [
        'status' => $odpowiedz->getStatusCode(),
        // Sesja ma `serialization => json`, więc worek błędów może wrócić
        // jako tablica, a nie `ViewErrorBag`.
        'blad' => is_object($bledy)
            ? $bledy->first('nazwa')
            : ($bledy['default']['messages']['nazwa'][0] ?? null),
        'zapisane' => $sesja?->get('status'),
    ]]);
} catch (Throwable $e) {
    $sqlstate = null;
    for ($szukany = $e; $szukany !== null; $szukany = $szukany->getPrevious()) {
        $kod = $szukany->getCode();
        if (is_string($kod) && preg_match('/^[0-9A-Z]{5}$/', $kod) === 1) {
            $sqlstate = $kod;
            break;
        }
    }

    zameldujPantry(['sqlstate' => $sqlstate, 'wyjatek' => $e::class, 'komunikat' => $e->getMessage()]);
}
