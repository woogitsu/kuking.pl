<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Numer sprawy moderacyjnej — to, co człowiek zapisuje na kartce.
 *
 * DLACZEGO TO NIE JEST JUŻ FRAGMENT UUID-a
 * Do 7 września 2026 numer był ośmioma pierwszymi znakami UUID-a v7 wiersza
 * `reports`. Zmierzone: w UUID-zie v7 pierwsze 48 bitów to znacznik czasu
 * w milisekundach, więc osiem znaków szesnastkowych to jego 32 GÓRNE bity —
 * zmieniają się raz na 2^16 ms, czyli raz na 65,5 sekundy. Dwie sprawy
 * przyjęte w tym samym okienku dostawały ten sam numer.
 *
 * Dla zgłaszającego BEZ KONTA ten numer jest jedynym śladem sprawy: nie ma
 * konta, nie ma listy zgłoszeń, a poczty serwis dziś nie wysyła. Dwie sprawy
 * o tym samym numerze znaczą, że nie da się powiedzieć, o którą chodzi —
 * ani jemu, ani moderatorowi.
 *
 * DLACZEGO TEN ALFABET
 * 30 znaków: cyfry 2-9 i litery bez `I`, `L`, `O` i `U` (a więc i bez cyfr
 * `0` i `1`). Numer jest w tej grupie odbiorców przepisywany ręcznie z ekranu
 * i dyktowany przez telefon, a `0`/`O`, `1`/`I` oraz `1`/`L` są wtedy tym
 * samym znakiem. `U` znika, żeby z losowych ośmiu znaków nie ułożyło się
 * przypadkiem słowo — ten numer pojawia się w piśmie urzędowym.
 *
 * DLACZEGO OSIEM ZNAKÓW
 * 30^8 = 656 100 000 000 kombinacji. Prawdopodobieństwo, że dwie z N spraw
 * wylosują ten sam numer, przekracza 50% dopiero przy N ≈ 954 tys. spraw
 * (przybliżenie urodzinowe). Przy zamkniętej becie liczonej w setkach osób
 * to jest liczba nieosiągalna — a gdyby kiedykolwiek przestała być, decyduje
 * o tym pomiar, nie przeczucie. Unikalności pilnuje przy tym BAZA
 * (`reports_numer_sprawy_unique`), nie ta klasa: losowanie może się powtórzyć,
 * indeks nie pozwoli zapisać powtórzenia.
 */
final class NumerSprawy
{
    /**
     * Znaki, z których losujemy — patrz komentarz klasy.
     *
     * Znaki NIEDOPUSZCZALNE są wypisane osobno w `ZNAKI_MYLACE`, a nie tylko
     * pominięte tutaj: pierwsza wersja tej stałej miała w sobie `U`, mimo że
     * komentarz obok mówił, że go nie ma. Wyszło to dopiero na wygenerowanym
     * numerze `KU-F6XC-9U7Y` — test sprawdzał LOSOWY wynik, więc przechodził
     * w około trzech na cztery przebiegi: szansa, że osiem losowanych znaków
     * ani razu nie trafi w `U`, to (29/30)^8 ≈ 76% (ta sama liczba stoi
     * w `tests/Feature/NumerSprawyTest.php` i w D-029).
     *
     * UWAGA: tej stałej NIE WOLNO zmienić samą edycją tego pliku. CHECK
     * `reports_numer_sprawy_check` dostał jej treść wklejoną na stałe w chwili
     * uruchomienia migracji `2026_09_07_910000_add_numer_sprawy_to_reports`
     * i od tamtej pory jest w bazie zamrożony. Nowy znak w alfabecie oznacza,
     * że `wygeneruj()` prędzej czy później wyprodukuje numer, którego baza nie
     * przyjmie — a wtedy KAŻDE nowe zgłoszenie (także droga DSA art. 16 dla
     * osób bez konta) kończy się błędem 500. Zmiana alfabetu = nowa migracja
     * przebudowująca CHECK. Pilnuje tego
     * `NumerSprawyTest::test_check_w_bazie_zna_ten_sam_alfabet_co_stala_w_php`.
     */
    public const ALFABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Znaki, których w numerze być NIE MOŻE — do sprawdzenia w teście wprost
     * na alfabecie, a nie na wylosowanej wartości.
     */
    public const ZNAKI_MYLACE = ['0', '1', 'I', 'L', 'O', 'U'];

    /**
     * Przedrostek. Stały, żeby numer dało się rozpoznać jako numer sprawy
     * Kuking, gdy trafi do cudzej korespondencji albo do pisma.
     */
    public const PRZEDROSTEK = 'KU';

    /**
     * Wzór, któremu numer musi odpowiadać. Odpowiednikiem tej treści jest
     * CHECK w bazie (`reports_numer_sprawy_check`) — tam jest regułą, tutaj
     * jest kopią do walidacji i do testów. Kopią, która MOŻE się rozjechać:
     * ta stała liczy się od nowa przy każdym uruchomieniu PHP, a CHECK stoi
     * w bazie w postaci zapisanej w dniu migracji. Patrz komentarz przy
     * `ALFABET`.
     */
    public const WZOR = '/^KU-['.self::ALFABET.']{4}-['.self::ALFABET.']{4}$/';

    /**
     * Nowy numer sprawy w postaci, w jakiej człowiek go zobaczy.
     *
     * `Str::random()` nie wchodzi w grę: daje znaki spoza tego alfabetu.
     * Losujemy z `random_int()`, czyli ze źródła kryptograficznego — numer
     * sprawy jest jednocześnie jedynym „hasłem" do jej rozpoznania, więc
     * przewidywalny generator byłby tu wadą, nie drobiazgiem.
     */
    public static function wygeneruj(): string
    {
        $znaki = '';
        $ostatni = mb_strlen(self::ALFABET) - 1;

        for ($i = 0; $i < 8; $i++) {
            $znaki .= mb_substr(self::ALFABET, random_int(0, $ostatni), 1);
        }

        return self::PRZEDROSTEK.'-'.mb_substr($znaki, 0, 4).'-'.mb_substr($znaki, 4, 4);
    }

    /**
     * Czy to jest numer sprawy w naszym formacie.
     */
    public static function poprawny(string $numer): bool
    {
        return Str::isMatch(self::WZOR, $numer);
    }
}
