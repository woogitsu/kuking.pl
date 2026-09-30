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
    /**
     * Napis przycisku prowadzącego do strony cofnięcia usunięcia konta.
     * Ten sam, co po zgłoszeniu usunięcia w „Twoich danych” (#2245).
     */
    public const ETYKIETA_COFNIECIA = 'Cofnij usunięcie konta';

    /** Napis przycisku do publicznego formularza odwołania (#10, D-333). */
    public const ETYKIETA_ODWOLANIA = 'Odwołaj się';

    /**
     * Przycisk pod komunikatem odmowy — dla `status_akcja` w layoucie.
     *
     * LINK COFNIĘCIA JAKO PRZYCISK, NIE ADRES W ZDANIU (D-333, 30.09.2026).
     * Adres strony cofnięcia stał w treści odmowy jako zwykły tekst: layout
     * i błąd przy polu wypisują ją bez odnośników, więc na telefonie trzeba
     * go było przepisać. Teraz każda droga wejścia przez przeglądarkę
     * (hasło, Google, Facebook, wylogowanie przez `EnsureAccountIsActive`)
     * dokłada ten przycisk przez `status_akcja` — ten sam mechanizm co #2245.
     *
     * Konto CZEKAJĄCE na usunięcie dostaje „Cofnij usunięcie konta”.
     * Konto ZABLOKOWANE — „Odwołaj się” do publicznego formularza odwołania
     * (decyzja właściciela z 30.09.2026), ale tylko wtedy, gdy ostatnia
     * blokada jest jeszcze odwoływalna: dokładnie wtedy, gdy zdanie odmowy
     * i tak mówi o odwołaniu, więc przycisk nie zdradza niczego ponad
     * komunikat. Konto po wykonanej karencji (`erased`) i blokada po
     * terminie odwołania nie dostają nic — przycisk byłby obietnicą bez
     * pokrycia.
     *
     * @return array{url: string, etykieta: string}|null
     */
    public static function akcja(User $user): ?array
    {
        if ($user->status === User::STATUS_PENDING_DELETE) {
            return [
                'url' => route('account.delete.cancel'),
                'etykieta' => self::ETYKIETA_COFNIECIA,
            ];
        }

        if ($user->status === User::STATUS_BANNED && self::ostatniaBlokada($user)?->isAppealable() === true) {
            return [
                'url' => route('appeals.guest'),
                'etykieta' => self::ETYKIETA_ODWOLANIA,
            ];
        }

        return null;
    }

    /** Ostatnia BLOKADA tej osoby — ta, o której mówi zdanie odmowy. */
    private static function ostatniaBlokada(User $user): ?ModerationAction
    {
        return ModerationAction::query()
            ->where('subject_user_id', $user->getKey())
            ->where('action', ModerationAction::ACTION_BAN)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Zdanie o drodze odwołania. W przeglądarce wskazuje przycisk z `akcja()`
     * w obszarze komunikatów na górze strony; w API (bez przycisku) — adres.
     */
    public static function wskazanieOdwolania(bool $adresWTresci = false): string
    {
        if ($adresWTresci) {
            return 'Odwołanie złożysz na '.route('appeals.guest').'.';
        }

        return 'Odwołanie złożysz przyciskiem „'.self::ETYKIETA_ODWOLANIA.'” na górze tej strony.';
    }

    /**
     * @param  bool  $adresWTresci  `true` tylko dla API (JSON): klient
     *                              aplikacji nie ma przycisku z `status_akcja`, więc adres strony
     *                              cofnięcia zostaje w zdaniu. W przeglądarce zdanie wskazuje
     *                              przycisk z `akcja()`.
     */
    public static function dla(User $user, bool $adresWTresci = false): string
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
                .self::wskazanieStronyCofniecia($adresWTresci).' i potwierdź '
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
        $blokada = self::ostatniaBlokada($user);

        $uzasadnienie = $blokada === null ? [] : UzasadnienieDecyzji::zdania($blokada);

        return 'To konto zostało zablokowane. '
            .($odModeratora !== null ? $odModeratora.' ' : '')
            .($uzasadnienie === [] ? '' : implode(' ', $uzasadnienie).' ')
            // Odwołanie dla osoby zablokowanej ma osobny, PUBLICZNY formularz
            // (#10) — bez niego zdanie „możesz się odwołać" wyżej nie miałoby
            // dokąd prowadzić, bo do serwisu ta osoba nie wejdzie.
            //
            // W przeglądarce adres zastępuje przycisk „Odwołaj się” pod
            // komunikatami (`akcja()`, D-333); API dostaje adres w zdaniu.
            .($blokada !== null && $blokada->isAppealable()
                ? self::wskazanieOdwolania($adresWTresci).' '
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

    /**
     * Fragment zdania „jak dojść do strony cofnięcia”, wspólny dla odmowy
     * logowania i wylogowania (`EnsureAccountIsActive`). Przycisk z `akcja()`
     * stoi w obszarze komunikatów na górze strony — tam, gdzie layout
     * rysuje `status_akcja`, nad nagłówkiem i nad podsumowaniem błędów.
     */
    public static function wskazanieStronyCofniecia(bool $adresWTresci = false): string
    {
        if ($adresWTresci) {
            return 'wejdź na stronę „'.self::ETYKIETA_COFNIECIA.'” ('.route('account.delete.cancel').')';
        }

        return 'użyj przycisku „'.self::ETYKIETA_COFNIECIA.'” na górze tej strony';
    }
}
