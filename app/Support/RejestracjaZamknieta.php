<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\RedirectResponse;

/**
 * Co widzi człowiek, gdy właściciel zamknął zakładanie kont
 * (`kuking.account.registration_open` = false).
 *
 * DLACZEGO NIE 503 (audyt B9, pkt 1)
 * Formularz rejestracji i domknięcie konta z Google i Facebooka kończyły się
 * `abort_unless(…, 503)`. Tekst podany do `abort()` nigdzie się nie
 * wyświetlał — człowiek dostawał `errors/503`: „Robimy przerwę techniczną,
 * Kuking jest teraz niedostępny”. Serwis działał, a ekran mówił, że nie
 * działa, i nie dawał drogi dalej. Osoba, która ma już konto i kliknęła
 * „Załóż konto” z przyzwyczajenia, uznawała, że nie zaloguje się też.
 *
 * Teraz każda z tych dróg odsyła na logowanie z jednym zdaniem: nowych kont
 * chwilowo nie zakładamy, a kto ma konto, loguje się jak zwykle. Zdanie nie
 * wymienia sposobów logowania — ekran logowania pokazuje te, które naprawdę
 * działają (link z wiadomości tylko przy działającej poczcie). Ekrany
 * domknięcia z Google i Facebooka miały własną, dłuższą wersję; tu jest
 * jedno źródło, żeby cztery drogi nie mówiły czterech rzeczy.
 *
 * Bramka nadal ZAMYKA: żadne konto nie powstaje. Zmienia się tylko to, co
 * człowiek widzi. Pilnuje `tests/Feature/ZamknietaRejestracjaNieUdajeAwariiTest.php`.
 */
final class RejestracjaZamknieta
{
    public const KOMUNIKAT = 'Nowych kont chwilowo nie zakładamy. Jeśli masz już konto, zaloguj się.';

    public static function czyZamknieta(): bool
    {
        return ! config('kuking.account.registration_open');
    }

    public static function przekierowanie(): RedirectResponse
    {
        return redirect()->route('login')->with('status', self::KOMUNIKAT);
    }
}
