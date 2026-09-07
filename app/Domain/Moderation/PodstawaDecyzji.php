<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

/**
 * PODSTAWA DECYZJI MODERACYJNEJ — zamknięta lista powodów, z których każdy
 * wskazuje KONKRETNY punkt `resources/legal/zasady.md`.
 *
 * PROBLEM, KTÓRY TO ZAMYKA (pomiar `docs/decyzje/DSA_POMIAR.md` §2)
 * `moderation_actions.reason_code` był swobodnym tekstem do 80 znaków, bez
 * słownika i bez odesłania do czegokolwiek. W panelu nazywał się „kod
 * wewnętrzny", w bazie leżały wpisy w rodzaju `spam_link`, `nekanie`,
 * `bo_tak` — i nigdy nie docierał do człowieka. Autor treści dowiadywał się
 * WIĘC, że coś mu ukryto, ale nie NA JAKIEJ PODSTAWIE. Art. 17 ust. 3 lit. d
 * i e wymagają jednego z dwojga: podstawy prawnej (gdy treść jest
 * niezgodna z prawem) albo podstawy umownej — czyli punktu zasad.
 *
 * DLACZEGO ZAMKNIĘTA LISTA, A NIE DALEJ SWOBODNY TEKST
 * Bo zdanie „naruszyłeś punkt 4 zasad" wolno wysłać tylko wtedy, gdy numer
 * bierze się z ODWZOROWANIA, a nie ze zgadywania. Lista niżej jest tym
 * odwzorowaniem: każdy klucz ma numer punktu i jego brzmienie z `zasady.md`,
 * a test `UzasadnienieDecyzjiTest::test_kazdy_punkt_z_listy_istnieje_w_zasadach`
 * sprawdza w PLIKU, że taki punkt naprawdę tam stoi i tak samo się nazywa.
 * Gdy ktoś przenumeruje zasady, test pada — i to jest jedyny moment, w którym
 * wolno o tym się dowiedzieć.
 *
 * CZEGO TU ŚWIADOMIE NIE MA
 * Kolumny na PODSTAWĘ PRAWNĄ, czyli na konkretny przepis. `reason_code`
 * mieści 80 znaków i jest jednym polem — nie da się w nim trzymać jednego
 * z dwóch różnych rodzajów podstawy razem z jej treścią. Dlatego podstawa
 * `niezgodne-z-prawem` NIE UDAJE, że zna przepis: mówi, co uznaliśmy,
 * a szczegół idzie w wiadomości od moderacji, która przy tej podstawie
 * jest OBOWIĄZKOWA (`ModerationController::decide()`, `required_if`).
 * Pełne lit. d wymaga kolumny `legal_ground` — opisane w raporcie, nie
 * wprowadzone, bo `database/migrations/**` należy do właściciela.
 *
 * SWOBODNY TEKST NADAL PRZECHODZI WALIDACJĘ i tak ma zostać: w bazie leżą
 * decyzje sprzed tej zmiany, a `unhide` z odwołania zapisuje
 * `appeal_overturned`. Kod, którego nie ma na liście, po prostu nie dostaje
 * numeru punktu — `zdanie()` mówi wtedy prawdę ogólną, zamiast wymyślać
 * numer.
 */
final class PodstawaDecyzji
{
    /** Treść niezgodna z prawem — podstawa PRAWNA, nie punkt zasad. */
    public const NIEZGODNE_Z_PRAWEM = 'niezgodne-z-prawem';

    /** Treści krzywdzące dzieci — sekcja „Czego nie tolerujemy w ogóle". */
    public const KRZYWDZENIE_DZIECI = 'krzywdzenie-dzieci';

    /**
     * Powód → punkt zasad.
     *
     * `punkt` = numer w `resources/legal/zasady.md`, `zasada` = jego
     * brzmienie (dokładnie ten fragment, który stoi w pliku pogrubiony),
     * `etykieta` = opis dla moderatora w panelu.
     *
     * `punkt` równe `null` znaczy „ta podstawa nie jest punktem z listy" —
     * i wtedy zdanie dla człowieka buduje się inaczej, patrz `zdanie()`.
     *
     * @var array<string, array{punkt: ?int, zasada: string, etykieta: string}>
     */
    public const PODSTAWY = [
        'podszywanie-sie' => [
            'punkt' => 1,
            'zasada' => 'Bądź sobą.',
            'etykieta' => 'Podszywanie się pod inną osobę (punkt 1)',
        ],
        'cudza-tresc' => [
            'punkt' => 2,
            'zasada' => 'Publikuj to, co zrobiłeś lub napisałeś sam.',
            'etykieta' => 'Cudzy przepis albo cudzy tekst (punkt 2)',
        ],
        'cudze-zdjecie' => [
            'punkt' => 3,
            'zasada' => 'Publikuj swoje zdjęcia.',
            'etykieta' => 'Cudze zdjęcie podane jako swoje (punkt 3)',
        ],
        'obrazanie-nekanie' => [
            'punkt' => 4,
            'zasada' => 'Szanuj innych.',
            'etykieta' => 'Obrażanie, wyzwiska, nękanie (punkt 4)',
        ],
        'mowa-nienawisci' => [
            'punkt' => 4,
            'zasada' => 'Szanuj innych.',
            'etykieta' => 'Mowa nienawiści (punkt 4)',
        ],
        'tresci-dla-doroslych' => [
            'punkt' => 5,
            'zasada' => 'Bez treści dla dorosłych.',
            'etykieta' => 'Nagość albo przemoc (punkt 5)',
        ],
        'niebezpieczna-porada' => [
            'punkt' => 6,
            'zasada' => 'Uważaj na porady zdrowotne.',
            'etykieta' => 'Niebezpieczna porada zdrowotna albo kulinarna (punkt 6)',
        ],
        'spam-reklama' => [
            'punkt' => 7,
            'zasada' => 'Bez spamu i reklamy udającej przepis.',
            'etykieta' => 'Spam, reklama, link afiliacyjny (punkt 7)',
        ],
        'dane-dziecka' => [
            'punkt' => 8,
            'zasada' => 'Szanuj dzieci.',
            'etykieta' => 'Dane albo zdjęcie cudzego dziecka (punkt 8)',
        ],
        'cudze-dane-osobowe' => [
            'punkt' => 9,
            'zasada' => 'Nie publikuj cudzych danych osobowych',
            'etykieta' => 'Cudze dane osobowe (punkt 9)',
        ],
        self::KRZYWDZENIE_DZIECI => [
            'punkt' => null,
            'zasada' => 'Czego nie tolerujemy w ogóle',
            'etykieta' => 'Krzywdzenie dzieci — usuwamy natychmiast',
        ],
        self::NIEZGODNE_Z_PRAWEM => [
            'punkt' => null,
            'zasada' => '',
            'etykieta' => 'Treść niezgodna z prawem (wymaga wiadomości do autora)',
        ],
    ];

