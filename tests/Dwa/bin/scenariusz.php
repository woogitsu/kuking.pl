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

use App\Domain\Collections\Actions\RemoveUnavailableFromCollection;
use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\Actions\UpdateCollectionItemNote;
use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Domain\Collections\Wspoldzielenie\DostepDoZeszytu;
use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\Actions\EditComment;
use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Contact\Actions\WyslijOdpowiedz;
use App\Domain\Feed\Actions\ZapiszKolaz;
use App\Domain\Feed\Actions\ZapiszTabliceDnia;
use App\Domain\Import\BudzetAi;
use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\LimitImportowOsoby;
use App\Domain\Import\LimitImportu;
use App\Domain\Import\Rezerwacja;
use App\Domain\Import\ZlecImportPrzepisu;
use App\Domain\Moderation\Actions\NotifyReporterDecisionChanged;
use App\Domain\Moderation\Actions\ReportContent;
use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Domain\Moderation\Actions\ZdejmijZUrzedu;
use App\Domain\Moderation\NowaDecyzja;
use App\Domain\Pantry\CoMamWDomu;
use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Alergeny\DeklaracjaAlergenow;
use App\Domain\Recipes\Alergeny\OznaczAlergenyPrzepisu;
use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Tags\Actions\MergeTags;
use App\Domain\Tags\Actions\UpdateTagFollows;
use App\Domain\Tags\PromowaneTagi;
use App\Domain\Users\Actions\ChangeUserRole;
use App\Domain\Users\Actions\ConfirmEmailChange;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Actions\RequestAccountDeletion;
use App\Domain\Wskazowki\OdrzucWskazowke;
use App\Domain\Wskazowki\PrzyjmijWskazowke;
use App\Domain\Wskazowki\WycofajWskazowke;
use App\Domain\Wskazowki\ZaproponujWskazowke;
use App\Domain\Wydania\Actions\ZarejestrujWdrozenie;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Settings\SecuritySettingsController;
use App\Http\Requests\Moderation\DecyzjaModeracyjnaRequest;
use App\Models\Appeal;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Comment;
use App\Models\ContactMessage;
use App\Models\CookedEvent;
use App\Models\CookingProgress;
use App\Models\ImportPrzepisu;
use App\Models\PendingEmailChange;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\Report;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
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
 * Bariera #1027: uczestnik staje PO rzeczywistym `DELETE` z tabeli wyboru,
 * a PRZED pierwszym `INSERT`-em — dokładnie w szczelinie, w której dwa
 * zastąpienia zestawu złączały się w A ∪ B. Czeka na blokadę doradczą
 * trzymaną przez test; zwolnienie jej puszcza uczestnika dalej.
 */
