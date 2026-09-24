<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Digest\OdnosnikWypisania;
use App\Domain\Zgody\PrzestawZgodeNaDigest;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Http\Request;
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
 * DROGA POWROTNA WŁĄCZA ZGODĘ WYŁĄCZNIE METODĄ `POST` (#1403). Do 23 września
 * 2026 stało tu, że skaner jej nie dosięgnie, bo odnośnik nie istnieje
 * w żadnym liście — ale `GET` na ten adres też włączał wysyłkę, a podpisany
 * adres ląduje w historii przeglądarki, w podglądzie linku i w pamięci
 * podręcznej przeglądarki, która sama pobiera strony „na zapas". Udzielenie
 * zgody musi być czynnością człowieka, więc `GET` pokazuje tylko pytanie
 * z jednym dużym przyciskiem, a zapis robi `POST` z tokenem CSRF (ta trasa
 * NIE jest wyjęta spod CSRF — patrz `bootstrap/app.php`). Asymetria z
 * wypisaniem jest zamierzona: wycofanie zgody jednym wejściem działa na
 * korzyść właściciela skrzynki, udzielenie jej bez kliknięcia — nie.
 *
 * OBIE TRASY PRZYJMUJĄ `POST` (patrz `routes/web.php`) po to, żeby list
 * mógł nieść nagłówki `List-Unsubscribe` i `List-Unsubscribe-Post`
 * (RFC 8058). Wtedy Gmail i Outlook pokazują własny przycisk „wypisz się"
 * przy nadawcy i wołają ten adres metodą POST, bez otwierania przeglądarki.
 * To jest najkrótsza droga wyjścia, jaka w ogóle istnieje, i jednocześnie
 * najmocniejszy sygnał dla filtrów, że ta poczta jest w porządku.
 */
class PodsumowanieTygodniaController extends Controller
{
    public function wypisz(User $user, ZapiszSygnal $sygnal, PrzestawZgodeNaDigest $zgoda): View
    {
        // ZGODĘ GASI KLASA DOMENOWA (D-072), a nie `forceFill()` w tym
        // miejscu: razem z flagą powstaje wiersz `wycofana` w `dziennik_zgod`,
        // ze ŹRÓDŁEM `link_wypisania` — czyli z dowodem, że decyzja przyszła
        // z odnośnika w liście, a nie z ekranu ustawień. Tej połowy dowodu
        // (i całej reszty) nie było tu do 10 września w ogóle — audyt DB1.
        //
        // BEZ WARUNKU NA STATUS KONTA, świadomie: zgodę wolno wycofać zawsze
        // i natychmiast (RODO art. 7 ust. 3). Konto zawieszone albo zgłoszone
        // do usunięcia i tak by listu nie dostało (`OdbiorcyDigestu`), ale
        // odmowa wypisania z powodu stanu konta byłaby odmową wykonania
        // prawa, a nie zabezpieczeniem. Z dokładnie tego samego powodu
        // nieudany zapis DOWODU nie wstrzymuje wypisania — patrz asymetria
        // opisana w `PrzestawZgodeNaDigest`.
        //
        // Zwrócone `true` znaczy „stan naprawdę się zmienił" i zastępuje
        // dawne odczytanie flagi przed zapisem.
        $bylZapisany = $zgoda->handle($user, false, WpisZgody::ZRODLO_LINK_WYPISANIA);

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

    public function wracam(Request $request, User $user, PrzestawZgodeNaDigest $zgoda): View
    {
        // `GET` NICZEGO NIE ZAPISUJE (#1403) — tylko pyta. Ten sam adres,
        // ten sam podpis; formularz na tej stronie wysyła `POST` z tokenem.
        if (! $request->isMethod('POST')) {
            return view('pages.podsumowanie-wracam', [
                'powrot' => OdnosnikWypisania::powrotDla($user),
            ]);
        }

        // Powrót jest UDZIELENIEM zgody, więc idzie tą samą drogą co haczyk
        // w ustawieniach — z własnym źródłem `link_powrotny`, żeby w dzienniku
        // było widać, że człowiek naprawiał wypisanie z ekranu potwierdzenia
        // (najczęściej po skanerze odnośników w firmowej poczcie, patrz
        // komentarz klasy), a nie zapisywał się od nowa w ustawieniach.
        //
        // Przy udzieleniu flaga i dowód powstają ATOMOWO: gdyby zapis dowodu
        // padł, ta strona oddaje błąd, a wysyłka NIE zostaje włączona bez
        // dowodu podstawy prawnej (D-072).
        $zgoda->handle($user, true, WpisZgody::ZRODLO_LINK_POWROTNY);

        return view('pages.podsumowanie-wrocono', [
            'wypisz' => OdnosnikWypisania::dla($user),
        ]);
    }
}
