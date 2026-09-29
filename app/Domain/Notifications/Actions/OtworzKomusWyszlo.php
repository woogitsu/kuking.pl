<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\User;

/**
 * Rozstrzyga, czy ekran „Komuś wyszło" (issue #17) ma się pokazać, i oznacza
 * powiadomienie jako przeczytane — wyjęte z `CookedEventController::celebrate()`
 * bez zmiany zachowania (issue #970).
 *
 * Pokazuje się raz na wykonanie. Zamiast nowej kolumny „już pokazano"
 * pożyczamy stan z `notifications.read_at` — druga flaga byłaby drugim
 * źródłem prawdy dla tego samego faktu. Powiadomienia może nie być
 * (skasowane porządkami albo stary link sprzed tej funkcji) — wtedy to
 * pierwsze wejście: ekran się pokazuje, nie ma czego oznaczać.
 */
final class OtworzKomusWyszlo
{
    /**
     * @param  mixed  $znacznikPierwszegoOtwarcia  wartość flasha
     *                                             `Notification::SESJA_PIERWSZE_OTWARCIE` z sesji
     * @return bool true = pokaż ekran, false = przekieruj na kartę wykonania
     */
    public function handle(CookedEvent $wykonanie, User $user, mixed $znacznikPierwszegoOtwarcia): bool
    {
        $notification = Notification::query()
            ->where('user_id', $user->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->where('data->cooked_event_id', $wykonanie->getKey())
            ->first();

        // ISSUE #770: „Zobacz" z listy ustawia `read_at` PRZED tym ekranem
        // (`NotificationController::open()`), więc samo `read_at` nie mówi,
        // czy ekran był już pokazany. Flash z tego jednego kliknięcia mówi:
        // „to pierwsze otwarcie". Po odświeżeniu flasha już nie ma, a `read_at`
        // stoi — i ekran pokazuje się raz, jak dotąd.
        $pierwszeOtwarcie = $notification !== null
            && $znacznikPierwszegoOtwarcia === (string) $notification->getKey();

        if ($notification !== null && $notification->isUnread() === false && ! $pierwszeOtwarcie) {
            return false;
        }

        // `read_at` CELOWO nie jest w `$fillable` Notification, więc
        // `$model->update()` po cichu by je zgubił. Zapis idzie zapytaniem
        // z `whereNull('read_at')` w samym `UPDATE` (D-079, jak
        // w `NotificationController::open()`): przy pierwszym otwarciu z listy
        // znacznik już stoi i nie wolno go przesuwać w przód — od niego
        // liczy się retencja.
        if ($notification !== null) {
            Notification::query()
                ->whereKey($notification->getKey())
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return true;
    }
}
