<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DailyBoard;
use App\Domain\Social\Actions\FollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Profile;
use App\Models\Topic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Onboarding: zainteresowania → opcjonalne obserwowanie → gotowe.
 *
 * Twarda zasada z docs/UX_50_PLUS.md: NIE WYMUSZAMY PUBLIKACJI.
 * Na końcu są dwa równorzędne wyjścia — "Dodaj pierwsze zdjęcie" oraz
 * "Na razie tylko pooglądam". Osoba, którą przymusi się do publikacji,
 * publikuje raz i nie wraca.
 *
 * Każdy krok da się pominąć i żaden nie blokuje korzystania z serwisu.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly DailyBoard $board,
        private readonly FollowUser $followUser,
    ) {}

    /**
     * Krok „co lubisz gotować".
     *
     * Lista przyszła z bazy, a nie ze stałej w tym pliku (issue #31).
     * Wcześniej były DWIE listy: dwanaście pozycji tutaj i trzydzieści
     * w seederze tematów — ta sama rzecz w dwóch miejscach, więc pytanie
     * z onboardingu nie dawało się połączyć z niczym w serwisie.
     */
    public function interests(): View
    {
        return view('pages.onboarding.interests', [
            'topics' => Topic::doWyboru()->get(),
        ]);
    }

    public function saveInterests(Request $request): RedirectResponse
    {
        $dane = $request->validate([
            'topics' => ['nullable', 'array'],
            'topics.*' => ['string', 'exists:topics,id'],
        ]);

        // TO JEST CAŁY SENS TEJ ZMIANY (issue #31).
        //
        // Wcześniej odpowiedź szła DO SESJI i ginęła na końcu onboardingu.
        // Marnowaliśmy najcenniejsze dane, jakie mamy przy cold starcie —
        // padają w jedynym momencie, w którym człowiek chętnie odpowiada
        // na pytania o siebie, i decydują o tym, czy jego pierwszy feed
        // będzie pusty.
        $wybrane = array_values(array_intersect(
            $dane['topics'] ?? [],
            Topic::doWyboru()->pluck('id')->all(),
        ));

        if ($wybrane !== []) {
            $request->user()->followedTopics()->syncWithoutDetaching(
                array_fill_keys($wybrane, ['created_at' => now()]),
            );
        }

        return redirect()->route('onboarding.people');
    }

    public function people(Request $request): View
    {
        return view('pages.onboarding.people', [
            'people' => $this->board->peopleToFollow($request->user(), 8),
        ]);
    }

    public function saveFollows(Request $request): RedirectResponse
    {
        $request->validate([
            'follow' => ['nullable', 'array'],
            'follow.*' => ['string'],
        ]);

        $user = $request->user();

        foreach ($request->input('follow', []) as $username) {
            // Bez rozróżniania wielkości liter, tak samo jak profil
            // i listy obserwujących — patrz `Profile::poNazwie()`.
            $target = Profile::poNazwie($username)?->user;

            if ($target === null) {
                continue;
            }

            try {
                $this->followUser->handle($user, $target);
            } catch (BladDlaCzlowieka) {
                // Pojedyncza nieudana próba (np. konto w międzyczasie
                // zablokowane) nie może przerwać całego onboardingu.
                //
                // Znacznik, a nie `RuntimeException`: ten drugi połykał tu
                // również `QueryException` (dziedziczy po nim przez
                // `PDOException`), więc awaria bazy udawała „konto
                // niedostępne" i onboarding kończył się bez ani jednego
                // obserwowania, nie mówiąc o tym nikomu.
                continue;
            }
        }

        return redirect()->route('onboarding.done');
    }

    public function done(Request $request): View
    {
        return view('pages.onboarding.done', [
            'name' => $request->user()->displayName(),
        ]);
    }
}
