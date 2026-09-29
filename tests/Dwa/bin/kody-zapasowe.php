<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #2057: jedno żądanie „Wygeneruj nowe kody zapasowe"
 * (`POST /ustawienia/2fa/nowe-kody`) przez PRAWDZIWY kontroler
 * `TwoFactorSettingsController::regenerateCodes()`, na własnym połączeniu.
 *
 * Osobny skrypt, a nie gałąź wspólnego `scenariusz.php`, bo tamten plik
 * zmienia naraz kilka gałęzi roboczych (zasady fali 4).
 *
 * Konto jest czytane PRZED wywołaniem kontrolera — tak jak w prawdziwym
 * żądaniu robi to middleware `auth` na samym początku. Dopiero potem
 * kontroler sprawdza hasło i zapisuje nowy komplet.
 *
 * Bariera (`pauza`): zaraz PO rzeczywistym `UPDATE users SET
 * two_factor_backup_codes` uczestnik staje na blokadzie doradczej
 * trzymanej przez test. Należy wyłącznie do przyrządu — mierzymy zapis
 * aplikacji, nie przepisujemy go do testu.
 *
 * Melduje jednym JSON-em: to, co żądanie pokazałoby człowiekowi (jawne kody
 * we flashu dla ekranu jednorazowego, komunikat `status`, błędy formularza,
 * adres przekierowania).
 */

use App\Http\Controllers\Settings\TwoFactorSettingsController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$argumenty = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);

if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}

DB::statement("SET lock_timeout = '".getenv('KUKING_LOCK_TIMEOUT')."'");
DB::statement("SET statement_timeout = '".getenv('KUKING_STATEMENT_TIMEOUT')."'");
DB::statement("SET idle_in_transaction_session_timeout = '".getenv('KUKING_STATEMENT_TIMEOUT')."'");
DB::selectOne("SELECT set_config('application_name', ?, false)", ['kody-2057-'.$argumenty['nazwa']]);

try {
    if (($argumenty['pauza'] ?? '') === '1') {
        $zatrzymany = false;

        DB::listen(static function (QueryExecuted $query) use (&$zatrzymany): void {
            if (! $zatrzymany && str_starts_with($query->sql, 'update "users" set "two_factor_backup_codes"')) {
                $zatrzymany = true;
                DB::select('SELECT pg_advisory_xact_lock(2057, 1)');
            }
        });
    }

    // „Middleware auth": konto odczytane na początku żądania.
    $konto = User::query()->whereKey($argumenty['konto'])->firstOrFail();

    $request = Request::create('/ustawienia/2fa/nowe-kody', 'POST', ['password' => $argumenty['haslo']]);

    // Kolejność ma znaczenie: podmiana `request` w kontenerze
    // przestawia rozwiązywanie użytkownika na guarda.
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    Auth::guard('web')->setUser($konto);

    try {
        $odpowiedz = app()->call([app(TwoFactorSettingsController::class), 'regenerateCodes']);
        $dokad = $odpowiedz instanceof RedirectResponse ? $odpowiedz->getTargetUrl() : null;
    } catch (ValidationException $bladFormularza) {
        $dokad = null;
        $request->session()->flash('errors', (new ViewErrorBag)->put($bladFormularza->errorBag, $bladFormularza->validator->errors()));
    }

    $kody = $request->session()->get('kody_zapasowe');

    /** @var ViewErrorBag|null $bledy */
    $bledy = $request->session()->get('errors');

    echo json_encode(['ok' => true, 'sqlstate' => null, 'wartosc' => [
        'kody' => is_array($kody) ? array_values($kody) : null,
        'status' => $request->session()->get('status'),
        'bledy' => $bledy?->getBag('regenerate')->all() ?? [],
        'dokad' => $dokad,
    ]]);
} catch (Throwable $wyjatek) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $wyjatek->getCode(),
        'wyjatek' => $wyjatek::class, 'komunikat' => $wyjatek->getMessage(),
    ]);
}
