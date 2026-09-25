<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DailyBoard;
use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use App\Domain\Feed\HeroKolaz;
use App\Domain\Feed\TagFeed;
use App\Domain\Pwa\InstallPrompt;
use App\Domain\Pwa\InstallPromptContext;
use App\Domain\Wspomnienia\Wspomnienia;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedController extends Controller
{
    /**
     * Ile osób i ile dań z tablicy „kuKINGi na dziś" widzi GOŚĆ na stronie
     * powitalnej. Decyzja właściciela (audyt 60+, `docs/research/AUDYT_60_PLUS.md`):
     * ta tablica jest na landingu najgęstszą, najbardziej interaktywną
     * częścią ekranu i ma pokazywać mniej niż gdzie indziej.
     *
     * DLACZEGO OBCINAMY TUTAJ, A NIE W `DailyBoard`
     * Sufit `DailyBoard::PEOPLE`/`POSTS` dotyczy WSZYSTKICH trzech ekranów,
     * które z tej klasy korzystają: `/home`, `/odkryj` i `/szukaj`. Samej
     * liczby świadomie tu nie przepisuję — stała już raz w tym komentarzu
     * i zestarzała się w dniu, w którym sufit poszedł z czterech na sześć.
     * Zmiana limitu tam zmieniłaby też to, co widzi zalogowany
     * — a decyzja właściciela dotyczy wyłącznie gościa. Do tego wybór
     * redakcyjny (`Admin\DailyBoardController::update()`) nie ma górnej
     * sumy: gospodarz może wskazać do 6 osób I do 6 dań na raz, czyli do
     * 12 kart, gdyby nic tego nie ograniczało. Obcięcie DOPIERO TUTAJ,
     * po `DailyBoard::forViewer()`, działa jednakowo dla obu ścieżek
     * (automatycznej i redakcyjnej) i nie rusza wspólnej klasy.
     */
    public const GUEST_BOARD_PEOPLE = 3;

    public const GUEST_BOARD_POSTS = 3;

    public function __construct(
        private readonly FollowingFeed $followingFeed,
        private readonly DiscoverFeed $discoverFeed,
        private readonly TagFeed $tagFeed,
        private readonly DailyBoard $dailyBoard,
        private readonly Wspomnienia $wspomnienia,
        private readonly HeroKolaz $heroKolaz,
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

        $board = $this->dailyBoard->forViewer(null);
        $board['people'] = $board['people']->take(self::GUEST_BOARD_PEOPLE)->values();
        $board['posts'] = $board['posts']->take(self::GUEST_BOARD_POSTS)->values();

        return view('pages.landing', [
            'posts' => $this->discoverFeed->paginate(null, 9),
            'board' => $board,

            // Kolaż w hero (zgłoszenie właściciela: „na samej górze po prawej
            // stronie … najładniejsze albo wybrane przez admina zdjęcia").
            //
            // TYLKO TUTAJ, TYLKO DLA GOŚCIA. `home()` niżej tego nie dostaje
            // i nie ma dostać: po zalogowaniu górę ekranu zajmuje feed, a
            // zachęta do rejestracji nie ma już kogo zachęcać.
            //
            // Pusta kolekcja jest normalnym wynikiem, nie awarią — znaczy
            // „serwis nie ma jeszcze czterech publicznych zdjęć" i widok
            // pomija wtedy kolaż w całości (patrz `HeroKolaz`).
            'kolaz' => $this->heroKolaz->doKolazu(),
        ]);
    }

    /**
     * /home — feed obserwowanych, a gdy go nie ma, feed tagów (D-021,
     * zastępuje usunięty już feed tematów z issue #31).
     *
     * TRZY STOPNIE
     * Do niedawna były dwa: albo wpisy obserwowanych, albo „Świeżo z Kuking"
     * — czyli wszystko jak leci, identyczne dla każdego. Nowe konto dostawało
     * więc ekran, który nie należał do niego.
     *
     * Między nie wchodzi feed TAGÓW wybranych w onboardingu
     * (`Tag::promowane()` — lista gospodarza, D-021). To jedyna rzecz,
     * którą o kimś wiemy w pierwszej minucie, i pierwszy ekran, który jest
     * jego, a nie serwisu. Dopiero gdy i to jest puste — bo ktoś pominął
     * onboarding albo w jego tagach nikt jeszcze nic nie ugotował —
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

        [$zrodlo, $posts] = $this->pierwszaStronaZrodla($user, $zrodlo, $request->query->has('cursor'));

        return view('pages.home', [
            'pwaEligible' => $user->pwa_prompt_state === InstallPrompt::ELIGIBLE,
            'pwaContext' => $user->pwa_prompt_state === InstallPrompt::ELIGIBLE
                ? app(InstallPromptContext::class)->issue($user, $request->session()->getId())
                : null,
            'greeting' => $this->pytanieDnia($user),
            'zeszyt' => $zeszyt,
            'wspomnienie' => $wspomnienie,
            'podpisWspomnienia' => $wspomnienie === null ? null : $this->wspomnienia->podpis($wspomnienie),
            'board' => $this->dailyBoard->forViewer($user),
            'posts' => $posts,
            'zrodloFeedu' => $zrodlo,
            'showingDiscover' => $zrodlo === 'odkrywanie',
        ]);
    }

    /**
     * Strona z wybranego źródła — a gdy to źródło okazało się puste, z
     * następnego w kolejności obserwowani → tagi → odkrywanie (issue #983).
     *
     * Wybór źródła (`isEmptyFor()` / `maTresci()`) i paginacja to osobne
     * zapytania, a przy Read Committed każde widzi inny zatwierdzony stan.
     * Cofnięcie obserwowania, blokada albo ukrycie ostatniego wpisu między
     * nimi zostawiało pusty Start, choć następne źródło miało treść. Dlatego
     * o źródle rozstrzyga dopiero to, co paginacja faktycznie oddała —
     * i `zrodloFeedu` opisuje źródło zwróconych wpisów, nie wcześniejszą
     * prognozę.
     *
     * Feed obserwowanych zawiera też własne wpisy, a `isEmptyFor()` celowo
     * ich nie liczy. Strona złożona z samych własnych wpisów zostaje więc
     * tylko wtedy, gdy obserwowani nadal mają treść — inaczej własny wpis
     * zacząłby sam blokować przejście dalej.
     *
     * Z kursorem nic nie przeskakujemy: kursor należy do źródła, a pusta
     * dalsza strona to zwyczajny koniec listy, nie wyścig.
     *
     * @return array{0: string, 1: CursorPaginator<int, Post>}
     */
    private function pierwszaStronaZrodla(User $user, string $zrodlo, bool $zKursorem): array
    {
        if ($zrodlo === 'obserwowani') {
            $posts = $this->followingFeed->paginate($user);

            if ($zKursorem
                || $posts->getCollection()->contains(fn (Post $post) => $post->author_id !== $user->getKey())
                || ($posts->isNotEmpty() && ! $this->followingFeed->isEmptyFor($user))) {
                return ['obserwowani', $posts];
            }

            $zrodlo = 'tagi';
        }

        if ($zrodlo === 'tagi') {
            $posts = $this->tagFeed->paginate($user);

            if ($zKursorem || $posts->isNotEmpty()) {
                return ['tagi', $posts];
            }
        }

        return ['odkrywanie', $this->discoverFeed->paginate($user)];
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
     * Staly zwrot grzecznosciowy, bez wnioskowania o porze dnia lub rodzaju.
     * Nazwa profilu pozostaje doslowna; brak nazwy nie dostaje zastepnika.
     * Pytanie o gotowanie nalezy do kafla publikacji, nie do powitania.
     */
    private function pytanieDnia(User $user): string
    {
        $imie = trim((string) $user->profile?->display_name);

        return $imie === '' ? 'Dzień dobry' : "Dzień dobry, {$imie}";
    }
}
