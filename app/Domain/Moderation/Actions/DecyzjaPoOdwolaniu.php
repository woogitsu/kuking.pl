<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\NowaDecyzja;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Wykonanie nowej decyzji po uznaniu odwołania zgłaszającego od decyzji bez
 * działania (#989, DSA art. 20 ust. 4).
 *
 * CO BYŁO
 * Zgłaszający odwoływał się od „Bez działania”, administrator zaznaczał
 * „Cofam”, a system zapisywał `overturned` i wysyłał „Zmieniamy naszą
 * decyzję” — bez żadnego skutku. Treść zostawała, zgłoszenie zostawało
 * `rejected`, a realne działanie miało się odbyć „ręcznie, poza systemem”.
 * Art. 20 ust. 4 każe przy zasadnej skardze decyzję ODWRÓCIĆ, nie opisać.
 *
 * CO JEST
 * Administrator wybiera nową decyzję w formularzu rozpatrzenia (te same
 * pola co przy decyzji ze zgłoszenia), a ta klasa wykonuje ją w transakcji
 * `ResolveAppeal`, pod blokadą odwołania. Nowa decyzja:
 *
 *  - jest zwykłym wierszem `moderation_actions` z `report_id = NULL`
 *    (jedna decyzja pierwszej instancji na zgłoszenie zostaje nietknięta)
 *    i `appeal_id` wskazującym odwołanie — przez nie pierwotną decyzję
 *    i zgłoszenie;
 *  - dociera do autora tym samym powiadomieniem co każda decyzja, z pełnym
 *    uzasadnieniem i własną drogą odwołania (`FileAppeal`);
 *  - przestawia zgłoszenie z `rejected` na `resolved`, bo zgłoszenie
 *    okazało się zasadne. Data i autor pierwszego rozstrzygnięcia zostają.
 *
 * Czego się nie da, to się nie zapisuje: akcja spoza macierzy dla typu celu,
 * cel, którego już nie ma, treść już zdjęta, zawieszenie albo blokada konta,
 * którego ROLA ma rangę równą albo wyższą niż rola rozpatrującego (policy
 * `sanctionAccount`, #1408). Ranga to rola, nie obecna kara: konto już
 * zawieszone, zablokowane albo w cyklu usuwania da się ukarać, a kara
 * idzie przez przejścia `User::suspend()`/`ban()` (#980 — w cyklu usuwania
 * do `punishment_status`). Każdy z tych przypadków to `BladDlaCzlowieka` — transakcja
 * się wycofuje, odwołanie zostaje otwarte i można je podtrzymać.
 */
final class DecyzjaPoOdwolaniu
{
    public function __construct(private readonly NotifyModerationDecision $powiadom) {}

    /**
     * @throws BladDlaCzlowieka gdy nowej decyzji nie da się wykonać
     */
    public function handle(
        User $moderator,
        Appeal $odwolanie,
        ModerationAction $pierwotna,
        NowaDecyzja $nowa,
        ?string $ip = null,
    ): ModerationAction {
        $dozwolone = array_diff(
            array_keys(ModerationAction::dozwoloneDla($pierwotna->target_type)),
            [ModerationAction::ACTION_NONE],
        );

        if (! in_array($nowa->akcja, $dozwolone, true)) {
            throw new BladDlaCzlowieka('Ta decyzja nie ma zastosowania do zgłoszonej treści. Wybierz jedną z pokazanych.');
        }

        $cel = $this->zablokujCel($pierwotna);

        if ($cel === null || (method_exists($cel, 'trashed') && $cel->trashed())) {
            throw new BladDlaCzlowieka(
                'Zgłoszonej treści już nie ma, więc nie da się wykonać nowej decyzji. '
                .'Jeśli nie ma nic więcej do zrobienia, wybierz „Podtrzymuję decyzję” i wyjaśnij to w uzasadnieniu.',
            );
        }

        if (in_array($nowa->akcja, [ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE], true)
            && ModeratedContent::jestZdjeta($cel)) {
            throw new BladDlaCzlowieka('Ta treść jest już ukryta albo usunięta. Wybierz inną decyzję albo podtrzymaj odmowę.');
        }

        $osoba = ModeratedContent::osoba($cel);
        $karaKonta = in_array($nowa->akcja, [ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN], true);

        if ($karaKonta && $osoba === null) {
            throw new BladDlaCzlowieka('Nie da się ustalić konta autora tej treści, więc nie ma kogo zawiesić ani zablokować.');
        }

        // Ta sama reguła rang co w `ModerationController::decide()` (#1408).
        if ($karaKonta && $moderator->cannot('sanctionAccount', $osoba)) {
            throw new BladDlaCzlowieka($osoba->isAdmin()
                ? 'Konta administratora nie da się zawiesić ani zablokować z panelu moderacji. '
                    .'Jeśli sprawa jest poważna, przekaż ją właścicielowi serwisu.'
                : 'Konto moderatora może zawiesić albo zablokować tylko administrator. '
                    .'Wybierz inną decyzję albo przekaż sprawę administratorowi.');
        }

        $stanPrzed = $cel->status ?? null;

        // Skutek PRZED zapisem decyzji: `suspend()`/`ban()` pisze do wiersza
        // konta, a `INSERT` decyzji bierze na nim `FOR KEY SHARE` — ta sama
        // kolejność co w reszcie ścieżek kar, bez odwróconych blokad.
        $this->wykonaj($cel, $osoba, $nowa);

        $decyzja = new ModerationAction([
            'moderator_id' => $moderator->getKey(),
            // NULL: decyzja po odwołaniu nie jest drugą decyzją pierwszej
            // instancji (`moderation_actions_one_per_report`).
            'report_id' => null,
            'target_type' => $pierwotna->target_type,
            'target_id' => $pierwotna->target_id,
            'subject_user_id' => $osoba?->getKey(),
            'action' => $nowa->akcja,
            'previous_status' => $stanPrzed,
            'reason_code' => $nowa->podstawa,
            'note' => 'Po uznaniu odwołania zgłaszającego od decyzji „'.$pierwotna->label().'”.',
            'user_message' => $nowa->wiadomoscDlaAutora,
        ]);
        // Poza `$fillable`: powiązanie ustala wyłącznie ta akcja.
        $decyzja->forceFill(['appeal_id' => $odwolanie->getKey()])->save();

        if ($osoba !== null) {
            $this->powiadom->handle(
                osoba: $osoba,
                decyzja: $nowa->akcja,
                wiadomoscModeratora: $nowa->wiadomoscDlaAutora,
                do: $nowa->terminZawieszenia,
                decyzjaModeracyjna: $decyzja,
            );
        }

        // Zgłoszenie okazało się zasadne. Tylko status: kto i kiedy
        // rozstrzygnął pierwszy raz, zostaje (retencja liczy od `resolved_at`).
        Report::query()
            ->whereKey($pierwotna->report_id)
            ->where('status', Report::STATUS_REJECTED)
            ->update(['status' => Report::STATUS_RESOLVED]);

        AuditLogEntry::record(
            action: 'moderation.after_appeal',
            actor: $moderator,
            subject: $decyzja,
            metadata: [
                'appeal_id' => (string) $odwolanie->getKey(),
                'original_action_id' => (string) $pierwotna->getKey(),
                'original_decision' => $pierwotna->action,
                'decision' => $nowa->akcja,
                'reason_code' => $nowa->podstawa,
                'suspend_until' => $nowa->terminZawieszenia?->toIso8601String(),
            ],
            ip: $ip,
        );

        return $decyzja;
    }

    /** Cel pod blokadą wiersza — dwie drogi do tej samej treści nie wykonają dwóch skutków. */
    private function zablokujCel(ModerationAction $pierwotna): ?Model
    {
        $cel = ModeratedContent::znajdz($pierwotna->target_type, $pierwotna->target_id, zUsunietymi: true);

        if (! $cel instanceof Model) {
            return null;
        }

        $zapytanie = $cel::query();

        if (method_exists($cel, 'trashed')) {
            $zapytanie->withTrashed();
        }

        return $zapytanie->whereKey($cel->getKey())->lockForUpdate()->first();
    }

    /** Ten sam skutek co `ModerationController::applyAction()` dla tej akcji. */
    private function wykonaj(Model $cel, ?User $osoba, NowaDecyzja $nowa): void
    {
        match ($nowa->akcja) {
            ModerationAction::ACTION_HIDE => ModeratedContent::daSieUkryc($cel)
                ? $cel->forceFill(['status' => ModeratedContent::UKRYTY[$cel::class]])->save()
                : null,
            // `remove` nie jest dozwolone dla celu `user` (macierz), więc tu
            // nie trafi konto — tylko miękkie usunięcie treści.
            ModerationAction::ACTION_REMOVE => $cel->delete(),
            ModerationAction::ACTION_SUSPEND => $osoba?->suspend($nowa->terminZawieszenia),
            ModerationAction::ACTION_BAN => $osoba?->ban(),
            // `warn` działa wyłącznie wiadomością do autora.
            default => null,
        };
    }
}
