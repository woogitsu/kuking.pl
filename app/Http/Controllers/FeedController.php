<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DailyBoard;
use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use App\Domain\Feed\TagFeed;
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
        private readonly TagFeed $tagFeed,
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
     * /home — feed obserwowanych, a gdy go nie ma, feed tagów (D-021).
     *
     * CZTERY STOPNIE, NIE TRZY (issue #31, rozszerzone przez D-021)
     * Zaczęło się od dwóch: albo wpisy obserwowanych, albo „Świeżo z Kuking"
     * — czyli wszystko jak leci, identyczne dla każdego. Nowe konto dostawało
     * więc ekran, który nie należał do niego.
     *
     * Między nie wszedł feed TEMATÓW wybranych w onboardingu, a teraz —
     * feed TAGÓW: onboarding od D-021 zapisuje wybór do `tag_follows`, nie
     * do `topic_follows`, więc to TAGI są tym, co o kimś wiemy w pierwszej
     * minucie. `TopicFeed` zostaje na miejscu (Tematy znikają dopiero
     * w kolejnym etapie D-021) jako TRZECI stopień — obsługuje konta, które
     * obserwowały tematy, zanim ten etap wszedł, oraz istniejące testy
     * tamtej ścieżki. Dopiero gdy WSZYSTKIE trzy są puste — bo ktoś pominął
     * onboarding albo nigdzie, co obserwuje, nikt jeszcze nic nie ugotował —
     * pokazujemy „Świeżo z Kuking".
     *
     * Kolejność jest ważna w drugą stronę też: człowiek, który KOGOŚ
     * obserwuje, dostaje wpisy tych osób, nawet jeśli obserwuje też tagi.
     * Ludzie są ważniejsi od kategorii — to jest serwis o ludziach,
     * którzy gotują (AGENTS.md).
     */
    public function home(Request $request): View
    {
        $user = $request->user();

        $zrodlo = match (true) {
            ! $this->followingFeed->isEmptyFor($user) => 'obserwowani',
            $this->tagFeed->maTresci($user) => 'tagi',
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
        //
        // I DOKŁADNIE TO ZDANIE BYŁO NIEPRAWDĄ. Stało tu samo
        // `widoczneDla($user)`, a to jest tylko JEDNA z dwóch granic:
        // liczy blokady i ustawienie widoczności, ale NIE liczy statusu konta
        // autora — tym zajmuje się `User::scopeDostepnyJakoAutor()`
        // (ustalenie audytowe W5-08: dwie różne reguły, obie obowiązkowe).
        // `CollectionController::show()` ma obie od tamtego audytu; ta szyna
        // miała jedną, więc przepis autora zbanowanego albo oznaczonego do
        // usunięcia znikał z ekranu zeszytu i JEDNOCZEŚNIE stał na stronie
        // głównej — z tytułem, nazwiskiem autora i miniaturą.
        //
        // Zmierzone, nie założone: `SzynaZeszytuUkrywaZbanowanegoAutoraTest`
        // oblewał się na wszystkich trzech przypadkach przed tą linijką.
        $zeszyt = Recipe::query()
            ->widoczneDla($user)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
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
                'tagi' => $this->tagFeed->paginate($user),
                'tematy' => $this->topicFeed->paginate($user),
                default => $this->discoverFeed->paginate($user),
            },
            'zrodloFeedu' => $zrodlo,
            'showingDiscover' => $zrodlo === 'odkrywanie',
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
