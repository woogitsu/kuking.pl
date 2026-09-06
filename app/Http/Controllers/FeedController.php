<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DailyBoard;
use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use App\Domain\Feed\TopicFeed;
use App\Domain\Wspomnienia\Wspomnienia;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedController extends Controller
{
    public function __construct(
        private readonly FollowingFeed $followingFeed,
        private readonly DiscoverFeed $discoverFeed,
        private readonly TopicFeed $topicFeed,
        private readonly DailyBoard $dailyBoard,
        private readonly Wspomnienia $wspomnienia,
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
            'board' => $this->dailyBoard->forViewer(null),
        ]);
    }

    /**
     * /home — feed obserwowanych, a gdy go nie ma, feed tematów.
     *
     * TRZY STOPNIE, NIE DWA (issue #31)
     * Do tej pory były dwa: albo wpisy obserwowanych, albo „Świeżo z Kuking"
     * — czyli wszystko jak leci, identyczne dla każdego. Nowe konto dostawało
     * więc ekran, który nie należał do niego.
     *
     * Między nie wchodzi feed TEMATÓW wybranych w onboardingu. To jedyna
     * rzecz, którą o kimś wiemy w pierwszej minucie, i pierwszy ekran, który
     * jest jego, a nie serwisu. Dopiero gdy i to jest puste — bo ktoś pominął
     * onboarding albo w jego tematach nikt jeszcze nic nie ugotował —
     * pokazujemy „Świeżo z Kuking".
     *
     * Kolejność jest ważna w drugą stronę też: człowiek, który KOGOŚ
     * obserwuje, dostaje wpisy tych osób, nawet jeśli obserwuje też tematy.
     * Ludzie są ważniejsi od kategorii — to jest serwis o ludziach,
     * którzy gotują (AGENTS.md).
     */
    public function home(Request $request): View
    {
        $user = $request->user();

        $zrodlo = match (true) {
            ! $this->followingFeed->isEmptyFor($user) => 'obserwowani',
            $this->topicFeed->maTresci($user) => 'tematy',
            default => 'odkrywanie',
        };

        // Wspomnienie (issue #34) — jeden własny wpis z tego samego dnia
        // sprzed roku albo więcej. `null`, gdy nie ma czego pokazać albo gdy
        // człowiek wyłączył tę mechanikę; widok NIE ma pustego stanu, bo
        // „nie masz jeszcze wspomnień" jest wyrzutem wobec kogoś, kto dopiero
        // zaczyna.
        $wspomnienie = $this->wspomnienia->dlaOsoby($user);

        // Trzy ostatnio odłożone przepisy do prawej szyny (UI kit v2, ekran 01).
        //
        // `widoczneDla($user)`, NIE samo `published()` (audyt W3-12).
        //
        // Stał tu komentarz: „zapytanie idzie przez zeszyty TEGO CZŁOWIEKA,
        // więc nie ma tu pytania o widoczność cudzych treści". To był błąd
        // w rozumowaniu. Do zeszytu odkłada się CUDZE przepisy, a ich autor
        // może po zapisaniu zmienić widoczność na prywatną, cofnąć obserwowanie
        // albo zablokować osobę, która przepis odłożyła. Ekran zeszytu
        // (`CollectionController::show()`) sprawdza to poprawnie — ta szyna nie
        // sprawdzała, więc pokazywała tytuł, nazwisko autora i miniaturę
        // przepisu, którego ta osoba nie ma już prawa zobaczyć.
        //
        // Ta sama treść nie może być bardziej widoczna przez inne miejsce
        // w interfejsie. Jedna granica, jeden scope, wszędzie.
        $zeszyt = Recipe::query()
            ->widoczneDla($user)
            ->whereHas('collections', fn ($q) => $q->where('collections.owner_id', $user->getKey()))
            ->with(['heroMedia', 'author.profile'])
            ->latest('recipes.published_at')
            ->limit(3)
            ->get();

        return view('pages.home', [
            'greeting' => $this->greeting($user->displayName()),
            'zeszyt' => $zeszyt,
            'wspomnienie' => $wspomnienie,
            'podpisWspomnienia' => $wspomnienie === null ? null : $this->wspomnienia->podpis($wspomnienie),
            'board' => $this->dailyBoard->forViewer($user),
            'posts' => match ($zrodlo) {
                'obserwowani' => $this->followingFeed->paginate($user),
                'tematy' => $this->topicFeed->paginate($user),
                default => $this->discoverFeed->paginate($user),
            },
            'zrodloFeedu' => $zrodlo,
            'showingDiscover' => $zrodlo === 'odkrywanie',
            'obserwowaneTematy' => $zrodlo === 'tematy'
                ? $user->followedTopics()->get()
                : collect(),
        ]);
    }

    /** /discover — "Świeżo z Kuking", dostępne też bez konta. */
    public function discover(Request $request): View
    {
        return view('pages.discover', [
            'posts' => $this->discoverFeed->paginate($request->user()),
            'board' => $this->dailyBoard->forViewer($request->user()),
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
            $hour < 21 => "Dobry wieczór, {$name}. Co dziś wyszło?",
            default => "Dobry wieczór, {$name}. Pokaż, co dziś wyszło.",
        };
    }
}
