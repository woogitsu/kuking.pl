<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DailyBoard;
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
    public function __construct(
        private readonly DailyBoard $board,
        private readonly FollowUser $followUser,
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

    public function people(Request $request): View
    {
        return view('pages.onboarding.people', [
            'people' => $this->board->peopleToFollow($request->user(), 8),
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
