<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * JEDNA ODPOWIEDŹ NA PYTANIE „co wolno pokazać człowiekowi z powrotem".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TA KLASA ISTNIEJE (audyt W7-03, W7-04, W7-12)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Produkt ma regułę, której nie chcemy stracić: POPRAWNIE WPISANE DANE
 * NIGDY NIE ZNIKAJĄ (docs/UX_50_PLUS.md). Człowiek, który napisał przepis
 * i trafił na wygasłą sesję albo na własne zawieszenie, nie ma go pisać
 * drugi raz.
 *
 * Ta reguła była jednak realizowana w DWÓCH miejscach, każde z własną
 * czarną listą nazw pól — i obie te listy były dziurawe w tym samym
 * momencie, z tego samego powodu:
 *
 *   1. `EnsureAccountIsActive` wołało gołe `back()->withInput()`. Osoba
 *      zawieszona, która wysłała formularz ZMIANY HASŁA, wkładała w ten
 *      sposób `password` i `current_password` do sesji, skąd `old()`
 *      wstawiało je z powrotem do `value=` pola typu password;
 *
 *   2. `OdzyskanyFormularz` (ekran 419) filtrowało po fragmentach nazw:
 *      `password`, `haslo`, `token`, `secret`, `otp`, `cvv`. Ekran drugiego
 *      składnika nazywa swoje pola `code` i `backup_code` — żadne z nich
 *      nie zawiera którejkolwiek z tych sześciu cząstek. Kod jednorazowy
 *      żyje 30 sekund, ale KOD ZAPASOWY jest ważny do użycia i wracał
 *      do HTML-a w `<input type="hidden" value="...">`.
 *
 * Komentarz w tamtej klasie mówił „hasła i pola wrażliwe nie wracają".
 * Kod znaczył co innego: „nie wracają pola, których nazwa zawiera jedną
 * z sześciu cząstek". To są dwie różne obietnice i różnica między nimi
 * była kodem zapasowym do drugiego składnika.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ZASADA: ZGODA PO NAZWIE TRASY, NIE ZAKAZ PO NAZWIE POLA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Czarna lista nazw pól przegrywa zawsze, bo wymaga, żeby autor NASTĘPNEGO
 * formularza z sekretem nazwał pole tak, jak ktoś przewidział rok wcześniej.
 * Nazwa pola jest po stronie tego, kto pisze formularz; nazwa trasy jest po
 * stronie tego, kto pisze tę listę.
 *
 * Dlatego: odzyskujemy TYLKO na trasach wymienionych w `TRASY_TRESCI`.
 * Wszystko inne — w tym każda nowa trasa, każda trasa bez nazwy (`/login`,
 * `/register`, `/ustawienia/profil`) i każdy formularz, o którym nikt tu
 * nie pomyślał — nie odzyskuje NICZEGO. Nowy ekran logowania nie ma jak
 * przez przypadek trafić na tę listę.
 *
 * Filtr po nazwach pól ZOSTAJE jako druga warstwa, nie jako jedyna: gdyby
 * kiedyś na trasę treści trafiło pole z sekretem (np. hasło do cudzej
 * strony w formularzu importu), i tak nie wróci.
 */
