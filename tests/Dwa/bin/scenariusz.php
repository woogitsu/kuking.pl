<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu z grupy `dwa-polaczenia` (D-105).
 *
 * Uruchamiany przez `Tests\Dwa\ProcesRownolegly` jako OSOBNY PROCES, żeby
 * dwie akcje domenowe mogły naprawdę wykonywać się jednocześnie, na dwóch
 * połączeniach do PostgreSQL. Wykonuje PRAWDZIWY kod aplikacji — to jest
 * cały sens tej grupy. Gdyby zamiast tego odgrywał przepisany SQL, byłby
 * zielony także po zmianie kodu, którego pilnuje.
 *
 * Melduje jednym wierszem JSON-a na standardowe wyjście:
 *
 *     {"ok":true,"wartosc":true,"sqlstate":null,"komunikat":"","wyjatek":null}
 *
 * Kod wyjścia jest zawsze 0 — wynik czyta się z JSON-a, a nie ze statusu
 * procesu. To ta sama zasada, co w `docs/PULAPKI_TESTOW.md` §5: pytamy
 * o WYNIK KROKU, a nie o to, czy narzędzie się nie wywróciło.
 */

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Moderation\Actions\ReportContent;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';

$scenariusz = $argv[1] ?? '';

/** @var array<string, string> $argumenty */
$argumenty = (array) json_decode($argv[2] ?? '[]', true);

/**
 * @param  array<string, mixed>  $dane
 */
function zamelduj(array $dane): never
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

$baza = DB::connection()->getDatabaseName();

// TEN SAM BEZPIECZNIK CO W KLASIE BAZOWEJ, I NIE JEST TO NADMIAROWY PAS
// OBOK SZELEK. Proces potomny dostaje nazwę bazy przez zmienną środowiskową,
// a `.env` w katalogu roboczym wskazuje na bazę deweloperską. Gdyby zmienna
// kiedykolwiek nie doszła (inna wersja Dotenva, `config:cache`, literówka
// w kluczu), ten skrypt wykonałby `EraseAccountData` NA PRAWDZIWYCH DANYCH.
// Dlatego odmawia, zamiast działać.
if (! str_starts_with($baza, 'kuking_race')) {
    zamelduj(['komunikat' => 'Odmowa: połączenie wskazuje na bazę "'.$baza.'", a wolno wyłącznie na kuking_race_*.']);
}

// ZASADA 4: twarde limity czasu na KAŻDYM połączeniu, także tutaj. Proces
// potomny, który utknie na blokadzie, jest gorszy od procesu, który padnie:
// blokady trzyma do końca przebiegu CI, a nikt na niego nie patrzy.
DB::statement("SET lock_timeout = '".(getenv('KUKING_LOCK_TIMEOUT') ?: '10s')."'");
DB::statement("SET statement_timeout = '".(getenv('KUKING_STATEMENT_TIMEOUT') ?: '30s')."'");
DB::statement("SET idle_in_transaction_session_timeout = '".(getenv('KUKING_STATEMENT_TIMEOUT') ?: '30s')."'");

try {
    $wartosc = match ($scenariusz) {
        // Egzekucja karencji jednego konta (Z-2, D-093).
        'kasowanie' => app(EraseAccountData::class)->handle(
            User::query()->whereKey($argumenty['konto'])->firstOrFail(),
        ),

        // „Obserwuj" (D-080).
        'obserwuj' => app(FollowUser::class)->handle(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            User::query()->whereKey($argumenty['kogo'])->firstOrFail(),
        ),

        // „Zablokuj" (D-090).
        'zablokuj' => (function () use ($argumenty): bool {
            app(BlockUser::class)->handle(
                User::query()->whereKey($argumenty['kto'])->firstOrFail(),
                User::query()->whereKey($argumenty['kogo'])->firstOrFail(),
            );

            return true;
        })(),

        // Komentarz pod wpisem (audyt podwójnego wysłania, 12.09.2026).
        // Dwa procesy z IDENTYCZNĄ treścią odtwarzają podwójne kliknięcie,
        // w którym oba żądania trafiły na serwer naprawdę jednocześnie —
        // czyli to, czego test w `tests/Feature/` nie umie zmierzyć.
        'komentarz' => (string) app(PublishComment::class)->handle(
            author: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            subject: Post::query()->whereKey($argumenty['wpis'])->firstOrFail(),
            body: $argumenty['tresc'],
        )->getKey(),

        // Komenda obchodząca zaległe potwierdzenia zgłoszeń (issue #797).
        // Wołamy PRAWDZIWĄ komendę przez Artisana, nie jej wnętrzności —
        // razem z jej kodem wyjścia, bo to na nim stoi wpięcie
        // w harmonogram.
        'dosylka-potwierdzen' => Artisan::call('kuking:dosylaj-potwierdzenia-zgloszen'),

        // Człowiek wracający do tej samej sprawy: ponowne kliknięcie „Zgłoś"
        // na tej samej treści. `ReportContent` oddaje istniejące zgłoszenie
        // i po drodze dokańcza zaległe potwierdzenie — to jest DRUGA droga
        // do tego samego znacznika i to z nią ma się ścigać dosyłka.
        'powrot-do-sprawy' => (string) app(ReportContent::class)->handle(
            reporter: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            target: Post::query()->whereKey($argumenty['wpis'])->firstOrFail(),
            reason: 'spam',
            details: 'To jest reklama.',
        )->getKey(),

        default => throw new InvalidArgumentException('Nieznany scenariusz wyścigu: '.$scenariusz),
    };

    zamelduj(['ok' => true, 'wartosc' => $wartosc]);
} catch (Throwable $e) {
    // SQLSTATE wyciągamy z NAJGŁĘBSZEGO wyjątku, bo `QueryException`
    // Laravela przepisuje kod sterownika, ale akcja domenowa mogła go
    // jeszcze raz opakować we własny wyjątek dla człowieka.
    $sqlstate = null;

    for ($szukany = $e; $szukany !== null; $szukany = $szukany->getPrevious()) {
        $kod = $szukany->getCode();

        if (is_string($kod) && preg_match('/^[0-9A-Z]{5}$/', $kod) === 1) {
            $sqlstate = $kod;
            break;
        }
    }

    zamelduj([
        'sqlstate' => $sqlstate,
        'wyjatek' => $e::class,
        'komunikat' => $e->getMessage(),
    ]);
}
