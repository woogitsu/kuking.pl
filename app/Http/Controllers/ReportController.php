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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * Zgłaszanie treści (DSA art. 16).
 *
 * Przycisk w interfejsie ma NAPIS "Zgłoś", nie ikonkę flagi — osoba, która
 * chce zgłosić oszustwo, nie ma zgadywać, co znaczy trójkącik.
 *
 * `resolveTarget()` niżej rozstrzyga TYLKO, czy cel ISTNIEJE — nie, czy
 * zgłaszającemu wolno go zobaczyć. Widoczność sprawdza `ReportContent`
 * (`authorize()`/`handle()`), nie ten kontroler (audyt W7-05, AGENTS.md §4):
 * gdyby bramka siedziała tutaj, a nie w Domain, każdy kolejny endpoint
 * budujący cel zgłoszenia musiałby o niej pamiętać z osobna.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportContent $report) {}

    public function create(Request $request, string $type, string $id): View
    {
        $target = $this->resolveTarget($type, $id);

        // Ta sama bramka co w `store()` (przez `handle()`) — inaczej sam
        // formularz renderowałby się dla treści, której zgłaszający nie ma
        // prawa zobaczyć, i byłby to oracle istnienia sam w sobie.
        $this->report->authorize($request->user(), $target);

        return view('pages.report', [
            'targetType' => $type,
            'targetId' => $id,
            'target' => $target,
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
        } catch (ModelNotFoundException $e) {
            // `ModelNotFoundException` DZIEDZICZY po `RuntimeException` — bez
            // tego jawnego wyjątku niżej ją złapałby i przerobił na zwykły
            // błąd formularza (302 z komunikatem), a bramka widoczności ma
            // dawać 404, nieodróżnialny od celu, który w ogóle nie istnieje.
            throw $e;
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
            // NIE `where('slug', $id)->orWhere('id', $id)`.
            //
            // `recipes.id` jest kolumną `uuid`, więc Postgres musi rzutować
            // parametr na uuid, żeby w ogóle wykonać porównanie — niezależnie
            // od tego, czy pierwszy warunek pasuje. Slug rzutowania nie
            // przechodzi i całe zapytanie pada:
            //
            //   SQLSTATE[22P02]: invalid input syntax for type uuid: "rosol-babci"
            //
            // Skutek: przycisk „Zgłoś" pod KAŻDYM przepisem zwracał 500,
            // bo widok przekazuje tam slug (pages/recipes/show.blade.php).
            // Zgłaszanie treści to obowiązek z DSA art. 16, więc awaria
            // dotyczyła nie wygody, tylko rzeczy, którą musimy zapewnić.
            //
            // Rozstrzygamy typ w PHP, zanim dotkniemy bazy.
            'recipe' => Str::isUuid($id)
                ? Recipe::findOrFail($id)
                : Recipe::where('slug', $id)->firstOrFail(),
            'comment' => Comment::findOrFail($id),
            'cooked_event' => CookedEvent::findOrFail($id),
            'user' => Profile::where('username', $id)->firstOrFail()->user,
            default => abort(404),
        };
    }
}
