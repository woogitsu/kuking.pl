<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Domain\Contact\Actions\WyslijOdpowiedz;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // Zawsze z bazy, nigdy z `old()`: po konflikcie (#846) formularz
            // ma nieść wersję, którą człowiek właśnie zobaczył nad nim.
            'wersjaObslugi' => $wiadomosc->wersjaObslugi(),
        ]);
    }

    public function update(Request $request, ContactMessage $wiadomosc): RedirectResponse
    {
        $this->authorize('handle', $wiadomosc);

        $dane = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(ContactMessage::STATUSY))],
            'handler_note' => ['nullable', 'string', 'max:2000'],
            'wersja' => ['nullable', 'string', 'max:64'],
        ], [
            'status.required' => 'Wybierz stan wiadomości.',
            'status.in' => 'Wybierz stan wiadomości.',
            'handler_note.max' => 'Notatka jest za długa — zmieść się w 2000 znakach.',
        ]);

        $notatka = $dane['handler_note'] ?? null;
        $wersjaFormularza = $dane['wersja'] ?? null;

        $zapisana = DB::transaction(function () use ($wiadomosc, $dane, $notatka, $wersjaFormularza, $request): ?ContactMessage {
            // Porównanie POD blokadą (issue #846): dwie karty otwarte na tej
            // samej wersji szeregują się na `FOR UPDATE`, więc druga widzi
            // już to, co zapisała pierwsza — także gdy żądania przychodzą
            // kolejno, nie równocześnie. `null` = żądanie bez pola `wersja`
            // (karta sprzed tej poprawki) — nie ma czego porównywać.
            $locked = ContactMessage::query()->whereKey($wiadomosc->getKey())->lockForUpdate()->firstOrFail();

            if ($wersjaFormularza !== null && ! hash_equals($locked->wersjaObslugi(), $wersjaFormularza)) {
                // Podwójne kliknięcie „Zapisz": drugie żądanie niesie starą
                // wersję, ale chce dokładnie tego, co już jest w bazie. To
                // nie konflikt — sukces bez zapisu (ten sam wyjątek co
                // w `EditPost`, przegląd #981).
                if (hash_equals($locked->wersjaObslugi(), ContactMessage::odciskObslugi($dane['status'], $notatka))) {
                    return $locked;
                }

                return null;
            }

            // Notatka najpierw, stan potem. `oznaczJako()` zapisuje wiersz sam
            // (musi, bo CHECK w bazie wymaga kompletu `status` + `handled_by` +
            // `handled_at`), więc odwrotna kolejność gubiłaby notatkę przy
            // przejściu na „Nowa", które czyści ślad obsługi.
            $locked->forceFill(['handler_note' => $notatka])->save();
            $locked->oznaczJako($dane['status'], $request->user());

            return $locked;
        }, 3);

        if ($zapisana === null) {
            // Nic nie zapisano. Notatka z formularza wraca do pola, a stan
            // NIE — radio pokazuje stan z bazy, żeby ponowne „Zapisz" nie
            // cofało go przez przypadek. Osobny klucz `wersja`, nie
            // `handler_note`: notatka jest poprawna, więc pole nie dostaje
            // `aria-invalid`; odnośnik w podsumowaniu prowadzi do sekcji
            // z zapisanym stanem (`#stan-zapisany`).
            return redirect()
                ->route('admin.contact.show', $wiadomosc)
                ->withInput($request->only('handler_note'))
                ->withErrors(['wersja' => 'Ta wiadomość zmieniła się w innej karcie albo na innym '
                    .'urządzeniu, więc nic nie zapisaliśmy. Nad formularzem widzisz, jak jest zapisana '
                    .'teraz, a w polu „Notatka dla siebie” — Twój tekst. Popraw go, jeśli trzeba, '
                    .'wybierz stan i naciśnij „Zapisz” jeszcze raz.'])
                ->with('konflikt_stanu', true);
        }

        $wiadomosc = $zapisana;

        return redirect()
            ->route('admin.contact.show', $wiadomosc)
            ->with('status', 'Zapisano: '.$wiadomosc->statusLabel().'.');
    }

    /**
     * ODPOWIEDŹ POCZTĄ DO OSOBY, KTÓRA NAPISAŁA (D-058).
     *
     * Do 10 września 2026 ten ekran miał wyłącznie odnośnik `mailto:` i pole
     * „Notatka dla siebie". Odpisywało się więc z własnego programu poczty,
     * a w serwisie nie zostawał ŻADEN ślad, że odpowiedź poszła — poza tym,
     * co moderator sam sobie zapisał. Zgłoszenie właściciela brzmiało wprost:
     * „widzę je, przychodzą, ale jak mam odpisać?".
     *
     * OSOBNA TRASA, NIE DRUGIE POLE W `update()`. Te dwie rzeczy mają różne
     * skutki i różną odwracalność: zapis stanu i notatki da się poprawić
     * w każdej chwili, a wysłanego listu nie da się odwołać. Jeden formularz
     * znaczyłby, że poprawienie literówki w notatce wysyła drugi list —
     * albo że wysłanie listu wymaga jednoczesnego wybrania stanu.
     *
     * NIE ZMIENIAMY TU STANU WIADOMOŚCI, i to jest decyzja, nie oszczędność.
     * „Odpisałem" nie znaczy „załatwione": odpowiedź bywa pytaniem
     * dodatkowym („z jakiego telefonu Pani pisze?"), po którym sprawa jest
     * bardziej otwarta niż przedtem. Automatyczne przestawienie na
     * „Załatwiona" ruszyłoby przy okazji `handled_at`, czyli ZEGAR RETENCJI
     * (12 miesięcy, D-045) — dla wiadomości, której nikt nie zamknął. Ekran
     * mówi więc wprost, że stan zaznacza się osobno, niżej.
     */
    public function odpowiedz(Request $request, ContactMessage $wiadomosc): RedirectResponse
    {
        $this->authorize('reply', $wiadomosc);

        $dane = $request->validate([
            // 5000 znaków — tyle samo, co sama wiadomość. Odpowiedź na opis
            // awarii bywa dłuższa niż opis.
            'odpowiedz' => ['required', 'string', 'max:5000'],
        ], [
            'odpowiedz.required' => 'Napisz odpowiedź, zanim ją wyślesz.',
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

        $odpowiedz = $this->wyslij->handle(
            wiadomosc: $wiadomosc,
            moderator: $request->user(),
            tresc: $dane['odpowiedz'],
            ip: $request->ip(),
        );

        if (! $odpowiedz->wyszla()) {
            /*
             * NIE MELDUJEMY SUKCESU I NIE GUBIMY TEKSTU.
             *
             * `withInput()` jest tu połową funkcji, nie uprzejmością:
             * odpowiedź na wiadomość od człowieka pisze się kwadrans, a
             * awaria poczty nie jest niczyim błędem we formularzu
             * (docs/UX_50_PLUS.md — poprawnie wpisane dane nigdy nie
             * znikają). Sama treść leży już zapisana przy wiadomości ze
             * stanem „Nie udało się wysłać", więc nie przepada nawet wtedy,
             * gdy ktoś zamknie kartę.
             *
             * Komunikat mówi, CO ZROBIĆ, i podaje obie drogi: spróbować
             * jeszcze raz albo odpisać z własnej poczty na widoczny obok
             * adres.
             */
            return back()
                ->withInput()
                ->withErrors(['odpowiedz' => 'Nie udało się wysłać odpowiedzi — poczta serwisu '
                    .'odmówiła przyjęcia listu. Twój tekst jest zapisany przy wiadomości i został '
                    .'w polu. Spróbuj wysłać jeszcze raz; jeśli znów się nie uda, odpisz z własnej '
                    .'poczty na '.$adres.'. Powód odmowy jest wypisany niżej, w historii odpowiedzi.']);
        }

        return redirect()
            ->route('admin.contact.show', $wiadomosc)
            ->with('status', 'Odpowiedź wysłana na '.$adres.'.');
    }
}
