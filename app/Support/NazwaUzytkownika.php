<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Profile;
use App\Rules\ReservedUsername;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * UŁOŻENIE NAZWY UŻYTKOWNIKA Z TEGO, CO CZŁOWIEK NAPRAWDĘ WPISAŁ.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * 63-letnia mama właściciela zakładała konto i dostała komunikat: „Nazwa
 * użytkownika może zawierać tylko litery bez polskich znaków, cyfry
 * i podkreślnik. Na przykład: basia_z_podkarpacia." — i nie zrozumiała, o co
 * chodzi. Trzy rzeczy były w tym złe naraz:
 *
 *   1. komunikat mówił językiem REGUŁY („polskie znaki", „podkreślnik"),
 *      a nie językiem człowieka;
 *   2. nie mówił, CO ONA ZROBIŁA ŹLE — nie wskazywał ani jednego znaku;
 *   3. kazał jej wymyślić coś na nowo, zamiast poprawić to, co wpisała.
 *
 * To jest pierwszy ekran produktu i najgorsze możliwe miejsce na taką
 * przeszkodę: człowiek, który odbije się o nią przy rejestracji, nie ma
 * jeszcze żadnego powodu, żeby próbować drugi raz.
 *
 * ROZWIĄZANIE: NORMALIZUJEMY PRZED WALIDACJĄ, TAK JAK ADRES E-MAIL
 * Dokładnie ten sam wzorzec, który stoi w `RegisterController` przy e-mailu
 * (audyt A25): wartość jest sprowadzana do postaci docelowej PRZED
 * walidacją, więc wszystkie dalsze reguły — `ReservedUsername`,
 * `UsernameNotTaken`, `regex`, `min`, `max` — pracują na tym, co naprawdę
 * trafi do bazy i do adresu profilu.
 *
 * TA KOLEJNOŚĆ JEST WARUNKIEM BEZPIECZEŃSTWA, NIE WYGODY. Gdyby
 * normalizacja szła PO sprawdzeniu nazw zarezerwowanych, „ądmin" przeszłoby
 * kontrolę jako nazwa nieznana i zamieniło się w „admin" dopiero przy
 * zapisie. Pilnuje tego osobny test.
 *
 * CZEGO TA KLASA NIE ROBI: nie rusza wartości, która JUŻ jest poprawna.
 * Kto wpisał `Basia_1971`, dostaje dokładnie `Basia_1971` — z wielką literą
 * i wszystkim. Poprawiamy tylko to, co inaczej skończyłoby się odmową.
 */
final class NazwaUzytkownika
{
    /** Ta sama reguła, co w walidacji formularzy — jedno miejsce prawdy. */
    public const WZORZEC = '/^[a-zA-Z0-9_]+$/';

    public const MIN = 3;

    public const MAX = 40;

    /**
     * Nazwa gotowa do zapisania — albo pusty napis, gdy nie da się nic ułożyć.
     *
     * Pusty wynik jest normalnym wynikiem, nie błędem: ktoś wpisał same emoji
     * albo same znaki interpunkcyjne. Wtedy odpowiada walidacja formularza,
     * bo tylko ona wie, jak o tym powiedzieć na tym konkretnym ekranie.
     */
    public static function znormalizuj(string $wpisane): string
    {
        $wpisane = trim($wpisane);

        // WARTOŚĆ POPRAWNA ZOSTAJE NIETKNIĘTA — patrz komentarz klasy.
        if (preg_match(self::WZORZEC, $wpisane) === 1) {
            return mb_substr($wpisane, 0, self::MAX);
        }

        // Transliteracja PRZED opuszczeniem wielkości liter: `Str::ascii`
        // zna „Ł" i „ł" osobno, a `mb_strtolower` po transliteracji nie ma
        // już czego rozpoznać.
        $nazwa = Str::ascii($wpisane);
        $nazwa = mb_strtolower($nazwa);

        // Spacja, kropka, łącznik i apostrof to znaki, którymi ludzie
        // NATURALNIE rozdzielają słowa („Basia Kowalska", „basia.kowalska",
        // „O'Brien"). Zamieniamy je na podkreślnik, zamiast wyrzucać —
        // inaczej „Basia Kowalska" zrobiłaby się „basiakowalska", czyli
        // czymś, czego ona sama by nie rozpoznała w adresie.
        $nazwa = (string) preg_replace('/[\s.\-\'’]+/u', '_', $nazwa);

        // Wszystko inne (emoji, przecinki, nawiasy, znaki spoza ASCII, których
        // transliteracja nie objęła) po prostu wypada.
        $nazwa = (string) preg_replace('/[^a-z0-9_]/', '', $nazwa);

        // Podkreślniki: nie dwa pod rząd, nie na brzegach.
        $nazwa = trim((string) preg_replace('/_+/', '_', $nazwa), '_');

        return mb_substr($nazwa, 0, self::MAX);
    }

    /**
     * Wolna nazwa do zaproponowania człowiekowi, gdy jego własna jest zajęta.
     *
     * Dokłada liczbę na końcu, bo to jedyna zmiana, którą człowiek rozpozna
     * jako „to nadal ja". Sprawdza zajętość BEZ rozróżniania wielkości liter,
     * tak samo jak `UsernameNotTaken` i jak logowanie.
     *
     * `null`, gdy nie ma z czego ułożyć propozycji — wtedy ekran mówi
     * o tym wprost, zamiast pokazywać „_2".
     */
    public static function wolnaPropozycja(string $wpisane, int $ileProb = 30): ?string
    {
        $baza = self::znormalizuj($wpisane);

        if (mb_strlen($baza) < self::MIN) {
            return null;
        }

        if (self::dopuszczalna($baza) && ! self::zajeta($baza)) {
            return $baza;
        }

        for ($i = 2; $i <= $ileProb; $i++) {
            $sufiks = '_'.$i;
            $propozycja = mb_substr($baza, 0, self::MAX - mb_strlen($sufiks)).$sufiks;

            if (self::dopuszczalna($propozycja) && ! self::zajeta($propozycja)) {
                return $propozycja;
            }
        }

        return null;
    }

    private static function dopuszczalna(string $nazwa): bool
    {
        return Validator::make(['username' => $nazwa], ['username' => [new ReservedUsername]])->passes();
    }

    private static function zajeta(string $nazwa): bool
    {
        return Profile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($nazwa)])
            ->exists();
    }
}
