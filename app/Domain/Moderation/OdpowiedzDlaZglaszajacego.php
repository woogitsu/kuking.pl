<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\ModerationAction;
use App\Models\Report;

/**
 * CO MÓWIMY ZGŁASZAJĄCEMU — jedno miejsce na wszystkie zdania, których
 * wymaga DSA art. 16 ust. 5 (informacja o decyzji wraz z pouczeniem
 * o dostępnych środkach odwoławczych).
 *
 * DLACZEGO TO WYSZŁO Z `DecyzjaWSprawieZgloszenia`
 * Do issue #10 te zdania istniały tylko w liście e-mail i tylko dla zgłoszeń
 * prawnych. Zgłaszający ze zwykłego formularza „Zgłoś" nie dostawał niczego
 * — ani potwierdzenia przyjęcia, ani informacji o rozstrzygnięciu (pomiar
 * w opisie PR-a i w `docs/AUDYT_2026-09.md`, wiersz #10). Domykając tamtą
 * drogę powiadomieniem w serwisie, mieliśmy do wyboru napisać te same zdania
 * drugi raz w Blade albo wyjąć je do jednej klasy. Druga kopia rozjechałaby
 * się z pierwszą przy pierwszej poprawce — a to są zdania, których treść
 * jest obowiązkiem prawnym, nie kwestią stylu.
 *
 * TA KLASA JEST ODPOWIEDNIKIEM `UzasadnienieDecyzji`, tylko dla drugiej
 * strony sprawy: tamta mówi AUTOROWI, dlaczego dostał karę (art. 17),
 * ta mówi ZGŁASZAJĄCEMU, co zrobiliśmy z jego zgłoszeniem (art. 16 ust. 5).
 *
 * CZEGO TU NIE MA I NIE MOŻE BYĆ (Luka 3 z `docs/research/DSA-LUKI.md`)
 * Nazwy zgłoszonej osoby, jej adresu, tego jaką dokładnie karę dostała ani
 * niczego, co ustaliliśmy o niej w trakcie sprawy. Zgłaszający ma prawo
 * wiedzieć, czy treść zostaje czy znika — i to mu mówimy. Reszta to dane
 * osobowe osoby trzeciej, a mechanizm zgłoszeń nie jest narzędziem do
 * ustalania, kogo ukarano.
 *
 * ZDANIA POWSTAJĄ PRZY WYŚWIETLANIU, NIE PRZY ZAPISIE — z tego samego
 * powodu co w `UzasadnienieDecyzji`: adres kontaktowy i termin na odwołanie
 * liczą się z aktualnej konfiguracji, a zamrożone w `notifications.data`
 * rozjechałyby się z prawdą przy pierwszej ich zmianie.
 */
final class OdpowiedzDlaZglaszajacego
{
    /**
     * Jedyne decyzje, po których zgłoszona treść PRZESTAJE być dostępna.
     *
     * Lista jest jawna i wąska, a nie „wszystko oprócz braku działania":
     * przy dodaniu nowej decyzji domyślną odpowiedzią ma być „treść
     * zostaje", bo to jest odpowiedź prawdziwa dla każdej decyzji
     * dotyczącej KONTA, a nie treści.
     */
    private const AKCJE_ZDEJMUJACE_TRESC = [
        ModerationAction::ACTION_HIDE,
        ModerationAction::ACTION_REMOVE,
    ];

