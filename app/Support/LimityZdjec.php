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

    public static function komunikatZaDuzyPlik(): string
    {
        return 'Jedno ze zdjęć waży za dużo. Maksymalny rozmiar to '.self::maksMegabajtowDoKomunikatu().' MB.';
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
