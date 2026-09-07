<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kolejka odwołań (issue #10, DSA art. 20).
 *
 * CO MODERATOR MUSI ZOBACZYĆ, ŻEBY W OGÓLE MÓC ROZPATRZYĆ SPRAWĘ
 *
 *  * własne słowa odwołującego się,
 *  * decyzję, od której się odwołuje, wraz z jej powodem,
 *  * DOKŁADNIE TĘ WIADOMOŚĆ, którą ta osoba wtedy dostała (`user_message`) —
 *    bez tego nie da się ocenić, czy pisze o tym samym, co jej powiedzieliśmy,
 *  * kto podjął pierwotną decyzję — bo od tego zależy karencja na jej
 *    podtrzymanie (`ResolveAppeal`),
 *  * termin odpowiedzi i to, czy już minął.
 *
 * Zamknięcie sprawy WYMAGA uzasadnienia. „Podtrzymuję" bez zdania wyjaśniającego
 * nie jest odpowiedzią w rozumieniu DSA art. 20 i nie da się go zapisać — pilnuje
 * tego i walidacja, i CHECK w bazie.
 */
class AppealController extends Controller
{
    public function __construct(private readonly ResolveAppeal $rozpatrz) {}

    public function index(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $status = $request->query('status', Appeal::STATUS_OPEN);

        return view('pages.admin.appeals', [
            'status' => $status,
            'appeals' => Appeal::query()
                ->when($status !== 'wszystkie', fn ($query) => $query->where('status', $status))
                ->with(['user.profile', 'report', 'decider.profile', 'moderationAction.moderator.profile'])
                // Otwarte najstarsze na górze: termin odpowiedzi liczy się od
                // złożenia, więc kolejność „najnowsze pierwsze" gwarantowałaby,
                // że przeterminowane leżą najgłębiej i nikt ich nie widzi.
                ->orderBy('created_at')
                ->paginate(25)
                ->withQueryString(),
            'counts' => [
                'open' => Appeal::where('status', Appeal::STATUS_OPEN)->count(),
                'upheld' => Appeal::where('status', Appeal::STATUS_UPHELD)->count(),
                'overturned' => Appeal::where('status', Appeal::STATUS_OVERTURNED)->count(),
            ],
        ]);
    }

    public function resolve(Request $request, Appeal $appeal): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $data = $request->validate([
            'outcome' => ['required', 'in:'.Appeal::STATUS_UPHELD.','.Appeal::STATUS_OVERTURNED],
            'decision_note' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'outcome.required' => 'Wybierz, czy podtrzymujesz decyzję, czy ją cofasz.',
            'outcome.in' => 'Wybierz jedną z dwóch odpowiedzi.',
            'decision_note.required' => 'Napisz uzasadnienie — to jest odpowiedź, którą przeczyta ta osoba.',
            'decision_note.min' => 'Uzasadnienie ma być zdaniem, nie jednym słowem.',
        ]);

        try {
            $this->rozpatrz->handle(
                moderator: $request->user(),
                odwolanie: $appeal,
                wynik: $data['outcome'],
                uzasadnienie: $data['decision_note'],
                ip: $request->ip(),
            );
        } catch (BladDlaCzlowieka $blad) {
            return back()->withErrors(['outcome' => $blad->getMessage()])->withInput();
        }

        return back()->with('status', 'Odpowiedź zapisana i wysłana.');
    }
}
