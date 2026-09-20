<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Limity zdjęć w JEDNYM miejscu — pochodne `config('kuking.media.*')`.
 *
 * PRZED TĄ KLASĄ TA SAMA FORMUŁA (`floor(max_bytes / 1024)`, żeby dostać
 * kilobajty na regułę walidacji `max:`) BYŁA PRZEPISANA RĘCZNIE W PIĘCIU
 * MIEJSCACH: PostController, CookedEventController, ProfileSettingsController
 * i dwa razy w RecipeController. Każde z nich mogło się rozjechać osobno —
 * a dokładnie taki rozjazd (różne miejsca, ta sama liczba, brak wspólnego
 * źródła) doprowadził do błędu z audytu A31 (post_max_size vs. config).
 *
 * Komunikaty są tutaj też, z tego samego powodu: dwie kopie tekstu
 * o tej samej liczbie potrafią się rozjechać równie łatwo, jak dwie kopie
 * samej liczby.
 */
final class LimityZdjec
{
    public static function maksBajtowJednegoZdjecia(): int
    {
        return (int) config('kuking.media.max_bytes');
    }

    /**
     * Reguła Laravel `max:` dla plików liczy w KILOBAJTACH. `floor`, celowo,
     * nie `round` — zaokrąglenie w górę przepuściłoby plik odrobinę większy
     * niż `max_bytes`, czyli walidacja pozwalałaby na więcej, niż obiecuje
     * konfiguracja.
     */
    public static function maksKilobajtowDoWalidacji(): int
    {
        return (int) floor(self::maksBajtowJednegoZdjecia() / 1024);
    }

    /** Do treści komunikatu — człowiek czyta megabajty, nie kilobajty. */
    public static function maksMegabajtowDoKomunikatu(): int
    {
        return (int) round(self::maksBajtowJednegoZdjecia() / 1024 / 1024);
    }

    /**
     * Ile zdjęć wolno dołączyć do JEDNEJ wysyłki (wpisu albo „Ugotowałem").
     *
     * Ta sama liczba steruje limitem w PostController I w
     * CookedEventController: to jest właśnie ta „jedna wysyłka" z audytu
     * A31, nie osobny limit na kontroler. Wcześniej „Ugotowałem" miało
     * wpisane na sztywno `max:4`, niezależnie od konfiguracji — ten sam
     * błąd, co rozjazd z `post_max_size`, tylko o jedno miejsce dalej.
     */
    public static function maksZdjecNaWysylke(): int
    {
        return (int) config('kuking.media.max_per_post');
    }

    public static function pomocPrzedWyslaniem(): string
    {
        $limit = self::maksZdjecNaWysylke();

        return 'Łącznie najwyżej '.$limit.' '.Odmiana::rzeczownik($limit, 'zdjęcie', 'zdjęcia', 'zdjęć')
            .', wliczając zdjęcia zachowane po poprzednim wysłaniu. Każdy plik do '
            .self::maksMegabajtowDoKomunikatu().' MB.';
    }

    /**
     * Ile pól plikowych ma formularz przepisu POZA krokami: zdjęcie gotowego
     * dania i zdjęcie starej kartki z zeszytu.
     */
    private const ZDJEC_STALYCH_W_FORMULARZU_PRZEPISU = 2;

    /**
     * Ile ZDJĘĆ KROKÓW wolno dołączyć do JEDNEGO zapisu przepisu.
     *
     * DLACZEGO TO NIE JEST „ILE KROKÓW, TYLE ZDJĘĆ"
     * Formularz przepisu bez JavaScriptu wysyła wszystko jednym POST-em,
     * razem ze zdjęciem gotowego dania i zdjęciem kartki. Budżet bajtów
     * jednego żądania to `post_max_size` z `docker/php.ini`, a jedno zdjęcie
     * może ważyć `max_bytes`. Przepis o dwudziestu krokach ze zdjęciem przy
     * każdym z nich to dwadzieścia dwa razy `max_bytes` — czyli żądanie,
     * które PHP odrzuca W CAŁOŚCI, razem z tokenem CSRF i całym wpisanym
     * tekstem. Człowiek widzi wtedy „Page Expired" i traci pracę (audyt A31).
     *
     * DLACZEGO WŁAŚNIE `maksZdjecNaWysylke()` MINUS DWA
     * Bo „ile zdjęć wchodzi w jedną wysyłkę" to pytanie, na które ten serwis
     * ma już odpowiedź, i jest to odpowiedź o tym samym budżecie bajtów —
     * ta, którą pilnuje `UploadLimitsAgreementTest` wobec `docker/php.ini`.
     * Osobna liczba obok byłaby drugą kopią tego samego limitu, czyli
     * dokładnie tym, przed czym istnieje cała ta klasa. Minus dwa, bo dwa
     * pola plikowe formularz przepisu ma zawsze.
     *
     * CENA, WPROST: przy dłuższym przepisie zdjęcia kroków dodaje się
     * w kilku zapisach, po kilka na raz. Da się, bo zdjęcie już zapisane
     * ZOSTAJE przy swoim kroku przy następnej edycji (`PublishRecipe`
     * rozwiązuje je po tożsamości kroku). Alternatywą było ciche gubienie
     * nadmiarowych zdjęć — a to jest gorsze niż limit, o którym się mówi.
     */
    public static function maksZdjecKrokowNaZapis(): int
    {
        return max(1, self::maksZdjecNaWysylke() - self::ZDJEC_STALYCH_W_FORMULARZU_PRZEPISU);
    }

