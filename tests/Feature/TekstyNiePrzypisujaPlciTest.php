<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Teksty dla czytelnika nie przypisują mu płci (issue #274).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Właściciel — mężczyzna, który nigdy nie podawał płci, bo serwis o nią nie
 * pyta — zobaczył w ustawieniach prywatności „Przypominaj mi, co gotowałam
 * w tym dniu w poprzednich latach". Serwis powiedział o nim coś, czego nie
 * wie, i zrobił to w pierwszej osobie, jakby to on sam o sobie mówił.
 * Inwentaryzacja z #274 znalazła kilkanaście takich miejsc: profil („za rok
 * będziesz mogła"), paczka RODO („co dziś ugotowałam"), bezpieczeństwo konta
 * („zostałaś/eś zalogowana/y"), pusta sekcja komentarzy („możesz być pierwsza
 * albo pierwszy"), walidacja nazwy konta („z tego, co wpisałeś").
 *
 * GRANICA, KTÓREJ PILNUJE TEN PLIK
 * Rodzaj wolno użyć, gdy WIEMY, o kim mówimy — „Halina ugotowała Twój rosół"
 * jest poprawne i COPY_STYLE.md nazywa je „już doskonałym". Nie wolno go użyć,
 * gdy mówimy DO czytelnika albo w jego imieniu. Dlatego wzorce niżej łapią
 * wyłącznie formy PIERWSZEJ I DRUGIEJ osoby („-łam", „-łaś", „-łem", „-łeś",
 * „będziesz mogła") oraz konstrukcje, które płeć czytelnika wywołują wprost:
 * ukośnik, nawias i wypisanie obu form obok siebie. Trzecia osoba („ugotowała",
 * „napisał") nie jest tu w ogóle sprawdzana i powiadomienia o cudzej
 * aktywności przechodzą bez żadnego wyjątku.
 *
 * ZASADA NIE JEST WYMYŚLONA W TYM TEŚCIE, JEST ZAPISANA
 * `docs/brand/COPY_STYLE.md` §2: „Zamiast szukać żeńskiej formy, zmieniamy
 * konstrukcję zdania" oraz „W pozostałych tekstach zwracamy się bezpośrednio,
 * przez «Ty», i unikamy rodzaju. […] W tekstach roboczych wolimy konstrukcje
 * bez rodzaju: «Co dziś gotujesz?» działa dla wszystkich i jest krótsze".
 * Poprawka polega na PRZEBUDOWANIU ZDANIA, nigdy na zamianie formy żeńskiej
 * na męską — to tylko przenosi ten sam błąd na drugą połowę ludzi.
 *
 * DLACZEGO OSOBNY PLIK, A NIE PUNKT W `TekstyWedlugCopyStyleTest`
 * Ten test tam jest — punkt 4, „bez zakładania rodzaju ukośnikiem" — i jego
 * wzorzec (`(?:ła|łeś|ał|eś)\s*\/`) nie złapał ANI JEDNEGO z pięciu ukośników,
 * które naprawdę siedziały w repozytorium: „Zrobiłam/zrobiłem" kończy się na
 * „-łam", „prosiłaś/eś" na „-łaś", „Zapisałem/am" na „-łem", a
 * „zalogowana/y" nie ma w sobie „ł" wcale. Zielony test przy pięciu żywych
 * wystąpieniach jest gorszy niż brak testu, bo wygląda na dowód. Ten plik
 * bierze cały wzorzec od nowa i pilnuje go na wszystkich powierzchniach
 * naraz — widoki, teksty prawne, tłumaczenia i napisy składane w PHP.
 */
class TekstyNiePrzypisujaPlciTest extends TestCase
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
    private const WYJATKI = [
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
    private const HOMOGRAFY = [
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
    private const WZORCE = [
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

    // ---------------------------------------------------------------
    // Widoki
    // ---------------------------------------------------------------

    public function test_widoki_nie_przypisuja_czytelnikowi_plci(): void
    {
        $pliki = $this->pliki(resource_path('views'), '.blade.php');

        // KONTROLA NEGATYWNA WBUDOWANA W TEST. Skan, który nie znajduje
        // żadnego pliku, przechodzi z tego samego powodu, z którego przechodzi
        // skan poprawnego repozytorium — i tego się z zielonego wyniku nie
        // odróżni. Zła ścieżka, zmiana układu katalogów albo literówka
        // w rozszerzeniu ma tu obleć, nie przemilczeć.
        $this->assertGreaterThan(
            100,
            count($pliki),
            'Skan widoków nie znalazł plików — sprawdź ścieżkę resources/views. '
            .'Test, który nic nie czyta, niczego nie pilnuje.',
        );

        $winowajcy = [];

        foreach ($pliki as $plik) {
            $tresc = $this->bezKomentarzyBlade((string) file_get_contents($plik));

            foreach ($this->trafienia($tresc) as $trafienie) {
                $winowajcy[] = $this->skrot($plik).':'.$trafienie;
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie($winowajcy));
    }

    // ---------------------------------------------------------------
    // Teksty prawne
    // ---------------------------------------------------------------

    /**
     * Regulamin, polityka prywatności i zasady społeczności. Rejestr
     * „poważny" (COPY_STYLE.md §3) nie zwalnia z zasady — a to właśnie tam
     * siedziało „nie sprawdzamy, czy otworzyłaś list" obok „czy je
     * przeczytałeś", czyli obie płcie w jednym dokumencie.
     */
    public function test_teksty_prawne_nie_przypisuja_czytelnikowi_plci(): void
    {
        $pliki = glob(base_path('resources/legal/*.md')) ?: [];

        $this->assertNotEmpty($pliki, 'Nie znalazłem tekstów prawnych — sprawdź resources/legal.');

        $winowajcy = [];

        foreach ($pliki as $plik) {
            foreach ($this->trafienia((string) file_get_contents($plik)) as $trafienie) {
                $winowajcy[] = $this->skrot($plik).':'.$trafienie;
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie($winowajcy));
    }

    // ---------------------------------------------------------------
    // Tłumaczenia
    // ---------------------------------------------------------------

    /** `lang/` to w całości komunikaty walidacji i teksty systemowe. */
    public function test_tlumaczenia_nie_przypisuja_czytelnikowi_plci(): void
    {
        $pliki = array_merge(
            glob(base_path('lang/*.json')) ?: [],
            glob(base_path('lang/pl/*.php')) ?: [],
        );

        $this->assertNotEmpty($pliki, 'Nie znalazłem plików językowych — sprawdź lang/.');

        $winowajcy = [];

        foreach ($pliki as $plik) {
            foreach ($this->trafienia((string) file_get_contents($plik)) as $trafienie) {
                $winowajcy[] = $this->skrot($plik).':'.$trafienie;
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie($winowajcy));
    }

    // ---------------------------------------------------------------
    // Redakcyjne opisy w danych zalążkowych
    // ---------------------------------------------------------------

    /**
     * Notatki przy promowanych tagach to TEKST PRODUKTU, tylko trzymany
     * w pliku z danymi — widać je na tablicy „kuKINGi na dziś" i w panelu.
     * Siedziało tam „Przepis, który dostałaś albo dostałeś od kogoś", czyli
     * dokładnie ta podwójna forma, której COPY_STYLE.md §2 zakazuje.
     *
     * DLACZEGO PLIK Z NAZWY, A NIE CAŁY KATALOG `database/seeders/dane`
     * Obok leży `tresc-zalazkowa.json` — udawane wpisy i komentarze
     * użytkowników („Przesoliłem, sięgałem po drugą garść"). To jest treść
     * CZŁOWIEKA, a nie serwisu: tam rodzaj jest poprawny z tego samego powodu,
     * z którego poprawne jest „Halina ugotowała Twój rosół". Skanowanie
     * całego katalogu wymagałoby więc wyjątku „cały ten plik", a taki wyjątek
     * zjada sens testu. Dlatego skanujemy plik z nazwy — a jeśli dojdzie
     * kolejny plik z tekstem produktu, dopisuje się go TUTAJ.
     */
    public function test_notatki_promowanych_tagow_nie_przypisuja_czytelnikowi_plci(): void
    {
        $plik = database_path('seeders/dane/tagi-promowane.json');

        $this->assertFileExists($plik, 'Nie znalazłem danych promowanych tagów — sprawdź ścieżkę.');

        $winowajcy = [];

        foreach ($this->trafienia((string) file_get_contents($plik)) as $trafienie) {
            $winowajcy[] = $this->skrot($plik).':'.$trafienie;
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie($winowajcy));
    }

    // ---------------------------------------------------------------
    // Napisy składane w PHP
    // ---------------------------------------------------------------

    /**
     * Komunikaty walidacji, wyjątki dla człowieka, powiadomienia, listy
     * i decyzje moderacyjne. Połowa inwentaryzacji z #274 siedziała nie
     * w widoku, tylko tutaj — „Z tego, co wpisałeś, nie da się ułożyć nazwy",
     * „które nam przysłałeś", „podziękowałaś/eś za wykonanie".
     *
     * Sprawdzamy SAME NAPISY, nie komentarze. `app/` jest pełne komentarzy,
     * które cytują potrzebę użytkownika jego własnymi słowami („gdzie jest to,
     * co zapisałam wczoraj") — to jest dobry komentarz i naiwny grep po pliku
     * zapalałby się na nim. `token_get_all()` rozdziela jedno od drugiego
     * pewnie, bez zgadywania regularnym wyrażeniem.
     */
    public function test_napisy_w_php_nie_przypisuja_czytelnikowi_plci(): void
    {
        $pliki = $this->pliki(app_path(), '.php');

        $this->assertGreaterThan(
            100,
            count($pliki),
            'Skan app/ nie znalazł plików — sprawdź ścieżkę. '
            .'Test, który nic nie czyta, niczego nie pilnuje.',
        );

        $winowajcy = [];

        foreach ($pliki as $plik) {
            foreach ($this->napisyZPliku($plik) as $numerLinii => $napis) {
                foreach ($this->trafienia($napis) as $trafienie) {
                    // Numer linii bierzemy z tokena, nie z pozycji w napisie:
                    // napis wielolinijkowy dostaje numer swojego początku.
                    $winowajcy[] = $this->skrot($plik).':'.$numerLinii.' → '
                        .trim(explode(' → ', $trafienie, 2)[1] ?? $napis);
                }
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie($winowajcy));
    }

    // ---------------------------------------------------------------
    // Sam wzorzec też jest sprawdzany
    // ---------------------------------------------------------------

    /**
     * DRUGA KONTROLA NEGATYWNA, tym razem na wzorcach, nie na ścieżkach.
     *
     * Wzorzec można zepsuć tak, że nadal się kompiluje i nadal nic nie łapie —
     * i wtedy cały plik wyżej jest zielony bez powodu. Dlatego tu stoją
     * PRAWDZIWE zdania, które właściciel widział na ekranie przed #274, razem
     * ze zdaniami poprawnymi, które muszą przechodzić.
     */
    public function test_wzorce_lapia_to_co_wlasciciel_widzial_i_przepuszczaja_poprawne(): void
    {
        $zle = [
            'Przypominaj mi, co gotowałam w tym dniu w poprzednich latach',
            'Za rok będziesz mogła tu wrócić i zobaczyć, co wtedy gotowałaś.',
            'Za rok będziesz mógł tu wrócić.',
            'Twoje wpisy „co dziś ugotowałam”',
            'Zrobiłam/zrobiłem po swojemu:',
            'Zapisałem/am kody — gotowe',
            'zostałaś/eś zalogowana/y na cudzym telefonie',
            'a to nie Ty prosiłaś/eś o zmianę',
            'Nikogo nie zablokowałaś.',
            'Możesz być pierwsza albo pierwszy.',
            'Będziesz pierwsza albo pierwszy?',
            'Podaj adres e-mail, którym je zakładałaś albo zakładałeś',
            'Z tego, co wpisałeś, nie da się ułożyć nazwy do adresu.',
            'Sprawdziliśmy zgłoszenie, które nam przysłałeś.',
            'Napisz, dlaczego tak zdecydowałeś.',
            'Powód, który wybrałeś: obrażanie.',
            'Możesz być pierwszy albo pierwsza.',
            'Jesteś zalogowany(a) na dwóch urządzeniach.',
            'Jesteś gotowa albo gotowy.',
            // Te dwa zdania pilnują WĄSKOŚCI listy wyjątków, nie samych
            // wzorców. Wyjątkiem jest FRAZA („co dziś ugotowałeś"), nie słowo,
            // i tylko zapis „Ugotowałem" z wielkiej litery. Gdyby ktoś
            // rozszerzył wyjątek do samego „ugotowałeś" albo dopisał zapis
            // małą literą, oblewa tutaj — a nie po cichu przestaje pilnować.
            'Wczoraj ugotowałeś rosół.',
            'Wczoraj ugotowałem rosół.',
            'Halina ugotowała/ugotował ten przepis.',
        ];

        foreach ($zle as $zdanie) {
            $this->assertNotSame(
                [],
                $this->trafienia($zdanie),
                "Wzorce przepuściły zdanie, które przypisuje czytelnikowi płeć: „{$zdanie}”. ".
                'Któryś wzorzec w WZORCE przestał działać — napraw wzorzec, nie ten test.',
            );
        }

        $dobre = [
            // Hasło główne i nazwa przycisku — wyjątki z WYJATKI.
            'Pokaż, co dziś ugotowałeś',
            'Dodaj zdjęcie tego, co ugotowałeś',
            'Kuking.pl — pokaż, co dziś ugotowałeś.',
            'razy Ugotowałem',
            'Pod każdym przepisem jest przycisk „Ugotowałem".',
            // Trzecia osoba o KONKRETNEJ, znanej osobie — rodzaj poprawny.
            'Halina ugotowała Twój rosół',
            'Basia zaczęła Cię obserwować',
            'Autor mógł ją schować',
            'Żeby ktoś inny mógł to u siebie zrobić.',
            'Ten link mógł wygasnąć albo zostać użyty.',
            // Nowe, przebudowane brzmienia z tej poprawki.
            'Przypominaj mi moje wpisy z tego dnia w poprzednich latach',
            'Za rok zobaczysz tu, co gotujesz dzisiaj.',
            'Nikogo nie blokujesz.',
            'Napisz pierwszy komentarz.',
            'Twoje wykonanie będzie pierwsze.',
            'Po swojemu:',
            'Coś po swojemu?',
            'Kody zapisane — gotowe',
            'Gotujesz ten przepis drugi raz?',
            'Z tej nazwy nie da się ułożyć adresu.',
            'A Ty będziesz mieć to zapisane.',
            'Numer sprawy podaj, jeśli będziesz do nas pisać.',
            // Homografy — nie są formami rodzajowymi.
            'Zaloguj się hasłem — Twoje konto działa normalnie.',
            'Zostanie podpisany nad tytułem: „przepis Haliny”.',
            'treść seksualna z udziałem dziecka',
            'Wysyłam wiadomość synchronicznie.',
            'Nic nie wysyłam.',
            'Przy słabym zasięgu skrypt bywa nie dociągnięty.',
            'Napis nad tłem zdjęcia jest zawsze widoczny.',
            'Zmień adres na /ustawienia/e-mail.',
        ];

        foreach ($dobre as $zdanie) {
            $this->assertSame(
                [],
                $this->trafienia($zdanie),
                "Wzorce zapaliły się na poprawnym zdaniu: „{$zdanie}”. ".
                'Zawężaj wzorzec albo dopisz homograf — nie rozszerzaj listy wyjątków.',
            );
        }
    }

    // ---------------------------------------------------------------
    // Narzędzia
    // ---------------------------------------------------------------

    /**
     * Trafienia w tekście, linia po linii, po wycięciu nazwanych wyjątków
     * i homografów.
     *
     * @return list<string> „numer linii → cytat"
     */
    private function trafienia(string $tresc): array
    {
        $trafienia = [];

        foreach (explode("\n", $tresc) as $numer => $linia) {
            $doSprawdzenia = str_replace(array_keys(self::WYJATKI), ' ', $linia);

            foreach (self::WZORCE as $nazwa => $wzorzec) {
                if (preg_match_all($wzorzec, $doSprawdzenia, $dopasowania) === 0) {
                    continue;
                }

                foreach ($dopasowania[0] as $dopasowanie) {
                    if ($this->jestHomografem($dopasowanie)) {
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
    private function jestHomografem(string $dopasowanie): bool
    {
        return in_array(mb_strtolower($dopasowanie), self::HOMOGRAFY, true);
    }

    /** @param list<string> $winowajcy */
    private function wyjasnienie(array $winowajcy): string
    {
        return "Tekst przypisuje czytelnikowi płeć:\n".implode("\n", $winowajcy)."\n\n"
            .'docs/brand/COPY_STYLE.md §2: „Zamiast szukać żeńskiej formy, zmieniamy '
            ."konstrukcję zdania\".\n"
            .'NIE zamieniaj formy żeńskiej na męską ani nie wypisuj obu — to przenosi ten '
            ."sam błąd na drugą połowę ludzi.\n"
            .'PRZEBUDUJ ZDANIE tak, żeby rodzaju w nim nie było: czas teraźniejszy '
            .(' („co gotujesz", „nikogo nie blokujesz"), strona bierna („konto zostało ')
            .'zalogowane"), rzeczownik („Po swojemu:", „Wybrany powód:") albo bezokolicznik '
            ."(„będziesz mieć\").\n"
            .'Nowe brzmienie ma być krótsze albo równie krótkie jak stare — przy grupie '
            .'50-75 lat długość zdania to nie estetyka.';
    }

    private function bezKomentarzyBlade(string $tresc): string
    {
        // Numery linii muszą przeżyć wycięcie komentarza, inaczej cytat
        // w komunikacie wskaże nie na tę linię. Dlatego komentarz nie znika,
        // tylko zostaje po nim tyle pustych linii, ile zajmował.
        $tresc = (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $tresc,
        );

        $tresc = (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $tresc,
        );

        // Komentarze jednolinijkowe w `@php` i w skryptach Alpine'a. Tylko
        // te, które zaczynają CAŁĄ linię — inaczej wycięlibyśmy „https://".
        $linie = explode("\n", $tresc);

        foreach ($linie as $i => $linia) {
            $bezWciecia = ltrim($linia);

            if (str_starts_with($bezWciecia, '//') || str_starts_with($bezWciecia, '*')) {
                $linie[$i] = '';
            }
        }

        return implode("\n", $linie);
    }

    /**
     * Napisy z pliku PHP, bez komentarzy.
     *
     * @return array<int, string> numer linii => napis
     */
    private function napisyZPliku(string $plik): array
    {
        $napisy = [];

        foreach (token_get_all((string) file_get_contents($plik)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $napisy[$token[2]] = ($napisy[$token[2]] ?? '').' '.$token[1];
            }
        }

        return $napisy;
    }

    /** @return list<string> */
    private function pliki(string $katalog, string $rozszerzenie): array
    {
        if (! is_dir($katalog)) {
            return [];
        }

        $znalezione = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($katalog));

        foreach ($iterator as $plik) {
            if ($plik->isFile() && str_ends_with($plik->getFilename(), $rozszerzenie)) {
                $znalezione[] = $plik->getPathname();
            }
        }

        sort($znalezione);

        return $znalezione;
    }

    private function skrot(string $sciezka): string
    {
        return str_replace(base_path().'/', '', $sciezka);
    }
}
