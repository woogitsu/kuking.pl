<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Ściągawka do wydruku” (F4, research z 30 września 2026).
 *
 * PO CO
 * Córka albo wnuk zakłada komuś konto, odjeżdża, a po tygodniu ta osoba nie
 * wie, jak wejść. Jedna kartka A4 dużym drukiem: adres serwisu, nazwa
 * użytkownika, częściowo zasłonięty e-mail konta, jak wejść bez hasła, jak
 * dodać zdjęcie obiadu i gdzie powiększyć tekst.
 *
 * CZEGO NA KARTCE NIE MA I MIEĆ NIE MOŻE
 * Kartka leży na stole, przy lodówce, w torbie na zajęciach w bibliotece.
 * Dlatego: bez hasła (i tak go nie znamy — jest skrótem), bez linku
 * logowania, bez kodu 2FA, bez żadnego tokenu i bez PEŁNEGO adresu e-mail.
 * Link „wejdź bez hasła” to zwykły adres formularza, na który trzeba samemu
 * wpisać e-mail — nie klucz do konta.
 *
 * AUTORYZACJA
 * Trasa w grupie `auth`, bez identyfikatora w adresie: kartka jest ZAWSZE
 * o koncie osoby zalogowanej, więc nie ma cudzego zasobu do podmiany
 * (AGENTS.md §7). Gość trafia na logowanie.
 */
class SciagawkaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('pages.settings.sciagawka', [
            'adresSerwisu' => rtrim((string) config('app.url'), '/'),
            'nazwaUzytkownika' => $user->profile?->username,
            'emailZakryty' => self::zakryjEmail((string) $user->email),
            'linkLogowaniaWlaczony' => (bool) config('kuking.login_link.wlaczone'),
        ]);
    }

    /**
     * „anna.kowalska@example.com” → „a…@example.com”.
     *
     * Pierwsza litera i domena wystarczą, żeby rozpoznać WŁASNY adres, a nie
     * wystarczą, żeby ktoś obcy z kartki poznał cudzy. Adres bez „@” (nie
     * powinien istnieć, ale kartka nie może przez niego wyciec) zasłaniamy
     * w całości.
     */
    public static function zakryjEmail(string $email): string
    {
        $malpa = mb_strrpos($email, '@');

        if ($malpa === false || $malpa === 0) {
            return '…';
        }

        return mb_substr($email, 0, 1).'…'.mb_substr($email, $malpa);
    }
}
