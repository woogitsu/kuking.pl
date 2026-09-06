<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\Actions\NotifyModerationDecision;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kolejka moderacji dla 1-2 osobowego zespołu.
 *
 * Musi istnieć PRZED publicznym startem (docs/ROADMAP.md, punkt 9).
 * Każda decyzja zapisuje uzasadnienie i treść wysłaną użytkownikowi —
 * bez tego nie da się rozpatrzyć odwołania (DSA art. 17).
 */
class ModerationController extends Controller
{
    public function __construct(private readonly NotifyModerationDecision $powiadom) {}

    public function reports(Request $request): View
    {
        $this->authorize('moderate', User::class);

        $status = $request->query('status', 'open');

        return view('pages.admin.reports', [
            'status' => $status,
            'reports' => Report::query()
                ->when($status !== 'wszystkie', fn ($query) => $query->where('status', $status))
                ->with(['reporter.profile', 'resolver.profile'])
                ->latest()
                ->paginate(25)
                ->withQueryString(),
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

        // Zgłoszenie rozstrzygnięte nie przyjmuje drugiej decyzji.
        //
        // Bez tego dwie zakładki albo dwa kliknięcia zapisywały DWA wpisy
        // w `moderation_actions` dla jednego zgłoszenia — a przy odwołaniu
        // (DSA art. 17) log przestawał być jednoznaczny.
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

        ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $report->getKey(),
            'target_type' => $report->target_type,
            'target_id' => $report->target_id,
            'action' => $data['action'],
            'reason_code' => $data['reason_code'],
            'note' => $data['note'] ?? null,
            'user_message' => $data['user_message'] ?? null,
        ]);

        $termin = $this->terminKary($data);

        // Cel i osobę wyznaczamy PRZED wykonaniem decyzji: `remove` kasuje
        // cel, a wtedy nie ma już kogo zapytać o autora.
        $cel = $this->cel($report);
        $osoba = $cel instanceof User ? $cel : ($cel === null ? null : $this->autorem($cel));

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
            );
        }

        $report->update([
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
            subject: $report,
            metadata: [
                'decision' => $data['action'],
                'reason_code' => $data['reason_code'],
                'suspend_days' => $data['suspend_days'] ?? null,
            ],
            ip: $request->ip(),
        );

        return back()->with('status', 'Decyzja zapisana.');
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
     * Obiekt, którego dotyczy zgłoszenie.
     *
     * Wyjęte z `applyAction`, bo tej samej odpowiedzi potrzebuje powiadomienie
     * o decyzji — i musi ją dostać, ZANIM `remove` skasuje cel.
     */
    private function cel(Report $report): ?object
    {
        return match ($report->target_type) {
            'post' => Post::find($report->target_id),
            'recipe' => Recipe::find($report->target_id),
            'comment' => Comment::find($report->target_id),
            'cooked_event' => CookedEvent::find($report->target_id),
            'user' => User::find($report->target_id),
            default => null,
        };
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
     * Autor zgłoszonej treści.
     *
     * Modele używają różnych nazw relacji, więc pytamy o to, co faktycznie
     * istnieje, zamiast zakładać jedną wspólną konwencję.
     */
    private function autorem(object $target): ?User
    {
        foreach (['author', 'user', 'owner'] as $relacja) {
            if (method_exists($target, $relacja)) {
                $osoba = $target->{$relacja};

                if ($osoba instanceof User) {
                    return $osoba;
                }
            }
        }

        return null;
    }

    private function hide(object $target): void
    {
        if ($target instanceof Post || $target instanceof Recipe) {
            $target->update(['status' => 'hidden']);

            return;
        }

        if ($target instanceof Comment) {
            $target->update(['status' => Comment::STATUS_HIDDEN]);
        }
    }
}
