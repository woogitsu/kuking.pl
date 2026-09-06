<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\Actions\NotifyModerationDecision;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Domain\Moderation\ModeratedContent;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Kolejka moderacji dla 1-2 osobowego zespołu.
 *
 * Musi istnieć PRZED publicznym startem (docs/ROADMAP.md, punkt 9).
 * Każda decyzja zapisuje uzasadnienie i treść wysłaną użytkownikowi —
 * bez tego nie da się rozpatrzyć odwołania (DSA art. 17).
 */
class ModerationController extends Controller
{
    public function __construct(
        private readonly NotifyModerationDecision $powiadom,
        private readonly RestoreContent $przywroc,
    ) {}

    public function reports(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $status = $request->query('status', 'open');

        $reports = Report::query()
            ->when($status !== 'wszystkie', fn ($query) => $query->where('status', $status))
            ->with(['reporter.profile', 'resolver.profile'])
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('pages.admin.reports', [
            'status' => $status,
            'reports' => $reports,
            // Które zgłoszenia da się dziś cofnąć (issue #65).
            'przywracalne' => $this->przywracalne($reports->getCollection()->all()),
            'counts' => [
                'open' => Report::where('status', Report::STATUS_OPEN)->count(),
                'reviewing' => Report::where('status', Report::STATUS_REVIEWING)->count(),
                'resolved' => Report::where('status', Report::STATUS_RESOLVED)->count(),
            ],
        ]);
    }

    public function decide(Request $request, Report $report): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        // Wstępne sprawdzenie — tanie i daje sensowny komunikat bez wchodzenia
        // w transakcję. NIE JEST GWARANCJĄ: prawdziwe rozstrzygnięcie stoi
        // niżej, pod blokadą wiersza.
        if ($report->status !== Report::STATUS_OPEN) {
            return back()->withErrors([
                'action' => 'To zgłoszenie zostało już rozstrzygnięte. Odśwież stronę, żeby zobaczyć decyzję.',
            ]);
        }

