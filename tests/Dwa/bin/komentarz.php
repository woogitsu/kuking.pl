<?php

declare(strict_types=1);

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Posts\Actions\EditPost;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Requests\Moderation\DecyzjaModeracyjnaRequest;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$args = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
// Rodzina baz wyścigów to `kuking_race` (główny checkout, CI) oraz
// `kuking_race_<kopia robocza>` — ta sama reguła, co `kuking_nazwa_bazy_wyscigow()`.
// Sam przedrostek `kuking_race_` odrzucał gołe `kuking_race`, więc w CI każde
// dziecko ginęło tu przed barierą, a test widział tylko jej brak po 4 s.
if (preg_match('/\Akuking_race(_|\z)/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Odmowa uruchomienia poza izolowaną bazą wyścigów.');
}
DB::statement("SET lock_timeout = '5s'");
DB::statement("SET statement_timeout = '8s'");
DB::statement("SET idle_in_transaction_session_timeout = '12s'");
DB::selectOne("SELECT set_config('application_name', ?, false)", [$args['name']]);

try {
    $actor = User::query()->findOrFail($args['actor']);
    $class = match ($args['type']) {
        'post' => Post::class, 'recipe' => Recipe::class, 'cooked' => CookedEvent::class,
    };
    $subject = $class::query()->findOrFail($args['subject']);
    $parent = isset($args['parent']) ? Comment::query()->findOrFail($args['parent']) : null;

    // Bariera testu, nie produkcji: zatrzymuje rzeczywistą akcję po wskazanym
    // zapytaniu. Obserwator potwierdza PID, SQL i właściciela blokady.
    $armed = isset($args['after_sql']);
    $accountLocks = [];
    DB::listen(function (QueryExecuted $query) use (&$armed, &$accountLocks, $args): void {
        if (str_contains($query->sql, '"users"') && str_contains($query->sql, 'FOR NO KEY UPDATE')) {
            $accountLocks[] = $query->bindings[0];
        }
        if ($armed && str_contains(strtolower($query->sql), $args['after_sql'])) {
            $armed = false;
            DB::selectOne('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$args['name']]);
        }
    });
    if (($args['pause'] ?? '') === 'loaded') {
        DB::selectOne('SELECT pg_advisory_lock(9157, hashtext(?))', [$args['name']]);
        DB::selectOne('SELECT pg_advisory_unlock(9157, hashtext(?))', [$args['name']]);
    }

    $result = match ($argv[1]) {
        'publish' => app(PublishComment::class)->handle($actor, $subject, $args['body'], $parent)->getKey(),
        'block' => app(BlockUser::class)->handle($actor, User::query()->findOrFail($args['other'])),
        'unfollow' => app(UnfollowUser::class)->handle($actor, User::query()->findOrFail($args['other'])),
        'edit_post' => app(EditPost::class)->handle($actor, $subject, 'Treść po zmianie.', 'private')->visibility,
        'edit_recipe' => app(PublishRecipe::class)->handle(
            author: $actor,
            attributes: ['title' => 'Przepis po zmianie', 'visibility' => 'private', 'source_type' => 'own'],
            ingredients: [['text' => 'ziemniaki']], steps: [['instruction' => 'Ugotuj ziemniaki.']],
            publish: true, existing: Recipe::query()->findOrFail($args['recipe'] ?? $args['subject']),
        )->visibility,
        'delete_parent' => app(DeleteComment::class)->handle($actor, $parent, 'Powód usunięcia.'),
        'moderate' => (function () use ($actor, $args): int {
            Auth::setUser($actor);
            $report = Report::query()->findOrFail($args['report']);
            // Wejście przez ten sam FormRequest co trasa (#970, krok 2):
            // rola, własna sprawa, stan zgłoszenia i reguły pól, potem kontroler.
            $request = DecyzjaModeracyjnaRequest::create('/admin/zgloszenia/'.$args['report'], 'POST', [
                'action' => $args['decision'], 'reason_code' => 'spam', 'user_message' => 'Decyzja w sprawie treści.',
            ]);
            $request->setContainer(app())->setRedirector(app('redirect'));
            $request->setUserResolver(fn () => $actor);
            $request->setLaravelSession(app('session.store'));
            $route = (new Route('POST', '/admin/zgloszenia/{report}', []))->bind($request);
            $route->setParameter('report', $report);
            $request->setRouteResolver(fn () => $route);
            $request->validateResolved();

            return app(ModerationController::class)->decide($request, $report)->getStatusCode();
        })(),
        default => throw new RuntimeException('Nieznany scenariusz.'),
    };
    echo json_encode(['ok' => true, 'wartosc' => isset($args['trace']) ? ['id' => $result, 'account_locks' => $accountLocks] : $result, 'sqlstate' => null]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
