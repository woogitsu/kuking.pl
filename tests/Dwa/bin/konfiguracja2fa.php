<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #2061: dwa wejścia na ekran włączenia 2FA naraz.
 *
 * Woła PRAWDZIWY `TwoFactorSettingsController` (`create()` i `confirm()`),
 * tak jak robi to trasa: konto wczytane z bazy jak przez guard, żądanie
 * z sesją, kontroler z kontenera. Bariera należy wyłącznie do przyrządu —
 * czeka na blokadę doradczą (2061, 1) trzymaną przez test:
 *
 *   `po_odczycie`           zaraz po wczytaniu konta, czyli po tym odczycie
 *                           `two_factor_secret = NULL`, na którym dotąd
 *                           kontroler opierał decyzję o zapisie sekretu;
 *   `po_ponownym_odczycie`  po PIERWSZYM zapytaniu kontrolera o wiersz
 *                           `users` — w naprawionym kodzie to odczyt pod
 *                           blokadą, więc uczestnik stoi, trzymając ją.
 *
 * Melduje jednym wierszem JSON-a, jak `scenariusz.php`.
 */

use App\Http\Controllers\Settings\TwoFactorSettingsController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);

if (preg_match('/\Akuking_race(_|\z)/', (string) DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}

DB::statement("SET lock_timeout = '8s'");
DB::statement("SET statement_timeout = '12s'");
DB::statement("SET idle_in_transaction_session_timeout = '15s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name'] ?? $argv[1]]);

/**
 * Jedno żądanie do kontrolera — konto wczytane ŚWIEŻO, jak przez guard
 * na początku każdego prawdziwego żądania.
 *
 * `$dane` jako Closure powstają dopiero PO wczytaniu konta i barierze — jak
 * kod z telefonu, liczony w chwili wysłania formularza.
 *
 * @param  array<string, string>|Closure(): array<string, string>  $dane
 */
function zadanie2fa(string $konto, string $metoda, string $httpMetoda, array|Closure $dane, ?Closure $poWczytaniu = null): mixed
{
    $user = User::query()->whereKey($konto)->firstOrFail();

    if ($poWczytaniu !== null) {
        $poWczytaniu();
    }

    $request = Request::create('/ustawienia/2fa/wlacz', $httpMetoda, $dane instanceof Closure ? $dane() : $dane);
    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    Auth::guard('web')->setUser($user);
    $request->setUserResolver(static fn () => $user);

    return app()->call([app(TwoFactorSettingsController::class), $metoda]);
}

/** @return array{typ: string, sekret?: string, cel?: string} */
function opiszOdpowiedz(mixed $odpowiedz): array
{
    if ($odpowiedz instanceof View) {
        return ['typ' => 'widok', 'sekret' => (string) $odpowiedz->getData()['sekret']];
    }

    if ($odpowiedz instanceof RedirectResponse) {
        return ['typ' => 'przekierowanie', 'cel' => $odpowiedz->getTargetUrl()];
    }

    throw new RuntimeException('Nieoczekiwana odpowiedź kontrolera: '.get_debug_type($odpowiedz));
}

try {
    $pauza = $args['pauza'] ?? '';
    $czekajNaTest = static fn () => DB::selectOne('SELECT pg_advisory_xact_lock(2061, 1)');

    $wynik = match ($argv[1]) {
        'wejdz' => (function () use ($args, $pauza, $czekajNaTest): array {
            $poWczytaniu = match ($pauza) {
                'po_odczycie' => $czekajNaTest,
                'po_ponownym_odczycie' => static function () use ($czekajNaTest): void {
                    $uzbrojona = true;
                    DB::listen(static function (QueryExecuted $query) use (&$uzbrojona, $czekajNaTest): void {
                        if ($uzbrojona && str_contains($query->sql, 'from "users"')) {
                            $uzbrojona = false;
                            $czekajNaTest();
                        }
                    });
                },
                default => null,
            };

            return opiszOdpowiedz(zadanie2fa($args['konto'], 'create', 'GET', [], $poWczytaniu));
        })(),

        // Właściciel w drugiej karcie: wchodzi na ekran, skanuje TEN kod QR,
        // który zobaczył, i potwierdza go hasłem i kodem z aplikacji.
        'wejdz_i_potwierdz' => (function () use ($args): array {
            $ekran = opiszOdpowiedz(zadanie2fa($args['konto'], 'create', 'GET', []));

            if ($ekran['typ'] !== 'widok') {
                return ['ekran' => $ekran, 'potwierdzenie' => null];
            }

            $potwierdzenie = opiszOdpowiedz(zadanie2fa($args['konto'], 'confirm', 'POST', [
                'code' => (new Google2FA)->getCurrentOtp($ekran['sekret']),
                'password' => $args['haslo'],
            ]));

            return ['ekran' => $ekran, 'potwierdzenie' => $potwierdzenie];
        })(),

        // Wysłanie formularza potwierdzenia z karty, która wczytała konto,
        // gdy 2FA było jeszcze niepotwierdzone. `przesuniecie` = 1 liczy kod
        // z NASTĘPNEGO kroku czasu — kod z bieżącego mógł już zużyć ktoś inny,
        // a ochrona przed powtórzeniem ma zostać nietknięta.
        'potwierdz' => (function () use ($args, $pauza, $czekajNaTest): array {
            $sekret = (string) User::query()->whereKey($args['konto'])->value('two_factor_secret');

            return opiszOdpowiedz(zadanie2fa($args['konto'], 'confirm', 'POST', static fn (): array => [
                'code' => (new Google2FA)->oathTotp($sekret, intdiv(time(), 30) + (int) ($args['przesuniecie'] ?? 0)),
                'password' => $args['haslo'],
            ], $pauza === 'po_odczycie' ? $czekajNaTest : null));
        })(),

        default => throw new RuntimeException('Nieznany scenariusz.'),
    };

    echo json_encode(['ok' => true, 'wartosc' => $wynik, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
