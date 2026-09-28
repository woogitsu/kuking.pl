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
    /**
     * @param  ?string  $dopisek  zdanie dołączane do uzasadnienia, gdy cofnięta
     *                            decyzja nie zdejmuje kary z konta, bo obowiązuje
     *                            późniejsza (#933) — cofnięcie decyzji to nie to
     *                            samo co odblokowanie konta i człowiek ma to wiedzieć
     */
    public function handle(Appeal $odwolanie, ?string $dopisek = null): Notification
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
                'message' => $dopisek === null
                    ? $odwolanie->decision_note
                    : $odwolanie->decision_note."\n\n".$dopisek,
                'decision' => 'appeal.'.$odwolanie->status,
                // Odwołanie od odwołania nie istnieje: wynik jest ostateczny
                // w ramach Kuking (MODERATION_PLAYBOOK §3 punkt 5).
                'appeal' => false,
                'appeal_id' => (string) $odwolanie->getKey(),
            ],
        ]);
    }
}
