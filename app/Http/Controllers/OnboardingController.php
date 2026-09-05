<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DailyBoard;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Profile;
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
    /**
     * Zamknięta lista zainteresowań. Świadomie krótka i konkretna —
     * to polska kuchnia domowa, nie taksonomia gastronomiczna.
     */
    public const INTERESTS = [
        'obiady' => 'Obiady na co dzień',
        'ciasta' => 'Ciasta i wypieki',
        'zupy' => 'Zupy',
        'przetwory' => 'Przetwory i weki',
        'chleb' => 'Chleb i zakwas',
        'kiszonki' => 'Kiszonki',
        'swieta' => 'Święta i uroczystości',
        'grill' => 'Grill i ognisko',
        'bezmiesne' => 'Bez mięsa',
        'dla_dzieci' => 'Dla dzieci i wnuków',
        'regionalne' => 'Kuchnia regionalna',
        'szybkie' => 'Szybkie, na jedną patelnię',
    ];

    public function __construct(
        private readonly DailyBoard $board,
        private readonly FollowUser $followUser,
    ) {}

    public function interests(): View
    {
        return view('pages.onboarding.interests', ['interests' => self::INTERESTS]);
    }

    public function saveInterests(Request $request): RedirectResponse
    {
        $request->validate([
            'interests' => ['nullable', 'array'],
            'interests.*' => ['string', 'in:'.implode(',', array_keys(self::INTERESTS))],
        ]);

        // Zainteresowania na tym etapie służą tylko do doboru propozycji
        // i do wiedzy, jakie tematy tygodnia mają sens. Trzymamy je
        // w sesji do końca onboardingu; osobna tabela to zadanie z backlogu.
        $request->session()->put('onboarding.interests', $request->input('interests', []));

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
            $target = Profile::where('username', $username)->first()?->user;

            if ($target === null) {
                continue;
            }

            try {
                $this->followUser->handle($user, $target);
            } catch (\RuntimeException) {
                // Pojedyncza nieudana próba (np. konto w międzyczasie
                // zablokowane) nie może przerwać całego onboardingu.
                continue;
            }
        }

        return redirect()->route('onboarding.done');
    }

    public function done(Request $request): View
    {
        $request->session()->forget('onboarding.interests');

        return view('pages.onboarding.done', [
            'name' => $request->user()->displayName(),
        ]);
    }
}
