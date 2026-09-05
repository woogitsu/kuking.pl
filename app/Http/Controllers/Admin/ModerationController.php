<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kolejka moderacji dla 1-2 osobowego zespołu.
 *
 * Musi istnieć PRZED publicznym startem (docs/ROADMAP.md, punkt 9).
 * Każda decyzja zapisuje uzasadnienie i treść wysłaną użytkownikowi —
 * bez tego nie da się rozpatrzyć odwołania (DSA art. 17).
 */
class ModerationController extends Controller
{
    public function reports(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $status = $request->query('status', 'open');

        return view('pages.admin.reports', [
            'status' => $status,
            'reports' => Report::query()
                ->when($status !== 'wszystkie', fn ($query) => $query->where('status', $status))
                ->with(['reporter.profile', 'resolver.profile'])
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'counts' => [
                'open' => Report::where('status', Report::STATUS_OPEN)->count(),
                'reviewing' => Report::where('status', Report::STATUS_REVIEWING)->count(),
                'resolved' => Report::where('status', Report::STATUS_RESOLVED)->count(),
            ],
        ]);
    }

    public function decide(Request $request, Report $report): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $data = $request->validate([
            'action' => ['required', 'in:no_action,hide,remove,warn,suspend,ban'],
            'reason_code' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:2000'],
            'user_message' => ['nullable', 'string', 'max:2000'],
        ], [
            'action.required' => 'Wybierz decyzję.',
            'reason_code.required' => 'Podaj powód decyzji — bez niego nie da się odpowiedzieć na odwołanie.',
        ]);

        $moderator = $request->user();

        ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $report->getKey(),
            'target_type' => $report->target_type,
            'target_id' => $report->target_id,
            'action' => $data['action'],
            'reason_code' => $data['reason_code'],
            'note' => $data['note'] ?? null,
            'user_message' => $data['user_message'] ?? null,
        ]);

        $this->applyAction($report, $data['action']);

        $report->update([
            'status' => $data['action'] === ModerationAction::ACTION_NONE
                ? Report::STATUS_REJECTED
                : Report::STATUS_RESOLVED,
            'resolution_note' => $data['note'] ?? null,
            'resolved_by' => $moderator->getKey(),
            'resolved_at' => now(),
        ]);

        AuditLogEntry::record(
            action: 'moderation.decided',
            actor: $moderator,
            subject: $report,
            metadata: ['decision' => $data['action'], 'reason_code' => $data['reason_code']],
            ip: $request->ip(),
        );

        return back()->with('status', 'Decyzja zapisana.');
    }

    /**
     * Wykonanie decyzji na obiekcie.
     *
     * `hide` ukrywa treść (da się przywrócić), `remove` usuwa miękko.
     * Automat nigdy nie banuje sam — ban jest zawsze decyzją człowieka.
     */
    private function applyAction(Report $report, string $action): void
    {
        if ($action === ModerationAction::ACTION_NONE) {
            return;
        }

        $target = match ($report->target_type) {
            'post' => Post::find($report->target_id),
            'recipe' => Recipe::find($report->target_id),
            'comment' => Comment::find($report->target_id),
            'cooked_event' => CookedEvent::find($report->target_id),
            'user' => User::find($report->target_id),
            default => null,
        };

        if ($target === null) {
            return;
        }

        match ($action) {
            ModerationAction::ACTION_HIDE => $this->hide($target),
            ModerationAction::ACTION_REMOVE => $target->delete(),
            ModerationAction::ACTION_SUSPEND => $target instanceof User
                ? $target->suspend()
                : $this->hide($target),
            ModerationAction::ACTION_BAN => $target instanceof User
                ? $target->ban()
                : $target->delete(),
            default => null,
        };
    }

    private function hide(object $target): void
    {
        if ($target instanceof Post || $target instanceof Recipe) {
            $target->update(['status' => 'hidden']);

            return;
        }

        if ($target instanceof Comment) {
            $target->update(['status' => Comment::STATUS_HIDDEN]);
        }
    }
}
