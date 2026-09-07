<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ModerationAction;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // `visibleTo` wycina powiadomienia od osób, z którymi łączy tę osobę
        // blokada — także te sprzed blokady. Ten sam filtr chodzi w liczniku
        // nieprzeczytanych (`User::unreadNotificationsCount()`); gdyby chodził
        // tylko tutaj, w belce świeciłoby „3 nieprzeczytane" nad pustą listą.
        $notifications = $user
            ->notifications()
            ->visibleTo($user)
            ->with('actor.profile.avatar')
            ->paginate(30);

        return view('pages.notifications', [
            'notifications' => $notifications,
            'decyzjeModeracyjne' => $this->decyzje($notifications->items()),
        ]);
    }

    /**
     * Decyzje moderacyjne dla powiadomień z tej strony — JEDNYM zapytaniem.
     *
     * Uzasadnienie z art. 17 ust. 3 (`UzasadnienieDecyzji`) powstaje przy
     * WYŚWIETLANIU, nie przy zapisie, bo termin na odwołanie liczy
     * `ModerationAction::appealDeadline()` i zamrożenie daty w `data`
     * pokazywałoby po zmianie konfiguracji termin krótszy niż prawdziwy.
     * Wczytanie w widoku (`$decyzja->appeal`) dałoby jednak N+1 na stronie
     * z trzydziestoma powiadomieniami, więc identyfikatory zbieramy tutaj.
     *
     * @param  list<Notification>  $powiadomienia
     * @return Collection<string, ModerationAction>
     */
    private function decyzje(array $powiadomienia): Collection
    {
        $identyfikatory = [];

        foreach ($powiadomienia as $powiadomienie) {
            if ($powiadomienie->type !== Notification::TYPE_MODERATION) {
                continue;
            }

            $id = $powiadomienie->data['action_id'] ?? null;

            if (is_string($id) && $id !== '') {
                $identyfikatory[$id] = true;
            }
        }

        if ($identyfikatory === []) {
            return new Collection;
        }

        return ModerationAction::query()
            ->whereIn('id', array_keys($identyfikatory))
            ->get()
            ->keyBy(fn (ModerationAction $decyzja): string => (string) $decyzja->getKey());
    }

    /**
     * Oznaczenie wszystkiego jako przeczytane jest JAWNYM kliknięciem,
     * nie efektem ubocznym wejścia na stronę. Osoba, która przypadkiem
     * weszła w powiadomienia, nie może stracić informacji o tym, że
     * ktoś ugotował z jej przepisu.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'Wszystkie powiadomienia oznaczone jako przeczytane.');
    }
}