function barieraPoKasowaniuWyboru(string $tabela): void
{
    $zatrzymany = false;

    DB::listen(static function (QueryExecuted $query) use ($tabela, &$zatrzymany): void {
        if (! $zatrzymany && str_starts_with(strtolower(ltrim($query->sql)), 'delete from "'.$tabela.'"')) {
            $zatrzymany = true;
            DB::select('SELECT pg_advisory_xact_lock(91027, 1)');
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

/**
 * Bariera #2311: uczestnik staje PO `ktore`-tym rzeczywistym sprawdzeniu
 * członkostwa w zeszycie (`Collection::maCzlonka()`, zapytanie Policy),
 * a PRZED dalszą częścią akcji. Pierwsze sprawdzenie to odczyt bez zamka,
 * drugie — ponowna Policy pod zamkiem zeszytu.
 */
function barieraPoSprawdzeniuCzlonkostwa(int $ktore): void
{
    $licznik = 0;

    DB::listen(static function (QueryExecuted $query) use ($ktore, &$licznik): void {
        if (str_contains($query->sql, '"collection_members"') && str_contains($query->sql, 'exists(')) {
            $licznik++;
            if ($licznik === $ktore) {
                DB::select('SELECT pg_advisory_xact_lock(2311, 1)');
            }
        }
    });
}

/**
 * Bariera #2318: uczestnik staje PO pierwszym rzeczywistym sprawdzeniu, czy
 * odbiorca dostał już dziś przypomnienie o urodzinach, a PRZED zapisem.
 */
function barieraPoSprawdzeniuUrodzin(): void
{
    $juz = false;

    DB::listen(static function (QueryExecuted $query) use (&$juz): void {
        if (! $juz && str_contains($query->sql, 'from "notifications"') && str_contains($query->sql, 'exists(')) {
            $juz = true;
            DB::select('SELECT pg_advisory_xact_lock(2318, 1)');
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
        'cofnij-podpis-wersji-2059' => (function (): bool {
            $migracja = require base_path('database/migrations/2026_09_26_100000_add_forked_from_to_recipes.php');
            $migracja->down();

            return true;
        })(),

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
                default => throw new LogicException('Nieobsłużony wariant w match.'),
            });

            return (string) $konto->fresh()?->status;
        })(),

        'opublikuj-wpis-2088' => (function () use ($argumenty): string {
            $autor = User::query()->whereKey($argumenty['konto'])->firstOrFail();
            if (! $autor->isActive()) {
                throw new RuntimeException('Przyrząd nie odczytał aktywnego autora przed przeplotem.');
            }

            // Kontrola odwróconej kolejności: zatrzymaj publikację dopiero PO
            // prawdziwym zapytaniu blokującym autora. Nie zmienia kodu akcji.
            $bariera = (int) ($argumenty['bariera'] ?? 0);
            if ($bariera !== 0) {
                $zatrzymany = false;
                DB::listen(static function (QueryExecuted $query) use ($bariera, &$zatrzymany): void {
                    if (! $zatrzymany && str_contains($query->sql, 'from "users"')
                        && str_contains(strtolower($query->sql), 'for no key update')) {
                        $zatrzymany = true;
                        DB::select('SELECT pg_advisory_xact_lock(2088, ?)', [$bariera]);
                    }
                });
            }

            config(['queue.default' => 'database', 'kuking.community.host_user_id' => $argumenty['gospodarz']]);

            return (string) app(PublishPost::class)->handle(
                $autor,
                'Rosół z kuchni 2088.',
                mediaIds: isset($argumenty['zdjecie']) ? [$argumenty['zdjecie']] : [],
                tagNames: isset($argumenty['tag']) ? [$argumenty['tag']] : [],
                kluczWyslania: $argumenty['klucz'],
            )->getKey();
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

        // Kandydat egzekutora wczytany PRZED lockiem (#2023). Bariera
        // pozwala w tym czasie zatwierdzić nowy wniosek na tym samym koncie.
        'kasowanie-wygaslego-wniosku' => (function () use ($argumenty): bool {
            $kandydat = User::query()->whereKey($argumenty['konto'])->firstOrFail();

            return app(EraseAccountData::class)->handleExpiredRequest($kandydat);
        })(),

        // Kara i usunięcie konta na NIEAKTUALNYM modelu (#980). Model jest
        // czytany zanim uczestnik stanie w kolejce po wiersz — jak formularz,
        // który sprawdził hasło, zanim moderator zdążył zbanować.
        'stan-konta-980' => (function () use ($argumenty): string {
            $konto = User::query()->whereKey($argumenty['konto'])->firstOrFail();
            match ($argumenty['przejscie']) {
                'zbanuj' => $konto->ban(),
                'usun' => $konto->markForDeletion(),
                default => throw new LogicException('Nieobsłużony wariant w match.'),
            };

            return (string) $konto->status;
        })(),

        // Formularz „Usuń konto" (#1346): prawdziwa akcja przyjęcia żądania,
        // na modelu czytanym przed kolejką po wiersz — jak formularz, który
        // sprawdził hasło, zanim druga karta zdążyła wysłać swój.
        'przyjmij-usuniecie' => (function () use ($argumenty): string {
            $konto = User::query()->whereKey($argumenty['konto'])->firstOrFail();
            app(RequestAccountDeletion::class)->handle($konto, $argumenty['zakres']);

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
            $zapisz = static function () use ($argumenty): string {
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
                    oczekiwanaRewizja: isset($argumenty['rewizja']) ? (int) $argumenty['rewizja'] : null,
                );

                return (string) $przepis->title;
            };

            if (isset($argumenty['bariera_2165'])) {
                return DB::transaction(static function () use ($argumenty, $zapisz): string {
                    // B trzyma ten sam zamek, który PublishRecipe bierze przed
                    // przepisem. Bariera ustawia A w kolejce, zanim B zapisze.
                    if (DB::select('SELECT 1 FROM users WHERE id = ? FOR KEY SHARE', [$argumenty['autor']]) === []) {
                        throw new RuntimeException('Brak konta autora pod blokadą FOR KEY SHARE.');
                    }
                    DB::select('SELECT pg_advisory_xact_lock(2165, 1)');

                    return $zapisz();
                });
            }

            return $zapisz();
        })(),

        // Samodzielne oznaczenie alergenów (#1902): prawdziwa akcja, bo mierzymy
        // kolejność blokad `users` → `recipes` wewnątrz niej.
        'oznacz-alergeny' => (function () use ($argumenty): string {
            $przepis = app(OznaczAlergenyPrzepisu::class)->handle(
                User::query()->whereKey($argumenty['autor'])->firstOrFail(),
                Recipe::query()->whereKey($argumenty['przepis'])->firstOrFail(),
                new DeklaracjaAlergenow(['milk'], true),
            );

            return (string) $przepis->allergen_status;
        })(),

        // #2189: prawdziwy zapis przepisu (szkic, publikacja, edycja) przez
        // `PublishRecipe`. Autor jest wczytany PRZED przeplotem, jak w
        // żądaniu po middleware i Policy — to jego nieaktualny model ma
        // wyglądać na aktywny, a akcja ma rozstrzygać na świeżym wierszu.
        'zapisz-przepis-2189' => (function () use ($argumenty): string {
            $autor = User::query()->whereKey($argumenty['autor'])->firstOrFail();
            if (! $autor->isActive()) {
                throw new RuntimeException('Przyrząd nie odczytał aktywnego autora przed przeplotem.');
            }

            // Odwrócony przeplot: zatrzymaj zapis dopiero PO prawdziwym
            // zapytaniu blokującym autora. Nie zmienia kodu akcji.
            $bariera = (int) ($argumenty['bariera'] ?? 0);
            if ($bariera !== 0) {
                $zatrzymany = false;
                DB::listen(static function (QueryExecuted $query) use ($bariera, &$zatrzymany): void {
                    if (! $zatrzymany && str_contains($query->sql, 'from "users"')
                        && str_contains(strtolower($query->sql), 'for no key update')) {
                        $zatrzymany = true;
                        DB::select('SELECT pg_advisory_xact_lock(2189, ?)', [$bariera]);
                    }
                });
            }

            $tryb = $argumenty['tryb'];
            $atrybuty = [
                'title' => $argumenty['tytul'],
                'visibility' => 'public',
                'source_type' => Recipe::SOURCE_OWN,
            ];
            if (isset($argumenty['zdjecie'])) {
                $atrybuty['hero_media_id'] = $argumenty['zdjecie'];
            }

            $przepis = app(PublishRecipe::class)->handle(
                author: $autor,
                attributes: $atrybuty,
                ingredients: [['text' => 'lubczyk']],
                steps: [['instruction' => 'Gotuj do miękkości.']],
                publish: in_array($tryb, ['nowa_publikacja', 'edycja_publicznego'], true),
                existing: isset($argumenty['przepis'])
                    ? Recipe::query()->whereKey($argumenty['przepis'])->firstOrFail()
                    : null,
            );

            return (string) $przepis->getKey();
        })(),

        // #2112: prawdziwe żądanie HTTP przełącznika. Route binding i Policy
        // czytają stan przed decyzją moderatora, a zapis czeka na blokadę.
        'przelacz-wartosci-2112' => (function () use ($argumenty): array {
            // W odwróconym przeplocie przyrząd zatrzymuje autora dopiero po
            // rzeczywistym SELECT FOR UPDATE, kiedy trzyma on zamek przepisu.
            if (isset($argumenty['bariera'])) {
                $zatrzymany = false;
                DB::listen(static function (QueryExecuted $query) use ($argumenty, &$zatrzymany): void {
                    if (! $zatrzymany && str_contains($query->sql, 'from "recipes"')
                        && str_contains(strtolower($query->sql), 'for update')) {
                        $zatrzymany = true;
                        DB::select('SELECT pg_advisory_xact_lock(2112, ?)', [(int) $argumenty['bariera']]);
                    }
                });
            }

            $autor = User::query()->whereKey($argumenty['autor'])->firstOrFail();
            $recipe = Recipe::query()->whereKey($argumenty['przepis'])->firstOrFail();
            Auth::guard('web')->setUser($autor);

            $zadanie = Request::create(route('recipes.wartosci-odzywcze', $recipe), 'PATCH', [
                'pokazuj' => '0',
            ], [], [], ['HTTP_REFERER' => route('recipes.show', $recipe)]);
            $odpowiedz = app(HttpKernel::class)->handle($zadanie);
            $sesja = $zadanie->hasSession() ? $zadanie->session() : null;
            $bledy = $sesja?->get('errors');

            return [
                'status' => $odpowiedz->getStatusCode(),
                'blad' => is_object($bledy)
                    ? $bledy->first('pokazuj')
                    : ($bledy['default']['messages']['pokazuj'][0] ?? null),
                'zapisane' => $sesja?->get('status'),
            ];
        })(),

        'moderuj-przepis-2112' => DB::transaction(static function () use ($argumenty): string {
            $recipe = Recipe::query()->withTrashed()->whereKey($argumenty['przepis'])
                ->lockForUpdate()->firstOrFail();
            $recipe->status = Recipe::STATUS_HIDDEN;
            $recipe->saveQuietly();

            return (string) $recipe->status;
        }),

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

        // Usunięcie komentarza kontra sankcja wykonawcy (#2190). Wykonawca jest
        // wczytany jako AKTYWNY przed zatwierdzeniem sankcji (jak model z
        // middleware); prawdziwa akcja ma sama odczytać świeży stan pod zamkiem.
        // Komentarz z `withTrashed()`, jak w trasie `comments.destroy`.
        'usun-komentarz' => (function () use ($argumenty): string {
            $wykonawca = User::query()->whereKey($argumenty['kto'])->firstOrFail();
            if (! $wykonawca->isActive()) {
                throw new RuntimeException('Przyrząd nie odczytał aktywnego wykonawcy przed przeplotem.');
            }

            return app(DeleteComment::class)->handle(
                $wykonawca,
                Comment::withTrashed()->whereKey($argumenty['komentarz'])->firstOrFail(),
                'Powód usunięcia.',
            ) ? 'usunieto' : 'juz-usuniety';
        })(),

        // Synchronizacja postępu gotowania (#2016): dwa urządzenia jednego konta
        // ustawiają kroki naraz. Wołamy akcję domenową, nie przepisany SQL —
        // test ma pęknąć, jeśli zniknie `lockForUpdate()` w `PostepGotowania`.
        'postep-ustaw' => (function () use ($argumenty): int {
            $postep = CookingProgress::query()->whereKey($argumenty['postep'])->firstOrFail();
            $po = app(PostepGotowania::class)->ustaw(
                $postep,
                $argumenty['krok'],
                true,
                (array) json_decode($argumenty['kroki'], true),
            );

            return $po === null ? -1 : $po->revision;
        })(),

        // Etap 2 (#2016): dwa urządzenia zaznaczają RÓŻNE składniki „przygotowane” naraz.
        'postep-skladnik' => (function () use ($argumenty): int {
            $postep = CookingProgress::query()->whereKey($argumenty['postep'])->firstOrFail();
            $po = app(PostepGotowania::class)->ustawSkladniki(
                $postep,
                [$argumenty['skladnik']],
                [],
                (array) json_decode($argumenty['skladniki'], true),
            );

            return $po === null ? -1 : $po->revision;
        })(),

        'postep-wlacz' => (function () use ($argumenty): int {
            return app(PostepGotowania::class)->wlacz(
                User::query()->whereKey($argumenty['kto'])->firstOrFail(),
                Recipe::query()->whereKey($argumenty['przepis'])->firstOrFail(),
                (array) json_decode($argumenty['kroki'], true),
                [],
            )->revision;
        })(),

        // Pierwszy zapis do zeszytu (#1095). Te scenariusze celowo wołają
        // akcje domenowe, a nie przepisany SQL: test ma pęknąć, jeśli wróci
        // wyścig w User::defaultCollection().
        'zapisz-przepis' => (string) app(SaveRecipeToCollection::class)->handle(
            user: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            recipe: Recipe::query()->whereKey($argumenty['przepis'])->firstOrFail(),
            // Jawny zeszyt — jedna osoba zapisująca naraz do dwóch SWOICH
            // zeszytów (przegląd PR #1213, D-070). Bez argumentu: domyślny.
            collection: isset($argumenty['zeszyt']) ? Collection::query()->whereKey($argumenty['zeszyt'])->firstOrFail() : null,
        )->getKey(),

        // Wspólny zeszyt (#1743): dwa równoległe przyjęcia tego samego
        // zaproszenia i zapis współpracownika kontra odebranie dostępu.
        'przyjmij-zaproszenie' => (string) app(OdpowiedzNaZaproszenie::class)->przyjmij(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            CollectionInvitation::query()->whereKey($argumenty['zaproszenie'])->firstOrFail(),
        )->getKey(),
        'odbierz-dostep' => app(DostepDoZeszytu::class)->odbierz(
            User::query()->whereKey($argumenty['wlasciciel'])->firstOrFail(),
            Collection::query()->whereKey($argumenty['zeszyt'])->firstOrFail(),
            User::query()->whereKey($argumenty['czlonek'])->firstOrFail(),
        ) ? 'odebrano' : 'nie-bylo',

        // Wskazówki od gotujących (#2352): prośba, odpowiedź i wycofanie zgody
        // na prawdziwych akcjach domenowych.
        'zaproponuj-wskazowke' => (string) app(ZaproponujWskazowke::class)->handle(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            CookedEvent::query()->whereKey($argumenty['wykonanie'])->firstOrFail(),
        )->getKey(),
        'przyjmij-wskazowke' => (string) app(PrzyjmijWskazowke::class)->handle(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            RecipeHint::query()->whereKey($argumenty['wskazowka'])->firstOrFail(),
        )->status,
        'odrzuc-wskazowke' => (string) app(OdrzucWskazowke::class)->handle(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            RecipeHint::query()->whereKey($argumenty['wskazowka'])->firstOrFail(),
        )->status,
        'wycofaj-wskazowke' => (string) app(WycofajWskazowke::class)->handle(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            RecipeHint::query()->whereKey($argumenty['wskazowka'])->firstOrFail(),
        )->status,

        // Dwa równoległe uruchomienia przypomnień o urodzinach (#2318).
        // Cisza nocna wyłączona (od = do), żeby wynik nie zależał od godziny.
        'przypomnij-o-urodzinach' => (function (): int {
            config([
                'kuking.notifications.zewnetrzne.cisza_od_godziny' => 0,
                'kuking.notifications.zewnetrzne.cisza_do_godziny' => 0,
                'kuking.urodziny.przypomnienia_na_odbiorce_dziennie' => 3,
            ]);
            barieraPoSprawdzeniuUrodzin();

            return Artisan::call('kuking:przypomnij-o-urodzinach');
        })(),

        // Notatka współpracownika kontra odebranie dostępu (#2311).
        'notatka-w-zeszycie' => (function () use ($argumenty): string {
            barieraPoSprawdzeniuCzlonkostwa((int) $argumenty['stop_po']);

            return (string) app(UpdateCollectionItemNote::class)->handle(
                User::query()->whereKey($argumenty['kto'])->firstOrFail(),
                Collection::query()->whereKey($argumenty['zeszyt'])->firstOrFail(),
                UpdateCollectionItemNote::PRZEPIS,
                $argumenty['przepis'],
                $argumenty['notatka'],
            );
        })(),

        // Zbiorcze „Wyjmij niedostępne zapisy” (#2205): odcisk liczony tu,
        // na świeżym koncie, tak jak robi to formularz przy otwarciu ekranu.
        'wyjmij-niedostepne' => (function () use ($argumenty): int {
            $kto = User::query()->whereKey($argumenty['kto'])->firstOrFail();
            $zeszyt = Collection::query()->whereKey($argumenty['zeszyt'])->firstOrFail();
            $zawartosc = app(WidocznaZawartoscZeszytu::class);

            return app(RemoveUnavailableFromCollection::class)->handle(
                $kto,
                $zeszyt,
                $zawartosc->odcisk($zeszyt, $zawartosc->niedostepne($zeszyt, $kto)),
            );
        })(),

        'zapisz-wpis' => (string) app(SavePostToCollection::class)->handle(
            user: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            post: Post::query()->whereKey($argumenty['wpis'])->firstOrFail(),
        )->getKey(),

        // Obserwowanie tagu kontra scalenie (#853). Prawdziwe akcje: test
        // ma pęknąć, gdy `UpdateTagFollows` przestanie sprawdzać świeży
        // status pod `TagMutationLock`.
        'obserwuj-tag' => (function () use ($argumenty): bool {
            app(UpdateTagFollows::class)->follow(
                User::query()->whereKey($argumenty['kto'])->firstOrFail(),
                [$argumenty['tag']],
            );

            return true;
        })(),

        // Zapis całego widocznego okna tagów (#2091): ten sam formularz,
        // który może dodać relację po zmianie statusu konta w innym żądaniu.
        'zapisz-obserwowane-tagi' => (function () use ($argumenty): bool {
            app(UpdateTagFollows::class)->save(
                User::query()->whereKey($argumenty['kto'])->firstOrFail(),
                [$argumenty['tag']],
                ['shown' => [$argumenty['tag']], 'followed' => []],
            );

            return true;
        })(),

        'scal-tagi' => (string) app(MergeTags::class)->handle(
            Tag::query()->whereKey($argumenty['zrodlo'])->firstOrFail(),
            Tag::query()->whereKey($argumenty['cel'])->firstOrFail(),
        )->getKey(),
        // Limit listy „Co mam w domu” (#1958): prawdziwa akcja domenowa,
        // żeby test pękł, jeśli blokada wiersza właściciela zniknie
        // z `CoMamWDomu::dodaj()`.
        'dodaj-do-pantry' => (string) app(CoMamWDomu::class)
            ->dodaj(User::query()->whereKey($argumenty['kto'])->firstOrFail(), $argumenty['nazwa'])['produkt']
            ->getKey(),

        // Zastąpienie wyboru redakcyjnego (#1027): prawdziwe akcje domenowe,
        // bariera po ich własnym DELETE.
        'tablica-dnia' => (function () use ($argumenty): array {
            barieraPoKasowaniuWyboru('daily_picks');

            return app(ZapiszTabliceDnia::class)->zastap(
                gospodarz: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
                osoby: [],
                wpisy: (array) json_decode($argumenty['wpisy'], true),
                notatki: [],
                przeslaneOsoby: 0,
                przeslaneWpisy: count((array) json_decode($argumenty['wpisy'], true)),
                ip: null,
                dzien: $argumenty['dzien'],
            );
        })(),

        'kolaz' => (function () use ($argumenty): int {
            barieraPoKasowaniuWyboru('hero_picks');
            /** @var array<string, string> $dopuszczone */
            $dopuszczone = (array) json_decode($argumenty['zdjecia'], true);

            return app(ZapiszKolaz::class)->zastap(
                User::query()->whereKey($argumenty['kto'])->firstOrFail(),
                array_keys($dopuszczone),
                $dopuszczone,
                null,
            );
        })(),

        // Dwa RÓŻNE konta potwierdzają zmianę na ten sam wolny adres (#1435).
        // Bariera przyrządu staje zaraz PO aplikacyjnym „czy adres wolny",
        // więc oba procesy mają go już za sobą, gdy ruszają do zapisu.
        // Blokada współdzielona: po zwolnieniu bariery oba idą naraz,
        // a rozstrzyga dopiero `users_email_lower_unique`.
        'potwierdz-wspolny-adres' => (function () use ($argumenty): string {
            // Sesje w bazie, jak na produkcji — inaczej `invalidateSessions()`
            // nie rusza tabeli `sessions` i test nie widziałby jej wycofania.
            config(['session.driver' => 'database']);
            DB::listen(static function (QueryExecuted $query): void {
                if (str_contains($query->sql, 'exists(') && str_contains($query->sql, 'lower(email) = ?')) {
                    DB::select('SELECT pg_advisory_xact_lock_shared(1435, 1)');
                }
            });

            return app(ConfirmEmailChange::class)->handle(
                User::query()->whereKey($argumenty['konto'])->firstOrFail(),
                PendingEmailChange::query()->whereKey($argumenty['zmiana'])->firstOrFail(),
                biezacaSesja: $argumenty['sesja'],
            );
        })(),

        // Korekta dla zgłaszającego po cofniętej decyzji (#2380). Odwołanie
        // czytane przed akcją — oba procesy mają je w pamięci.
        'skoryguj-zglaszajacemu' => (static function () use ($argumenty): string {
            app(NotifyReporterDecisionChanged::class)->handle(
                Appeal::query()->whereKey($argumenty['odwolanie'])->firstOrFail(),
            );

            return 'ok';
        })(),

        // Rozpatrzenie odwołania (#950). Odwołanie czytane PRZED akcją, tak
        // jak zrobiłoby to wiązanie trasy w dwóch równoległych żądaniach —
        // oba procesy trzymają w pamięci `open`.
        'rozpatrz-odwolanie' => (string) app(ResolveAppeal::class)->handle(
            moderator: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            odwolanie: Appeal::query()->whereKey($argumenty['odwolanie'])->firstOrFail(),
            wynik: $argumenty['wynik'],
            uzasadnienie: $argumenty['uzasadnienie'],
            nowaDecyzja: isset($argumenty['nowa_akcja'])
                ? new NowaDecyzja($argumenty['nowa_akcja'], $argumenty['podstawa'], $argumenty['wiadomosc'])
                : null,
        )->status,

        'zmien-role' => app(ChangeUserRole::class)->handle(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            $argumenty['rola'],
        )['changed'],

        'zdejmij-z-urzedu' => (string) app(ZdejmijZUrzedu::class)->handle(
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            Post::query()->whereKey($argumenty['wpis'])->firstOrFail(),
            'spam-reklama',
            'Treść jest reklamą, nie rozmową o gotowaniu.',
        )->getKey(),

        'wyslij-odpowiedz' => (string) app(WyslijOdpowiedz::class)->handle(
            ContactMessage::query()->whereKey($argumenty['wiadomosc'])->firstOrFail(),
            User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            'Odpowiedź z testu wyścigu.',
            replyKey: (string) Str::uuid(),
        )->getKey(),

        // Decyzja w sprawie zgłoszenia przez prawdziwy kontroler panelu
        // (#933: nowa kara równolegle z uchyleniem starej). Bez HTTP, tak jak
        // `ModerationDecideRaceTest` — middleware 2FA nie jest tu mierzone.
        'decyzja-zgloszenia' => (function () use ($argumenty): string {
            $moderator = User::query()->whereKey($argumenty['kto'])->firstOrFail();
            Auth::setUser($moderator);

            $zgloszenie = Report::query()->whereKey($argumenty['zgloszenie'])->firstOrFail();

            // Wejście przez ten sam FormRequest co trasa (#970, krok 2):
            // rola, własna sprawa, stan zgłoszenia i reguły pól, potem kontroler.
            $zadanie = DecyzjaModeracyjnaRequest::create('/admin/zgloszenia/x', 'POST', array_filter([
                'action' => $argumenty['akcja'],
                'reason_code' => 'harassment',
                'suspend_days' => $argumenty['dni'] ?? null,
                'user_message' => 'Decyzja z testu wyścigu.',
            ]));
            $zadanie->setContainer(app())->setRedirector(app('redirect'));
            $zadanie->setLaravelSession(app('session.store'));
            $zadanie->setUserResolver(static fn () => $moderator);
            $trasa = (new Route('POST', '/admin/zgloszenia/{report}', []))->bind($zadanie);
            $trasa->setParameter('report', $zgloszenie);
            $zadanie->setRouteResolver(static fn () => $trasa);

            try {
                $zadanie->validateResolved();
            } catch (ValidationException $e) {
                return implode(' ', $e->validator->errors()->all());
            }

            $odpowiedz = app(ModerationController::class)->decide($zadanie, $zgloszenie);

            $bledy = $odpowiedz->getSession()?->get('errors');

            return $bledy === null ? 'ok' : implode(' ', $bledy->all());
        })(),

        // Bezpośrednie przywrócenie treści akcją domenową (#2086).
        'przywroc-tresc' => (string) app(RestoreContent::class)->handle(
            moderator: User::query()->whereKey($argumenty['kto'])->firstOrFail(),
            target: Post::query()->withTrashed()->whereKey($argumenty['wpis'])->firstOrFail(),
            reasonCode: 'appeal_overturned',
            note: 'Przywrócenie z testu wyścigu.',
            userMessage: 'Przywracamy Twoją treść.',
        )->getKey(),

        // Przywrócenie PRAWDZIWYM kontrolerem panelu (`ModerationController::restore()`,
        // #2086). Bez HTTP: middleware 2FA nie jest tu mierzone, rola z początku
        // żądania jest sprawdzana bramką kontrolera tak jak w trasie.
        'przywroc-z-panelu' => (function () use ($argumenty): string {
            $moderator = User::query()->whereKey($argumenty['kto'])->firstOrFail();
            Auth::setUser($moderator);

            $zgloszenie = Report::query()->whereKey($argumenty['zgloszenie'])->firstOrFail();

            $zadanie = Request::create('/admin/zgloszenia/x/przywroc', 'POST', [
                'reason_code' => 'appeal_overturned',
                'user_message' => 'Przywracamy Twoją treść.',
            ]);
            $zadanie->setLaravelSession(app('session.store'));
            $zadanie->setUserResolver(static fn () => $moderator);
            app()->instance('request', $zadanie);

            $odpowiedz = app(ModerationController::class)->restore($zadanie, $zgloszenie);

            $bledy = $odpowiedz->getSession()?->get('errors');

            return $bledy === null ? 'ok' : implode(' ', $bledy->all());
        })(),

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

        // Zmiany listy tagów promowanych (#1308) — ta sama klasa, której
        // używa panel gospodarza (`TagPromotionController`).
        'promuj-tag' => app(PromowaneTagi::class)->dodaj(Tag::query()->findOrFail($argumenty['tag'])),

        'przesun-promowany' => app(PromowaneTagi::class)->przesun(
            Tag::query()->findOrFail($argumenty['tag']),
            (int) $argumenty['kierunek'],
        ),
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

        // Rezerwacja budżetu modelu importu (D-297): PRAWDZIWE `BudzetAi`,
        // z limitem dziennym podanym przez test.
        'rezerwacja-budzetu' => (function () use ($argumenty): string {
            config(['kuking.import.budzet.dzienny_usd' => (float) $argumenty['limit_usd']]);
            config(['kuking.import.budzet.miesieczny_usd' => 1000.0]);
            $wynik = app(BudzetAi::class)->zarezerwuj((int) $argumenty['kwota'], (string) Str::uuid(), 1);

            return $wynik instanceof Rezerwacja ? 'zarezerwowano' : 'odmowa:'.$wynik;
        })(),

        'ponow-import' => (function () use ($argumenty): string {
            config([
                'kuking.import.model.klucz' => 'sk-test-import',
                'kuking.import.model.nazwa' => 'gpt-6-luna',
                'kuking.import.model.cena_wejscie_mln_usd' => '2',
                'kuking.import.model.cena_wyjscie_mln_usd' => '8',
                'kuking.import.zrodla.zdjecie' => true,
                'queue.default' => 'database',
            ]);

            return (string) app(ZlecImportPrzepisu::class)->ponow(
                User::query()->findOrFail($argumenty['kto']),
                ImportPrzepisu::query()->findOrFail($argumenty['poprzednie']),
            )->getKey();
        })(),
        'wspolny-limit-importu' => (function () use ($argumenty): string {
            config([
                'kuking.import.limity.na_osobe_dzien' => (int) ($argumenty['limit_dzienny'] ?? 1),
                'kuking.import.limity.na_osobe_miesiac' => (int) ($argumenty['limit_miesieczny'] ?? 30),
            ]);
            $osoba = User::query()->findOrFail($argumenty['kto']);
            $zrodlo = (string) $argumenty['zrodlo'];

            if ($zrodlo === 'zdjecie') {
                $proba = DB::transaction(static fn () => app(LimitImportowOsoby::class)
                    ->rezerwuj($osoba, 'zdjecie', (string) Str::uuid()));
            } else {
                try {
                    $proba = app(LimitImportu::class)->zuzyj($osoba, $zrodlo, (string) Str::uuid());
                } catch (ImportOdrzucony) {
                    $proba = null;
                }
            }

            return $proba === null ? 'odmowa' : 'rezerwacja';
        })(),
        // Rejestracja wdrożenia (issue #1932, D-318): numer kolejny liczony
        // pod `pg_advisory_xact_lock(hashtext($etykieta))` wewnątrz akcji —
        // test na dwóch połączeniach trzyma TĘ SAMĄ blokadę na własnym
        // połączeniu (ten sam klucz), żeby wymusić prawdziwe zderzenie dwóch
        // równoległych rejestracji pod tą samą etykietą.
        'zarejestruj-wdrozenie' => app(ZarejestrujWdrozenie::class)->handle(
            $argumenty['commit'],
            $argumenty['etykieta'],
        ),

        // Import wartości odżywczych (#2130) na prawdziwej klasie. `bariera`
        // zatrzymuje uczestnika tuż PRZED zapisem znacznika (zdarzenie
        // `WritingKey` z magazynu cache): czeka na blokadę doradczą trzymaną
        // przez test i zaraz ją oddaje. Przyrząd nie zmienia kodu importu —
        // tylko wybiera moment, w którym uczestnik staje.
        'importuj-odzywcze' => (static function () use ($argumenty): array {
            if (($argumenty['bariera'] ?? '') === '1') {
                Event::listen(WritingKey::class, static function (WritingKey $zdarzenie): void {
                    if ($zdarzenie->key === 'odzywcze:import:hash-plikow') {
                        DB::select('SELECT pg_advisory_lock(2130, 1)');
                        DB::select('SELECT pg_advisory_unlock(2130, 1)');
                    }
                });
            }

            return app(ImportujWartosciOdzywcze::class)->handle($argumenty['katalog']);
        })(),

        // Prawdziwa komenda używana przez obie ścieżki wdrożenia (#2082).
        'migruj-pod-blokada' => Artisan::call('kuking:migruj-pod-blokada'),

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
