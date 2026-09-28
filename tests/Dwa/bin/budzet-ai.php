<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #2013: płatny odczyt importu kontra miesięczny budżet AI.
 *
 * Woła PRAWDZIWY `PlatnyOdczytImportu` (rezerwacja, `oznaczWyslana`, żądanie
 * do modelu, rozliczenie). Żądanie HTTP do OpenAI jest atrapą, która TYLKO
 * LICZY wywołania — dzięki temu test widzi, czy odmowa budżetu zapadła
 * PRZED płatnym wywołaniem (koszt = 0), a nie po nim.
 *
 * Sztuczny czas ustawia argument `dzien` (Europe/Warsaw, południe).
 * Melduje wiersz JSON-a jak `scenariusz.php`.
 */

use App\Domain\Import\BudzetAi;
use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\PlatnyOdczytImportu;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);

if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}

DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '30s'");
DB::statement("SET idle_in_transaction_session_timeout = '30s'");

try {
    Carbon::setTestNow(Carbon::parse($args['dzien'].' 12:00', 'Europe/Warsaw'));

    config([
        'kuking.import.budzet.dzienny_usd' => 1_000_000.0,
        'kuking.import.model.klucz' => 'sk-test-import',
        'kuking.import.model.nazwa' => 'gpt-6-luna',
        'kuking.import.model.cena_wejscie_mln_usd' => '2',
        'kuking.import.model.cena_wyjscie_mln_usd' => '8',
    ]);

    // Limit miesięczny mieści DOKŁADNIE jedną rezerwację (1,5 szacunku).
    $szacunek = BudzetAi::szacunek(KlientLuna::ZADANIE_TEKST) ?? throw new RuntimeException('Brak szacunku kosztu.');
    config(['kuking.import.budzet.miesieczny_usd' => $szacunek * 1.5 / 1_000_000]);

    $wywolan = 0;
    // 503 = „model chwilowo niedostępny": rezerwacja zostaje policzona
    // jako wydana (nie wraca do puli), więc wynik wyścigu jest stały.
    Http::fake(function (Request $zadanie) use (&$wywolan) {
        $wywolan++;

        return Http::response('', 503);
    });

    $osoba = User::query()->findOrFail($args['kto']);
    $powod = 'przyznano';

    try {
        app(PlatnyOdczytImportu::class)->odczytaj(
            $osoba, true, (string) $args['proba'], KlientLuna::ZADANIE_TEKST,
            'Wypisz przepis.', [['type' => 'input_text', 'text' => 'Przepis.']], 'przepis', ['type' => 'object'],
        );
    } catch (ImportOdrzucony $odmowa) {
        // Budżet daje BUDZET_AI przed żądaniem; 503 daje MODEL_NIEDOSTEPNY po nim.
        $powod = $wywolan === 0 ? 'odmowa' : 'wyslano';
    }

    echo json_encode([
        'ok' => true, 'wartosc' => ['powod' => $powod, 'wywolan' => $wywolan],
        'sqlstate' => null, 'wyjatek' => null, 'komunikat' => '',
    ]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
