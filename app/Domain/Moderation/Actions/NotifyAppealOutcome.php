<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Models\Appeal;
use App\Models\Notification;

/**
 * Odpowiedź na odwołanie — dostarczona człowiekowi, nie tylko zapisana.
 *
 * DLACZEGO TO JEST POWIADOMIENIE TYPU MODERACYJNEGO
 * `User::latestModerationMessage()` bierze OSTATNIE powiadomienie typu
 * `moderation.decision` i pokazuje je na ekranie logowania osobie
 * zablokowanej (`LoginController`, `EnsureAccountIsActive`). To jedyny kanał,
 * który zbanowany człowiek widzi — więc odpowiedź na jego odwołanie musi iść
 * tym samym typem. Inaczej odwołanie kończyłoby się wpisem w bazie, którego
 * adresat nigdy nie zobaczy, a to jest dokładnie ten stan, który zamykał
 * audyt A16.
 *
 * Bez gry słowem `kuKING` — D-009 zabrania jej w wiadomości moderacyjnej.
 */
final class NotifyAppealOutcome
{
    public function handle(Appeal $odwolanie): Notification
    {
        $utrzymana = $odwolanie->status === Appeal::STATUS_UPHELD;

        return Notification::create([
            'user_id' => $odwolanie->user_id,
            // Od serwisu, nie od człowieka — patrz NotifyModerationDecision.
            'actor_id' => null,
            'type' => Notification::TYPE_MODERATION,
            'data' => [
                'title' => $utrzymana
                    ? 'Sprawdziliśmy Twoje odwołanie. Podtrzymujemy decyzję.'
                    : 'Sprawdziliśmy Twoje odwołanie. Cofamy decyzję.',
                // Uzasadnienie napisane przez moderatora. DSA art. 20 wymaga
                // odpowiedzi z uzasadnieniem, nie samego wyniku — dlatego
                // pole jest w formularzu obowiązkowe i dlatego jest tutaj.
                'message' => $odwolanie->decision_note,
                'decision' => 'appeal.'.$odwolanie->status,
                // Odwołanie od odwołania nie istnieje: wynik jest ostateczny
                // w ramach Kuking (MODERATION_PLAYBOOK §3 punkt 5).
                'appeal' => false,
                'appeal_id' => (string) $odwolanie->getKey(),
            ],
        ]);
    }
}
