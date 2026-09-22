<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Domain\Contact\Actions\UpdateContactMessage;
use App\Domain\Contact\Actions\WyslijOdpowiedz;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
    public function __construct(
        private readonly WyslijOdpowiedz $wyslij,
        private readonly PrzedawnioneWiadomosciDoOperatora $retencja,
    ) {}

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

        foreach ($wiadomosc->odpowiedzi()->whereNull('audit_recorded_at')->get() as $reply) {
            $this->wyslij->finishAudit($reply, $wiadomosc);
        }

        return view('pages.admin.wiadomosc', [
            // `odpowiedzi.author.profile` doładowane RAZEM z resztą, nie
            // w widoku: bez tego każda odpowiedź w historii dokładałaby
            // własne zapytanie o nazwę moderatora.
            'wiadomosc' => $wiadomosc->load([
                'author.profile',
                'handler.profile',
                'odpowiedzi.author.profile',
            ]),
            // `null`, gdy sprawa jest otwarta — patrz
            // `PrzedawnioneWiadomosciDoOperatora::terminUsuniecia()` (#847).
            'terminUsuniecia' => $this->retencja->terminUsuniecia($wiadomosc),
        ]);
    }

    public function update(Request $request, ContactMessage $wiadomosc): RedirectResponse
    {
        $this->authorize('handle', $wiadomosc);

        $dane = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(ContactMessage::STATUSY))],
            'handler_note' => ['nullable', 'string', 'max:2000'],
        ], [
            'version.required' => 'Otwórz ponownie kartę wiadomości przed zapisem. Zachowaj wpisaną notatkę.',
            'version.integer' => 'Otwórz ponownie kartę wiadomości przed zapisem.',
            'version.min' => 'Otwórz ponownie kartę wiadomości przed zapisem.',
            'status.required' => 'Wybierz stan wiadomości.',
            'status.in' => 'Wybierz stan wiadomości.',
            'handler_note.max' => 'Notatka jest za długa — zmieść się w 2000 znakach.',
        ]);

        try {
            app(UpdateContactMessage::class)->handle(
                $wiadomosc, $request->user(), (int) $dane['version'], $dane['status'], $dane['handler_note'] ?? null,
            );
        } catch (ValidationException $conflict) {
            return back()->withInput()->withErrors($conflict->errors());
        }

        return redirect()
            ->route('admin.contact.show', $wiadomosc)
            ->withInput($request->only(['odpowiedz', 'reply_key']))
            ->with('status', 'Zapisano: '.$wiadomosc->statusLabel().'.');
    }

    /** Wysyłka ma osobny endpoint; wspólny formularz zachowuje sąsiedni szkic. */
    public function odpowiedz(Request $request, ContactMessage $wiadomosc): RedirectResponse
    {
        $this->authorize('reply', $wiadomosc);

        $dane = $request->validate([
            // 5000 znaków — tyle samo, co sama wiadomość. Odpowiedź na opis
            // awarii bywa dłuższa niż opis.
            'odpowiedz' => ['required', 'string', 'max:5000'],
            'reply_key' => ['required', 'uuid'],
        ], [
            'odpowiedz.required' => 'Napisz odpowiedź, zanim ją wyślesz.',
            'reply_key.required' => 'Otwórz ponownie kartę wiadomości przed wysłaniem. Zachowaj tekst odpowiedzi.',
            'reply_key.uuid' => 'Otwórz ponownie kartę wiadomości przed wysłaniem. Zachowaj tekst odpowiedzi.',
            'odpowiedz.max' => 'Odpowiedź jest za długa — zmieść się w 5000 znakach.',
        ]);

        $adres = $wiadomosc->adresDoOdpowiedzi();

        if ($adres === null) {
            // Widok nie pokazuje w tej sytuacji formularza, więc tutaj
            // dochodzi się wyłącznie żądaniem złożonym poza ekranem albo
            // z karty otwartej przed anonimizacją konta autora. Komunikat
            // i tak mówi, co się stało, a wpisana treść zostaje w polu.
            return back()
                ->withInput()
                ->withErrors(['odpowiedz' => 'Ta osoba nie zostawiła adresu e-mail, więc nie ma jak '
                    .'wysłać jej odpowiedzi. Jeśli sprawa jest do zamknięcia, oznacz wiadomość jako '
                    .'załatwioną i zapisz w notatce, co ustalono.']);
        }

        try {
            $odpowiedz = $this->wyslij->handle(
                wiadomosc: $wiadomosc,
                moderator: $request->user(),
                tresc: $dane['odpowiedz'],
                ip: $request->ip(),
                replyKey: $dane['reply_key'],
            );
        } catch (ValidationException $conflict) {
            return back()->withInput()->withErrors($conflict->errors());
        } catch (\Throwable) {
            // Wyjątek bazy może zawierać treść listu i adres. Nie logujemy go.
            Log::error('Sprawdź zapis wyniku odpowiedzi w panelu wiadomości.', ['message_id' => $wiadomosc->getKey()]);

            return back()->withInput()->withErrors([
                'odpowiedz' => 'Nie można potwierdzić zapisu wyniku. Zachowaj tekst i sprawdź historię odpowiedzi oraz panel dostawcy poczty. Ponowienie tego formularza nie wyśle rozpoczętej odpowiedzi drugi raz.',
            ]);
        }

        if ($odpowiedz->status === ContactMessageReply::STATUS_W_TOKU) {
            return back()->withInput()->withErrors([
                'odpowiedz' => 'Nie wiadomo, czy odpowiedź wyszła. Twój tekst pozostał w polu. Sprawdź panel dostawcy poczty przed rozpoczęciem nowej odpowiedzi. Ponowienie tego formularza nie wyśle listu drugi raz.',
            ]);
        }

        if (! $odpowiedz->wyszla()) {
            return back()
                ->withInput()
                ->withErrors(['odpowiedz' => 'Nie udało się wysłać odpowiedzi — poczta serwisu '
                    .'odmówiła przyjęcia listu. Twój tekst jest zapisany przy wiadomości i został '
                    .'w polu. Wybierz „Wyślij jako nową odpowiedź”; jeśli znów się nie uda, odpisz z własnej '
                    .'poczty na '.$adres.'. Powód odmowy jest wypisany niżej, w historii odpowiedzi.']);
        }

        return redirect()
            ->route('admin.contact.show', $wiadomosc)
            ->withInput($request->only(['handler_note', 'status', 'version']))
            ->with('status', 'Odpowiedź wysłana na '.$adres.'.');
    }
}
