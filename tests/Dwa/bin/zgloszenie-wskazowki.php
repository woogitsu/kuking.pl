<?php

declare(strict_types=1);

/** #2887: rzeczywiste akcje domeny; bariery istnieją tylko w tym przyrządzie. */
use App\Domain\Moderation\Actions\ReportContent;
use App\Domain\Recipes\Actions\PoprawWykonanie;
use App\Domain\Wskazowki\WycofajWskazowke;
use App\Models\CookedEvent;
use App\Models\RecipeHint;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require __DIR__.'/../../bootstrap.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$trace = [];

try {
    if (! $app->environment('testing')
        || DB::connection()->getDatabaseName() !== kuking_nazwa_bazy_wyscigow($app->basePath())) {
        throw new RuntimeException('Przyrząd #2887 wymaga własnej bazy grupy dwa-polaczenia.');
    }
    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '30s'");
    Http::preventStrayRequests();
    Mail::fake();
    $mode = $argv[1];
    $arguments = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    DB::select('SELECT set_config(\'application_name\', ?, false)', ['2887-'.$mode.'-'.$arguments['hint']]);
    $trace['pid'] = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $trace['action_file'] = (new ReflectionClass(ReportContent::class))->getFileName();
    $hint = RecipeHint::with(['recipe.author', 'cook', 'cookedEvent.recipe.author', 'cookedEvent.user'])->findOrFail($arguments['hint']);
    $trace['note_before'] = $hint->cookedEvent->note;

    if ($mode === 'withdraw-edit') {
        $cook = User::query()->findOrFail($arguments['cook']);
        app(WycofajWskazowke::class)->handle($cook, $hint);
        $event = CookedEvent::query()->findOrFail($hint->cooked_event_id);
        $correction = app(PoprawWykonanie::class)->handle($cook, $event, [
            'note' => 'Uwaga B po wycofaniu.',
            'changes_note' => 'Osobny opis zmian.',
            'actual_minutes' => 23,
        ], $event->wersjaPolKorekty());
        $trace['changed'] = $correction->zmienione;
        $trace['skipped'] = $correction->pominiete;
    } else {
        $viewer = User::query()->findOrFail($arguments['viewer']);
        if ($mode === 'report-forward') {
            $first = true;
            Gate::after(static function (?User $user, string $ability, ?bool $result, array $targets) use ($hint, &$first, &$trace): void {
                if ($first && $ability === 'report' && ($targets[0] ?? null) instanceof RecipeHint
                    && $targets[0]->getKey() === $hint->getKey() && $result === true) {
                    $first = false;
                    $trace['initial_authorization'] = true;
                    DB::select('SELECT pg_advisory_xact_lock(628870, 1)');
                }
            });
        } elseif ($mode === 'report-reverse') {
            Report::creating(static function (Report $report) use ($hint, &$trace): void {
                if ($report->target_type === 'recipe_hint' && $report->target_id === $hint->getKey()) {
                    $trace['creating_reached'] = true;
                    DB::select('SELECT pg_advisory_xact_lock(628870, 1)');
                }
            });
        } elseif ($mode !== 'report-duplicate') {
            throw new RuntimeException('Nieznany scenariusz przyrządu #2887.');
        }
        $report = app(ReportContent::class)->handle($viewer, $hint, 'spam');
        $trace['report_id'] = (string) $report->getKey();
    }

    $trace['hint_status'] = $hint->fresh()->status;
    $trace['note_after'] = $hint->cookedEvent->fresh()->note;
    echo json_encode(['ok' => true, 'wartosc' => $trace, 'sqlstate' => null, 'komunikat' => '', 'wyjatek' => null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'wartosc' => $trace,
        'sqlstate' => $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null,
        'komunikat' => $exception->getMessage(),
        'wyjatek' => $exception::class,
    ], JSON_UNESCAPED_UNICODE);
}
