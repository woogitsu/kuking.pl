<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('pages.notifications', ['notifications' => $notifications]);
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