        // Lista dozwolonych decyzji zależy od TYPU zgłoszenia — patrz
        // ModerationAction::DOZWOLONE. Kombinacja spoza listy jest błędem,
        // a nie cichym „zrób coś innego".
        $dozwolone = array_keys(ModerationAction::dozwoloneDla($report->target_type));

        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', $dozwolone)],
            'reason_code' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:2000'],
            'user_message' => ['nullable', 'string', 'max:2000'],
            // Długość zawieszenia w dniach. `bezterminowo` zostaje możliwe,
            // ale wymaga świadomego wyboru — nie jest już domyślne przez
            // przypadek, jak wtedy, gdy nie było gdzie zapisać terminu (#40).
            'suspend_days' => ['nullable', 'in:1,7,30,bezterminowo'],
        ], [
            'action.required' => 'Wybierz decyzję.',
            'action.in' => 'Ta decyzja nie ma zastosowania do tego zgłoszenia. Wybierz jedną z pokazanych.',
            'reason_code.required' => 'Podaj powód decyzji — bez niego nie da się odpowiedzieć na odwołanie.',
            'suspend_days.in' => 'Wybierz długość zawieszenia z listy.',
        ]);

        $moderator = $request->user();

        $termin = $this->terminKary($data);

        /*
         * WSZYSTKO PONIŻEJ W JEDNEJ TRANSAKCJI, POD BLOKADĄ WIERSZA
         * (audyt W3-09, W6-01).
         *
         * Sprawdzenie statusu wyżej stało samo i miało komentarz mówiący, że
         * chroni przed podwójną decyzją. Nie chroniło: między odczytem
         * a zapisem jest okno, w którym drugie żądanie widzi jeszcze `open`.
         * Dwie karty moderatora wykonywały więc dwie kary, tworzyły dwa wpisy
         * w `moderation_actions` i wysyłały dwa powiadomienia — a przy
         * odwołaniu (DSA art. 17) log przestawał być jednoznaczny. Baza też
         * tego nie łapała: `moderation_actions.report_id` nie ma ograniczenia
         * unikalności.
         *
         * To był komentarz pewniejszy niż kod — ten sam wzorzec, który audyty
         * wskazują jako powtarzalny w tym repozytorium.
         *
         * `lockForUpdate()` wstrzymuje drugie żądanie do końca pierwszej
         * transakcji, a ponowne sprawdzenie statusu JUŻ POD BLOKADĄ rozstrzyga
         * je jednoznacznie. Powiadomienie zostaje w środku świadomie: ma nie
         * wyjść, jeśli zapis się nie powiedzie.
         */
        $wynik = DB::transaction(function () use ($request, $report, $data, $moderator, $termin) {
            $zablokowane = Report::query()->whereKey($report->getKey())->lockForUpdate()->first();

            if ($zablokowane === null || $zablokowane->status !== Report::STATUS_OPEN) {
                return null;
            }

            // Cel i osobę wyznaczamy PRZED zapisaniem decyzji i przed jej
            // wykonaniem. Powodów są teraz dwa:
            //  - `remove` kasuje cel, a wtedy nie ma już kogo zapytać o autora;
            //  - do logu wchodzi STAN SPRZED decyzji (`previous_status`), więc
            //    trzeba go odczytać, zanim cokolwiek się zmieni. Bez tego
            //    ukrycia nie da się później cofnąć do właściwego stanu (#65).
            $cel = ModeratedContent::znajdz($report->target_type, $report->target_id);
            $osoba = $cel === null ? null : ModeratedContent::osoba($cel);

            $akcja = ModerationAction::create([
                'moderator_id' => $moderator->getKey(),
                'report_id' => $report->getKey(),
                'target_type' => $report->target_type,
                'target_id' => $report->target_id,
                'subject_user_id' => $osoba?->getKey(),
                'action' => $data['action'],
                'previous_status' => $cel === null ? null : ($cel->status ?? null),
                'reason_code' => $data['reason_code'],
                'note' => $data['note'] ?? null,
                'user_message' => $data['user_message'] ?? null,
            ]);

            $this->applyAction($cel, $osoba, $data['action'], $termin);

            // Powiadomienie o decyzji. Dopóki go nie było, `user_message` lądowała
            // wyłącznie w logu moderacji: dokumentacja twierdziła, że autora
            // poinformowano, a autor nie dostawał niczego (audyt A16).
            //
            // Dotyczyło to WSZYSTKICH decyzji zapisujących `user_message`, nie
            // tylko `warn` — `hide`, `remove`, `suspend` i `ban` milczały tak samo.
            if ($osoba !== null) {
                $this->powiadom->handle(
                    osoba: $osoba,
                    decyzja: $data['action'],
                    wiadomoscModeratora: $data['user_message'] ?? null,
                    do: $termin,
                    // Bez tego powiadomienie mówi „możesz się odwołać" i nie ma
                    // gdzie kliknąć — a formularz odwołania musi wiedzieć,
                    // KTÓREJ decyzji dotyczy (#10).
                    decyzjaModeracyjna: $akcja,
                );
            }

            $zablokowane->update([
                'status' => $data['action'] === ModerationAction::ACTION_NONE
                    ? Report::STATUS_REJECTED
                    : Report::STATUS_RESOLVED,
                'resolution_note' => $data['note'] ?? null,
                'resolved_by' => $moderator->getKey(),
                'resolved_at' => now(),
            ]);

            AuditLogEntry::record(
                action: 'moderation.decided',
                actor: $moderator,
                subject: $zablokowane,
                metadata: [
                    'decision' => $data['action'],
                    'reason_code' => $data['reason_code'],
                    'suspend_days' => $data['suspend_days'] ?? null,
                ],
                ip: $request->ip(),
            );

            return $akcja;
        });

        if ($wynik === null) {
            // Drugie żądanie doszło tu po tym, jak pierwsze już zapisało
            // decyzję. Ten sam komunikat co przy wstępnym sprawdzeniu — dla
            // człowieka to jest ta sama sytuacja.
            return back()->withErrors([
                'action' => 'To zgłoszenie zostało już rozstrzygnięte. Odśwież stronę, żeby zobaczyć decyzję.',
            ]);
        }

        return back()->with('status', 'Decyzja zapisana.');
    }

    /**
     * Cofnięcie ukrycia albo usunięcia treści (issue #65).
     *
     * DLACZEGO OSOBNY ENDPOINT, A NIE KOLEJNA POZYCJA W `DOZWOLONE`
     * Formularz decyzji obsługuje zgłoszenie OTWARTE i słusznie odmawia
     * drugiej decyzji dla zgłoszenia rozstrzygniętego (inaczej log przestaje
     * być jednoznaczny). Przywrócenie z definicji przychodzi PÓŹNIEJ — po
     * poprawieniu treści przez autora albo po odwołaniu — więc musiało dostać
     * własne wejście, własny przycisk i własną nazwę.
     *
     * Powód decyzji jest obowiązkowy tak samo jak przy ukrywaniu: cofnięcie
     * kary bez uzasadnienia wygląda w logu jak przypadek.
     */
    public function restore(Request $request, Report $report): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:80'],
            'user_message' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason_code.required' => 'Podaj powód przywrócenia — w logu musi zostać ślad, dlaczego zdjęto ukrycie.',
        ]);

        $cel = ModeratedContent::znajdz($report->target_type, $report->target_id, zUsunietymi: true);

        if ($cel === null) {
            return back()->withErrors([
                'reason_code' => 'Tej treści już nie ma w bazie — nie da się jej przywrócić.',
            ]);
        }

        try {
            $this->przywroc->handle(
                moderator: $request->user(),
                target: $cel,
                reasonCode: $data['reason_code'],
                note: 'Przywrócone przy zgłoszeniu '.$report->getKey().'.',
                userMessage: $data['user_message'] ?? null,
                ip: $request->ip(),
            );
        } catch (RuntimeException $blad) {
            return back()->withErrors(['reason_code' => $blad->getMessage()]);
        }

        return back()->with('status', 'Treść przywrócona. Wróciła do stanu sprzed ukrycia, a autor dostał powiadomienie.');
    }

    /**
     * Zgłoszenia, przy których jest dziś co przywracać.
     *
     * Zapytanie na cel idzie osobno dla każdego zgłoszenia z decyzją
     * `hide`/`remove` — świadomie, bo cele leżą w czterech różnych tabelach,
     * a strona kolejki ma 25 pozycji obsługiwanych przez jedną osobę.
     * Optymalizacja tego miejsca kosztowałaby więcej czytelności niż daje.
     *
     * @param  list<Report>  $reports
     * @return array<string, true> klucz: id zgłoszenia
     */
    private function przywracalne(array $reports): array
    {
        if ($reports === []) {
            return [];
        }

        $decyzje = ModerationAction::query()
            ->whereIn('report_id', array_map(fn (Report $r): string => (string) $r->getKey(), $reports))
            ->whereIn('action', [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE])
            ->orderBy('created_at')
            ->get();

        $wynik = [];

        foreach ($decyzje as $decyzja) {
            $cel = ModeratedContent::znajdz($decyzja->target_type, $decyzja->target_id, zUsunietymi: true);

            if ($cel === null || ! ModeratedContent::daSieUkryc($cel)) {
                continue;
            }

            $schowana = ModeratedContent::jestUkryta($cel)
                || (method_exists($cel, 'trashed') && $cel->trashed());

            if ($schowana) {
                $wynik[(string) $decyzja->report_id] = true;
            }
        }

        return $wynik;
    }

    /**
     * Zamiana wyboru z formularza na konkretną datę.
     *
     * `null` znaczy „bezterminowo, do decyzji człowieka" — i tak ma zostać
     * przy `bezterminowo` oraz przy każdej decyzji innej niż zawieszenie.
     */
    private function terminKary(array $data): ?CarbonInterface
    {
        if (($data['action'] ?? null) !== ModerationAction::ACTION_SUSPEND) {
            return null;
        }

        $wybor = $data['suspend_days'] ?? null;

        if ($wybor === null || $wybor === 'bezterminowo') {
            return null;
        }

        return now()->addDays((int) $wybor);
    }

    /**
     * Wykonanie decyzji na obiekcie.
     *
     * `hide` ukrywa treść (da się przywrócić), `remove` usuwa miękko.
     * Automat nigdy nie banuje sam — ban jest zawsze decyzją człowieka.
     *
     * @param  ?User  $osoba  autor zgłoszonej treści albo zgłoszona osoba.
     *                        Kara dotyczy CZŁOWIEKA, więc przy zgłoszonej treści
     *                        sięgamy po jej autora — wcześniej „Zawieś konto" na
     *                        zgłoszonym wpisie tylko ukrywało wpis, a konto
     *                        zostawało aktywne.
     */
    private function applyAction(?object $target, ?User $osoba, string $action, ?CarbonInterface $do = null): void
    {
        if ($action === ModerationAction::ACTION_NONE || $target === null) {
            return;
        }

        match ($action) {
            ModerationAction::ACTION_HIDE => $this->hide($target),

            // `remove` nie jest dozwolone dla celu `user` (macierz
            // ModerationAction::DOZWOLONE), więc tu nigdy nie trafi konto.
            // Wcześniej trafiało i kasowało je bezpowrotnie.
            ModerationAction::ACTION_REMOVE => $target->delete(),

            ModerationAction::ACTION_SUSPEND => $osoba?->suspend($do),
            ModerationAction::ACTION_BAN => $osoba?->ban(),

            // `warn` nie robi nic z treścią ani z kontem — całą jego siłą jest
            // wiadomość do autora. Wysyła ją `NotifyModerationDecision`
            // w `decide()`, wspólnie dla wszystkich decyzji.
            default => null,
        };
    }

    /**
     * Ukrycie treści.
     *
     * Status bierzemy z `ModeratedContent::UKRYTY`, a nie z łańcucha
     * `instanceof`. Poprzednia wersja miała gałęzie dla trzech modeli i cicho
     * przepuszczała czwarty („Ugotowałem"): moderator klikał „Ukryj treść",
     * zgłoszenie robiło się rozstrzygnięte, autor dostawał powiadomienie
     * „ukryliśmy Twoją treść" — a treść stała dalej. Teraz `cooked_event`
     * w ogóle nie ma tej decyzji na liście (`ModerationAction::DOZWOLONE`),
     * a gdyby ktoś ją tam dopisał, ten warunek nadal nie da się oszukać.
     */
    private function hide(object $target): void
    {
        if (! ModeratedContent::daSieUkryc($target)) {
            return;
        }

        $target->forceFill(['status' => ModeratedContent::UKRYTY[$target::class]])->save();
    }
}
