<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\DailyPick;
use App\Models\Post;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wybór redakcyjny na tablicę „kuKINGi na dziś".
 *
 * Ekran ma zająć gospodarzowi minutę, nie pięć — dlatego jest to jedna strona
 * z listą świeżych wpisów i jednym polem wyboru przy każdym, a nie kreator.
 *
 * Świadomie NIE MA tu sortowania po popularności ani żadnej liczby, która
 * podpowiadałaby wybór. Gospodarz ma patrzeć na zdjęcia i na ludzi,
 * nie na słupki.
 */
class DailyBoardController extends Controller
{
    public function edit(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $picks = DailyPick::query()->forDate()->get();

        return view('pages.admin.daily-board', [
            'wybraneWpisy' => $picks->where('subject_type', DailyPick::TYPE_POST)->pluck('subject_id')->all(),
            'wybraneOsoby' => $picks->where('subject_type', DailyPick::TYPE_USER)->pluck('subject_id')->all(),
            'notatki' => $picks->pluck('note', 'subject_id')->filter()->all(),

            // Świeże wpisy z ostatnich dni — z tego gospodarz wybiera.
            'wpisy' => Post::query()
                ->publiclyVisible()
                ->where('published_at', '>=', now()->subDays(7))
                ->with(['author.profile.avatar', 'media'])
                ->orderByDesc('published_at')
                ->limit(40)
                ->get(),

            // Osoby, które ostatnio coś pokazały.
            'osoby' => User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->whereHas('posts', fn ($query) => $query->publiclyVisible())
                ->with('profile.avatar')
                ->limit(40)
                ->get()
                ->sortBy(fn (User $user) => $user->displayName())
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $data = $request->validate([
            'wpisy' => ['nullable', 'array', 'max:6'],
            'wpisy.*' => ['uuid'],
            'osoby' => ['nullable', 'array', 'max:6'],
            'osoby.*' => ['uuid'],
            'notatki' => ['nullable', 'array'],
            'notatki.*' => ['nullable', 'string', 'max:300'],
        ], [
            'wpisy.max' => 'Wybierz najwyżej 6 dań. Tablica ma być krótka.',
            'osoby.max' => 'Wybierz najwyżej 6 osób. Tablica ma być krótka.',
        ]);

        $notatki = $data['notatki'] ?? [];
        $moderator = $request->user();

        // Wybór na dany dzień zastępujemy w całości — to jest prostsze
        // w obsłudze niż dokładanie i odejmowanie pozycji.
        DailyPick::query()->whereDate('shown_on', Czas::dzisiajData())->delete();

        $position = 0;

        foreach ($data['osoby'] ?? [] as $userId) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_USER,
                'subject_id' => $userId,
                'position' => $position++,
                'curator_id' => $moderator->getKey(),
                'note' => $this->nullIfBlank($notatki[$userId] ?? null),
            ]);
        }

        $position = 0;

        foreach ($data['wpisy'] ?? [] as $postId) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_POST,
                'subject_id' => $postId,
                'position' => $position++,
                'curator_id' => $moderator->getKey(),
                'note' => $this->nullIfBlank($notatki[$postId] ?? null),
            ]);
        }

        AuditLogEntry::record(
            action: 'daily_board.updated',
            actor: $moderator,
            metadata: [
                'osoby' => count($data['osoby'] ?? []),
                'wpisy' => count($data['wpisy'] ?? []),
            ],
            ip: $request->ip(),
        );

        $razem = count($data['osoby'] ?? []) + count($data['wpisy'] ?? []);

        return back()->with('status', $razem === 0
            ? 'Wyczyszczone. Tablica dobierze treści sama.'
            : "Zapisane. Na tablicy jest dziś {$razem} pozycji.");
    }

    /** Usuwa dzisiejszy wybór — tablica wraca do trybu automatycznego. */
    public function destroy(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        DailyPick::query()->whereDate('shown_on', Czas::dzisiajData())->delete();

        return back()->with('status', 'Wyczyszczone. Tablica dobierze treści sama.');
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
