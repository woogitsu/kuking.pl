<?php

declare(strict_types=1);

namespace App\Domain\Digest;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Podpisane odnośniki „nie chcę tych listów" i „jednak chcę" (issue #11 pkt 6).
 *
 * JEDNO MIEJSCE, BO INACZEJ POŁOWA MIEJSC ZAPOMNI O PODPISIE
 * Odnośnik wypisania pojawia się w trzech postaciach naraz: w stopce listu
 * HTML, w wersji tekstowej i w nagłówku `List-Unsubscribe`. Trzy razy
 * `URL::signedRoute(...)` w trzech szablonach to trzy okazje, żeby ktoś
 * kiedyś napisał `route(...)` — a wtedy adres wygląda identycznie i działa
 * identycznie, tylko każdy może go zbudować dla cudzego konta i wypisać
 * dowolną osobę, znając sam identyfikator.
 *
 * PODPIS, A NIE LOGOWANIE — i to jest cała rzecz.
 * `AGENTS.md` §7 mówi „UUID w adresie NIE JEST autoryzacją" i to zdanie
 * zostaje w mocy: autoryzacją jest **podpis**, czyli dowód, że ten adres
 * wystawił Kuking. Człowiek, który chce przestać dostawać listy, nie może
 * być zmuszony do zalogowania się — połowa osób 60+ nie pamięta hasła
 * dokładnie wtedy, gdy chce się wypisać, i zamiast kliknąć „wypisz" klika
 * „to jest spam". To jest gorsze dla wszystkich: dla niej, bo dalej dostaje
 * listy, i dla serwisu, bo zgłoszenie spamu psuje dostarczalność poczty
 * WSZYSTKIM, łącznie z resetami haseł (`docs/decyzje/POCZTA.md` §3).
 *
 * BEZ DATY WAŻNOŚCI — `signedRoute`, nie `temporarySignedRoute`.
 * Odwrotnie niż przy paczce z danymi (`App\Mail\DataExportReady`), gdzie
 * termin zamyka dostęp do pliku razem z jego usunięciem. Tutaj odnośnik ma
 * działać także w liście sprzed pół roku, wyciągniętym z archiwum skrzynki —
 * bo dokładnie wtedy ktoś się rozmyśla. Wygasający odnośnik wypisania to
 * odnośnik, który w najważniejszym momencie oddaje „Ten link jest już
 * nieważny", czyli mówi człowiekowi „nie da się wypisać".
 *
 * CO TEN ODNOŚNIK ODSŁANIA: identyfikator konta. Nic poza tym — nie da się
 * z niego zalogować, przeczytać adresu e-mail ani zobaczyć czyichkolwiek
 * treści. Trasa robi jedną rzecz: przestawia `wants_weekly_digest` na
 * `false`, czyli działa wyłącznie na korzyść osoby, do której należy skrzynka.
 */
final class OdnosnikWypisania
{
    public static function dla(User $odbiorca): string
    {
        return URL::signedRoute('podsumowanie.wypisz', ['user' => $odbiorca->getKey()]);
    }

    public static function powrotDla(User $odbiorca): string
    {
        return URL::signedRoute('podsumowanie.wracam', ['user' => $odbiorca->getKey()]);
    }
}
