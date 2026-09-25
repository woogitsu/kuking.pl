<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #1022: zapis do zeszytu kontra równoległa zmiana stanu.
 *
 * Woła PRAWDZIWE akcje (zapis, edycja, moderacja, blokada, status konta,
 * usunięcie zeszytu). Bariera należy wyłącznie do przyrządu: zatrzymuje
 * zapis po sprawdzeniu, jakie robi kontroler (`pause`), albo wewnątrz
 * transakcji zamka, po wskazanym zapytaniu (`after_sql`).
 */

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Posts\Actions\EditPost;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Social\Actions\BlockUser;
use App\Http\Controllers\Admin\ModerationController;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
// Ta sama reguła rodziny baz co w `komentarz.php`.
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '5s'");
DB::statement("SET statement_timeout = '8s'");
DB::statement("SET idle_in_transaction_session_timeout = '12s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    $actor = User::query()->findOrFail($args['actor']);
    $class = $args['type'] === 'post' ? Post::class : Recipe::class;

    $armed = isset($args['after_sql']);
    DB::listen(function (QueryExecuted $query) use (&$armed, $args): void {
        if (! $armed) {
            return;
        }
        foreach ((array) $args['after_sql'] as $fragment) {
            if (! str_contains(strtolower($query->sql), $fragment)) {
                return;
            }
        }
        $armed = false;
        DB::selectOne('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$args['name']]);
    });

    $result = match ($argv[1]) {
        'save' => (function () use ($actor, $class, $args): string {
            // Dokładnie to, co robi kontroler przed akcją: Policy i własny zeszyt.
            $subject = $class::query()->findOrFail($args['subject']);
            Gate::forUser($actor)->authorize('view', $subject);
            $collection = isset($args['collection']) ? $actor->collections()->findOrFail($args['collection']) : null;

            if (($args['pause'] ?? '') === 'po_sprawdzeniu') {
                DB::selectOne('SELECT pg_advisory_lock(9157, hashtext(?))', [$args['name']]);
                DB::selectOne('SELECT pg_advisory_unlock(9157, hashtext(?))', [$args['name']]);
            }

            $target = $subject instanceof Recipe
                ? app(SaveRecipeToCollection::class)->handle($actor, $subject, $collection)
                : app(SavePostToCollection::class)->handle($actor, $subject, $collection);

            return (string) $target->getKey();
        })(),
        'private' => $args['type'] === 'post'
            ? app(EditPost::class)->handle($actor, Post::query()->findOrFail($args['subject']), 'Treść po zmianie.', 'private')->visibility
            : app(PublishRecipe::class)->handle(
                author: $actor,
                attributes: ['title' => 'Przepis po zmianie', 'visibility' => 'private', 'source_type' => 'own'],
                ingredients: [['text' => 'ziemniaki']], steps: [['instruction' => 'Ugotuj ziemniaki.']],
                publish: true, existing: Recipe::query()->findOrFail($args['subject']),
            )->visibility,
        'block' => (function () use ($actor, $args): bool {
            app(BlockUser::class)->handle($actor, User::query()->findOrFail($args['other']));

            return true;
        })(),
        'status' => (function () use ($actor, $args): string {
            DB::transaction(static fn () => match ($args['transition']) {
                'zawies' => $actor->suspend(),
                'zbanuj' => $actor->ban(),
                'usun' => $actor->markForDeletion(),
            });

            return (string) $actor->fresh()?->status;
        })(),
        // Jak `CollectionController::destroy()`: sam `delete()` wiersza.
        'delete_collection' => (bool) Collection::query()->findOrFail($args['collection'])->delete(),
        'moderate' => (function () use ($actor, $args): int {
            Auth::setUser($actor);
            $request = Request::create('/admin/zgloszenia/'.$args['report'], 'POST', [
                'action' => $args['decision'], 'reason_code' => 'spam', 'user_message' => 'Decyzja w sprawie treści.',
            ]);
            $request->setUserResolver(fn () => $actor);
            $request->setLaravelSession(app('session.store'));

            return app(ModerationController::class)->decide($request, Report::query()->findOrFail($args['report']))->getStatusCode();
        })(),
        default => throw new RuntimeException('Nieznany scenariusz.'),
    };
    echo json_encode(['ok' => true, 'wartosc' => $result, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
