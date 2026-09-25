<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\WzorceRodzaju;
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
 *
 * OD D-268 (25.09.2026): JEDYNA FURTKA TO HELPER FORMY
 * Osoba, która sama wybrała formę („Jak mamy do Ciebie pisać?”), dostaje
 * teksty w swojej formie — ale wyłącznie przez `App\Support\Forma::dla()`
 * z trzema wariantami. Skan widoków i napisów w PHP wycina ARGUMENTY tych
 * wywołań (`WzorceRodzaju::bezWywolanFormy()`), nic poza nimi: goły
 * „ugotowałaś” obok helpera w tej samej linii dalej oblewa. Że wariant
 * neutralny każdego wywołania jest bez rodzaju, pilnuje `FormaTekstyTest`.
 * Teksty prawne i tłumaczenia helpera nie mają i furtki nie dostają.
 */
class TekstyNiePrzypisujaPlciTest extends TestCase
{
    /**
     * WZORCE, WYJĄTKI I HOMOGRAFY MIESZKAJĄ W `Tests\Support\WzorceRodzaju`.
     *
     * Stały tu do issue #38. Wyprowadziłem je, bo tej samej zasady pilnuje
     * od tego issue drugi test — `PrzewodnikTrzymaSieWlasnychZasadTest`,
     * skanujący gotowe teksty do wklejenia w `docs/brand/`. Dwie kopie
     * wzorców znaczyłyby, że poprawka wzorca w jednym pliku zostawia drugi
     * ślepy, a oba są wtedy zielone i różnicy nie widać.
     *
     * Ten plik decyduje WYŁĄCZNIE o tym, co skanuje (produkt: widoki, teksty
     * prawne, tłumaczenia, napisy w PHP). Czym mierzy — tamta klasa.
     */

    /**
     * Druga warstwa wzorców — TYLKO dla widoków, nie dla tekstów prawnych,
     * tłumaczeń ani napisów w PHP (issue #38, przy okazji przenoszenia PR
     * #254 do #288).
     *
     * DLACZEGO OSOBNA STAŁA, A NIE DOPISKA DO `WZORCE` WYŻEJ
     * Ten skan łapie gołe „sam"/„sama" jako podmiotowy zaimek wzmacniający
     * („sam decydujesz", „włączasz sam") — a to słowo naprawdę wystąpiło
     * jako żywy błąd w PIĘCIU ekranach (patrz test niżej), mimo że
     * `test_widoki_nie_przypisuja_czytelnikowi_plci` obok był zielony.
     * Ten sam zaimek stoi też legalnie w `resources/legal/regulamin.md`
     * i `polityka-prywatnosci.md` („sam wybierasz", „sam decydujesz, co
     * dzieje się z Twoimi tekstami") — a teksty prawne mają swój reżim
     * i osobne zlecenie (`docs/brand/COPY_STYLE.md` §6). Gdyby te
     * wzorce trafiły do wspólnej `WZORCE`, `test_teksty_prawne_…` zacząłby
     * obalać rzeczy, których ten PR świadomie nie rusza — dokładnie ten
     * rodzaj przypadkowego rozszerzenia zakresu, przed którym ostrzega
     * `docs/PULAPKI_TESTOW.md`.
     *
     * @var array<string, string>
     */
    private const WZORCE_TYLKO_WIDOKI = [
        // „sam"/„sama" tuż obok czasownika w 2. osobie l.poj. czasu
        // teraźniejszego („sam decydujesz", „sama wybierasz", „włączasz
        // sam"). Wymóg samogłoski przed „sz" odcina rzeczowniki kończące
        // się na spółgłoskę + „sz" („ten sam wiersz", „sam mechanizm" —
        // „wiersz" nie ma samogłoski przed „sz", więc nie łapie).
        'sam_bezposredni' => '/\bsam(?:a)?\b\s+\p{L}*[aeiy]sz\b|\p{L}*[aeiy]sz\b\s+\bsam(?:a)?\b/u',

        // Ukośnik „sam/sama" wprost — ten sam mechanizm co „ukosnik_koncowka"
        // wyżej, ale ten wzorzec go nie łapał: „sam" nie kończy się na „ł",
        // „na", „ta", „a" ani „ą", więc „decydujesz sam/sama" przeszło przez
        // #288 nietknięte (`resources/views/pages/settings/data.blade.php`).
        'sam_ukosnik' => '/\bsam\s*\/\s*sama\b|\bsama\s*\/\s*sam\b/iu',

        // „zostaniesz" + imiesłów rodzajowy: „zostaniesz poproszona/y",
        // „zostaniesz zalogowana/y". Wzorzec „przyszlosc" wyżej łapie tylko
        // „będziesz", nie „zostaniesz" — inna konstrukcja czasu przyszłego,
        // ten sam błąd (`resources/views/mail/data-export-ready.blade.php`
        // pisał „zostaniesz poproszona" do każdego czytelnika).
        'zostaniesz_forma' => '/\bzostaniesz\s+\p{L}*n[ay]\b/iu',
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
            // Rodzaj wolno pokazać wyłącznie przez helper formy (D-268):
            // argumenty `Forma::dla(...)` wypadają ze skanu, reszta linii nie.
            $tresc = WzorceRodzaju::bezWywolanFormy($this->bezKomentarzyBlade((string) file_get_contents($plik)));

            foreach ($this->trafienia($tresc) as $trafienie) {
                $winowajcy[] = $this->skrot($plik).':'.$trafienie;
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie($winowajcy));
    }

