<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\ModerationAction;
use App\Moderacja\OcenaModelem;
use App\Support\Czas;

/**
 * UZASADNIENIE DECYZJI DLA AUTORA TREŚCI — jedno miejsce na wszystkie zdania,
 * których wymaga art. 17 ust. 3 DSA.
 *
 * CO ZMIERZYŁ POMIAR (`docs/decyzje/DSA_POMIAR.md` §2)
 * Autor dostawał nagłówek („Moderacja Kuking ukryła Twoją treść"), wiadomość
 * napisaną przez moderatora i jedno zdanie „możesz się odwołać". Brakowało:
 * podstawy (lit. d i e), informacji o tym, czy sprawa zaczęła się od
 * zgłoszenia (lit. b), zdania o braku automatu (lit. e) i pełnego pouczenia
 * o środkach odwoławczych (lit. f) — a to ostatnie ZGŁASZAJĄCY dostawał
 * w całości. Autor treści, czyli osoba ukarana, wiedział mniej niż osoba,
 * która go zgłosiła. Dokładna odwrotność tego, co wymaga przepis.
 *
 * DLACZEGO ZDANIA POWSTAJĄ PRZY WYŚWIETLANIU, A NIE PRZY ZAPISIE
 * Bo TERMIN NA ODWOŁANIE liczy `ModerationAction::appealDeadline()`, a on
 * bierze większą z dwóch wartości: sześć miesięcy z przepisu i
 * `kuking.moderation.appeal_days` z konfiguracji. Zamrożenie daty w
 * `notifications.data` znaczyłoby, że po podniesieniu tej liczby stare
 * powiadomienia pokazują termin KRÓTSZY niż prawdziwy — czyli odstraszają od
 * odwołania, do którego człowiek ma jeszcze prawo. Ten sam powód, dla którego
 * `NotifyModerationDecision` nie zamraża adresu kontaktowego.
 *
 * CZEGO TU NIE MA I NIE MOŻE BYĆ
 *  - Nazwiska moderatora. Serwis prowadzi 1-2 osoby; podpisanie decyzji
 *    nazwiskiem to wskazanie palcem konkretnego człowieka przez kogoś, kto
 *    właśnie dostał karę (`NotifyModerationDecision`, „DLACZEGO `actor_id`
 *    JEST PUSTE").
 *  - Czegokolwiek o zgłaszającym poza samym faktem, że zgłoszenie było.
 *    Zgłaszający zostaje dla autora anonimowy — tak samo jak autor zostaje
 *    anonimowy dla zgłaszającego (`DecyzjaWSprawieZgloszenia`).
 *  - OBIETNICY NIEZALEŻNEGO ORGANU ODWOŁAWCZEGO. Zdanie „sprawa wróci do
 *    człowieka, który jej wcześniej nie prowadził" zostało wycofane jako
 *    nieprawdziwe (commit 8548ada): serwis prowadzi jedna osoba. Jedyne, co
 *    kod naprawdę egzekwuje, to karencja z `ResolveAppeal` — tej samej
 *    decyzji nie da się PODTRZYMAĆ przez pierwsze `appeal_self_uphold_hours`
 *    — a jej COFNIĘCIE działa natychmiast. Tylko to wolno napisać i tylko to
 *    tu jest.
 */
final class UzasadnienieDecyzji
{
    /**
     * Zdania uzasadnienia w kolejności czytania.
     *
     * Pusta tablica dla decyzji, od której nie ma się co odwoływać:
     * `unhide` (zdjęcie ukrycia) to dobra wiadomość, a akapit o podstawie
     * i terminie odwołania pod nią brzmi jak groźba. `no_action` w ogóle nie
     * tworzy powiadomienia (`NotifyModerationDecision::handle()`).
     *
     * @return list<string>
     */
    public static function zdania(ModerationAction $decyzja): array
    {
        if (! in_array($decyzja->action, ModerationAction::ODWOLYWALNE, true)) {
            return [];
        }

        return [
            PodstawaDecyzji::zdanie($decyzja->reason_code),
            self::skadSprawa($decyzja),
            // Art. 17 ust. 3 lit. e. To zdanie jest prawdą, bo wiersza
            // w `moderation_actions` NIE DA SIĘ utworzyć bez moderatora:
            // `moderator_id` jest NOT NULL z kluczem obcym do `users`,
            // a każde miejsce w `app/`, które go tworzy, stoi za bramką
            // moderatora i przyjmuje konkretnego człowieka. Pilnuje tego
            // `UzasadnienieDecyzjiTest::test_nie_ma_w_kodzie_drogi_do_decyzji_bez_czlowieka`.
            'Decyzję podjął człowiek z naszego zespołu. Nie mamy w Kuking automatu, '
                .'który sam ukrywa, usuwa albo blokuje.',
            ...self::pouczenie($decyzja),
        ];
    }