    /**
     * Powody ZGŁOSZENIA (`Report::REASONS`) o tym samym znaczeniu.
     *
     * Nie jest to zgadywanie: to ta sama, zamknięta lista, z której wybiera
     * ZGŁASZAJĄCY na formularzu — a moderatorzy wpisywali dotąd jej klucze
     * do `reason_code` ręcznie (`harassment`, `spam`, `copyright`). Dzięki
     * temu odwzorowaniu decyzje sprzed tej zmiany też podają punkt zasad,
     * zamiast zostawać bez podstawy.
     *
     * Czego tu nie ma i nie będzie: kodów wymyślonych ad hoc
     * (`spam_link`, `nekanie`, `bo_tak`). Zgadywanie ich znaczenia byłoby
     * dokładnie tym wymyślaniem numeru, którego ta klasa ma nie robić.
     *
     * @var array<string, string>
     */
    public const SYNONIMY = [
        'impersonation' => 'podszywanie-sie',
        'copyright' => 'cudza-tresc',
        'harassment' => 'obrazanie-nekanie',
        'hate' => 'mowa-nienawisci',
        'sexual' => 'tresci-dla-doroslych',
        'dangerous_advice' => 'niebezpieczna-porada',
        'spam' => 'spam-reklama',
        'scam' => 'spam-reklama',
        'minor' => 'dane-dziecka',
        'personal_data' => 'cudze-dane-osobowe',
    ];

    /**
     * Lista do wyboru w panelu moderacji: kod => etykieta.
     *
     * @return array<string, string>
     */
    public static function dlaFormularza(): array
    {
        return array_map(fn (array $opis): string => $opis['etykieta'], self::PODSTAWY);
    }

    /**
     * Rozpoznana podstawa albo `null`, gdy kod jest spoza listy.
     *
     * @return ?array{punkt: ?int, zasada: string, etykieta: string}
     */
    public static function rozpoznaj(?string $kod): ?array
    {
        if ($kod === null) {
            return null;
        }

        $kod = self::SYNONIMY[$kod] ?? $kod;

        return self::PODSTAWY[$kod] ?? null;
    }

    /**
     * Zdanie o podstawie — to, co przeczyta autor treści.
     *
     * Trzy przypadki i żaden z nich nie zmyśla numeru:
     *  - podstawa prawna: mówimy, że uznaliśmy treść za niezgodną z prawem,
     *    i odsyłamy do wiadomości od moderacji (obowiązkowej przy tej
     *    podstawie);
     *  - punkt zasad: podajemy numer i brzmienie punktu;
     *  - kod spoza listy (decyzje sprzed tej zmiany): podstawą są zasady
     *    jako całość, bez numeru, którego nie znamy.
     */
    public static function zdanie(?string $kod): string
    {
        $rozpoznana = self::rozpoznaj($kod);
        $zasady = url('/zasady');

        if ($rozpoznana === null) {
            return 'Podstawą tej decyzji są zasady Kuking, które obowiązują wszystkich w serwisie ('.$zasady.').';
        }

        if (($self = self::SYNONIMY[$kod] ?? $kod) === self::NIEZGODNE_Z_PRAWEM) {
            return 'Podstawą tej decyzji jest prawo, a nie tylko nasze zasady: uznaliśmy tę treść '
                .'za niezgodną z prawem. Wyjaśnienie masz w wiadomości od moderacji powyżej.';
        }

        if ($self === self::KRZYWDZENIE_DZIECI) {
            return 'Podstawą tej decyzji jest zasada, od której nie ma u nas wyjątku: treści '
                .'krzywdzące dzieci usuwamy natychmiast ('.$zasady.').';
        }

        return 'Podstawą tej decyzji jest punkt '.$rozpoznana['punkt'].' zasad Kuking: '
            .'„'.rtrim($rozpoznana['zasada'], '.').'” ('.$zasady.').';
    }
}
