<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kolejka wiadomości z „Napisz do nas" — OSOBNA od kolejki zgłoszeń.
 *
 * DLACZEGO OSOBNY EKRAN, A NIE ZAKŁADKA W `/admin/zgloszenia`
 * Bo to są dwie różne prace, wykonywane w innym rytmie i z innym skutkiem.
 * Zgłoszenie treści kończy się DECYZJĄ, od której druga strona ma prawo się
 * odwołać (DSA art. 20), więc ma uzasadnienie, powiadomienie i termin.
 * Wiadomość „nie działa mi przycisk" kończy się poprawką w kodzie albo
 * odpisaniem po ludzku — i nie ma w niej niczego, od czego dałoby się
 * odwołać. Zmieszanie ich w jednej liście znaczyłoby, że najważniejsza
 * kolejka w serwisie (moderacja) zapycha się rzeczami, które nie są sprawami.
 *
 * KAŻDA METODA PYTA POLITYKĘ, mimo że cała grupa tras stoi już za
 * `auth` + `moderator` + `moderator.2fa`. UUID w adresie nie jest
 * autoryzacją (AGENTS.md §7), a middleware pilnuje wejścia do panelu, nie
 * prawa do wiersza — patrz `App\Policies\ContactMessagePolicy`.
 */
class WiadomosciController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ContactMessage::class);

        $status = $request->query('status', ContactMessage::STATUS_NOWA);
        $znane = array_keys(ContactMessage::STATUSY);

        if (! in_array($status, [...$znane, 'wszystkie'], true)) {
            $status = ContactMessage::STATUS_NOWA;
        }

        $wiadomosci = ContactMessage::query()
            ->when($status !== 'wszystkie', fn ($query) => $query->where('status', $status))
            ->with(['author.profile', 'handler.profile'])
            // NAJSTARSZE NA GÓRZE, odwrotnie niż w kolejce zgłoszeń.
            //
            // Tam liczy się „co się właśnie dzieje" (fala spamu). Tutaj
            // liczy się, żeby nikt nie czekał dłużej niż inni — a przy
            // jednoosobowej obsłudze wystarczy jedna lista posortowana od
            // najstarszej, żeby to samo z siebie działało. `id` jako drugi
            // warunek porządku: UUID v7 rozstrzyga remis na sekundzie w tę
            // samą stronę co czas, więc podział na strony jest stabilny
            // między kliknięciami (ta sama pułapka co w `ModerationController`).
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('pages.admin.wiadomosci', [
            'status' => $status,
            'wiadomosci' => $wiadomosci,
            'liczniki' => [
                ContactMessage::STATUS_NOWA => ContactMessage::where('status', ContactMessage::STATUS_NOWA)->count(),
                ContactMessage::STATUS_W_TOKU => ContactMessage::where('status', ContactMessage::STATUS_W_TOKU)->count(),
                ContactMessage::STATUS_ZALATWIONA => ContactMessage::where('status', ContactMessage::STATUS_ZALATWIONA)->count(),
            ],
        ]);
    }

    public function show(ContactMessage $wiadomosc): View
    {
        $this->authorize('view', $wiadomosc);

        return view('pages.admin.wiadomosc', [
            'wiadomosc' => $wiadomosc->load(['author.profile', 'handler.profile']),
        ]);
    }

    public function update(Request $request, ContactMessage $wiadomosc): RedirectResponse
    {
        $this->authorize('handle', $wiadomosc);

        $dane = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(ContactMessage::STATUSY))],
            'handler_note' => ['nullable', 'string', 'max:2000'],
        ], [
            'status.required' => 'Wybierz stan wiadomości.',
            'status.in' => 'Wybierz stan wiadomości.',
            'handler_note.max' => 'Notatka jest za długa — zmieść się w 2000 znakach.',
        ]);

        // Notatka najpierw, stan potem. `oznaczJako()` zapisuje wiersz sam
        // (musi, bo CHECK w bazie wymaga kompletu `status` + `handled_by` +
        // `handled_at`), więc odwrotna kolejność gubiłaby notatkę przy
        // przejściu na „Nowa", które czyści ślad obsługi.
        $wiadomosc->forceFill(['handler_note' => $dane['handler_note'] ?? null])->save();
        $wiadomosc->oznaczJako($dane['status'], $request->user());

        return redirect()
            ->route('admin.contact.show', $wiadomosc)
            ->with('status', 'Zapisano: '.$wiadomosc->statusLabel().'.');
    }
}
