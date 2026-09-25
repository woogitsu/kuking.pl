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

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Comments\Actions\EditComment;
use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Moderation\Actions\ReportContent;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Users\Actions\ConfirmEmailChange;
use App\Domain\Users\Actions\EraseAccountData;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Settings\SecuritySettingsController;
use App\Models\Comment;
use App\Models\PendingEmailChange;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/../../bootstrap.php';

/**
 * Bariera #1016: uczestnik staje PO rzeczywistym zapytaniu o innych czynnych
 * administratorów. Należy wyłącznie do przyrządu — mierzymy zapytanie
 * aplikacji, nie przepisujemy jej warunku do drugiego SQL-a.
 */
function barieraPoLiczeniuAdministratorow(): void
{
    DB::listen(static function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'from "users"')
            && str_contains($query->sql, '"role" =')
            && str_contains($query->sql, '"status" =')
            && (str_contains($query->sql, 'count(') || str_contains($query->sql, 'exists('))) {
            DB::select('SELECT pg_advisory_xact_lock(1016, 2)');
        }
    });
}

/**
 * Bariera #887: uczestnik staje PO rzeczywistym zapytaniu reguły
 * `UsernameNotTaken` o zajętość nazwy, a PRZED zapisem profilu. Żądanie nie
 * jest w transakcji, więc blokada doradcza trwa tylko jedno zapytanie —
 * wystarcza, żeby oba żądania przeczytały „wolna", zanim którekolwiek zapisze.
 */
