<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Digest\OdnosnikWypisania;
use App\Models\User;
use Illuminate\View\View;

/**
 * Wypisanie się z tygodniowego podsumowania — BEZ LOGOWANIA (issue #11 pkt 6).
 *
 * DWIE TRASY, DWA KIERUNKI, JEDNA ZASADA: JEDNO KLIKNIĘCIE.
 * Wejście na podpisany odnośnik ze stopki listu **od razu wyłącza wysyłkę**.
 * Bez formularza, bez pytania „na pewno?" i bez ankiety „dlaczego" — issue
 * mówi o tym wprost, a `docs/UX_50_PLUS.md` dokłada powód: każdy dodatkowy
 * ekran między człowiekiem a wyjściem jest ekranem, na którym część ludzi
 * kliknie zamiast tego „to jest spam". Zgłoszenie spamu psuje dostarczalność
 * CAŁEJ poczty Kuking, łącznie z resetami haseł
 * (`docs/decyzje/POCZTA.md` §3) — wyjście musi być łatwiejsze niż donos.
 *
 * DLACZEGO WIĘC ISTNIEJE DRUGA TRASA („jednak chcę")
 * Bo pierwsza działa też na `GET`, a `GET` bywa wywołany bez udziału
 * człowieka: filtry antyspamowe i skanery odnośników w firmowej poczcie
 * otwierają linki z treści, żeby je sprawdzić. Taki skaner potrafi wypisać
 * kogoś bez jego wiedzy. Ekran po wypisaniu ma więc przycisk powrotny —
 * jeden, duży, na tej samej stronie — żeby naprawa też była jednym
 * kliknięciem, a nie wyprawą do ustawień przez przypomnienie hasła.
 *
 * Odwrotna kolejność (najpierw zapytaj, potem wypisz) byłaby wyborem,
 * w którym pomyłka skanera kosztuje mniej, a pomyłka człowieka więcej.
 * Wybieramy odwrotnie, bo to człowiek jest tu stroną, która nie może
 * przegrać.
 *
 * DROGA POWROTNA NIE JEST NARAŻONA NA TEN SAM SKANER, choć też działa na
 * `GET`: jej odnośnik nie istnieje w żadnym liście, tylko na ekranie
 * potwierdzenia. Skaner poczty nie ma go skąd wziąć.
 *
 * OBIE TRASY PRZYJMUJĄ TAKŻE `POST` (patrz `routes/web.php`) po to, żeby list
 * mógł nieść nagłówki `List-Unsubscribe` i `List-Unsubscribe-Post`
 * (RFC 8058). Wtedy Gmail i Outlook pokazują własny przycisk „wypisz się"
 * przy nadawcy i wołają ten adres metodą POST, bez otwierania przeglądarki.
 * To jest najkrótsza droga wyjścia, jaka w ogóle istnieje, i jednocześnie
 * najmocniejszy sygnał dla filtrów, że ta poczta jest w porządku.
 */
class PodsumowanieTygodniaController extends Controller
{
    public function wypisz(User $user, ZapiszSygnal $sygnal): View
    {
        $bylZapisany = (bool) $user->wants_weekly_digest;

        // `forceFill()`, bo zgoda jest tu wycofywana BEZ formularza i bez
        // sesji — nie ma czego walidować, jest jedno pole i jedna wartość.
        //
        // BEZ WARUNKU NA STATUS KONTA, świadomie: zgodę wolno wycofać zawsze
        // i natychmiast (RODO art. 7 ust. 3). Konto zawieszone albo zgłoszone
        // do usunięcia i tak by listu nie dostało (`OdbiorcyDigestu`), ale
        // odmowa wypisania z powodu stanu konta byłaby odmową wykonania
        // prawa, a nie zabezpieczeniem.
        $user->forceFill(['wants_weekly_digest' => false])->save();

        if ($bylZapisany) {
            // Sygnał TYLKO przy realnej zmianie. Drugie wejście na ten sam
            // odnośnik (odświeżenie strony, skaner poczty) nie jest drugim
            // wypisaniem, a próg „wypisy > 1% na wysyłkę"
            // (`docs/product/RETENTION_LOOPS.md` §6 wiersz 5) liczy ludzi,
            // nie kliknięcia.
            $sygnal->handle($user, ZapiszSygnal::WEEKLY_DIGEST_UNSUBSCRIBED);
        }

        return view('pages.podsumowanie-wypisano', [
            'powrot' => OdnosnikWypisania::powrotDla($user),
        ]);
    }

    public function wracam(User $user): View
    {
        $user->forceFill(['wants_weekly_digest' => true])->save();

        return view('pages.podsumowanie-wrocono', [
            'wypisz' => OdnosnikWypisania::dla($user),
        ]);
    }
}
