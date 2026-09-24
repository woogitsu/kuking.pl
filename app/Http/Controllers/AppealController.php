<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\Actions\FileAppeal;
use App\Domain\Security\LimitProbHasla;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Odwołanie od decyzji moderacyjnej — strona człowieka (issue #10).
 *
 * GDZIE SKŁADA SIĘ ODWOŁANIE I DLACZEGO TAK
 *
 * Wybór był realny i obie drogi mają cenę:
 *
 *  * ADRES E-MAIL — nic nie kosztuje i działa dla każdego, ale odwołania nie
 *    ma wtedy w logu, nikt nie wie, ile ich leży ani od kiedy, terminu nie da
 *    się pilnować, a odpowiedź nie trafia do produktu. Przy audycie zostaje
 *    zdanie „odpowiadamy na maile" i nic więcej.
 *  * FORMULARZ PUBLICZNY, bez logowania — dostępny dla zablokowanych, ale
 *    otwarty na oścież: to gotowy cel spamu i darmowy kanał do wpisywania
 *    czegokolwiek prosto w kolejkę jedynego moderatora.
 *
 * WYBRALIŚMY FORMULARZ, ALE ZAMKNIĘTY HASŁEM.
 *
 * Osoba zalogowana (aktywna albo zawieszona) odwołuje się z powiadomienia —
 * jedno kliknięcie, wiadomo od której decyzji. Osoba ZABLOKOWANA nie wejdzie
 * do serwisu, więc ma ten sam formularz przed logowaniem, tyle że podaje
 * login i hasło. To nie loguje jej nigdzie i nie zdejmuje blokady — służy
 * wyłącznie do tego, żeby odwołanie dało się przypisać do konta.
 *
 * CENA TEGO WYBORU, wprost:
 *  1. Kto zapomniał hasła, nie złoży odwołania tą drogą. Zostaje mu
 *     „Nie pamiętam hasła" (działa też dla konta zablokowanego) albo adres
 *     e-mail, który zostaje jako droga zapasowa i jest wypisany na ekranie.
 *  2. Formularz stoi przed logowaniem, więc jest celem — stąd limit
 *     `kuking.limits.appeal` (5 prób na godzinę) i ten sam, celowo
 *     nieinformacyjny komunikat co przy logowaniu, żeby nie dało się nim
 *     sprawdzać, czy konto istnieje.
 *
 * ILE RAZY MOŻNA SIĘ ODWOŁAĆ: RAZ OD JEDNEJ DECYZJI. Uzasadnienie i miejsce,
 * w którym to jest egzekwowane — `FileAppeal` oraz `UNIQUE` w bazie.
 */
class AppealController extends Controller
{
    /**
     * Górna granica sześciu miesięcy z `ModerationAction::appealDeadline()`
     * w dniach: najdłuższe sześć kolejnych miesięcy ma 184 dni, a przepełnienie
     * `addMonths()` (31 sierpnia → 3 marca) dokłada najwyżej 3.
     */
    private const NAJDLUZSZY_TERMIN_W_DNIACH = 187;

    /**
     * Ile decyzji czytamy naraz. Jedna, bo w zwykłym przypadku najnowsza
     * decyzja JEST tą w terminie — i wtedy formularz hydratuje jeden model.
     */
    private const PORCJA_DECYZJI = 1;

    public function __construct(
        private readonly FileAppeal $zloz,
        private readonly LimitProbHasla $limit,
    ) {}

    /**
     * Formularz odwołania od konkretnej decyzji.
     *
     * Strona obsługuje WSZYSTKIE stany jednej sprawy: można się odwołać,
     * odwołanie już złożono, termin minął. Gdyby przycisk w powiadomieniu
     * prowadził tylko do formularza, a resztę załatwiał błąd 403, człowiek
     * dostawałby ścianę zamiast odpowiedzi „co się dzieje z moją sprawą".
     */
    public function show(Request $request, ModerationAction $action): View
    {
        $this->sprawdzWlascicielaSprawy($request->user(), $action);

        return view('pages.appeals.create', [
            'decyzja' => $action,
            'odwolanie' => $action->authorAppeal,
        ]);
    }

    public function store(Request $request, ModerationAction $action): RedirectResponse
    {
        $this->sprawdzWlascicielaSprawy($request->user(), $action);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'body.required' => 'Napisz w kilku zdaniach, dlaczego uważasz decyzję za błędną.',
            'body.min' => 'Napisz trochę więcej — kilka zdań wystarczy, ale jedno słowo nam nie pomoże.',
            'body.max' => 'To za długie. Zmieść się w 2000 znaków — liczy się to, co najważniejsze.',
        ]);

        try {
            $this->zloz->handle($request->user(), $action, $data['body'], $request->ip());
        } catch (BladDlaCzlowieka $blad) {
            return back()->withErrors(['body' => $blad->getMessage()])->withInput();
        }

        return redirect()->route('appeals.show', $action)->with(
            'status',
            'Odwołanie do nas trafiło. Odpowiemy w ciągu '
            .config('kuking.moderation.appeal_response_working_days')
            .' dni roboczych — odpowiedź zobaczysz w powiadomieniach.',
        );
    }

    /**
     * Formularz dla osoby, która nie może się zalogować (blokada konta).
     *
     * Trasa jest publiczna, bo musi być — zablokowany człowiek nie ma jak
     * wejść do serwisu, a DSA art. 20 daje mu prawo do odwołania właśnie od
     * blokady konta.
     */
    public function guestForm(): View
    {
        return view('pages.appeals.guest');
    }

    public function guestStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'login.required' => 'Podaj swój adres e-mail albo nazwę użytkownika.',
            'password.required' => 'Wpisz hasło do swojego konta.',
            'body.required' => 'Napisz w kilku zdaniach, dlaczego uważasz decyzję za błędną.',
            'body.min' => 'Napisz trochę więcej — kilka zdań wystarczy, ale jedno słowo nam nie pomoże.',
        ]);

        // TEN FORMULARZ SPRAWDZA HASŁO, więc chodzi po TYCH SAMYCH TRZECH
        // KOSZYKACH CO `/login` (`App\Domain\Security\LimitProbHasla`).
        //
        // Sam `throttle:appeal` (5/60 min) tego nie załatwiał, bo liczy się
        // po ADRESIE, a `config/kuking.php` → `login_limits` mówi wprost, że
        // licznik przywiązany do adresu nie widzi ataku rozproszonego po
        // wielu adresach na jedno konto. Zmierzone przed tą zmianą: 60 prób
        // hasła do jednego konta z 60 różnych adresów — ZERO odmów, podczas
        // gdy `/login` blokuje przy piętnastej. Był to więc drugi, słabszy
        // wjazd do tej samej wyroczni — i jedyny z tych trzech, który nie
        // ma nawet Turnstile.
        $adres = (string) $request->ip();

        $this->limit->zatrzymajJesliZaDuzo($data['login'], $adres);

        $osoba = User::findByLogin($data['login']);

        // Komunikat jednakowy dla złego loginu i złego hasła — inaczej ten
        // formularz byłby wygodnym sprawdzaczem, czy dane konto istnieje
        // (ta sama zasada co w LoginController).
        if ($osoba === null || ! Hash::check($data['password'], (string) $osoba->password)) {
            $this->limit->zapiszNieudanaProbe($data['login'], $adres);

            throw ValidationException::withMessages([
                'login' => 'Nie rozpoznajemy tych danych. Sprawdź, czy nazwa i hasło są wpisane poprawnie. '
                    .'Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła” — to działa także przy zablokowanym koncie.',
            ]);
        }

        // DOBRE HASŁO CZYŚCI PARĘ I KONTO, NIGDY ADRES — ta sama reguła
        // i to samo uzasadnienie co w `LoginController` (`KluczeLimitow`).
        // Bez tego osoba, która pomyliła hasło trzy razy, a za czwartym
        // trafiła, nadal siedziałaby przy pełnym liczniku.
        $this->limit->wyczyscPoUdanej($data['login'], $adres);

        $decyzja = $this->ostatniaDecyzjaDoOdwolania($osoba);

        if ($decyzja === null) {
            throw ValidationException::withMessages([
                'login' => 'Nie mamy decyzji, od której można się teraz odwołać. Możliwe, że odwołanie już złożono '
                    .'albo minęło sześć miesięcy od decyzji. Napisz do nas: '.config('kuking.community.contact_email'),
            ]);
        }

        try {
            $this->zloz->handle($osoba, $decyzja, $data['body'], $request->ip());
        } catch (BladDlaCzlowieka $blad) {
            throw ValidationException::withMessages(['body' => $blad->getMessage()]);
        }

        return redirect()->route('appeals.guest')->with(
            'status',
            'Odwołanie do nas trafiło. Odpowiemy w ciągu '
            .config('kuking.moderation.appeal_response_working_days')
            .' dni roboczych. Odpowiedź zobaczysz na tym ekranie logowania, gdy spróbujesz wejść na konto.',
        );
    }

    /**
     * Ostatnia decyzja tej osoby, od której da się jeszcze odwołać.
     *
     * Formularz przed logowaniem nie pyta „od której decyzji", bo osoba
     * zablokowana i tak nie widzi ich listy, a pytanie o identyfikator
     * z powiadomienia, którego nie może otworzyć, byłoby okrucieństwem.
     * Bierzemy najnowszą sprawę bez odwołania i w terminie — przy blokadzie
     * konta to praktycznie zawsze ta jedna, o którą chodzi.
     *
     * NIE CAŁA HISTORIA KONTA (issue #999). Wcześniej `get()` hydratowało
     * każdą odwoływalną decyzję bez odwołania, żeby dopiero w PHP wybrać
     * pierwszą w terminie — koszt ścieżki dostępnej przed logowaniem rósł
     * z wiekiem konta i liczbą działań moderatorów.
     *
     * DLACZEGO NIE SAMO `first()`: termin NIE jest monotoniczny względem
     * `created_at`. `appealDeadline()` liczy `addMonths(6)` z przepełnieniem
     * miesiąca, więc decyzja z 31 sierpnia ma termin do 3 marca, a późniejsza
     * z 1 września — do 1 marca. Gdyby brać tylko najnowszą, 2 marca
     * osoba straciłaby prawo do odwołania od starszej decyzji, która wciąż
     * jest w terminie.
     *
     * Dlatego dwa ograniczenia: SQL odcina decyzje starsze niż najdłuższy
     * możliwy termin (`NAJDLUZSZY_TERMIN_W_DNIACH` albo `appeal_days`, jeśli
     * dłuższe), a `lazy()` czyta resztę po jednej od najnowszej
     * i kończy na pierwszej w terminie. Poza pierwszą porcją czyta się tylko
     * decyzje z kilkudniowego pasa na granicy terminu. Ostatnie słowo ma
     * `isAppealable()`; brak decyzji i decyzja przeterminowana dają ten sam
     * `null` — i ten sam komunikat.
     */
    private function ostatniaDecyzjaDoOdwolania(User $osoba): ?ModerationAction
    {
        $dni = max(self::NAJDLUZSZY_TERMIN_W_DNIACH, (int) config('kuking.moderation.appeal_days'));

        return ModerationAction::query()
            ->where('subject_user_id', $osoba->getKey())
            ->whereIn('action', ModerationAction::ODWOLYWALNE)
            ->whereDoesntHave('authorAppeal')
            ->where('created_at', '>', now()->subDays($dni + 1))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lazy(self::PORCJA_DECYZJI)
            ->first(fn (ModerationAction $decyzja): bool => $decyzja->isAppealable());
    }

    /**
     * UUID decyzji w adresie NIE JEST autoryzacją (AGENTS.md §7).
     *
     * Bez tego dowolna zalogowana osoba, która zna albo zgadnie identyfikator,
     * czytałaby cudze uzasadnienie moderacyjne — a to jest jedna z
     * najbardziej wrażliwych rzeczy, jakie mamy o człowieku.
     */
    private function sprawdzWlascicielaSprawy(?User $osoba, ModerationAction $action): void
    {
        abort_if($osoba === null, 403);

        // Decyzja bez wskazanej osoby to wpis sprzed migracji
        // `..._add_context_to_moderation_actions` — nie wiadomo, czyja jest,
        // więc nie pokazujemy jej nikomu.
        abort_if($action->subject_user_id === null, 404);

        // Moderator też nie ogląda tu cudzych spraw — od tego jest kolejka
        // odwołań w panelu.
        abort_unless($action->subject_user_id === $osoba->getKey(), 403);
    }
}
