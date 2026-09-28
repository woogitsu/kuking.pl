<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nazwa użytkownika zastrzeżona dla obsługi serwisu (issue #42).
 *
 * Dziś `moderacja` i `basia_z_podkarpacia` są tak samo dostępne przy
 * rejestracji. Konto o nazwie sugerującej obsługę Kuking jest gotowym
 * narzędziem phishingu — a użytkownik 50+ ma prawo założyć, że @pomoc
 * to naprawdę my.
 *
 * Reguła jest JEDNA i wpina się w obie ścieżki nadawania nazwy (rejestracja
 * i ustawienia profilu). Sama lista mieszka w `config/kuking.php`.
 *
 * ---------------------------------------------------------------------------
 * GDZIE STAWIAMY GRANICĘ NORMALIZACJI (najważniejsza decyzja w tej klasie)
 * ---------------------------------------------------------------------------
 *
 * Zasada: normalizujemy WYGLĄD znaku, nigdy treść nazwy.
 *
 * Wchodzi do normalizacji — bo dwie takie nazwy czyta się jako tę samą:
 *
 *   1. wielkość liter          `Moderacja`   = `mODERACJA`
 *   2. polskie znaki           `obsługa`     = `obsluga`
 *   3. homoglify cyfr i znaków `m0deracja`   = `moderacja`, `4dm1n` = `admin`
 *   4. znaki rozdzielające     `m_o_d_e_r_a_c_j_a` = `moderacja`
 *
 * NIE wchodzi — bo blokowałoby nazwy niewinne:
 *
 *   * dopasowanie do FRAGMENTU. Porównujemy całe nazwy, nie prefiksy.
 *     Ktoś naprawdę nazywa się Adminowicz, a „kontaktowa_ania" i „pomocnik"
 *     to zwykłe nicki. Zablokowanie ich to też błąd — i to taki, którego
 *     użytkownik nie zrozumie i nie obejdzie inaczej niż rezygnacją;
 *   * sklejanie powtórzonych liter (`anna` → `ana`). Psuje polskie imiona,
 *     a `addmin` i tak nie jest wiarygodnym podszyciem się;
 *   * dwuznaki udające literę (`rn` → `m`). Trafiłoby w „darnowa", „czarna";
 *   * podobieństwo edycyjne (Levenshtein) i fonetyczne. Przy progu 1 blokuje
 *     `kucking`, `adamin`, `pomost`; przy progu 0 nic nie wnosi.
 *
 * Świadomie zostawiona dziura: `admin2024` przechodzi (cyfra `2` nie jest
 * homoglifem żadnej litery, więc całe nazwy się różnią). Zamknięcie jej
 * wymagałoby dopasowania do fragmentu, czyli zablokowania „Adminowicza".
 * Wybieramy przepuszczenie kilku nazw podobnych zamiast blokowania nazw
 * prawdziwych — od reszty jest moderacja i zgłoszenia.
 *
 * Ta sama funkcja normalizuje obie strony porównania. Dzięki temu w configu
 * piszemy `obsluga`, a nie listę wariantów zapisu.
 */
class ReservedUsername implements ValidationRule
{
    /**
     * Polskie znaki → ich odpowiedniki bez ogonków.
     *
     * Kolumna `username` dopuszcza dziś tylko [a-zA-Z0-9_], więc `obsługa`
     * odpadnie już na regexie. Mapę trzymamy mimo to: reguła ma działać
     * poprawnie także wtedy, gdy ktoś kiedyś rozluźni tamten warunek albo
     * użyje tej klasy do innego pola. Zabezpieczenie, które zależy
     * od kolejności reguł w innym pliku, jest zabezpieczeniem pozornym.
     *
     * @var array<string, string>
     */
    private const DIACRITICS = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
    ];

    /**
     * Znaki, które na ekranie wyglądają jak litera.
     *
     * `l`, `1`, `|` i `!` sprowadzamy do JEDNEJ postaci, bo w typowym kroju
     * pisma są nie do odróżnienia — inaczej `zespo1` przeszłoby obok `zespol`.
     * Ujednolicenie działa na obie strony porównania, więc `zespol` z configu
     * i `zespo1` z formularza spotykają się na `zespoi`.
     *
     * Świadomie pomijamy cyfry wieloznaczne (`2`, `6`, `9`): każda udaje
     * kilka różnych liter naraz, a każde takie zgadywanie to kolejna szansa
     * na zablokowanie kogoś, kto nikogo nie udaje.
     *
     * Klucze `'0'`, `'1'` itd. PHP zamienia na liczby — dla `strtr()` to bez
     * różnicy, ale typ musi to mówić (issue #1731).
     *
     * @var array<int|string, string>
     */
    private const HOMOGLYPHS = [
        '0' => 'o',
        '1' => 'i', 'l' => 'i', '|' => 'i', '!' => 'i',
        '3' => 'e',
        '4' => 'a',
        '5' => 's',
        '7' => 't',
        '8' => 'b',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $kandydat = self::normalize($value);

        if ($kandydat === '') {
            return;
        }

        foreach (self::reserved() as $zastrzezona) {
            if ($kandydat === self::normalize($zastrzezona)) {
                // Komunikat mówi, CO ZROBIĆ (docs/UX_50_PLUS.md) i nie tłumaczy,
                // dlaczego nazwa jest zajęta — to byłaby podpowiedź dla kogoś,
                // kto szuka listy kont obsługi. Bez gry słowem `kuKING`:
                // w komunikacie błędu jest ona zakazana (D-009).
                $fail('Ta nazwa jest zarezerwowana. Wybierz inną.');

                return;
            }
        }
    }

    /**
     * Sprowadza nazwę do postaci porównywalnej. Granice — patrz opis klasy.
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, self::DIACRITICS);
        $value = strtr($value, self::HOMOGLYPHS);

        // Znaki rozdzielające (`_`, `-`, `.`, spacja) i wszystko, co zostało
        // poza [a-z0-9], wycinamy na końcu — po zamianie polskich znaków,
        // żeby `ł` zdążyło stać się literą, a nie zniknęło jako „obcy znak".
        return (string) preg_replace('/[^a-z0-9]+/', '', $value);
    }

    /**
     * @return array<int, string>
     */
    private static function reserved(): array
    {
        /** @var array<int, string> $lista */
        $lista = config('kuking.account.reserved_usernames', []);

        return $lista;
    }
}