    /**
     * Druga warstwa, TYLKO widoki — patrz `WZORCE_TYLKO_WIDOKI` wyżej po
     * uzasadnienie, dlaczego to osobny test, a nie dopisek do wzorców
     * używanych też przez tekst prawny.
     *
     * ZNALEZIONE TYM WZORCEM, ŻYWE W REPOZYTORIUM PRZED TĄ POPRAWKĄ (issue
     * #38): `pages/landing.blade.php` („sam decydujesz"), `pages/static/
     * help.blade.php` („sam wybierasz"), `pages/settings/accessibility.
     * blade.php` („włączasz sam"), `pages/settings/privacy.blade.php`
     * („sama decydujesz"), `pages/settings/data.blade.php` (dwa razy „sam/
     * sama") i `mail/data-export-ready.blade.php` („zostaniesz poproszona").
     * Sześć miejsc, zero czerwonych testów — bo `test_widoki_nie_przypisuja_
     * czytelnikowi_plci` obok skanuje te same pliki, ale innym wzorcem.
     */
    public function test_widoki_nie_przypisuja_czytelnikowi_plci_slowem_sam_i_zostaniesz(): void
    {
        $pliki = $this->pliki(resource_path('views'), '.blade.php');

        $this->assertGreaterThan(
            100,
            count($pliki),
            'Skan widoków nie znalazł plików — sprawdź ścieżkę resources/views. '
            .'Test, który nic nie czyta, niczego nie pilnuje.',
        );

        $winowajcy = [];

        foreach ($pliki as $plik) {
            // Rodzaj wolno pokazać wyłącznie przez helper formy (D-268):
            // argumenty `Forma::dla(...)` wypadają ze skanu, reszta linii nie.
            $tresc = WzorceRodzaju::bezWywolanFormy($this->bezKomentarzyBlade((string) file_get_contents($plik)));

            foreach ($this->trafieniaTylkoWidoki($tresc) as $trafienie) {
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

    /**
     * DRUGA KONTROLA NEGATYWNA, tym razem na `WZORCE_TYLKO_WIDOKI`. Ten sam
     * powód co dla `WZORCE` wyżej: wzorzec, który się kompiluje i nic nie
     * łapie, jest gorszy niż brak testu.
     *
     * Zdania w `$zle` to DOSŁOWNE brzmienia z sześciu ekranów sprzed tej
     * poprawki (issue #38) — patrz `WZORCE_TYLKO_WIDOKI` po pełną listę
     * plików. `$dobre` to te same zdania po przebudowie.
     */
    public function test_wzorce_tylko_widoki_lapia_zywe_bledy_i_przepuszczaja_poprawki(): void
    {
        $zle = [
            'Przy każdym wpisie sam decydujesz, kto go widzi: wszyscy, tylko obserwujący albo tylko Ty.',
            'Przy każdym wpisie i przepisie sam wybierasz: wszyscy, tylko osoby, które Cię obserwują, albo tylko Ty.',
            'dla każdego konta — ciemny włączasz sam, jeśli wolisz.',
            'Przy każdym wpisie i przepisie sama decydujesz: wszyscy, tylko osoby które Cię obserwują, albo tylko Ty.',
            'tekstami, decydujesz sam/sama w formularzu niżej.',
            'tylko wybrane przepisy albo wpisy, usuń je sam/sama, zanim skasujesz konto.',
            'Zanim zaczniesz pobierać, zostaniesz poproszona o zalogowanie się.',
            'Zanim zaczniesz pobierać, zostaniesz poproszony o zalogowanie się.',
            // Kontrola, że wzorzec `zostaniesz_forma` nie jest zawężony
            // wyłącznie do słowa „poproszona/y" z tego jednego ekranu.
            'Zostaniesz przekierowany na stronę logowania.',
        ];

        foreach ($zle as $zdanie) {
            $this->assertNotSame(
                [],
                $this->trafieniaTylkoWidoki($zdanie),
                "Wzorce WZORCE_TYLKO_WIDOKI przepuściły żywy błąd sprzed poprawki: „{$zdanie}”. ".
                'Któryś wzorzec przestał działać — napraw wzorzec, nie ten test.',
            );
        }

        $dobre = [
            // Brzmienia PO tej poprawce.
            'Przy każdym wpisie decydujesz, kto go widzi: wszyscy, tylko obserwujący albo tylko Ty.',
            'Przy każdym wpisie i przepisie wybierasz: wszyscy, tylko osoby, które Cię obserwują, albo tylko Ty.',
            'dla każdego konta — ciemny włączasz, jeśli wolisz.',
            'Przy każdym wpisie i przepisie decydujesz Ty: wszyscy, tylko osoby które Cię obserwują, albo tylko Ty.',
            'tekstami, decydujesz Ty w formularzu niżej.',
            'tylko wybrane przepisy albo wpisy, usuń je samodzielnie, zanim skasujesz konto.',
            'Zanim zaczniesz pobierać, poprosimy Cię o zalogowanie się.',
            // „sam"/„sama" jako zwykły przymiotnik odmienny przez rodzaj
            // RZECZOWNIKA (nie przez rodzaj czytelnika) — nie jest tym
            // błędem i ma przechodzić.
            'Kolor to sam dodatek, nośnikiem jest treść.',
            'To ten sam mechanizm co przy zdjęciu.',
            'Widok NIE filtruje niczego sam — dostaje z kontrolera.',
            'Zgłoś pod samą treścią.',
            // „Zostaniesz" w znaczeniu „zostać" (pozostać), nie w znaczeniu
            // strony biernej — nic po nim nie kończy się na „n"+„a"/„y".
            'Zostaniesz tu jeszcze chwilę, czy już zamykasz kartę?',
        ];

        foreach ($dobre as $zdanie) {
            $this->assertSame(
                [],
                $this->trafieniaTylkoWidoki($zdanie),
                "WZORCE_TYLKO_WIDOKI zapaliły się na poprawnym zdaniu: „{$zdanie}”. ".
                'Zawężaj wzorzec — nie dodawaj wyjątku.',
            );
        }
    }

    // ---------------------------------------------------------------
    // Narzędzia
    // ---------------------------------------------------------------

    /**
     * Trafienia w tekście — wzorcami współdzielonymi z przewodnikiem
     * (`Tests\Support\WzorceRodzaju`, patrz komentarz na górze klasy).
     *
     * @return list<string> „numer linii → cytat"
     */
    private function trafienia(string $tresc): array
    {
        return WzorceRodzaju::trafienia($tresc);
    }

    /**
     * To samo co `trafienia()`, ale wzorcem `WZORCE_TYLKO_WIDOKI` — patrz
     * komentarz przy tej stałej po to, dlaczego jest osobna od `WZORCE`.
     * Bez wyjątków i homografów: lista `WZORCE_TYLKO_WIDOKI` jest krótka
     * i celowo wąska, więc żaden z dotychczasowych wyjątków (hasło główne,
     * „Ugotowałem") się na niej nie zapala — dodanie ich tu na wyrost
     * tylko utrudniłoby czytanie.
     *
     * @return list<string> „numer linii → cytat"
     */
    private function trafieniaTylkoWidoki(string $tresc): array
    {
        $trafienia = [];

        foreach (explode("\n", $tresc) as $numer => $linia) {
            foreach (self::WZORCE_TYLKO_WIDOKI as $nazwa => $wzorzec) {
                if (preg_match_all($wzorzec, $linia, $dopasowania) === 0) {
                    continue;
                }

                foreach ($dopasowania[0] as $dopasowanie) {
                    $trafienia[] = ($numer + 1).' → ['.$nazwa.'] '.$dopasowanie
                        .'   w zdaniu: '.trim($linia);
                }
            }
        }

        return $trafienia;
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

        // Argumenty `Forma::dla(...)` wycięte przed podziałem na tokeny (D-268) —
        // nawiasy zostają, więc `token_get_all()` czyta resztę pliku jak dotąd.
        foreach (token_get_all(WzorceRodzaju::bezWywolanFormy((string) file_get_contents($plik))) as $token) {
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
