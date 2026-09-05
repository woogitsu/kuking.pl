<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\Actions\ReportContent;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Zgłaszanie treści (DSA art. 16).
 *
 * Przycisk w interfejsie ma NAPIS "Zgłoś", nie ikonkę flagi — osoba, która
 * chce zgłosić oszustwo, nie ma zgadywać, co znaczy trójkącik.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportContent $report) {}

    public function create(Request $request, string $type, string $id): View
    {
        return view('pages.report', [
            'targetType' => $type,
            'targetId' => $id,
            'target' => $this->resolveTarget($type, $id),
            'reasons' => Report::REASONS,
        ]);
    }

    public function store(Request $request, string $type, string $id): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string'],
            'details' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason.required' => 'Wybierz, co jest nie tak z tą treścią.',
        ]);

        try {
            $this->report->handle(
                reporter: $request->user(),
                target: $this->resolveTarget($type, $id),
                reason: $data['reason'],
                details: $data['details'] ?? null,
                ip: $request->ip(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('home')->with('status',
            'Dziękujemy. Zgłoszenie trafiło do nas i sprawdzimy je najszybciej, jak się da. Jeśli chcesz, możesz też zablokować tę osobę — wtedy nie zobaczycie już wzajemnie swoich treści.',
        );
    }

    private function resolveTarget(string $type, string $id): Model
    {
        return match ($type) {
            'post' => Post::findOrFail($id),
            'recipe' => Recipe::where('slug', $id)->orWhere('id', $id)->firstOrFail(),
            'comment' => Comment::findOrFail($id),
            'cooked_event' => CookedEvent::findOrFail($id),
            'user' => Profile::where('username', $id)->firstOrFail()->user,
            default => abort(404),
        };
    }
}