function barieraPoSprawdzeniuNazwy(): void
{
    $juz = false;

    DB::listen(static function (QueryExecuted $query) use (&$juz): void {
        if (! $juz && str_contains($query->sql, 'lower(username) = ?') && str_contains($query->sql, 'exists(')) {
            $juz = true;
            DB::select('SELECT pg_advisory_xact_lock(887, 1)');
        }
    });
}

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
        'nadaj-role' => (function () use ($argumenty): array {
            // Bariera należy wyłącznie do przyrządu. Mierzymy zapytanie
            // komendy/akcji, nie przepisujemy jej warunku do drugiego SQL-a.
            barieraPoLiczeniuAdministratorow();
            $output = new BufferedOutput;
            $code = app(Kernel::class)->call('kuking:nadaj-role', [
                'login' => $argumenty['login'], 'rola' => $argumenty['rola'], '--tak' => true,
            ], $output);

            return ['code' => $code, 'output' => $output->fetch()];
        })(),

        // Zmiana statusu konta, która odbiera aktywność (#1016, etap B).
        // Zewnętrzna transakcja jak w kontrolerach: odmowa ma ją wycofać.
        'status-konta' => (function () use ($argumenty): string {
            barieraPoLiczeniuAdministratorow();
            $konto = User::query()->whereKey($argumenty['konto'])->firstOrFail();
            DB::transaction(static fn () => match ($argumenty['przejscie']) {
                'zawies' => $konto->suspend(),
                'zbanuj' => $konto->ban(),
                'usun' => $konto->markForDeletion(),
            });

            return (string) $konto->fresh()?->status;
        })(),

        'zapis-do-zeszytu' => (function () use ($argumenty): string {
            $save = function () use ($argumenty): string {
                $user = User::query()->findOrFail($argumenty['kto']);
                $collection = $argumenty['typ'] === 'recipe'
                    ? app(SaveRecipeToCollection::class)->handle($user, Recipe::query()->findOrFail($argumenty['tresc']))
                    : app(SavePostToCollection::class)->handle($user, Post::query()->findOrFail($argumenty['tresc']));

                return (string) $collection->getKey();
            };

            // Transakcja zewnętrzna sprawdza, czy konflikt INSERT nie zostawia
            // połączenia w stanie 25P02 i pozwala dopisać zamówioną treść.
            return ($argumenty['transakcja'] ?? '0') === '1' ? DB::transaction($save) : $save();
        })(),

        // Egzekucja karencji jednego konta (Z-2, D-093).
        'kasowanie' => app(EraseAccountData::class)->handle(
            User::query()->whereKey($argumenty['konto'])->firstOrFail(),
        ),

        // Kara i usunięcie konta na NIEAKTUALNYM modelu (#980). Model jest
        // czytany zanim uczestnik stanie w kolejce po wiersz — jak formularz,
        // który sprawdził hasło, zanim moderator zdążył zbanować.
        'stan-konta-980' => (function () use ($argumenty): string {
            $konto = User::query()->whereKey($argumenty['konto'])->firstOrFail();
            match ($argumenty['przejscie']) {
                'zbanuj' => $konto->ban(),
                'usun' => $konto->markForDeletion(),
            };

            return (string) $konto->status;
        })(),

        // „Obserwuj" (D-080).
        'obserwuj' => app(FollowUser::class)->handle(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            User::query()->whereKey($argumenty['kogo'])->firstOrFail(),
        ),

        // Scalenie tagu SUROWYM `UPDATE` (#996). Świadomie z pominięciem
        // `MergeTags`: mierzymy barierę w PostgreSQL, która ma działać na
        // KAŻDEJ drodze zapisu — `MergeTags` i tak serializuje się własną
        // blokadą `TagMutationLock`, więc przez nią wyścigu nie widać.
        'scal-tag-surowo' => DB::table('tags')->where('id', $argumenty['zrodlo'])->update([
            'status' => 'merged',
            'merged_into_tag_id' => $argumenty['cel'],
        ]),

        // „Zablokuj" (D-090).
        'zablokuj' => (function () use ($argumenty): bool {
            app(BlockUser::class)->handle(
                User::query()->whereKey($argumenty['kto'])->firstOrFail(),
                User::query()->whereKey($argumenty['kogo'])->firstOrFail(),
            );

            return true;
        })(),

        // Edycja OPUBLIKOWANEGO przepisu (A01). Prawdziwa akcja domenowa,
        // bo mierzymy właśnie to, czy zapis treści i zapis historii idą
        // razem — przepisany do testu SQL byłby zielony także po zmianie
        // kolejności w `PublishRecipe`.
        'edytuj-przepis' => (function () use ($argumenty): string {
            $przepis = app(PublishRecipe::class)->handle(
                author: User::query()->whereKey($argumenty['autor'])->firstOrFail(),
                attributes: [
                    'title' => $argumenty['tytul'],
                    'visibility' => 'public',
                    'source_type' => Recipe::SOURCE_OWN,
                ],
                ingredients: [['text' => $argumenty['skladnik']]],
                steps: [['instruction' => 'Gotuj do miękkości.']],
                publish: true,
                existing: Recipe::query()->whereKey($argumenty['przepis'])->firstOrFail(),
            );

            return (string) $przepis->title;
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

        // Odpowiedź i poprawka tego samego komentarza (#1337). Obie strony to
        // prawdziwe akcje domenowe — test ma pęknąć, gdy `EditComment` przestanie
        // brać zamek korzenia albo pytać pod nim Policy.
        'odpowiedz' => (string) app(PublishComment::class)->handle(
            author: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            subject: Post::query()->whereKey($argumenty['wpis'])->firstOrFail(),
            body: $argumenty['tresc'],
            parent: Comment::query()->whereKey($argumenty['rodzic'])->firstOrFail(),
        )->getKey(),
        'popraw-komentarz' => app(EditComment::class)->handle(
            author: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            comment: Comment::query()->whereKey($argumenty['komentarz'])->firstOrFail(),
            body: $argumenty['tresc'],
        ) === null ? 'odmowa' : 'zapisano',

        // Pierwszy zapis do zeszytu (#1095). Te scenariusze celowo wołają
        // akcje domenowe, a nie przepisany SQL: test ma pęknąć, jeśli wróci
        // wyścig w User::defaultCollection().
        'zapisz-przepis' => (string) app(SaveRecipeToCollection::class)->handle(
            user: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            recipe: Recipe::query()->whereKey($argumenty['przepis'])->firstOrFail(),
        )->getKey(),

        'zapisz-wpis' => (string) app(SavePostToCollection::class)->handle(
            user: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            post: Post::query()->whereKey($argumenty['wpis'])->firstOrFail(),
        )->getKey(),

        // Ustawienie nowego hasła PRAWDZIWYM kontrolerem (#1358): zmiana
        // w ustawieniach albo reset linkiem. Bariera przyrządu staje zaraz
        // po zapisie `users.password` — w oknie, w którym stary kod miał
        // hasło już zatwierdzone, a zamówioną zmianę adresu jeszcze żywą.
        // Kontroler, a nie akcja, bo test ma pęknąć także wtedy, gdy ktoś
        // wróci do zapisu hasła poza akcją.
        'ustaw-haslo' => (function () use ($argumenty): array {
            Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
            DB::listen(static function (QueryExecuted $query): void {
                if (str_starts_with($query->sql, 'update "users" set "password"')) {
                    DB::select('SELECT pg_advisory_xact_lock(1358, 1)');
                }
            });

            $konto = User::query()->whereKey($argumenty['konto'])->firstOrFail();
            $haslo = ['password' => $argumenty['haslo'], 'password_confirmation' => $argumenty['haslo']];

            [$request, $kontroler, $metoda] = $argumenty['droga'] === 'zmiana'
                ? [Request::create('/', 'PUT', ['current_password' => $argumenty['obecne'], ...$haslo]), SecuritySettingsController::class, 'updatePassword']
                : [Request::create('/', 'POST', ['token' => $argumenty['token'], 'email' => $argumenty['email'], ...$haslo]), PasswordResetController::class, 'reset'];

            // Kolejność ma znaczenie: podmiana `request` w kontenerze
            // przestawia rozwiązywanie użytkownika na guarda.
            $request->setLaravelSession(app('session.store'));
            app()->instance('request', $request);

            if ($argumenty['droga'] === 'zmiana') {
                Auth::guard('web')->setUser($konto);
            }

            app()->call([app($kontroler), $metoda], ['request' => $request]);

            /** @var ViewErrorBag|null $bledy */
            $bledy = $request->session()->get('errors');

            return ['bledy' => $bledy?->all() ?? []];
        })(),

        // Potwierdzenie zamówionej zmiany adresu — ta sama akcja, którą woła
        // `EmailSettingsController::confirm()` z wierszem odczytanym przed nią.
        'potwierdz-adres' => app(ConfirmEmailChange::class)->handle(
            User::query()->whereKey($argumenty['konto'])->firstOrFail(),
            PendingEmailChange::query()->whereKey($argumenty['zmiana'])->firstOrFail(),
        ),

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

        // Zmiana profilu przez PRAWDZIWE żądanie HTTP (#887): cały stos
        // middleware, walidacja i kontroler, a na koniec to, co zobaczyłby
        // człowiek — kod odpowiedzi, błąd pola i odłożone dane formularza.
        'zmien-profil' => (function () use ($argumenty): array {
            barieraPoSprawdzeniuNazwy();
            $konto = User::query()->whereKey($argumenty['kto'])->firstOrFail();
            Auth::guard('web')->setUser($konto);

            $zadanie = Request::create(url('/ustawienia/profil'), 'PUT', [
                'display_name' => 'Barbara',
                'username' => $argumenty['nazwa'],
                'bio' => 'Gotuję od czterdziestu lat.',
                'region' => 'Podkarpacie',
                'speciality' => 'zupy i kiszonki',
            ], [], [], ['HTTP_REFERER' => url('/ustawienia/profil')]);

            $odpowiedz = app(HttpKernel::class)->handle($zadanie);
            $sesja = $zadanie->hasSession() ? $zadanie->session() : null;
            $bledy = $sesja?->get('errors');

            return [
                'status' => $odpowiedz->getStatusCode(),
                // Sesja ma `serialization => json`, więc po zapisie worek
                // błędów wraca jako tablica, a nie `ViewErrorBag`.
                'blad' => is_object($bledy)
                    ? $bledy->first('username')
                    : ($bledy['default']['messages']['username'][0] ?? null),
                'stare' => $sesja?->get('_old_input'),
                'zapisane' => $sesja?->get('status'),
            ];
        })(),

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
