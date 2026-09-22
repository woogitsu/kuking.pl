<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Moderation\UzasadnienieDecyzji;
use App\Models\ModerationAction;
use App\Models\User;

/**
 * Zdanie, którym odmawiamy wejścia na konto ZAMKNIĘTE — jedno dla wszystkich
 * dróg wejścia.
 *
 * PO CO OSOBNA KLASA
 * Ten tekst jest jedyną rzeczą, jaką osoba ZABLOKOWANA od nas przeczyta:
 * do serwisu nie wejdzie, a powiadomienie o decyzji leży w środku. Niesie
 * więc pouczenie z DSA art. 17 ust. 3 — podstawę decyzji, informację o tym,
 * czy sprawa zaczęła się od zgłoszenia, i drogę odwoławczą z terminem.
 *
 * Do 10 września żył jako metoda prywatna w `LoginController`, czyli tylko
 * na drodze przez hasło. Od dnia, w którym doszła DRUGA droga wejścia
 * (wejście kontem Google, D-069), byłby to obowiązek prawny spełniony na
 * jednym ekranie z dwóch — a przy trzeciej drodze na jednym z trzech.
 * Rozjazd byłby przy tym niewidoczny: ekran bez tego tekstu wygląda
 * normalnie, tylko nie mówi człowiekowi, za co i gdzie się odwołać.
 *
 * Treść jest przeniesiona BEZ ZMIAN. To nie jest miejsce na poprawianie
 * brzmienia — pilnują go testy odmowy logowania i testy DSA.
 */
final class KomunikatZamknietegoKonta
{
    /**
     * Dlaczego nie wpuszczamy — z treścią napisaną przez moderatora.
     *
     * Powiadomienie o decyzji leży w serwisie, do którego ta osoba właśnie nie
     * weszła. Ekran logowania jest jedynym miejscem, w którym zbanowany
     * człowiek cokolwiek od nas przeczyta, więc to tutaj musi trafić odpowiedź
     * na pytanie „za co" — inaczej DSA art. 17 zostaje spełniony tylko
     * na papierze.
     */
    public static function dla(User $user): string
    {
        // Konto po wykonanej karencji (D-022): nie ma czego odzyskiwać
        // i trzeba to powiedzieć wprost, a nie odsyłać do formularza
        // cofnięcia, który tej osobie odmówi.
        if ($user->isErased()) {
            return 'To konto zostało usunięte na Twoją prośbę, razem z danymi do logowania, '
                .'i nie da się go odzyskać. Jeśli chcesz wrócić do Kuking, założysz nowe konto. '
                .'Jeśli to pomyłka, napisz do nas: '.config('kuking.community.contact_email');
        }

        if ($user->status === User::STATUS_PENDING_DELETE) {
            return 'To konto jest oznaczone do usunięcia, dlatego logowanie jest zamknięte. Jeśli chcesz je odzyskać, '
                .'wejdź na stronę „Cofnij usunięcie konta” ('.route('account.delete.cancel').') i potwierdź '
                .'hasłem, że to Ty. Jeśli dane zostały już usunięte na stałe, ta strona Cię o tym poinformuje — '
                .'wtedy napisz do nas: '.config('kuking.community.contact_email');
        }

        $odModeratora = $user->latestModerationMessage();

        /*
         * UZASADNIENIE Z ART. 17 UST. 3 TEŻ MUSI BYĆ TUTAJ.
         *
         * Powiadomienie w serwisie niesie od dziś podstawę decyzji, informację
         * o tym, czy sprawa zaczęła się od zgłoszenia, zdanie o braku automatu
         * i pełne pouczenie o środkach odwoławczych z terminem
         * (`UzasadnienieDecyzji`). Osoba ZABLOKOWANA tego powiadomienia nie
         * przeczyta — do serwisu nie wejdzie. Gdyby uzasadnienie zostało tylko
         * tam, art. 17 byłby spełniony dla wszystkich POZA tymi, których
         * dotyczy najmocniejsza z decyzji.
         *
         * Bierzemy ostatnią BLOKADĘ tej osoby, nie ostatnią decyzję w ogóle:
         * komunikat wyżej mówi „to konto zostało zablokowane" i uzasadnienie
         * musi dotyczyć tej samej decyzji, a nie ukrycia wpisu z zeszłego roku.
         */
        $blokada = ModerationAction::query()
            ->where('subject_user_id', $user->getKey())
            ->where('action', ModerationAction::ACTION_BAN)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $uzasadnienie = $blokada === null ? [] : UzasadnienieDecyzji::zdania($blokada);

        return 'To konto zostało zablokowane. '
            .($odModeratora !== null ? $odModeratora.' ' : '')
            .($uzasadnienie === [] ? '' : implode(' ', $uzasadnienie).' ')
            // Odwołanie dla osoby zablokowanej ma osobny, PUBLICZNY formularz
            // (#10) — bez niego zdanie „możesz się odwołać" wyżej nie miałoby
            // dokąd prowadzić, bo do serwisu ta osoba nie wejdzie.
            .($blokada !== null && $blokada->isAppealable()
                ? 'Odwołanie złożysz na '.route('appeals.guest').'. '
                : '')
            // Dwa warianty ostatniego zdania, bo uzasadnienie mówi już
            // „jeśli uważasz, że to pomyłka, możesz się odwołać". Powtórzenie
            // tego samego wtrętu dwa zdania później wygląda jak usterka
            // i wydłuża komunikat, który i tak jest długi.
            .($uzasadnienie === []
                ? 'Jeśli uważasz, że to pomyłka, napisz do nas: '
                : 'Możesz też napisać do nas: ')
            .config('kuking.community.contact_email');
    }
}