    /**
     * Art. 17 ust. 3 lit. b — czy decyzja jest skutkiem zgłoszenia.
     *
     * Odpowiada na to `moderation_actions.report_id`, i to jest odpowiedź
     * PEWNA dla każdej decyzji, przy której to zdanie w ogóle powstaje:
     *
     *  - decyzje odwoływalne (`hide`, `remove`, `warn`, `suspend`, `ban`)
     *    tworzą dwa miejsca: `ModerationController::decide()`, który zawsze
     *    zapisuje `report_id` — rozpatruje przecież zgłoszenie — oraz
     *    `ZdejmijZUrzedu` (G31, D-251), który zapisuje `remove` z pustym
     *    `report_id`, bo nikt niczego nie zgłosił;
     *  - `unhide` tworzy `RestoreContent` i tam `report_id` zostaje puste,
     *    bo indeks częściowy `moderation_actions_one_per_report` dopuszcza
     *    jedną decyzję na zgłoszenie. Ale `zdania()` dla `unhide` nie tworzy
     *    uzasadnienia wcale, więc gałąź „nikt tego nie zgłosił" nie ma jak
     *    trafić do człowieka jako nieprawda.
     *
     * Gałąź dla pustego `report_id` to decyzja z WŁASNEGO przeglądu serwisu,
     * bez niczyjego zgłoszenia — od G31 droga istniejąca w produkcie
     * („Zdejmij z urzędu”), a nie tylko przewidziana.
     */
    private static function skadSprawa(ModerationAction $decyzja): string
    {
        /*
         * TRZECIA DROGA: TREŚĆ WSKAZAŁ AUTOMAT (D-052).
         *
         * `report_id` jest tu niepuste, więc bez tego warunku człowiek
         * przeczytałby „sprawa zaczęła się od zgłoszenia, które dostaliśmy od
         * innej osoby" — NIEPRAWDĘ, i to nieprawdę najgorszego rodzaju: każe
         * komuś szukać wśród znajomych osoby, która go zgłosiła, choć nikt
         * tego nie zrobił.
         *
         * Art. 17 ust. 3 lit. c wymaga poza tym informacji o użyciu środków
         * automatycznych PRZY WYKRYCIU treści, nie tylko przy decyzji.
         * Zdanie niżej mówi jedno i drugie: co wskazało treść i kto
         * postanowił. Zdanie o braku automatu, które idzie zaraz po nim,
         * zostaje prawdą — nasz automat niczego nie ukrywa, nie usuwa i nie
         * blokuje (`docs/legal/SYGNALY_AUTOMATU.md`).
         */
        if ($decyzja->report?->wykrylAutomat() === true) {
            // KTÓRE narzędzie — bo to nie jest szczegół. „Narzędzie do
            // wychwytywania spamu" przy treści wskazanej przez model
            // oceniający przemoc i nienawiść byłoby zdaniem nieprawdziwym,
            // a art. 17 ust. 3 lit. c mówi o poinformowaniu o użyciu środków
            // automatycznych, nie o wspomnieniu, że jakieś istnieją.
            $narzedzie = $decyzja->report?->reason === OcenaModelem::KOD
                ? 'narzędzie, które maszynowo ocenia publikowane treści i zdjęcia'
                : 'nasze narzędzie do wychwytywania spamu';

            return 'Nikt tego nie zgłosił. Treść wskazało '.$narzedzie.', '
                .'a decyzję podjął potem człowiek, który ją przeczytał.';
        }

        if ($decyzja->report_id !== null) {
            return 'Sprawa zaczęła się od zgłoszenia, które dostaliśmy od innej osoby. '
                .'Nie podajemy, kto je złożył.';
        }

        return 'Nikt tego nie zgłosił — sprawę znaleźliśmy sami, przeglądając serwis.';
    }

    /**
     * Art. 17 ust. 3 lit. f — środki odwoławcze, WSZYSTKIE TRZY.
     *
     * Do tej pory autor dostawał tylko pierwszy z nich („możesz się
     * odwołać"), i to bez terminu: `appealDeadline()` pokazywał się dopiero
     * na stronie sprawy i tylko wtedy, gdy termin JUŻ MINĄŁ. Człowiek, który
     * przeczytał powiadomienie i odłożył sprawę, nie miał skąd wiedzieć, ile
     * ma czasu.
     *
     * Termin to sześć miesięcy (art. 20 ust. 1) i tak go liczy
     * `ModerationAction::appealDeadline()`. Nigdzie nie ma i nie może być
     * 14 dni — ta liczba brała się z podręcznika operacyjnego, nie z prawa.
     *
     * PO UPŁYWIE TERMINU ZDANIE MUSI SIĘ ZMIENIĆ. Powiadomienia zostają
     * w serwisie na lata, a `isAppealable()` przestaje przepuszczać
     * odwołanie w dniu, w którym termin mija. „Możesz się odwołać" pod
     * decyzją sprzed roku byłoby zaproszeniem na stronę, która odmówi.
     *
     * @return list<string>
     */
    private static function pouczenie(ModerationAction $decyzja): array
    {
        $termin = Czas::data($decyzja->appealDeadline(), 'j F Y');

        if (! $decyzja->isAppealable()) {
            return [
                'Na odwołanie w serwisie był czas do '.$termin.' i ten termin już minął. '
                    .'Jeśli pojawiły się nowe okoliczności, napisz do nas: '
                    .config('kuking.community.contact_email').'.',
                'Możesz też zwrócić się do pozasądowego organu rozstrzygania sporów albo do sądu. '
                    .'Ta decyzja nie zamyka Ci żadnej z tych dróg.',
            ];
        }

        return [
            'Jeśli uważasz, że to pomyłka, możesz się odwołać. Sprawdzimy decyzję jeszcze raz. '
                .'Masz na to czas do '.$termin.'.',
            // Prawda o tym, co kod naprawdę zapewnia — bez obietnicy
            // niezależnego organu. Patrz komentarz klasy. Skutek cofnięcia
            // opisujemy DLA TEJ decyzji, bo `ResolveAppeal::cofnij()` robi
            // trzy różne rzeczy: przywraca treść, odwiesza konto albo nie ma
            // czego cofać poza samym ostrzeżeniem.
            'Jeśli przyznamy Ci rację, '.match ($decyzja->action) {
                ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE => 'treść wraca na miejsce od razu.',
                ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN => 'konto odzyskuje dostęp od razu.',
                default => 'cofamy to ostrzeżenie.',
            },
            'Możesz też zwrócić się do pozasądowego organu rozstrzygania sporów albo do sądu. '
                .'Ta decyzja nie zamyka Ci żadnej z tych dróg.',
        ];
    }
}
