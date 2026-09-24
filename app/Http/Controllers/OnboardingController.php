<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\DailyBoard;
use App\Domain\Search\SearchQuery;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Tags\Actions\UpdateTagFollows;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Requests\TagSelection;
use App\Models\Profile;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $selected = app(TagSelection::class)->validate($request, 50);
        app(UpdateTagFollows::class)->follow($request->user(), $selected, promotedOnly: true);

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
     * NIE ZAPISUJEMY SYGNAŁU ANALITYCZNEGO. W przeciwieństwie do `/szukaj`, ten krok
     * świadomie NIE woła `ZapiszSygnal` — nie ma dziś decyzji produktowej,
     * że warto mierzyć to osobno, a `docs/research/MIGRACJA_Z_GARNKA.md`
     * §3.1 wprost preferuje rozwiązanie bez nowego zapisu.
     */
    public function people(Request $request): View
    {
        // Ten sam kontrakt co na `/szukaj` (issue #738): parametr GET może
        // być tablicą (`q[]=...`). Nie wolno rzutować go na tekst, bo PHP
        // zgłasza wtedy „Array to string conversion”, a ekran kończy na 500.
        // Nietekstowe `q` znaczy dokładnie to samo co brak frazy.
        $qSurowe = $request->query('q', '');
        $phrase = $request->boolean('clear') ? '' : trim(is_string($qSurowe) ? $qSurowe : '');
        $searchErrors = SearchQuery::phraseValidator($phrase, 'Imię lub nazwa użytkownika')->errors();
        $context = $request->session()->get('onboarding.selection');
        $contextValid = is_array($context)
            && ($context['user'] ?? null) === $request->user()->getKey()
            && ($context['expires'] ?? 0) > now()->getTimestamp();
        $selectionValid = $contextValid && old('selection', $request->input('selection')) === $context['token'];
        if (! $contextValid) {
            $context = ['user' => $request->user()->getKey(), 'token' => (string) Str::uuid(), 'expires' => now()->addMinutes(30)->getTimestamp()];
            $request->session()->put('onboarding.selection', $context);
        }
        $input = old('follow', $request->input('follow', []));
        $selected = $selectionValid && is_array($input)
            ? array_values(array_unique(array_filter($input, fn ($name) => is_string($name) && strlen($name) <= 40)))
            : [];
        // Nadmiar pozostaje widoczny po błędzie POST, aby można go odznaczyć.
        // GET również ma granicę kosztu, niezależną od walidacji zapisu.
        $selected = array_slice($selected, 0, 50);

        // Ten sam próg co `SearchController` — MUSI się zgadzać z tym,
        // co i tak robi `SearchQuery::people()` (poniżej dwóch znaków
        // w ogóle nie odpytuje bazy), inaczej ekran pokazałby „nic nie
        // znaleźliśmy" tam, gdzie baza w ogóle nie została zapytana.
        $zaKrotka = $phrase !== '' && mb_strlen(SearchQuery::peoplePhrase($phrase)) < 2;

        $wynikiWyszukiwania = null;

        if ($phrase !== '' && ! $zaKrotka && $searchErrors->isEmpty()) {
            $user = $request->user();

            $wynikiWyszukiwania = $this->search
                // Szukającego samego siebie nie ma sensu proponować mu
                // do zaobserwowania — `FollowUser` i tak by to odrzucił,
                // ale checkbox przy własnym koncie byłby mylący. Wykluczenie
                // idzie W ZAPYTANIU (`bezWidza`), nie przez `reject()` po
                // `LIMIT`: własny profil zajmował wtedy jedno z sześciu miejsc,
                // ekran gubił poprawną osobę i kłamał, że więcej nie ma (#945).
                ->people($phrase, $user, self::WYNIKI_WYSZUKIWANIA + 1, bezWidza: true);
        }

        $results = $wynikiWyszukiwania?->take(self::WYNIKI_WYSZUKIWANIA);
        $people = $this->board->peopleToFollow($request->user(), 8)
            ->reject(fn ($person) => $results?->contains('user_id', $person->getKey()));
        $visibleNames = $people->pluck('profile.username')->merge($results?->pluck('username') ?? []);
        $selectedProfiles = Profile::query()->whereIn('username', $selected)->with('user')->get()
            ->filter(fn (Profile $profile) => $profile->user !== null && $request->user()->can('follow', $profile->user));
        $selected = $selectedProfiles->pluck('username')->all();

        return view('pages.onboarding.people', [
            'people' => $people,
            'selectedFollows' => $selected,
            'selectionContext' => $context['token'],
            'selectedProfiles' => $selectedProfiles->reject(fn ($profile) => $visibleNames->contains($profile->username)),
            'selectionExpired' => ! $selectionValid && $request->has('selection'),
            'phrase' => $phrase,
            'searchErrors' => $searchErrors,
            'zaKrotka' => $zaKrotka,
            'wynikiWyszukiwania' => $results,
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
        $data = $request->validate([
            'follow' => ['nullable', 'array', 'max:20'],
            'follow.*' => ['string'],
            // `oczekiwani[nazwa] => id` — patrz niżej. Ten sam sufit co na
            // `follow`: to lista sparowana z tamtą, nie osobne wejście.
            'oczekiwani' => ['nullable', 'array', 'max:20'],
            'oczekiwani.*' => ['string'],
        ], [
            'follow.max' => 'Zaznacz najwyżej :max osób. Odznacz pozostałe i kliknij „Dalej”.',
        ]);

        $user = $request->user();
        // Bez powtórzeń, bez rozróżniania wielkości liter (`Profile::poNazwie()`
        // też jej nie rozróżnia), ale z zachowaniem pisowni widzianej
        // na ekranie — komunikat niżej cytuje nazwę człowiekowi.
        $selected = [];

        foreach ($data['follow'] ?? [] as $nazwa) {
            $selected[mb_strtolower((string) $nazwa)] ??= (string) $nazwa;
        }

        $completed = 0;
        $skipped = 0;

        // NAZWA UŻYTKOWNIKA W FORMULARZU TO NIE AUTORYZACJA (#793).
        //
        // Ten krok wskazuje osoby NAZWAMI (`follow[]`), a nazwę da się
        // zwolnić zmianą w Ustawieniach i od razu ponownie zająć —
        // `UsernameNotTaken` sprawdza tylko aktualne zajęcie, nie historię.
        // Ekran onboardingu potrafi stać otwarty bardzo długo (to jest krok,
        // który ludzie przerywają i wracają do niego), więc okno między
        // wyrenderowaniem listy a jej wysłaniem jest tu SZERSZE niż
        // gdziekolwiek indziej. A jedno żądanie zakłada relacje z wieloma
        // osobami naraz, więc pomyłka nie jest pojedyncza, tylko seryjna.
        //
        // `SocialController::assertToTaSamaOsoba()` nie da się tu użyć:
        // tamta metoda broni JEDNEJ osoby wskazanej adresem trasy, a tu
        // wskazań jest wiele i żadne nie jest w adresie. Kształt jest za to
        // ten sam — ukryte pole z identyfikatorem osoby widzianej w chwili
        // renderowania, sparowane z nazwą, i OPCJONALNE (starsze wywołania
        // i istniejące testy go nie wysyłają).
        //
        // Klucze po `mb_strtolower`, bo `Profile::poNazwie()` nie rozróżnia
        // wielkości liter — inaczej para rozjeżdżałaby się na samym zapisie
        // nazwy i ochrona po cichu przestawałaby działać.
        $oczekiwani = [];

        foreach ($request->input('oczekiwani', []) as $nazwa => $id) {
            $oczekiwani[mb_strtolower((string) $nazwa)] = (string) $id;
        }

        // Nazwy, które między wyrenderowaniem a wysłaniem zmieniły
        // właściciela. Człowiek MUSI o nich usłyszeć: cicho pominięte
        // zaznaczenie wygląda dokładnie jak zaznaczenie, którego nie było.
        $zmieniloWlasciciela = [];

        foreach ($selected as $username) {
            // Bez rozróżniania wielkości liter, tak samo jak profil
            // i listy obserwujących — patrz `Profile::poNazwie()`.
            $target = Profile::poNazwie($username)?->user;

            if ($target === null) {
                $skipped++;

                continue;
            }

            $oczekiwanyId = $oczekiwani[mb_strtolower((string) $username)] ?? null;

            if ($oczekiwanyId !== null && (string) $target->getKey() !== $oczekiwanyId) {
                $zmieniloWlasciciela[] = (string) $username;

                continue;
            }

            try {
                $this->followUser->handle($user, $target);
                // Już istniejąca relacja także spełnia wybór człowieka.
                $completed++;
            } catch (BladDlaCzlowieka) {
                // Pojedyncza nieudana próba (np. konto w międzyczasie
                // zablokowane) nie może przerwać całego onboardingu.
                //
                // Znacznik, a nie `RuntimeException`: ten drugi połykał tu
                // również `QueryException` (dziedziczy po nim przez
                // `PDOException`), więc awaria bazy udawała „konto
                // niedostępne" i onboarding kończył się bez ani jednego
                // obserwowania, nie mówiąc o tym nikomu.
                $skipped++;

                continue;
            }
        }

        $dalej = redirect()->route('onboarding.done');

        // DWIE RÓŻNE RZECZY MOGŁY PÓJŚĆ NIE TAK NARAZ, więc komunikaty
        // zbieramy, zamiast wybierać jeden. Pominięte konto (`$skipped`)
        // i nazwa, która zmieniła właściciela, to osobne przypadki i każdy
        // ma własne „co zrobić".
        $komunikaty = [];

        if ($skipped > 0) {
            $komunikaty[] = ($completed > 0 ? 'Nie udało się dodać wszystkich wybranych osób. ' : 'Nie udało się dodać wybranych osób. ')
                .'Możesz teraz wejść do serwisu i wybrać inne później.';
        }

        if ($zmieniloWlasciciela !== []) {
            // Komunikat mówi, CO ZROBIĆ, a nie tylko że coś poszło nie tak
            // (docs/UX_50_PLUS.md). Onboarding się NIE cofa i nie gubi reszty
            // zaznaczeń — pozostałe osoby są już zaobserwowane, a ta jedna
            // wymaga świadomego powtórzenia wyboru, bo to już ktoś inny.
            $komunikaty[] = count($zmieniloWlasciciela) === 1
                ? 'Nazwa „'.$zmieniloWlasciciela[0].'” należy teraz do innej osoby, więc jej nie zaobserwowaliśmy. Resztę zaznaczeń zapisaliśmy. Jeśli nadal chcesz obserwować tę osobę, znajdź ją w wyszukiwarce i kliknij „Obserwuj” na jej profilu.'
                : 'Te nazwy należą teraz do innych osób, więc ich nie zaobserwowaliśmy: '.implode(', ', $zmieniloWlasciciela).'. Resztę zaznaczeń zapisaliśmy. Jeśli nadal chcesz obserwować te osoby, znajdź je w wyszukiwarce i kliknij „Obserwuj” na ich profilach.';
        }

        if ($komunikaty === []) {
            return $dalej;
        }

        return $dalej->with('status', implode(' ', $komunikaty));
    }

    public function done(Request $request): View
    {
        $request->session()->forget('onboarding.selection');

        return view('pages.onboarding.done', [
            'name' => $request->user()->displayName(),
        ]);
    }
}