final class OdzyskiwalneDane
{
    /**
     * Trasy, na których wolno odłożyć i pokazać z powrotem to, co człowiek
     * wpisał.
     *
     * Kryterium wpisania na listę jest jedno: CZY NA TEJ TRASIE CZŁOWIEK
     * PISZE COŚ WŁASNYMI SŁOWAMI, czego nie odtworzy z pamięci w dziesięć
     * sekund. Przepis, komentarz, zgłoszenie, odwołanie, uzasadnienie
     * decyzji moderacyjnej — tak. Zaznaczenie trzech pól wyboru
     * w ustawieniach — nie, bo koszt utraty jest niższy niż koszt
     * pilnowania kolejnej pozycji na tej liście.
     *
     * NIE MA TU I NIE MOŻE BYĆ: logowania, rejestracji, drugiego składnika,
     * zmiany i przypomnienia hasła, włączania i wyłączania 2FA, generowania
     * kodów zapasowych, potwierdzenia usunięcia konta ani cofnięcia
     * usunięcia konta.
     */
    private const TRASY_TRESCI = [
        // Treść społeczności
        'posts.store',
        'questions.store',
        'recipes.store',
        'recipes.update',
        'posts.comment',
        'recipes.comment',
        'cooked.comment',
        'comments.update',
        'cooked.store',

        // Pisma: zgłoszenia i odwołania. Tu utrata tekstu boli najbardziej,
        // bo pisze je człowiek zdenerwowany i często długo.
        'reports.store',
        'zglos.nielegalna.store',
        'appeals.store',
        'appeals.guest.store',

        // Moderator też pisze własnymi słowami — uzasadnienie decyzji
        // i odpowiedź na wpis bez odpowiedzi.
        'admin.reports.decide',
        'admin.appeals.resolve',
        'admin.unanswered.reply',
        // Odpowiedź na wiadomość z „Napisz do nas" (D-058). Ten sam powód co
        // wyżej, wzmocniony tym, że po drugiej stronie czeka konkretny
        // człowiek: tekst pisany kwadrans nie ma przepadać przez wygasłą
        // sesję ani przez awarię poczty.
        'admin.contact.reply',
    ];

    /**
     * Druga warstwa: fragmenty nazw pól, które nie wracają NIGDY, nawet
     * z trasy treści.
     *
     * Dopasowanie po fragmencie, nie po pełnej nazwie: `password` łapie też
     * `password_confirmation` i `current_password`. `code` celowo NIE jest
     * fragmentem — złapałby `postal_code` i `reason_code`, czyli pola bez
     * sekretu; kody jednorazowe i zapasowe stoją niżej pod pełną nazwą.
     */
    private const FRAGMENTY_WRAZLIWE = [
        'password',
        'haslo',
        'hasło',
        'token',
        'secret',
        'otp',
        'cvv',
    ];

    /** Pełne nazwy pól z sekretem — te, których fragment by nie złapał. */
    private const NAZWY_WRAZLIWE = [
        'code',
        'backup_code',
        'recovery_code',
        'verification_code',
        'pin',
    ];

    /** Pola techniczne — wracają inną drogą albo nie wracają wcale. */
    public const POLA_TECHNICZNE = ['_token', '_method'];

    /**
     * Czy na tej trasie wolno cokolwiek odzyskiwać.
     *
     * Trasa bez nazwy zwraca `false` i to jest zamierzone: `/login`,
     * `/register` i `/ustawienia/profil` nie mają dziś nazw, więc odpadają
     * same, bez pamiętania o nich.
     */
    public static function wolnoOdzyskac(Request $request): bool
    {
        $nazwa = $request->route()?->getName();

        return $nazwa !== null && in_array($nazwa, self::TRASY_TRESCI, true);
    }

    /**
     * To, co wolno odłożyć z tego żądania. Pusta tablica znaczy „nic".
     *
     * @return array<string, mixed>
     */
    public static function zZadania(Request $request): array
    {
        if (! self::wolnoOdzyskac($request)) {
            return [];
        }

        $dane = $request->except(self::POLA_TECHNICZNE);

        return array_filter(
            $dane,
            static fn (string $klucz): bool => ! self::jestWrazliwe($klucz),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Czy pole o tej nazwie niesie sekret.
     *
     * Publiczne, bo używa tego również `OdzyskanyFormularz`, który spłaszcza
     * zagnieżdżone klucze (`skladniki.0.tekst`) i sprawdza każdy z osobna.
     */
    public static function jestWrazliwe(string $klucz): bool
    {
        $maly = mb_strtolower($klucz);

        // Ostatni segment, bo klucz bywa spłaszczony: `dane.0.backup_code`.
        $ostatni = mb_strtolower((string) mb_strrchr('.'.$maly, '.', false));
        $ostatni = ltrim($ostatni, '.');

        if (in_array($ostatni, self::NAZWY_WRAZLIWE, true)) {
            return true;
        }

        foreach (self::FRAGMENTY_WRAZLIWE as $fragment) {
            if (str_contains($maly, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
