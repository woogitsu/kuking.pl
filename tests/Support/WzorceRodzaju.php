<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Wzorce łapiące teksty, które przypisują czytelnikowi płeć (issue #274).
 *
 * DLACZEGO TO STOI W OSOBNEJ KLASIE, A NIE W TEŚCIE
 * Pilnują tej samej zasady — `docs/brand/COPY_STYLE.md` §2 — dwa testy, na
 * dwóch różnych powierzchniach:
 *
 *   - `Tests\Feature\TekstyNiePrzypisujaPlciTest` skanuje PRODUKT: widoki,
 *     teksty prawne, tłumaczenia, napisy składane w PHP;
 *   - `Tests\Feature\PrzewodnikTrzymaSieWlasnychZasadTest` skanuje
 *     PRZEWODNIK: gotowe teksty do wklejenia w `docs/brand/` (issue #38).
 *
 * Drugi powstał dlatego, że pierwszy nie czytał `docs/` — i w §6 przewodnika
 * stały jako wzór do skopiowania te same frazy („będziesz mogła", „pierwsza
 * albo pierwszy"), które w produkcie zostały naprawione przy #274.
 *
 * Gdyby każdy z tych testów miał własną kopię wzorców, poprawienie wzorca
 * w jednym miejscu zostawiałoby drugi ślepy — a różnicy nie widać w wyniku,
 * bo oba są wtedy zielone. Wzorce, wyjątki i homografy żyją więc tu, w jednym
 * egzemplarzu, a testy różnią się WYŁĄCZNIE tym, co czytają.
 *
 * Wyjątki dodatkowe (`$dodatkoweWyjatki` w `trafienia()`) są celowo parametrem,
 * nie dopiskiem do `WYJATKI`: wyjątek potrzebny w przewodniku (nagłówek hasła
 * głównego z wielkiej litery) nie ma prawa rozluźniać skanu produktu.
 */
final class WzorceRodzaju
{
    /**
     * WYJĄTKI — trzy nazwane brzmienia i jedno zdanie diagnostyczne.
     *
     * Lista jest krótka celowo. Szeroki wyjątek („cały ten plik", „słowo
     * «ugotowałeś» wszędzie") sprawia, że test przestaje czegokolwiek pilnować,
     * a wygląda, jakby pilnował. Każda pozycja to DOKŁADNY fragment tekstu,
     * nie plik i nie katalog:
     *
     *   1-2. Hasło główne. COPY_STYLE.md §2 mówi wprost: „«ugotowałeś»
     *        w haśle głównym jest już utrwalone i zostaje". To decyzja marki,
     *        nie przeoczenie — dotyczy claimu „Pokaż, co dziś ugotowałeś",
     *        stopki listów, tytułu strony i przycisku „Dodaj zdjęcie tego,
     *        co ugotowałeś".
     *   3.   „Ugotowałem" z wielkiej litery — NAZWA PRZYCISKA brana
     *        w cudzysłów, ustalona w PR #235. Cytowanie nazwy przycisku nie
     *        mówi nic o płci czytelnika. Zapis małą literą wyjątku nie ma.
     *   4.   Udawany wpis podawany modelowi moderacji w `kuking:sprawdz-model`.
     *        To treść użytkownika w roli próbki, nie tekst serwisu do nikogo —
     *        i akurat na niej sprawdzamy, że model nie flaguje zwykłego rosołu.
     *
     * Wyjątki są WYCINANE z linii przed dopasowaniem wzorców, nie „zerują"
     * całej linii. Zdanie, w którym obok hasła głównego wróci nowa forma
     * rodzajowa, i tak obleje.
     *
     * @var array<string, string> fragment => dlaczego wolno
     */
    public const WYJATKI = [
        'co dziś ugotowałeś' => 'hasło główne, utrwalone w COPY_STYLE.md §2',
        'co ugotowałeś' => 'to samo hasło w przycisku dodawania zdjęcia',
        'Ugotowałem' => 'nazwa przycisku w cudzysłowie (PR #235)',
        'Dziś ugotowałam rosół' => 'udawany wpis podawany modelowi moderacji',
    ];

    /**
     * Słowa, które tylko WYGLĄDAJĄ jak forma rodzajowa.
     *
     * To nie są wyjątki od zasady — to homografy. „hasłem" jest narzędnikiem
     * rzeczownika „hasło", „wysyłam" pierwszą osobą czasu teraźniejszego:
     * ani jedno, ani drugie nie mówi nic o płci. Polszczyzna nie odróżnia ich
     * pisownią od „zrobiłem", więc jedynym sposobem jest lista.
     *
     * Dopisując tu słowo, sprawdź, czy naprawdę nie jest formą rodzajową.
     * Jeśli jest — poprawia się zdanie, nie tę listę.
     *
     * @var list<string>
     */
    public const HOMOGRAFY = [
        // Narzędnik rzeczowników na „-ło" i „-ł" — „pod tłem", „jednym kliknięciem
        // i hasłem". Kończą się na „-łem" dokładnie jak „zrobiłem".
        'hasłem', 'tytułem', 'udziałem', 'przedziałem', 'dziełem', 'ciałem',
        'działem', 'kołem', 'stołem', 'czołem', 'tłem', 'źródłem', 'sygnałem',
        'kanałem', 'oryginałem', 'materiałem', 'szczegółem', 'rosołem',
        'popiołem', 'kotłem', 'żywiołem', 'zapałem', 'upałem', 'szałem',
        // Czas teraźniejszy czasowników na „-syłać": „Wysyłam wiadomość".
        'wysyłam', 'przesyłam', 'odsyłam', 'przysyłam', 'posyłam',
        'rozsyłam', 'nadsyłam', 'dosyłam', 'zsyłam',
        // Przymiotnik „słaby" w narzędniku — „przy słabym zasięgu" stoi
        // w tekstach o braku skryptu i zawiera w sobie „-łabym".
        'słabym',
    ];

    /**
     * Wzorce. Każdy ma w komentarzu przykład z tego repozytorium, bo wzorzec
     * bez przykładu jest nieweryfikowalny przy czytaniu.
     *
     * @var array<string, string>
     */
    public const WZORCE = [
        // Pierwsza i druga osoba czasu przeszłego oraz tryb przypuszczający:
        // „gotowałam", „zablokowałaś", „wpisałeś", „zdecydowałeś", „chciałabyś".
        'osoba' => '/\p{L}+ł(?:am|aś|em|eś|abym|abyś|bym|byś)(?![\p{L}])/u',

        // Czas przyszły złożony: „Za rok będziesz mogła tu wrócić",
        // „będziesz miał". Neutralnie: „będziesz mieć", „zobaczysz".
        'przyszlosc' => '/\bbędziesz\s+\p{L}*ł[ao]?(?![\p{L}])/iu',

        // Ukośnik rodzajowy z DOKLEJONĄ KOŃCÓWKĄ: „prosiłaś/eś",
        // „zalogowana/y", „podziękowałaś/eś". Po ukośniku stoi sama końcówka,
        // bez liter przed nią — dlatego „usuwa/anonimizuje" w opisie komendy
        // artisan się tu nie łapie i łapać nie ma.
        'ukosnik_koncowka' => '/\p{L}+(?:ł(?:a|am|aś)|na|ta|a|ą)\s*\/\s*(?:e|em|eś|y|ym|ego|ł|łem|łeś)(?![\p{L}\-])/u',

        // To samo z formą męską na pierwszym miejscu: „Zapisałem/am kody".
        'ukosnik_koncowka_odwrotnie' => '/\p{L}+ł(?:em|eś|y)\s*\/\s*(?:am|aś|a)(?![\p{L}\-])/u',

        // Ukośnik rodzajowy z POWTÓRZONYM SŁOWEM: „Zrobiłam/zrobiłem",
        // „ugotowała/ugotował". Warunkiem jest ten sam rdzeń po obu stronach
        // (`\1`) — inaczej wzorzec zapalałby się na każdym „to/albo tamto".
        // Myślnik po ukośniku też wyklucza trafienie: „/ustawienia/e-mail"
        // jest adresem, nie formą rodzajową.
        'ukosnik_powtorzony' => '/\b(\p{L}{3,}?)(?:ł(?:a|am|aś)|a|ą)\s*\/\s*\1(?:ł(?:|em|eś)|y|e|ym|ego)(?![\p{L}])/iu',

        // Nawias rodzajowy: „zalogowany(a)", „pierwsz(a)".
        'nawias' => '/\p{L}{3,}\((?:a|ą|y|e|ła|em|eś)\)/u',

        // Dwie formy obok siebie, rozpisane słowami: „pierwsza albo pierwszy",
        // „zakładałaś albo zakładałeś". COPY_STYLE.md §2 każe zmienić
        // konstrukcję zdania, a nie wymienić oba warianty — to jest dłuższe,
        // potyka się przy czytaniu na głos i nadal zwraca uwagę na płeć.
        'dwie_formy' => '/\b(\p{L}{3,}?)(?:a|ą|ła|łaś|łam)\s+(?:albo|lub|czy)\s+\1(?:y|i|e|ł|łeś|łem|ym)?(?![\p{L}])/u',
        'dwie_formy_odwrotnie' => '/\b(\p{L}{3,}?)(?:y|i|ł|łeś|łem|ym)\s+(?:albo|lub|czy)\s+\1(?:a|ą|ła|łaś|łam)(?![\p{L}])/u',
    ];

    /**
     * Trafienia w tekście, linia po linii, po wycięciu nazwanych wyjątków
     * i homografów.
     *
     * @param  array<string, string>  $dodatkoweWyjatki  fragment => dlaczego wolno;
     *                                                   obowiązuje TYLKO w tym wywołaniu
     * @return list<string> „numer linii → cytat"
     */
    public static function trafienia(string $tresc, array $dodatkoweWyjatki = []): array
    {
        $wyjatki = array_merge(self::WYJATKI, $dodatkoweWyjatki);
        $trafienia = [];

        foreach (explode("\n", $tresc) as $numer => $linia) {
            $doSprawdzenia = str_replace(array_keys($wyjatki), ' ', $linia);

            foreach (self::WZORCE as $nazwa => $wzorzec) {
                if (preg_match_all($wzorzec, $doSprawdzenia, $dopasowania) === 0) {
                    continue;
                }

                foreach ($dopasowania[0] as $dopasowanie) {
                    if (self::jestHomografem($dopasowanie)) {
                        continue;
                    }

                    $trafienia[] = ($numer + 1).' → ['.$nazwa.'] '.$dopasowanie
                        .'   w zdaniu: '.trim($linia);
                }
            }
        }

        return $trafienia;
    }

    /**
     * Czy całe dopasowanie jest słowem z listy homografów.
     *
     * Sprawdzamy CAŁE dopasowanie, nie „czy zawiera": „hasłem" przechodzi,
     * ale „wpisz hasłem/hasłam" już nie, bo dopasowaniem jest wtedy cała
     * konstrukcja z ukośnikiem, a nie samo słowo.
     */
    private static function jestHomografem(string $dopasowanie): bool
    {
        return in_array(mb_strtolower($dopasowanie), self::HOMOGRAFY, true);
    }
}