    public static function komunikatZaDuzoZdjecKrokow(): string
    {
        $limit = self::maksZdjecKrokowNaZapis();

        return 'Za jednym razem można dodać najwyżej '.$limit.' '
            .Odmiana::rzeczownik($limit, 'zdjęcie', 'zdjęcia', 'zdjęć').' do kroków. '
            .'Zapisz przepis z tymi zdjęciami, a potem dodaj kolejne — '
            .'zdjęcia już zapisane zostaną przy swoich krokach.';
    }

    /**
     * Formaty, które naprawdę umiemy przetworzyć.
     *
     * @return list<string>
     */
    public static function dozwoloneTypy(): array
    {
        return array_values((array) config('kuking.media.accepted_mime_types'));
    }

    /**
     * Wartość atrybutu `accept` dla pola wyboru pliku.
     *
     * DLACZEGO Z KONFIGURACJI, A NIE WPISANA W WIDOKU
     * Ta lista stała wpisana na sztywno w SIEDMIU widokach. Gdy okazało się,
     * że HEIC-a nie umiemy przetworzyć, trzeba było poprawić siedem miejsc
     * zamiast jednego — a przeoczenie jednego z nich znaczyłoby, że jeden
     * formularz nadal podpowiada format, który serwis odrzuci. To ten sam
     * rodzaj rozjazdu, dla którego powstała cała ta klasa.
     *
     * `accept` NIE JEST WALIDACJĄ — to podpowiedź dla okna wyboru pliku,
     * którą da się obejść. Prawdziwe sprawdzenie jest w `StoreUploadedImage`,
     * po zawartości pliku. Ale ta podpowiedź ma znaczenie: iOS potrafi
     * przekonwertować zdjęcie HEIC do JPEG przy wysyłce WŁAŚNIE WTEDY, gdy
     * formularz nie deklaruje, że HEIC przyjmie. Deklarowanie go było więc
     * gorsze niż bezużyteczne — mogło wyłączać konwersję, która działa sama.
     */
    public static function atrybutAccept(): string
    {
        return implode(',', self::dozwoloneTypy());
    }

    /**
     * Nazwy formatów dla człowieka — do komunikatów, nie do walidacji.
     */
    public static function formatyDlaCzlowieka(): string
    {
        $nazwy = array_map(
            static fn (string $mime): string => match ($mime) {
                'image/jpeg' => 'JPG',
                'image/png' => 'PNG',
                'image/webp' => 'WebP',
                'image/avif' => 'AVIF',
                default => mb_strtoupper(str_replace('image/', '', $mime)),
            },
            self::dozwoloneTypy(),
        );

        if (count($nazwy) < 2) {
            return implode('', $nazwy);
        }

        $ostatni = array_pop($nazwy);

        return implode(', ', $nazwy).' albo '.$ostatni;
    }

    /**
     * KOMUNIKAT KOŃCZY SIĘ TYM, CO ZROBIĆ, a nie tym, co się stało.
     *
     * „Jedno ze zdjęć waży za dużo. Maksymalny rozmiar to 15 MB." mówiło
     * człowiekowi, że coś jest nie tak, i zostawiało go z tym: liczbę trzeba
     * było samemu przełożyć na czynność. AGENTS.md §11 i docs/UX_50_PLUS.md
     * żądają zdania, po którym wiadomo, co kliknąć — tak jak robi to bliźniaczy
     * komunikat w `StoreUploadedImage`, który tę końcówkę miał od początku.
     *
     * Zdanie musi pasować i do formularza z jednym polem (zdjęcie profilowe),
     * i do wysyłki kilku zdjęć naraz (wpis, przepis) — stąd „Jedno ze zdjęć"
     * na początku i „wybierz mniejsze" bez rzeczownika na końcu.
     */
    public static function komunikatZaDuzyPlik(): string
    {
        return 'Jedno ze zdjęć waży za dużo. Maksymalny rozmiar to '
            .self::maksMegabajtowDoKomunikatu().' MB — wybierz mniejsze.';
    }

    /**
     * Gdy zdjęcie odpadnie na wewnętrznym endpoincie uploadu Livewire.
     *
     * To jest INNA droga niż zwykła walidacja formularza: kreator przepisu
     * wysyła plik od razu po wyborze, a odpowiedź 422 z tamtego endpointu nie
     * trafia do worka błędów komponentu — zdarzenie `livewire-upload-error`
     * niesie tylko `{id, property}`, bez treści. Dlatego tekst musi powstać
     * po naszej stronie, inaczej pasek postępu po prostu znika i pole zostaje
     * puste bez słowa wyjaśnienia (issue #111).
     *
     * Mówi CO ZROBIĆ, nie co się stało wewnątrz.
     */
    public static function komunikatNieudanejWysylki(): string
    {
        return 'Nie udało się wysłać tego zdjęcia. Sprawdź, czy plik waży mniej niż '
            .self::maksMegabajtowDoKomunikatu().' MB, i spróbuj jeszcze raz.';
    }

    public static function komunikatZaDuzoZdjec(): string
    {
        $limit = self::maksZdjecNaWysylke();

        if ($limit === 1) {
            // Najczęstszy dziś przypadek (limit = 1) ma osobne zdanie,
            // bo "maksymalnie 1 zdjęć" jest po prostu złą polszczyzną.
            return 'Do jednej wysyłki można dodać tylko jedno zdjęcie. Pozostałe wyślij osobno.';
        }

        // Odmiana liczebnika NIE mieszka już tutaj (issue #86) — to była
        // druga kopia dokładnie tej samej reguły co w `Odmiana::rzeczownik()`
        // (nastki, 2-4 kontra 5+). Dwie kopie tej samej reguły to dokładnie
        // to, przed czym ostrzega ten sam problem: rozjeżdżają się osobno.
        return 'Do jednej wysyłki można dodać maksymalnie '.$limit.' '
            .Odmiana::rzeczownik($limit, 'zdjęcie', 'zdjęcia', 'zdjęć').'.';
    }
}
