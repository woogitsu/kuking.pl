<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Zgody\PrzestawZgodeNaDigest;
use App\Http\Controllers\Controller;
use App\Models\WpisZgody;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrivacySettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('pages.settings.privacy', [
            'blocked' => $request->user()->blocking()->with('profile.avatar')->get(),
        ]);
    }

    public function update(Request $request, PrzestawZgodeNaDigest $zgoda): RedirectResponse
    {
        $request->validate([
            'wants_weekly_digest' => ['nullable', 'boolean'],
            'memories_enabled' => ['nullable', 'boolean'],
            'wants_weekly_digest_bylo' => ['nullable', 'boolean'],
            'memories_enabled_bylo' => ['nullable', 'boolean'],
        ]);

        $osoba = $request->user();

        // ZMIENIAMY TYLKO TO, CO CZŁOWIEK NAPRAWDĘ PRZESTAWIŁ (issue #880).
        //
        // Formularz niesie oba haczyki naraz. Stara karta, w której ktoś
        // odznaczył tylko wspomnienia, wysyła też dawny, zaznaczony haczyk
        // listu — a w międzyczasie ten człowiek mógł się wypisać odnośnikiem
        // z e-maila. Zapis „całego formularza" ponownie zapisałby go na list
        // i dopisał do dziennika zgodę, której nikt nie udzielił.
        //
        // Dlatego formularz odsyła też stan widziany przy otwarciu
        // (`*_bylo`). Haczyk równy temu stanowi = człowiek go nie ruszał,
        // więc zostaje to, co jest w bazie TERAZ. Haczyk różny = świadoma
        // decyzja z tego ekranu i ona wygrywa. Przy wartościach tak/nie nie
        // ma trzeciego wyniku: jeśli obie drogi coś zmieniły, zmieniły to
        // w tę samą stronę.
        //
        // Brak pól `*_bylo` (strona wyrenderowana przed tą zmianą, inny
        // klient) = dawny kontrakt: formularz mówi o obu ustawieniach.
        $zmienione = fn (string $pole): bool => ! $request->has($pole.'_bylo')
            || $request->boolean($pole) !== $request->boolean($pole.'_bylo');

        $pominieteNowsze = [];

        if ($zmienione('memories_enabled')) {
            $osoba->update([
                // Wspomnienia „Rok temu gotowałaś…" (issue #34). Wyłączenie musi
                // być JEDNYM przełącznikiem i musi działać od razu — człowiek,
                // któremu wspomnienia zaczęły sprawiać ból, nie ma odklikiwać ich
                // po kolei ani szukać tego ustawienia w trzecim menu.
                'memories_enabled' => $request->boolean('memories_enabled'),
            ]);
        } elseif ((bool) $osoba->memories_enabled !== $request->boolean('memories_enabled_bylo')) {
            $pominieteNowsze[] = $osoba->memories_enabled
                ? 'Wspomnienia zostały w międzyczasie włączone w innym miejscu — zostawiliśmy je włączone.'
                : 'Wspomnienia zostały w międzyczasie wyłączone w innym miejscu — zostawiliśmy je wyłączone.';
        }

        // ZGODA NA POCZTĘ IDZIE OSOBNO, PRZEZ KLASĘ DOMENOWĄ (D-072), a nie
        // razem z resztą w jednym `update()`.
        //
        // Wspomnienia są PREFERENCJĄ wyświetlania — wolno ją nadpisać
        // i zapomnieć poprzedni stan. Zgoda na tygodniowy digest jest
        // PODSTAWĄ PRAWNĄ wysyłki (art. 6 ust. 1 lit. a RODO), a art. 7
        // ust. 1 każe każdą jej zmianę umieć WYKAZAĆ — więc przechodzi przez
        // jedno miejsce, które przy realnej zmianie dopisuje wiersz do
        // `dziennik_zgod`. Zapis formularza BEZ ruszenia haczyka (ktoś zmienił
        // tylko wspomnienia) nie woła jej wcale, patrz wyżej (#880).
        if ($zmienione('wants_weekly_digest')) {
            $zgoda->handle(
                $osoba,
                $request->boolean('wants_weekly_digest'),
                WpisZgody::ZRODLO_USTAWIENIA,
            );
        } elseif ((bool) $osoba->wants_weekly_digest !== $request->boolean('wants_weekly_digest_bylo')) {
            $pominieteNowsze[] = $osoba->wants_weekly_digest
                ? 'Tygodniowy e-mail został w międzyczasie włączony w innym miejscu — zostawiliśmy go włączonego.'
                : 'Tygodniowy e-mail został w międzyczasie wyłączony w innym miejscu — zostawiliśmy go wyłączonego. Jeśli chcesz go znów dostawać, zaznacz pole poniżej i zapisz.';
        }

        if ($pominieteNowsze !== []) {
            return back()->with('status', 'Zapisane. '.implode(' ', $pominieteNowsze));
        }

        return back()->with('status', 'Zapisane.');
    }
}
