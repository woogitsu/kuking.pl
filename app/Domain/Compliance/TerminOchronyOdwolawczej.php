<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use Carbon\CarbonInterface;

/**
 * Termin ochrony odwoławczej powiadomienia (issue #19, ADR §5.2/§5.6) —
 * wydzielony z modelu `Notification` w etapie 3 issue #1687, bez zmiany
 * zachowania. Czyta go wyłącznie retencja (`PrzedawnionePowiadomienia`),
 * więc mieszka obok niej. KTÓRE typy mają własny termin, dalej mówi
 * `Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA` — to jest
 * klasyfikacja typu powiadomienia i zostaje przy modelu.
 */
final class TerminOchronyOdwolawczej
{
    /**
     * Termin, do którego retencja (issue #19, ADR §5.2/§5.6) NIE MOŻE
     * skasować tego powiadomienia — wyłącznie dla typów z
     * `Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`. `null` dla pozostałych
     * typów znaczy „brak wydłużenia — obowiązuje ogólny okres wprost",
     * NIE „można skasować natychmiast".
     *
     * `null` wraca też, gdy powiązanej decyzji moderacyjnej nie da się
     * ustalić (odniesienie puste albo wiersz już nie istnieje) — retencja
     * (`PrzedawnionePowiadomienia`) świadomie NIE zgaduje w tej sytuacji:
     * traktuje `null` jak "nie wiadomo, więc nie kasujemy w tym przebiegu",
     * dokładnie tak samo, jak błąd kasowania w `PrzedawnioneSprawyModeracyjne`
     * nie może "zgadywać", że się udało.
     */
    public function dla(Notification $powiadomienie): ?CarbonInterface
    {
        if (! in_array($powiadomienie->type, Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA, true)) {
            return null;
        }

        return $this->decyzjaModeracyjna($powiadomienie)?->appealDeadline();
    }

    /**
     * `ModerationAction`, z którą to powiadomienie jest związane — przez
     * `data.action_id` wprost (`NotifyModerationDecision`) albo przez
     * `data.appeal_id` → `Appeal::moderationAction()` (`NotifyAppealOutcome`).
     * Patrz komentarz `Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA` po pełne
     * uzasadnienie obu ścieżek.
     */
    private function decyzjaModeracyjna(Notification $powiadomienie): ?ModerationAction
    {
        $akcjaId = $powiadomienie->data['action_id'] ?? null;

        if (is_string($akcjaId) && $akcjaId !== '') {
            return ModerationAction::find($akcjaId);
        }

        $odwolanieId = $powiadomienie->data['appeal_id'] ?? null;

        if (is_string($odwolanieId) && $odwolanieId !== '') {
            return Appeal::find($odwolanieId)?->moderationAction;
        }

        return null;
    }
}
