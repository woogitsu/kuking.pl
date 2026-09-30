<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\Actions\FileAppeal;
use App\Domain\Security\KodDwuetapowyZFormularza;
use App\Domain\Security\LimitProbHasla;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\ModerationAction;
use App\Models\User;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\Komunikat;
use App\Support\Turnstile;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
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
 *     `kuking.limits.appeal` (5 prób na godzinę), trzy koszyki hasła
 *     wspólne z `/login`, Turnstile i JEDEN komunikat dla złych danych
 *     i konta bez decyzji do odwołania, żeby nie dało się nim sprawdzać,
 *     czy konto istnieje ani czy hasło jest dobre (#2272). Konto z 2FA
 *     podaje też kod — samo hasło nie wystarcza, tak jak przy logowaniu.
 *
 * ILE RAZY MOŻNA SIĘ ODWOŁAĆ: RAZ OD JEDNEJ DECYZJI. Uzasadnienie i miejsce,
 * w którym to jest egzekwowane — `FileAppeal` oraz `UNIQUE` w bazie.
 */
class AppealController extends Controller
{
    public function __construct(
        private readonly FileAppeal $zloz,
        private readonly LimitProbHasla $limit,
        private readonly KodDwuetapowyZFormularza $kodDwuetapowy,
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

        return redirect()->route('appeals.show', $action)->with(Komunikat::sukces('Odwołanie do nas trafiło. Odpowiemy w ciągu '
            .config('kuking.moderation.appeal_response_working_days')
            .' dni roboczych — odpowiedź zobaczysz w powiadomieniach.',
        ));
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
        // `Validator::make` zamiast `$request->validate()`: wyjątek walidacji
        // odkłada w sesji całe wejście poza hasłem, czyli także `code` —
        // a kod zapasowy jest sekretem (ta sama zasada co przy cofnięciu
        // usunięcia konta). Wracają wyłącznie `login` i `body`.
        $walidator = Validator::make($request->all(), [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'code' => ['nullable', 'string', 'max:64'],
            /*
             * Turnstile (D-050) — WARUNEK WYSŁANIA, nie filtr (#2272, audyt
             * S-04). Ten formularz sprawdza hasło do DOWOLNEGO konta, tak
             * jak `/login` i `/cofnij-usuniecie-konta`, a do 30.09.2026 był
             * jedynym z trzech bez tej bramki: automat sprawdzał nim hasła
             * obok logowania. Wyłącznik: `TURNSTILE_NA_ODWOLANIU=false`.
             */
            Turnstile::POLE => TurnstileJestPotwierdzony::reguly('odwolanie'),
        ], [
            'login.required' => 'Podaj swój adres e-mail albo nazwę użytkownika.',
            'password.required' => 'Wpisz hasło do swojego konta.',
            'body.required' => 'Napisz w kilku zdaniach, dlaczego uważasz decyzję za błędną.',
            'body.min' => 'Napisz trochę więcej — kilka zdań wystarczy, ale jedno słowo nam nie pomoże.',
            'body.max' => 'To za długie. Zmieść się w 2000 znaków — liczy się to, co najważniejsze.',
            'code.string' => 'Wpisz kod z aplikacji albo kod zapasowy.',
            'code.max' => 'Ten kod jest za długi. Wpisz sześciocyfrowy kod z aplikacji albo kod zapasowy.',
        ]);

        if ($walidator->fails()) {
            $this->odmow($request, $walidator->errors()->toArray());
        }

        $data = $walidator->validated();

        // TEN FORMULARZ SPRAWDZA HASŁO, więc chodzi po TYCH SAMYCH TRZECH
        // KOSZYKACH CO `/login` (`App\Domain\Security\LimitProbHasla`).
        //
        // Sam `throttle:appeal` (5/60 min) tego nie załatwiał, bo liczy się
        // po ADRESIE, a `config/kuking.php` → `login_limits` mówi wprost, że
        // licznik przywiązany do adresu nie widzi ataku rozproszonego po
        // wielu adresach na jedno konto. Zmierzone przed tą zmianą: 60 prób
        // hasła do jednego konta z 60 różnych adresów — ZERO odmów, podczas
        // gdy `/login` blokuje przy piętnastej.
        $adres = (string) $request->ip();

        try {
            $this->limit->zatrzymajJesliZaDuzo($data['login'], $adres);
        } catch (ValidationException $odmowaLimitu) {
            $this->odmow($request, $odmowaLimitu->errors());
        }

        $osoba = User::findByLogin($data['login']);

        // JEDEN komunikat dla złego loginu, złego hasła i konta bez decyzji do
        // odwołania (#2272, audyt S-04). Do 30.09.2026 dobre hasło dawało inne
        // zdanie („Nie mamy decyzji…”) niż złe — formularz bez Turnstile był
        // więc wyrocznią hasła do każdego aktywnego konta.
        if ($osoba === null || ! Hash::check($data['password'], (string) $osoba->password)) {
            $this->limit->zapiszNieudanaProbe($data['login'], $adres);

            $this->odmow($request, ['login' => self::nieMozemyPrzyjac()]);
        }

        // DOBRE HASŁO CZYŚCI PARĘ I KONTO, NIGDY ADRES — ta sama reguła
        // i to samo uzasadnienie co w `LoginController` (`KluczeLimitow`).
        $this->limit->wyczyscPoUdanej($data['login'], $adres);

        // Czy jest od czego się odwołać — liczone TERAZ, ogłaszane po kodzie.
        $decyzja = $this->ostatniaDecyzjaDoOdwolania($osoba);

        // Drugi składnik PRZED jakąkolwiek odpowiedzią o koncie (#2272):
        // konto z 2FA nie loguje się samym hasłem, więc samym hasłem nie
        // składa też odwołania. Kod zapasowy zużywamy tylko wtedy, gdy
        // odwołanie naprawdę powstanie.
        if ($osoba->hasTwoFactorConfirmed()) {
            $blad = $this->kodDwuetapowy->sprawdz(
                $osoba,
                trim((string) ($data['code'] ?? '')),
                zuzyjKodZapasowy: $decyzja !== null,
                przycisk: 'Wyślij odwołanie',
            );

            if ($blad !== null) {
                $this->odmow($request, ['code' => $blad]);
            }
        }

        if ($decyzja === null) {
            $this->odmow($request, ['login' => self::nieMozemyPrzyjac()]);
        }

        try {
            $this->zloz->handle($osoba, $decyzja, $data['body'], $request->ip());
        } catch (BladDlaCzlowieka $blad) {
            $this->odmow($request, ['body' => $blad->getMessage()]);
        }

        return redirect()->route('appeals.guest')->with(Komunikat::sukces('Odwołanie do nas trafiło. Odpowiemy w ciągu '
            .config('kuking.moderation.appeal_response_working_days')
            .' dni roboczych. Odpowiedź zobaczysz na tym ekranie logowania, gdy spróbujesz wejść na konto.',
        ));
    }

    /**
     * Wspólne zdanie dla złych danych i konta bez decyzji do odwołania.
     * Mówi obie możliwości i co zrobić przy każdej — nie mówi, która zaszła.
     */
    public static function nieMozemyPrzyjac(): string
    {
        return 'Nie możemy przyjąć tego odwołania. Sprawdź, czy nazwa albo e-mail i hasło są wpisane poprawnie. '
            .'Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła” — to działa także przy zablokowanym koncie. '
            .'Jeśli dane są dobre, na tym koncie nie ma decyzji, od której można się teraz odwołać: odwołanie już '
            .'złożono albo minęło sześć miesięcy od decyzji. Wtedy napisz do nas: '.config('kuking.community.contact_email');
    }

    /**
     * Powrót na formularz z błędami. Wracają wyłącznie `login` i `body` —
     * nigdy hasło ani kod (sekrety, patrz `Validator::make` wyżej).
     *
     * @param  array<string, string|array<int, string>>  $bledy
     */
    private function odmow(Request $request, array $bledy): never
    {
        throw new HttpResponseException(
            back()->withErrors($bledy)->withInput($request->only('login', 'body')),
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
     */
    private function ostatniaDecyzjaDoOdwolania(User $osoba): ?ModerationAction
    {
        // Bez pobierania całej historii decyzji tej osoby (issue #999).
        // Termin rozstrzyga nadal `isAppealable()` — SQL tylko odcina
        // decyzje, którym termin na pewno minął. Granica jest celowo
        // luźniejsza o kilka dni: `appealDeadline()` dodaje miesiące
        // z przepełnieniem (31 sierpnia + 6 miesięcy = 3 marca), więc
        // odcięcie równo sześć miesięcy wstecz zgubiłoby takie decyzje.
        $najstarszaMozliwa = now()->subDays((int) config('kuking.moderation.appeal_days'))
            ->min(now()->subMonthsNoOverflow(6)->subDays(4));

        // `cursor()` + `first()`: modele powstają po jednym i przestają
        // powstawać przy pierwszej decyzji w terminie — zwykle najnowszej.
        return ModerationAction::query()
            ->where('subject_user_id', $osoba->getKey())
            ->whereIn('action', ModerationAction::ODWOLYWALNE)
            ->where('created_at', '>=', $najstarszaMozliwa)
            ->whereDoesntHave('authorAppeal')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursor()
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
