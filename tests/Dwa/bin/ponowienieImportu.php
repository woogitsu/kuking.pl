<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #2064: „Spróbuj jeszcze raz” pod odczytem kartki,
 * wysłane PRAWDZIWYM żądaniem HTTP (`POST /import/{import}/ponow`).
 *
 * Cały stos: middleware, wiązanie trasy, Policy, kontroler i akcja
 * `ZlecImportPrzepisu::ponow()`. Kolejka jest bazodanowa, na tym samym
 * połączeniu (jak na produkcji), więc zadanie `OdczytajPrzepis` ląduje
 * w `jobs` razem ze zleceniem — test liczy je potem sam.
 *
 * Melduje jednym wierszem JSON-a (ten sam kształt co `scenariusz.php`):
 * w `wartosc` kod odpowiedzi, adres przekierowania i błąd pola `ponow`.
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
// Ta sama reguła rodziny baz co w `komentarz.php` i `zeszyt.php`.
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '20s'");
DB::statement("SET idle_in_transaction_session_timeout = '20s'");

try {
    config([
        'kuking.import.model.klucz' => 'sk-test-import',
        'kuking.import.model.nazwa' => 'gpt-6-luna',
        'kuking.import.model.cena_wejscie_mln_usd' => '2',
        'kuking.import.model.cena_wyjscie_mln_usd' => '8',
        'kuking.import.zrodla.zdjecie' => true,
        // Baza wyścigów zachowuje wydatki poprzednich przebiegów.
        'kuking.import.budzet.dzienny_usd' => 1000.0,
        'kuking.import.budzet.miesieczny_usd' => 10000.0,
        'queue.default' => 'database',
    ]);

    Auth::guard('web')->setUser(User::query()->findOrFail($args['kto']));
    $zadanie = Request::create(url('/import/'.$args['poprzednie'].'/ponow'), 'POST', [], [], [], [
        'HTTP_REFERER' => url('/import/'.$args['poprzednie']),
    ]);
    $odpowiedz = app(HttpKernel::class)->handle($zadanie);
    $sesja = $zadanie->hasSession() ? $zadanie->session() : null;
    $bledy = $sesja?->get('errors');

    echo json_encode(['ok' => true, 'wartosc' => [
        'status' => $odpowiedz->getStatusCode(),
        'dokad' => $odpowiedz->headers->get('Location'),
        'blad' => is_object($bledy) ? $bledy->first('ponow') : ($bledy['default']['messages']['ponow'][0] ?? null),
    ], 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $e->getCode(),
        'wyjatek' => $e::class, 'komunikat' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