    /**
     * Skutek decyzji w dwóch częściach: zdanie wiodące (wyróżniane) i reszta.
     *
     * DWIE CZĘŚCI, NIE JEDEN ŁAŃCUCH — bo wyróżnienie robi się inaczej
     * w liście (`**pogrubienie**` Markdown) niż na ekranie (`<strong>`),
     * a sklejanie tu składni jednego z tych kanałów zmusiłoby drugi do
     * jej rozbierania.
     *
     * TRZY ODPOWIEDZI, NIE DWIE — bo „zgłoszenie zasadne" i „treści już nie
     * ma" to dwie różne rzeczy. Kod liczył kiedyś skutek jako
     * `action !== ACTION_NONE`, czyli „cokolwiek poza brakiem działania
     * znaczy, że treści nie ma". Tymczasem treść przestaje być dostępna
     * WYŁĄCZNIE przy `hide` i `remove`; ostrzeżenie, zawieszenie i ban nie
     * ruszają zgłoszonej treści wcale. Najgorszy przypadek: przy zgłoszeniu
     * OSOBY macierz `ModerationAction::DOZWOLONE` nie dopuszcza ani `hide`,
     * ani `remove`, więc KAŻDE uznane zgłoszenie konta wysyłało zdanie,
     * które nie mogło być prawdziwe.
     *
     * @return array{naglowek: string, reszta: string}
     */
    public static function skutek(ModerationAction $decyzja): array
    {
        if ($decyzja->action === ModerationAction::ACTION_TARGET_UNAVAILABLE) {
            return [
                'naglowek' => 'Nie mogliśmy ocenić wskazanej treści.',
                'reszta' => 'W chwili rozpatrywania zgłoszenia nie była już dostępna albo nie udało się jej jednoznacznie odnaleźć. '
                    .'Nie zapisaliśmy ani nie wykonaliśmy sankcji wobec autora.',
            ];
        }

        if (in_array($decyzja->action, self::AKCJE_ZDEJMUJACE_TRESC, true)) {
            return [
                'naglowek' => 'Uznaliśmy Twoje zgłoszenie za zasadne.',
                'reszta' => 'Zgłoszona treść nie jest już dostępna w serwisie.',
            ];
        }

        if ($decyzja->action !== ModerationAction::ACTION_NONE) {
            // Zasadne, ale treść zostaje. NIE PISZEMY, co zrobiliśmy
            // z kontem: to dane osobowe osoby trzeciej.
            return [
                'naglowek' => 'Uznaliśmy Twoje zgłoszenie za zasadne',
                'reszta' => 'i podjęliśmy działania przewidziane w naszym regulaminie. '
                    .'Sama zgłoszona treść zostaje w serwisie — środek, który zastosowaliśmy, '
                    .'jej nie dotyczy.',
            ];
        }

        // Decyzja odmowna MUSI podać powód — inaczej nie da się jej sensownie
        // zakwestionować, a pouczenie o środkach odwoławczych bez powodu jest
        // puste.
        return [
            'naglowek' => 'Po sprawdzeniu uznaliśmy, że ta treść zostaje w serwisie.',
            'reszta' => 'Nie znaleźliśmy w niej naruszenia, które by to uzasadniało.',
        ];
    }

    /**
     * Nagłówek pouczenia — wspólny dla listu i dla ekranu, żeby oba kanały
     * zapowiadały tę samą rzecz tymi samymi słowami.
     */
    public const NAGLOWEK_POUCZENIA = 'Jeśli się z nami nie zgadzasz';

    /**
     * Pouczenie o dostępnych środkach (art. 16 ust. 5 zdanie drugie),
     * w kolejności czytania, bez zdania o formularzu odwołania.
     *
     * Zdania idą POD `NAGLOWEK_POUCZENIA`, więc żadne z nich go nie powtarza.
     *
     * DLACZEGO BEZ FORMULARZA
     * Droga do formularza jest inna w każdym kanale i tylko dla części
     * spraw: przy zgłoszeniu prawnym z adresem e-mail jest to PODPISANY link
     * wysłany listem (`DecyzjaWSprawieZgloszenia`), a przy zgłoszeniu
     * społecznościowym nie ma jej wcale — wewnętrzny system skarg (art. 20)
     * leży w Sekcji 3 DSA, z której Kuking jest zwolniony jako
     * mikroprzedsiębiorstwo (`docs/legal/COMPLIANCE.md` §1.2), a otwarcie go
     * dla wszystkich zgłoszeń to decyzja właściciela, nie ta zmiana.
     *
     * ZDANIA NIŻEJ SĄ PRAWDZIWE ZAWSZE I DLA KAŻDEGO — i dokładnie tego
     * wymaga art. 16 ust. 5: zgłaszający ma wiedzieć, że decyzja serwisu
     * nie zamyka mu żadnej drogi na zewnątrz.
     *
     * NIE OBIECUJEMY „INNEGO CZŁOWIEKA". Stało tu kiedyś zdanie „sprawa
     * wróci do człowieka, który jej wcześniej nie prowadził" — kod tego nie
     * czynił prawdą (commit 8548ada). Przy zespole 1-2 osób nic tego nie
     * zapewnia.
     *
     * @return list<string>
     */
    public static function pouczenie(Report $zgloszenie): array
    {
        return [
            'Napisz do nas na '.(string) config('kuking.community.contact_email')
                .', podając numer sprawy '.$zgloszenie->numer_sprawy.' — zajmiemy się nią ponownie.',
            'Możesz też zwrócić się do pozasądowego organu rozstrzygania sporów '
                .'albo do sądu. Decyzja, którą tu opisujemy, nie zamyka Ci żadnej z tych dróg.',
        ];
    }
}
