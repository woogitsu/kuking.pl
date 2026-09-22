<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Numer sprawy RODO — JEDYNE, co łączy potwierdzenie obsługi żądania
 * z człowiekiem, który to żądanie złożył.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO OSOBNA KLASA, SKORO JEST `App\Support\NumerSprawy`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo te dwa numery są numerami W DWÓCH RÓŻNYCH REJESTRACH i człowiek, który
 * trzyma kartkę z numerem, musi umieć powiedzieć, o który rejestr chodzi.
 * `NumerSprawy` numeruje zgłoszenia moderacyjne (`reports`) i ma przedrostek
 * `KU`. Gdyby potwierdzenie obsługi żądania RODO dostało ten sam przedrostek
 * i ten sam kształt, to samo `KU-F6XC-9W7Y` znaczyłoby raz „Twoje zgłoszenie
 * cudzej treści", a raz „Twoje żądanie usunięcia konta" — a pyta się o nie
 * w dwóch różnych trybach, u dwóch różnych osób i z dwoma różnymi skutkami.
 *
 * ALFABET JEST WSPÓLNY I TO JEST ŚWIADOME: powód, dla którego z numeru
 * wypadły `0`, `1`, `I`, `L`, `O` i `U`, jest dokładnie ten sam (numer bywa
 * przepisywany ręcznie i dyktowany przez telefon), a druga kopia tej listy
 * rozjechałaby się z pierwszą przy pierwszej zmianie. Uzasadnienie doboru
 * znaków stoi przy `NumerSprawy::ALFABET` i nie jest tu powtarzane.
 *
 * UWAGA NA SPRZĘŻENIE: `NumerSprawy::ALFABET` jest ZAMROŻONY w CHECK-u
 * `reports_numer_sprawy_check`, a od tej migracji także w
 * `potwierdzenia_zadan_rodo_numer_check`. Zmiana tej stałej wymaga więc
 * DWÓCH migracji przebudowujących CHECK, nie jednej. Pilnują tego dwa testy:
 * `NumerSprawyTest::test_check_w_bazie_zna_ten_sam_alfabet_co_stala_w_php`
 * i `MinimalnePotwierdzenieRodoTest::test_check_w_bazie_zna_ten_sam_wzor_co_stala_w_php`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO DWANAŚCIE ZNAKÓW, A NIE OSIEM JAK W `NumerSprawy`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo te dwa numery niosą inny ciężar. Numer zgłoszenia moderacyjnego służy
 * DO ODNALEZIENIA sprawy, której właściciel jest znany z innych źródeł
 * (konto, adres, wątek). Ten numer jest natomiast jedynym powiązaniem
 * potwierdzenia z człowiekiem: po wykonaniu usunięcia konto jest
 * zanonimizowane, a w wierszu potwierdzenia NIE MA `konto_id` (pilnuje tego
 * CHECK w bazie). Kto zgadnie numer, ten zgłosi się po cudzą sprawę.
 *
 * Osiem znaków z tego alfabetu to 30^8 ≈ 6,6 · 10^11, czyli około 39 bitów.
 * Dwanaście znaków to 30^12 ≈ 5,3 · 10^17, czyli około 59 bitów — dwadzieścia
 * bitów różnicy przy jednej dodatkowej czwórce znaków do przepisania.
 *
 * CZEGO TEN NUMER MIMO TO NIE OBIECUJE — i to musi wybrzmieć, bo inaczej
 * ktoś potraktuje go jak hasło: **numer sam w sobie NIE JEST upoważnieniem**.
 * Nie otwiera żadnego ekranu, nie zwraca żadnych danych i nie ma trasy HTTP,
 * która by go przyjmowała. Jest identyfikatorem, który człowiek podaje
 * w korespondencji obsługiwanej przez człowieka — i dopiero tam rozstrzyga
 * się, czy rozmawiamy z wnioskodawcą. Gdyby kiedykolwiek powstał ekran
 * „wpisz numer, zobacz sprawę", ta klasa przestałaby wystarczać i trzeba
 * byłoby dołożyć drugi składnik.
 */
final class NumerZadaniaRodo
{
    /**
     * Przedrostek rejestru. Inny niż `NumerSprawy::PRZEDROSTEK` — patrz
     * komentarz klasy.
     */
    public const PRZEDROSTEK = 'RODO';

    /**
     * Ile znaków losujemy (bez przedrostka i myślników).
     */
    public const DLUGOSC = 12;

    /**
     * Wzór, któremu numer musi odpowiadać. Odpowiednikiem tej treści jest
     * CHECK w bazie; tutaj jest KOPIĄ do walidacji i do testów, i kopią,
     * która może się rozjechać — ta stała liczy się od nowa przy każdym
     * uruchomieniu PHP, a CHECK stoi w bazie w postaci z dnia migracji.
     */
    public const WZOR = '/^RODO-['.NumerSprawy::ALFABET.']{4}-['.NumerSprawy::ALFABET.']{4}-['.NumerSprawy::ALFABET.']{4}$/';

    /**
     * Nowy numer w postaci, w jakiej człowiek go zobaczy i zapisze.
     *
     * `random_int()`, nie `Str::random()` i nie `mt_rand()`: ten numer jest
     * jedynym rozpoznaniem sprawy, więc przewidywalny generator byłby tu
     * wadą, nie drobiazgiem. Alfabet jest poza tym węższy niż ten, który
     * daje `Str::random()`.
     */
    public static function wygeneruj(): string
    {
        $znaki = '';
        $ostatni = mb_strlen(NumerSprawy::ALFABET) - 1;

        for ($i = 0; $i < self::DLUGOSC; $i++) {
            $znaki .= mb_substr(NumerSprawy::ALFABET, random_int(0, $ostatni), 1);
        }

        return self::PRZEDROSTEK
            .'-'.mb_substr($znaki, 0, 4)
            .'-'.mb_substr($znaki, 4, 4)
            .'-'.mb_substr($znaki, 8, 4);
    }

    /**
     * Czy to jest numer w naszym formacie.
     */
    public static function poprawny(string $numer): bool
    {
        return Str::isMatch(self::WZOR, $numer);
    }
}
