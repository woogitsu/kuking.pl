<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedController extends Controller
{
    public function __construct(
        private readonly FollowingFeed $followingFeed,
        private readonly DiscoverFeed $discoverFeed,
    ) {}

    /**
     * Strona główna dla niezalogowanych. Nie jest to marketingowy landing
     * z animacjami — to od razu prawdziwe zdjęcia prawdziwych ludzi, bo to
     * jest jedyny wiarygodny argument, żeby tu zostać.
     */
    public function landing(Request $request): View
    {
        if ($request->user() !== null) {
            return $this->home($request);
        }

        return view('pages.landing', [
            'posts' => $this->discoverFeed->paginate(null, 9),
        ]);
    }

    /**
     * /home — feed obserwowanych.
     *
     * Gdy użytkownik nikogo nie obserwuje, feed jest z definicji pusty.
     * Zamiast pokazywać pustkę, pokazujemy "Świeżo z Kuking" i propozycje
     * ludzi. Bez tego cold start się nie udaje.
     */
    public function home(Request $request): View
    {
        $user = $request->user();
        $feedIsEmpty = $this->followingFeed->isEmptyFor($user);

        return view('pages.home', [
            'greeting' => $this->greeting($user->displayName()),
            'posts' => $feedIsEmpty
                ? $this->discoverFeed->paginate($user)
                : $this->followingFeed->paginate($user),
            'showingDiscover' => $feedIsEmpty,
            'suggestedPeople' => $feedIsEmpty ? $this->discoverFeed->suggestedPeople($user) : collect(),
        ]);
    }

    /** /discover — "Świeżo z Kuking", dostępne też bez konta. */
    public function discover(Request $request): View
    {
        return view('pages.discover', [
            'posts' => $this->discoverFeed->paginate($request->user()),
            'suggestedPeople' => $this->discoverFeed->suggestedPeople($request->user()),
        ]);
    }

    /**
     * Powitanie zależne od pory dnia. Drobiazg, ale to jest pierwsza rzecz,
     * którą użytkownik czyta, i decyduje o tym, czy produkt sprawia
     * wrażenie żywego miejsca, czy panelu administracyjnego.
     */
    private function greeting(string $name): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 10 => "Dzień dobry, {$name}. Co dziś gotujesz?",
            $hour < 15 => "Dzień dobry, {$name}. Co dziś na obiad?",
            $hour < 21 => "Dobry wieczór, {$name}. Co dziś ugotowałeś?",
            default => "Dobry wieczór, {$name}. Pokaż, co dziś wyszło.",
        };
    }
}
