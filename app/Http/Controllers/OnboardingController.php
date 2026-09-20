<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DailyBoard;
use App\Domain\Search\SearchQuery;
use App\Domain\Social\Actions\FollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Profile;
use App\Models\Tag;
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
     * Ile trafień wyszukiwarki pokazujemy najwyżej na tym kroku.
     *
     * Celowo dużo mniej niż na `/szukaj` (tam 20). Ten krok ma pomóc
     * odnaleźć JEDNĄ konkretną, znaną osobę — nie przeglądać listę.
     * Przy popularnym imieniu wolimy powiedzieć „wpisz dokładniej", niż
     * dołożyć stronicowanie, które zamieniłoby to w katalog ludzi.
     */
    private const WYNIKI_WYSZUKIWANIA = 5;

    public function __construct(
        private readonly DailyBoard $board,
        private readonly FollowUser $followUser,
        private readonly SearchQuery $search,
    ) {}

    /**
     * Krok „co lubisz gotować" (D-021 — czyta teraz `Tag`, nie `Topic`).
     *
     * Lista to `Tag::scopePromowane()` — tagi z listy gospodarza (D-021,
     * „tag promowany"), NIE „najpopularniejsze tagi". Przy zerowym ruchu
     * produkcyjnym popularność nie istnieje, więc lista po popularności
     * dałaby nowemu kontu pusty albo losowy ekran — dokładnie problem,
     * który ten krok ma rozwiązywać. Widok radzi sobie z pustą listą
     * (gospodarz jeszcze niczego nie promował) pokazując zachętę do
     * pominięcia kroku zamiast pustej siatki checkboxów.
     */
    public function interests(): View
    {
        return view('pages.onboarding.interests', [
            'tags' => Tag::promowane()->get(),
        ]);
    }

    public function saveInterests(Request $request): RedirectResponse
    {
        // `max:` NA SAMEJ TABLICY, NIE TYLKO NA JEJ ELEMENTACH.
        //
        // Bez tego jedno żądanie mogło podać dowolnie długą listę, a reguła
        // `exists:tags,id` wykonuje OSOBNE zapytanie dla KAŻDEGO elementu —
        // dziesięć tysięcy pozycji w formularzu to dziesięć tysięcy zapytań,
        // zanim kontroler cokolwiek zdecyduje. Limit żądań na trasie tego nie
        // łapie, bo to jedno żądanie.
        //
        // Pięćdziesiąt, a nie dokładnie tyle, ile pokazuje ekran: lista
        // promowanych tagów jest w rękach gospodarza i ma prawo urosnąć,
        // a próg ma odcinać nadużycie, nie normalny wybór.
        $dane = $request->validate([
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'exists:tags,id'],
        ]);

        // TO JEST CAŁY SENS TEJ ZMIANY (issue #31, kontynuowane przez D-021).
        //
        // Odpowiedź idzie DO BAZY, nie do sesji, gdzie ginęłaby po
        // zakończeniu kroku. Marnowalibyśmy najcenniejsze dane, jakie mamy
        // przy cold starcie — padają w jedynym momencie, w którym człowiek
        // chętnie odpowiada na pytania o siebie, i decydują o tym, czy jego
        // pierwszy feed będzie pusty.
        $wybrane = array_values(array_intersect(
            $dane['tags'] ?? [],
            Tag::promowane()->pluck('id')->all(),
        ));

        if ($wybrane !== []) {
            $request->user()->followedTags()->syncWithoutDetaching(
                array_fill_keys($wybrane, ['created_at' => now()]),
            );
        }

        return redirect()->route('onboarding.people');
    }

    /**
     * Krok „kogo obserwować" — i, od tego zadania, „znasz już kogoś tutaj?"
     * (`docs/research/MIGRACJA_Z_GARNKA.md` §3.1).
     *
     * Wyszukiwanie idzie DOKŁADNIE przez `SearchQuery::people()` — tę samą
     * klasę, której używa `SearchController` na `/szukaj`. Żadnej drugiej
     * wyszukiwarki, żadnych nowych reguł widoczności: kto jest zbanowany,
     * zawieszony, w trakcie usuwania konta albo zablokował/został
     * zablokowany przez tego widza, ten tam już dziś nie wychodzi —
     * `SearchQuery` filtruje to samo dla każdego wywołania.
     *
     * `q` jest parametrem GET, nie POST: to zwykłe wyszukiwanie w treści
     * strony, więc działa jako link do zapisania i wraca poprawnie po
     * cofnięciu się przeglądarką — zgodnie z AGENTS.md §5 (ważne rzeczy
     * bez JavaScriptu).
     *
     * NIC Z TEGO NIE ZAPISUJEMY. W przeciwieństwie do `/szukaj`, ten krok
     * świadomie NIE woła `ZapiszSygnal` — nie ma dziś decyzji produktowej,
     * że warto mierzyć to osobno, a `docs/research/MIGRACJA_Z_GARNKA.md`
     * §3.1 wprost preferuje rozwiązanie bez nowego zapisu.
     */
    public function people(Request $request): View
    {
        $phrase = trim((string) $request->query('q', ''));
        $searchErrors = SearchQuery::phraseValidator($phrase, 'Imię lub nazwa użytkownika')->errors();

        // Ten sam próg co `SearchController` — MUSI się zgadzać z tym,
        // co i tak robi `SearchQuery::people()` (poniżej dwóch znaków
        // w ogóle nie odpytuje bazy), inaczej ekran pokazałby „nic nie
        // znaleźliśmy" tam, gdzie baza w ogóle nie została zapytana.
        $zaKrotka = $phrase !== '' && mb_strlen(SearchQuery::peoplePhrase($phrase)) < 2;

        $wynikiWyszukiwania = null;

        if ($phrase !== '' && ! $zaKrotka && $searchErrors->isEmpty()) {
            $user = $request->user();

            $wynikiWyszukiwania = $this->search
                ->people($phrase, $user, self::WYNIKI_WYSZUKIWANIA + 1)
                // Szukającego samego siebie nie ma sensu proponować mu
                // do zaobserwowania — `FollowUser` i tak by to odrzucił,
                // ale checkbox przy własnym koncie byłby mylący.
                ->reject(fn (Profile $profil) => $profil->user_id === $user->getKey())
                ->values();
        }

        return view('pages.onboarding.people', [
            'people' => $this->board->peopleToFollow($request->user(), 8),
            'phrase' => $phrase,
            'searchErrors' => $searchErrors,
            'zaKrotka' => $zaKrotka,
            'wynikiWyszukiwania' => $wynikiWyszukiwania?->take(self::WYNIKI_WYSZUKIWANIA),
            'jestWiecejWynikow' => ($wynikiWyszukiwania?->count() ?? 0) > self::WYNIKI_WYSZUKIWANIA,
        ]);
    }

    public function saveFollows(Request $request): RedirectResponse
    {
        // `max:` NA TABLICY — TU WAŻNIEJSZE NIŻ GDZIEKOLWIEK INDZIEJ.
        //
        // To jedyne miejsce w serwisie, w którym POJEDYNCZE żądanie tworzy
        // powiadomienia u WIELU osób naraz: pętla niżej woła `FollowUser`
        // dla każdej pozycji listy. Bez tej reguły limit zapytań na trasie
        // (`masowe_obserwowanie` w config/kuking.php) był ochroną tylko
        // z nazwy — pięć żądań po tysiąc nazw to pięć tysięcy powiadomień.
        //
        // Ekran proponuje osiem osób (`people()` niżej). Dwadzieścia daje
        // zapas na zmianę tej liczby i nadal odcina nadużycie.
        $request->validate([
            'follow' => ['nullable', 'array', 'max:20'],
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
