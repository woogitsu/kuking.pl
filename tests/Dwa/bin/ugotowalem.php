<?php

declare(strict_types=1);

/**
 * Uczestnik wyścigu #2017: „Ugotowałem” kontra równoległa utrata dostępu.
 *
 * Woła PRAWDZIWE akcje: `RecordCookedEvent` poprzedzone tym samym
 * sprawdzeniem Policy co w kontrolerze, `PublishRecipe` (autor przełącza
 * przepis na „tylko ja”), decyzję moderacji przez `ModerationController`,
 * `BlockUser` i `User::ban()`. Bariera należy wyłącznie do przyrządu:
 * zatrzymuje akcję wewnątrz jej transakcji zaraz po wskazanym zapytaniu
 * (`after_sql` — wszystkie fragmenty muszą wystąpić w jednym zapytaniu).
 */

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Social\Actions\BlockUser;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Requests\Moderation\DecyzjaModeracyjnaRequest;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
    $recipe = Recipe::query()->findOrFail($args['recipe']);

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
        'cook' => (function () use ($actor, $recipe): string {
            // Dokładnie to, co robi `CookedEventController::store` przed akcją.
            Gate::forUser($actor)->authorize('cook', $recipe);

            return (string) app(RecordCookedEvent::class)->handle(
                cook: $actor,
                recipe: $recipe,
                note: 'Wyszło w wyścigu.',
                kluczWyslania: (string) Str::uuid7(),
            )->getKey();
        })(),
        'private' => app(PublishRecipe::class)->handle(
            author: $actor,
            attributes: ['title' => 'Przepis po zmianie', 'visibility' => 'private', 'source_type' => 'own'],
            ingredients: [['text' => 'ziemniaki']], steps: [['instruction' => 'Ugotuj ziemniaki.']],
            publish: true, existing: $recipe,
        )->visibility,
        'block' => app(BlockUser::class)->handle($actor, User::query()->findOrFail($args['other'])),
        'ban' => (function () use ($args): string {
            $osoba = User::query()->findOrFail($args['other']);
            $osoba->ban();

            return $osoba->status;
        })(),
        'hide' => (function () use ($actor, $args): int {
            Auth::setUser($actor);
            $report = Report::query()->findOrFail($args['report']);
            // To samo wejście co w `komentarz.php`: FormRequest trasy, potem kontroler.
            $request = DecyzjaModeracyjnaRequest::create('/admin/zgloszenia/'.$args['report'], 'POST', [
                'action' => 'hide', 'reason_code' => 'spam', 'user_message' => 'Decyzja w sprawie treści.',
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
    echo json_encode(['ok' => true, 'wartosc' => $result, 'sqlstate' => null, 'wyjatek' => null, 'komunikat' => '']);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false, 'wartosc' => null, 'sqlstate' => (string) $exception->getCode(),
        'wyjatek' => $exception::class, 'komunikat' => $exception->getMessage(),
    ]);
}
