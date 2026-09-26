<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Wspoldzielenie\DostepDoZeszytu;
use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Wspólny zeszyt — zaproszenia i dostęp (#1743, D-302).
 *
 * Kontroler jest cienki: reguły żyją w `App\Domain\Collections\Wspoldzielenie`
 * i w `CollectionPolicy`. Pięć pytań z AGENTS.md §7 dla każdej trasy:
 * `auth` (grupa tras), Policy albo zakres przypięty do zalogowanej osoby,
 * walidacja z polskim zdaniem, limit `zaproszenia`, a ślad — w samych
 * wierszach zaproszeń (kto, kiedy, jak odpowiedział).
 *
 * UUID ZAPROSZENIA W ADRESIE NIE JEST AUTORYZACJĄ. Zaproszenie po nazwie
 * konta otwiera się wyłącznie adresatowi; każdy inny — także zalogowany —
 * dostaje 404, jakby go nie było.
 */
class CollectionSharingController extends Controller
{
    /** Ekran właściciela: kto ma dostęp, zaproszenia, nowy link. */
    public function show(Request $request, Collection $collection): View
    {
        abort_unless($request->user()->getKey() === $collection->owner_id, 403);

        return view('pages.collections.wspolny', [
            'collection' => $collection,
            'mozeZapraszac' => $request->user()->can('share', $collection),
            'czlonkowie' => $collection->members()->with('profile')->get(),
            'zaproszenia' => $collection->invitations()
                ->where('status', CollectionInvitation::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->with('invitee.profile')
                ->orderBy('created_at')
                ->get(),
            'limit' => (int) config('kuking.collections.max_members'),
        ]);
    }

    public function invite(Request $request, Collection $collection, ZaprosDoZeszytu $akcja): RedirectResponse
    {
        $this->authorize('share', $collection);

        $dane = $request->validate([
            'nazwa' => ['required', 'string', 'max:60'],
        ], [
            'nazwa.required' => 'Wpisz nazwę konta osoby, którą zapraszasz — jest na jej profilu, po znaku @.',
            'nazwa.max' => 'Nazwa konta jest krótsza. Sprawdź ją na profilu tej osoby i wpisz jeszcze raz.',
        ]);

        try {
            $zaproszenie = $akcja->poNazwie($request->user(), $collection, $dane['nazwa']);
        } catch (BladDlaCzlowieka $e) {
            return back()->withInput()->withErrors(['nazwa' => $e->getMessage()]);
        }

        $kto = $zaproszenie->invitee?->displayName() ?? 'Ta osoba';

        return redirect()->route('collections.sharing', $collection)
            ->with('status', "Zaproszenie wysłane. {$kto} zobaczy je w powiadomieniach i na stronie „Moje”.");
    }

    public function createLink(Request $request, Collection $collection, ZaprosDoZeszytu $akcja): RedirectResponse
    {
        $this->authorize('share', $collection);

        try {
            [, $token] = $akcja->linkiem($request->user(), $collection);
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['link' => $e->getMessage()]);
        }

        // Jawny token istnieje tylko w tej jednej odpowiedzi — w bazie jest
        // jego skrót. Sesja trzyma go do następnego wyświetlenia strony.
        return redirect()->route('collections.sharing', $collection)
            ->with('link_zaproszenia', route('collections.link.show', $token))
            ->with('status', 'Link-zaproszenie jest gotowy. Skopiuj go i wyślij jednej osobie.');
    }

    public function revoke(Request $request, Collection $collection, CollectionInvitation $invitation, DostepDoZeszytu $akcja): RedirectResponse
    {
        abort_unless($invitation->collection_id === $collection->getKey(), 404);

        try {
            $akcja->odwolaj($request->user(), $invitation);
        } catch (BladDlaCzlowieka) {
            abort(403);
        }

        return redirect()->route('collections.sharing', $collection)
            ->with('status', $invitation->jestLinkiem()
                ? 'Link odwołany. Nikt już z niego nie dołączy.'
                : 'Zaproszenie odwołane.');
    }

    public function removeMember(Request $request, Collection $collection, string $member, DostepDoZeszytu $akcja): RedirectResponse
    {
        abort_unless(Str::isUuid($member), 404);

        $czlonek = $collection->members()->whereKey($member)->first();

        // Nieznany i nie-członek dają to samo: nic tu nie ma do odebrania.
        abort_if($czlonek === null, 404);

        try {
            $akcja->odbierz($request->user(), $collection, $czlonek);
        } catch (BladDlaCzlowieka) {
            abort(403);
        }

        return redirect()->route('collections.sharing', $collection)
            ->with('status', "Dostęp odebrany. {$czlonek->displayName()} nie widzi już tego zeszytu. To, co dodała ta osoba, zostaje w zeszycie.");
    }

    public function leave(Request $request, Collection $collection, DostepDoZeszytu $akcja): RedirectResponse
    {
        $akcja->odejdz($request->user(), $collection);

        return redirect()->route('collections.index')
            ->with('status', "Nie masz już dostępu do zeszytu „{$collection->name}”. Wszystko, co w nim zapisano, zostaje u właściciela.");
    }

    /** Zaproszenie po nazwie konta — widzi je tylko adresat. */
    public function showInvitation(Request $request, CollectionInvitation $invitation): View
    {
        $this->tylkoAdresat($request->user(), $invitation);

        return view('pages.collections.zaproszenie', $this->daneZaproszenia($request->user(), $invitation, [
            'przyjmij' => route('collections.invitations.accept', $invitation),
            'odrzuc' => route('collections.invitations.decline', $invitation),
        ]));
    }

    public function acceptInvitation(Request $request, CollectionInvitation $invitation, OdpowiedzNaZaproszenie $akcja): RedirectResponse
    {
        $this->tylkoAdresat($request->user(), $invitation);

        return $this->przyjmij($request->user(), $invitation, $akcja);
    }

    public function declineInvitation(Request $request, CollectionInvitation $invitation, OdpowiedzNaZaproszenie $akcja): RedirectResponse
    {
        $this->tylkoAdresat($request->user(), $invitation);

        return $this->odrzuc($request->user(), $invitation, $akcja);
    }

    /** Link-zaproszenie — każdy zalogowany, kto ma link, dokładnie raz. */
    public function showLink(Request $request, string $token): View|Response
    {
        $zaproszenie = $this->poTokenie($token);

        if ($zaproszenie === null) {
            return response()->view('pages.collections.zaproszenie-nieaktualne', [], 410);
        }

        return view('pages.collections.zaproszenie', $this->daneZaproszenia($request->user(), $zaproszenie, [
            'przyjmij' => route('collections.link.accept', $token),
            'odrzuc' => route('collections.link.decline', $token),
        ]));
    }

    public function acceptLink(Request $request, string $token, OdpowiedzNaZaproszenie $akcja): RedirectResponse|Response
    {
        $zaproszenie = $this->poTokenie($token);

        if ($zaproszenie === null) {
            return response()->view('pages.collections.zaproszenie-nieaktualne', [], 410);
        }

        return $this->przyjmij($request->user(), $zaproszenie, $akcja);
    }

    public function declineLink(Request $request, string $token, OdpowiedzNaZaproszenie $akcja): RedirectResponse|Response
    {
        $zaproszenie = $this->poTokenie($token);

        if ($zaproszenie === null) {
            return response()->view('pages.collections.zaproszenie-nieaktualne', [], 410);
        }

        return $this->odrzuc($request->user(), $zaproszenie, $akcja);
    }

    private function przyjmij(User $user, CollectionInvitation $zaproszenie, OdpowiedzNaZaproszenie $akcja): RedirectResponse
    {
        try {
            $zeszyt = $akcja->przyjmij($user, $zaproszenie);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('collections.index')->withErrors(['zaproszenie' => $e->getMessage()]);
        }

        return redirect()->route('collections.show', $zeszyt)
            ->with('status', "Masz dostęp do zeszytu „{$zeszyt->name}”. Możesz w nim zapisywać przepisy i wpisy.");
    }

    private function odrzuc(User $user, CollectionInvitation $zaproszenie, OdpowiedzNaZaproszenie $akcja): RedirectResponse
    {
        try {
            $akcja->odrzuc($user, $zaproszenie);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('collections.index')->withErrors(['zaproszenie' => $e->getMessage()]);
        }

        return redirect()->route('collections.index')->with('status', 'Zaproszenie odrzucone. Nikt nie dostanie o tym powiadomienia.');
    }

    private function tylkoAdresat(User $user, CollectionInvitation $zaproszenie): void
    {
        abort_unless($zaproszenie->invitee_id === $user->getKey(), 404);
    }

    /**
     * Zaproszenie z linku — tylko oczekujące i nieprzeterminowane. Zły,
     * zużyty i odwołany token dają ten sam wynik: nie mówimy, który.
     */
    private function poTokenie(string $token): ?CollectionInvitation
    {
        if (mb_strlen($token) !== 40) {
            return null;
        }

        $zaproszenie = CollectionInvitation::query()
            ->where('token_hash', CollectionInvitation::skrotTokenu($token))
            ->with('collection.owner.profile')
            ->first();

        return $zaproszenie !== null && $zaproszenie->czeka() ? $zaproszenie : null;
    }

    /**
     * @param  array{przyjmij: string, odrzuc: string}  $akcje
     * @return array<string, mixed>
     */
    private function daneZaproszenia(User $user, CollectionInvitation $zaproszenie, array $akcje): array
    {
        $zeszyt = $zaproszenie->collection;

        return [
            'zaproszenie' => $zaproszenie,
            'zeszyt' => $zeszyt,
            'wlasciciel' => $zeszyt?->owner,
            'czeka' => $zaproszenie->czeka() && $zeszyt !== null,
            'jestWlascicielem' => $zeszyt?->owner_id === $user->getKey(),
            'maJuzDostep' => $zeszyt !== null && $zeszyt->maCzlonka($user),
            ...$akcje,
        ];
    }
}
