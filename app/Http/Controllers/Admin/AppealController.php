<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Domain\Moderation\DlugoscZawieszenia;
use App\Domain\Moderation\NowaDecyzja;
use App\Domain\Moderation\PodstawaDecyzji;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kolejka odwołań (issue #10, DSA art. 20).
 *
 * CO MODERATOR MUSI ZOBACZYĆ, ŻEBY W OGÓLE MÓC ROZPATRZYĆ SPRAWĘ
 *
 *  * własne słowa odwołującego się,
 *  * decyzję, od której się odwołuje, wraz z jej powodem,
 *  * DOKŁADNIE TĘ WIADOMOŚĆ, którą ta osoba wtedy dostała (`user_message`) —
 *    bez tego nie da się ocenić, czy pisze o tym samym, co jej powiedzieliśmy,
 *  * kto podjął pierwotną decyzję — bo od tego zależy karencja na jej
 *    podtrzymanie (`ResolveAppeal`),
 *  * termin odpowiedzi i to, czy już minął.
 *
 * Zamknięcie sprawy WYMAGA uzasadnienia. „Podtrzymuję" bez zdania wyjaśniającego
 * nie jest odpowiedzią w rozumieniu DSA art. 20 i nie da się go zapisać — pilnuje
 * tego i walidacja, i CHECK w bazie.
 *
 * KTO ROZSTRZYGA (D-039): `index()` pokazuje kolejkę każdemu moderatorowi —
 * `resolve()` przyjmuje decyzję wyłącznie od administratora
 * (`UserPolicy::resolveAppeals()`).
 *
 * CZEGO TO NIE ROBI, ŻEBY NIKT SIĘ NIE POMYLIL: to jest bramka NA ROLĘ,
 * nie na osobę. Administrator przechodzi też przez `moderate()`, więc ta
 * sama osoba może wydać decyzję i rozstrzygnąć odwołanie od niej —
 * przeszkadza jej w tym wyłącznie karencja `ResolveAppeal::sprawdzKarencje()`
 * i tylko przy PODTRZYMANIU. Zawężenie ma sens dopiero przy dwóch osobach:
 * moderator pierwszej linii przestaje móc zamknąć sprawę, którą sam
 * rozstrzygał. Przy jednym człowieku pełniącym obie role nie zmienia nic
 * poza tym, że drugie konto (bez roli `admin`) tego nie zrobi.
 */
class AppealController extends Controller
{
    public function __construct(private readonly ResolveAppeal $rozpatrz) {}

    public function index(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $status = $request->query('status', Appeal::STATUS_OPEN);

        return view('pages.admin.appeals', [
            'status' => $status,
            'appeals' => Appeal::query()
                ->when($status !== 'wszystkie', fn ($query) => $query->where('status', $status))
                ->with(['user.profile', 'report', 'decider.profile', 'moderationAction.moderator.profile', 'decisionAfterAppeal'])
                // Otwarte najstarsze na górze: termin odpowiedzi liczy się od
                // złożenia, więc kolejność „najnowsze pierwsze" gwarantowałaby,
                // że przeterminowane leżą najgłębiej i nikt ich nie widzi.
                //
                // Drugi warunek rozstrzyga REMIS na sekundzie (audyt G10). Bez
                // niego PostgreSQL oddaje takie wiersze w porządku fizycznym
                // w stercie, a ten przestawia każdy UPDATE — przy stronicowaniu
                // po 25 znaczy to inny podział na strony między jednym
                // kliknięciem a drugim, czyli odwołanie pokazane dwa razy albo
                // pominięte. Tu boli podwójnie, bo odwołanie ma termin
                // (DSA art. 20) i pominięte nie zgłosi się samo.
                //
                // `id` jest UUID-em v7, więc rozstrzyga w tę samą stronę co
                // czas: starsze wyżej. Kolejność par o różnym `created_at`
                // zostaje bez zmian.
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate(25)
                ->withQueryString(),
            'counts' => [
                'open' => Appeal::where('status', Appeal::STATUS_OPEN)->count(),
                'upheld' => Appeal::where('status', Appeal::STATUS_UPHELD)->count(),
                'overturned' => Appeal::where('status', Appeal::STATUS_OVERTURNED)->count(),
            ],
        ]);
    }

    public function resolve(Request $request, Appeal $appeal): RedirectResponse
    {
        // A-4: samą DECYZJĘ o odwołaniu rozstrzyga administrator, nie
        // moderator, który mógł wydać (albo wydał) sprawdzaną tu decyzję —
        // `moderate` powyżej w `index()` nadal wystarcza, żeby kolejkę
        // ZOBACZYĆ, ta bramka zawęża, kto może ją ZAMKNĄĆ.
        $this->authorize('resolveAppeals', User::class);

        // Uznanie odwołania zgłaszającego od „Bez działania” wymaga NOWEJ
        // decyzji (#989). Pola są te same co przy decyzji ze zgłoszenia
        // (`ModerationController::decide()`), a rozstrzyga o nich domena —
        // tu jest kształt formularza i błędy przy właściwych polach.
        $zNowaDecyzja = $appeal->wymagaNowejDecyzji()
            && $request->input('outcome') === Appeal::STATUS_OVERTURNED;
        $dozwolone = array_diff(
            array_keys(ModerationAction::dozwoloneDla($appeal->moderationAction?->target_type)),
            [ModerationAction::ACTION_NONE],
        );

        $walidator = Validator::make($request->all(), [
            'outcome' => ['required', 'in:'.Appeal::STATUS_UPHELD.','.Appeal::STATUS_OVERTURNED],
            'decision_note' => ['required', 'string', 'min:10', 'max:2000'],
            'nowa_decyzja' => $zNowaDecyzja ? ['required', Rule::in($dozwolone)] : ['nullable'],
            'reason_code' => $zNowaDecyzja ? ['required', Rule::in(array_keys(PodstawaDecyzji::dlaFormularza()))] : ['nullable'],
            'user_message' => $zNowaDecyzja
                ? ['nullable', 'string', 'max:2000', 'required_if:reason_code,'.PodstawaDecyzji::NIEZGODNE_Z_PRAWEM]
                : ['nullable'],
            'suspend_days' => ['nullable', Rule::in(DlugoscZawieszenia::wartosci())],
            'suspend_days_custom' => $zNowaDecyzja && DlugoscZawieszenia::wymagaLiczby($request->input('suspend_days'))
                ? ['required', 'integer', 'min:'.DlugoscZawieszenia::MIN_DNI, 'max:'.DlugoscZawieszenia::MAX_DNI]
                : ['nullable'],
        ], [
            'outcome.required' => 'Wybierz, czy podtrzymujesz decyzję, czy ją cofasz.',
            'outcome.in' => 'Wybierz jedną z dwóch odpowiedzi.',
            'decision_note.required' => 'Napisz uzasadnienie — to jest odpowiedź, którą przeczyta ta osoba.',
            'decision_note.min' => 'Uzasadnienie ma być zdaniem, nie jednym słowem.',
            'nowa_decyzja.required' => ResolveAppeal::WYBIERZ_NOWA_DECYZJE,
            'nowa_decyzja.in' => 'Ta decyzja nie ma zastosowania do zgłoszonej treści. Wybierz jedną z pokazanych.',
            'reason_code.required' => 'Wybierz podstawę nowej decyzji — autor treści zobaczy ją w powiadomieniu.',
            'reason_code.in' => 'Wybierz podstawę z listy.',
            'user_message.required_if' => 'Przy podstawie „treść niezgodna z prawem” napisz autorowi, '
                .'co dokładnie uznaliśmy za niezgodne z prawem.',
            'suspend_days.in' => 'Wybierz długość zawieszenia z listy.',
            'suspend_days_custom.required' => 'Przy „Własnym terminie” wpisz liczbę dni od '
                .DlugoscZawieszenia::MIN_DNI.' do '.DlugoscZawieszenia::MAX_DNI.'.',
            'suspend_days_custom.integer' => 'Wpisz własny termin jako liczbę dni, na przykład 14.',
            'suspend_days_custom.min' => 'Najkrótsze zawieszenie to '.DlugoscZawieszenia::MIN_DNI.' dzień.',
            'suspend_days_custom.max' => 'Najdłuższe zawieszenie z terminem to '.DlugoscZawieszenia::MAX_DNI.' dni.',
        ]);

        // „Zawieś konto” bez terminu to pomyłka, nie bezterminowość — ta sama
        // reguła co w `ModerationController::decide()`.
        $walidator->after(function ($sprawdzenie) use ($request, $zNowaDecyzja): void {
            if (! $zNowaDecyzja || $request->input('nowa_decyzja') !== ModerationAction::ACTION_SUSPEND) {
                return;
            }

            $wybor = $request->input('suspend_days');

            if (! DlugoscZawieszenia::zawiesza(is_string($wybor) ? $wybor : null)) {
                $sprawdzenie->errors()->add('suspend_days', 'Przy decyzji „Zawieś konto” zaznacz jeszcze, na jak długo.');
            }
        });

        $data = $walidator->validate();

        $nowaDecyzja = null;

        if ($zNowaDecyzja) {
            $wybor = $data['suspend_days'] ?? null;
            $dni = $data['suspend_days_custom'] ?? null;

            $nowaDecyzja = new NowaDecyzja(
                akcja: $data['nowa_decyzja'],
                podstawa: $data['reason_code'],
                wiadomoscDlaAutora: isset($data['user_message']) && trim((string) $data['user_message']) !== ''
                    ? trim((string) $data['user_message'])
                    : null,
                terminZawieszenia: $data['nowa_decyzja'] === ModerationAction::ACTION_SUSPEND
                    ? DlugoscZawieszenia::termin(is_string($wybor) ? $wybor : null, is_numeric($dni) ? (int) $dni : null)
                    : null,
            );
        }

        try {
            $this->rozpatrz->handle(
                moderator: $request->user(),
                odwolanie: $appeal,
                wynik: $data['outcome'],
                uzasadnienie: $data['decision_note'],
                ip: $request->ip(),
                nowaDecyzja: $nowaDecyzja,
            );
        } catch (BladDlaCzlowieka $blad) {
            return back()->withErrors(['outcome' => $blad->getMessage()])->withInput();
        }

        return back()->with('status', 'Odpowiedź zapisana i wysłana.');
    }
}
