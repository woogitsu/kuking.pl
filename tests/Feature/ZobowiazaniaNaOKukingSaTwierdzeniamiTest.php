<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Sekcja zobowiązań na `/o-kuking` — decyzja właściciela: ta sama treść
 * obietnic, ale w formie TWIERDZĄCEJ.
 *
 * DO CZEGO TEN PLIK JEST POTRZEBNY
 * Do tej zmiany sekcja nazywała się „Czego tu nie ma i nie będzie" i składała
 * się z trzech zdań zaczynających się od „Nie". Właściciel kazał przepisać ją
 * na twierdzenia, zachowując KAŻDE zobowiązanie. Taka zmiana psuje się cicho
 * w dwie strony i żadna z nich nie objawia się błędem:
 *
 *   1. przy przepisywaniu na twierdzenia łatwo ZGUBIĆ zobowiązanie — zdanie
 *      „nie kupujemy ruchu" nie ma oczywistego odpowiednika i wypada
 *      z listy bez śladu. Dlatego każde zobowiązanie ma tu własną asercję,
 *      a nie jedną wspólną na całą sekcję;
 *   2. przy następnej korekcie tekstu łatwo WRÓCIĆ do zaprzeczeń — są
 *      krótsze i piszą się same. Dlatego obok stoi skan struktury: żadna
 *      pozycja listy w treści strony nie zaczyna się od „Nie", „Bez",
 *      „Nigdy" ani „Żaden".
 *
 * DLACZEGO NIE ASERCJA NA CAŁYM DOKUMENCIE (pułapka 1 z `docs/PULAPKI_TESTOW.md`)
 * Nawigacja, prawa szyna i stopka mają te same słowa co treść. Stopka dokłada
 * na KAŻDEJ stronie licznik „{n} kuKINGów", więc asercja o zapisie marki
 * przechodziłaby z powodu obudowy strony, nie z powodu tej sekcji. Test wycina
 * więc stopkę (`bezStopki()`, ten sam wzorzec co w
 * `TekstyWedlugCopyStyleTest`), a zobowiązania sprawdza w SAMEJ SEKCJI —
 * od jej nagłówka do następnego `<h2>`.
 *
 * CZEGO TEN PLIK NIE SPRAWDZA
 * Brzmienia pozostałych sekcji `/o-kuking`. Pilnują ich
 * `OKukingWymieniaGarnekIDurszlakTest` (Garnek, Durszlak i źródła)
 * oraz `EkranyMowiaFaktyNieTokRozumowaniaTest` (charakter strony).
 */
class ZobowiazaniaNaOKukingSaTwierdzeniamiTest extends TestCase
{
    /**
     * Lista kontrolna zmiany: zobowiązanie => fragmenty, które je niosą.
     *
     * PO DWA FRAGMENTY NA ZOBOWIĄZANIE, nie po jednym. Sam nagłówek pozycji
     * („Cały ekran należy do gotowania") jest hasłem; zobowiązanie niesie
     * dopiero zdanie pod nim. Gdyby test pilnował tylko hasła, można by
     * skasować zdanie i zostawić samo hasło — i przeszedłby. Sprawdzone
     * kontrolą ujemną (K7), nie założone.
     *
     * PIERWSZA POZYCJA MA JEDEN FRAGMENT I JEST CYTATEM. „bez opłat i bez
     * reklam" to brzmienie przyjęte decyzją właściciela B1
     * (`docs/brand/GLOS_MARKI.md` §6), gdzie odrzucone zostały wprost „to
     * darmowe" (sprzedażowo) i „za darmo, na zawsze" (obietnica bez
     * gwarancji). Pilnujemy tu CO DO SŁOWA, bo to jedyne zobowiązanie na tej
     * stronie, które ma zatwierdzone brzmienie — reszta ma zatwierdzoną
     * treść, a brzmienie wolne.
     *
     * @var array<string, list<string>>
     */
    private const ZOBOWIAZANIA = [
        'bez opłat i bez reklam — brzmienie z decyzji B1, co do słowa' => [
            'bez opłat i bez reklam',
        ],
        'bez opłat — za korzystanie nikt nie płaci' => [
            'Korzystasz bez opłat',
            'poprosimy o wsparcie wprost',
        ],
        'bez reklam — miejsce po reklamie zajmuje czyjeś danie' => [
            'Cały ekran należy do gotowania',
            'stawiają reklamy, u nas zajmuje czyjeś danie',
        ],
        'przepisów nie pisze automat, piszą je ludzie, którzy je gotują' => [
            'Wszystko tutaj napisali ludzie, którzy to gotują',
            'wyszedł z czyjejś kuchni',
        ],
        'bez masowego importu cudzych przepisów' => [
            'Każdy przepis wpisał tu jego właściciel',
            'jeden po drugim, ręcznie',
        ],
        'bez kupowania ruchu' => [
            'Rośniemy z polecenia',
            'ktoś im o tym miejscu powiedział',
        ],
        'bez rankingu użytkowników' => [
            'Wszystkie konta są tu równe',
            'Jedyna kolejność w tym serwisie',
        ],
    ];

    /**
     * Dokładne brzmienia sprzed zmiany. Wróciłyby najłatwiej — bo są
     * krótsze od twierdzeń i bo stoją w historii tego pliku.
     *
     * @var list<string>
     */
    private const STARE_ZAPRZECZENIA = [
        'Czego tu nie ma i nie będzie',
        'Nie kupujemy ruchu',
        'nie generujemy przepisów sztuczną inteligencją',
        'Nie importujemy masowo',
        'Nie robimy rankingów',
    ];

    /**
     * Brzmienia odrzucone przez `GLOS_MARKI.md` §6 — nie zaprzeczenia, ale
     * ta sama klasa usterki: zdanie o pieniądzach, którego właściciel
     * NIE wybrał. Pierwsza wersja tej sekcji miała „Zawsze za darmo",
     * czyli wariant „za darmo, na zawsze" w innym szyku.
     *
     * @var list<string>
     */
    private const ODRZUCONE_BRZMIENIA = [
        'Zawsze za darmo',
        'za darmo, na zawsze',
        'to darmowe',
        'darmowe konto',
    ];

    /** Nagłówek sekcji po zmianie — kotwica dla całego pliku. */
    private const NAGLOWEK = '<h2>Na co możesz liczyć</h2>';

    // ---------------------------------------------------------------
    // 1. Każde zobowiązanie z listy kontrolnej jest na ekranie
    // ---------------------------------------------------------------

    public function test_sekcja_niesie_kazde_zobowiazanie_z_listy_kontrolnej(): void
    {
        $sekcja = $this->sekcjaZobowiazan();

        foreach (self::ZOBOWIAZANIA as $nazwa => $fragmenty) {
            foreach ($fragmenty as $fragment) {
                $this->assertStringContainsString(
                    $fragment,
                    $sekcja,
                    "Z sekcji „Na co możesz liczyć” zniknęło zobowiązanie: {$nazwa}. "
                    ."Brakuje fragmentu „{$fragment}”. Sekcja została przepisana "
                    .'z zaprzeczeń na twierdzenia decyzją właściciela i treść obietnic '
                    .'miała zostać nietknięta — jeśli zmieniasz brzmienie, przenieś '
                    .'zobowiązanie do nowego zdania i popraw tę listę razem z widokiem.',
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // 2. Lista zaprzeczeń nie wróciła
    // ---------------------------------------------------------------

    public function test_stare_zaprzeczenia_nie_wrocily_na_strone(): void
    {
        $html = $this->bezStopki($this->stronaOKuking());

        // KONTROLA DODATNIA (pułapka 4): asercja „tego nie ma" przechodzi
        // także wtedy, gdy strona nie zwróciła nic albo gdy ktoś skasował
        // pół widoku. Najpierw więc dowód, że to naprawdę ta strona.
        // Kotwicą jest nagłówek Z TREŚCI strony, a NIE napis „O Kuking".
        // Po decyzji B2 (PR #398) `<h1>` brzmi „O <x-kuking-word />", więc
        // napisu „O Kuking" nie ma w treści — został tylko w `<title>`
        // i w `meta`, gdzie nazwa jest zwyczajna. Asercja na nim przechodziłaby
        // z powodu nagłówka dokumentu, nie z powodu strony (pułapka 1).
        $this->assertStringContainsString(
            'Dlaczego to powstało',
            $html,
            'To nie jest strona `/o-kuking` — asercje „tego nie ma" niżej nic by '
            .'nie znaczyły, bo w pustej albo obcej odpowiedzi nie ma nic.',
        );
        $this->assertStringContainsString(
            self::NAGLOWEK,
            $html,
            'Sekcja zobowiązań nie ma tytułu „Na co możesz liczyć". Jeśli wrócił '
            .'stary „Czego tu nie ma i nie będzie", to jest dokładnie ta zmiana, '
            .'której ten test ma nie przepuścić: tytuł też jest zobowiązaniem '
            .'i też ma być twierdzeniem.',
        );

        foreach (self::STARE_ZAPRZECZENIA as $zaprzeczenie) {
            $this->assertStringNotContainsString(
                $zaprzeczenie,
                $html,
                "Na `/o-kuking` wróciło zaprzeczenie sprzed zmiany: „{$zaprzeczenie}”. "
                .'Sekcja zobowiązań mówi twierdzeniami — decyzja właściciela. '
                .'Zobowiązanie zostaje, zmienia się tylko forma zdania.',
            );
        }

        foreach (self::ODRZUCONE_BRZMIENIA as $brzmienie) {
            $this->assertStringNotContainsString(
                $brzmienie,
                $html,
                "Na `/o-kuking` stanęło brzmienie odrzucone przez właściciela: „{$brzmienie}”. "
                .'docs/brand/GLOS_MARKI.md §6 przyjął „bez opłat i bez reklam", a odrzucił '
                .'„to darmowe" (sprzedażowo, C3) i „za darmo, na zawsze" (obietnica '
                .'na przyszłość bez gwarancji). Na przycisku i w zobowiązaniu wolno '
                .'napisać zobowiązanie, którego łamanie byłoby widoczne — nie zaletę, '
                .'której nikt nie umie sprawdzić.',
            );
        }
    }

    /**
     * To samo, ale po STRUKTURZE, nie po konkretnych zdaniach. Lista
     * zaprzeczeń wróci raczej w nowym brzmieniu niż w starym, a wtedy skan
     * wyżej jej nie złapie: „Nie sprzedajemy Twoich danych" jest inne
     * słowo w słowo, a to ten sam wzorzec.
     */
    public function test_zadna_pozycja_listy_w_tresci_nie_zaczyna_sie_od_zaprzeczenia(): void
    {
        $pozycje = $this->pozycjeListWTresci();

        // KONTROLA DODATNIA (pułapka 2): skan, który nie widzi ani jednego
        // węzła, przechodzi zawsze. Sekcja zobowiązań ma sześć pozycji,
        // a sekcja „Co jest tu najważniejsze" cztery.
        //
        // PRÓG JEST NIŻSZY NIŻ DZISIEJSZE DZIESIĘĆ I TO JEST CELOWE. Próg
        // równy stanowi faktycznemu zapalałby się PIERWSZY przy każdej korekcie
        // redakcyjnej i przesłaniałby asercję poniżej — czyli tę, po którą ten
        // test istnieje. Sprawdzone przy kontroli ujemnej: przy przywróconej
        // starej sekcji (siedem pozycji, trzy zaczynające się od „Nie")
        // ma zaboleć detektor zaprzeczeń, a nie licznik węzłów. Skasowana
        // sekcja zobowiązań zostawia cztery pozycje i wtedy łapie licznik.
        $this->assertGreaterThanOrEqual(
            7,
            count($pozycje),
            'Skan nie widzi pozycji list w treści `/o-kuking` — zła kotwica '
            .'albo przebudowany widok. Popraw kotwicę, nie kasuj testu.',
        );

        $winowajcy = array_values(array_filter(
            $pozycje,
            fn (string $tekst): bool => $this->jestZaprzeczeniem($tekst),
        ));

        $this->assertSame(
            [],
            $winowajcy,
            "Pozycja listy w treści `/o-kuking` zaczyna się od zaprzeczenia:\n  "
            .implode("\n  ", $winowajcy)."\n"
            .'Decyzja właściciela: zobowiązania na tej stronie są twierdzeniami. '
            .'Powiedz, co jest, zamiast wyliczać, czego nie ma.',
        );
    }

    /**
     * KONTROLA WZORCA, w obie strony. Detektor zaprzeczeń, który przestał
     * cokolwiek łapać, nadal się kompiluje i nadal świeci na zielono —
     * a test wyżej byłby wtedy atrapą.
     */
    public function test_detektor_zaprzeczen_lapie_stare_pozycje_i_przepuszcza_nowe(): void
    {
        // Dokładnie to, co stało na tej stronie przed zmianą.
        $this->assertTrue($this->jestZaprzeczeniem('Nie kupujemy ruchu i nie generujemy przepisów sztuczną inteligencją, żeby wypełnić serwis treścią.'));
        $this->assertTrue($this->jestZaprzeczeniem('Nie importujemy masowo cudzych przepisów.'));
        $this->assertTrue($this->jestZaprzeczeniem('Nie robimy rankingów najpopularniejszych użytkowników.'));
        // Ta sama klasa, inne brzmienie — to jest powód, dla którego
        // detektor patrzy na strukturę, a nie na listę zdań.
        $this->assertTrue($this->jestZaprzeczeniem('Bez rankingów, bez wyścigu, bez liczników.'));
        $this->assertTrue($this->jestZaprzeczeniem('Nigdy nie sprzedamy Twoich danych.'));
        $this->assertTrue($this->jestZaprzeczeniem('Żadnych reklam między daniami.'));

        // A te muszą przechodzić, inaczej detektor zacznie kłamać w drugą
        // stronę i wywróci sekcję, która jest napisana poprawnie.
        $this->assertFalse($this->jestZaprzeczeniem('Zawsze za darmo. Konto, przepisy, zdjęcia i pobranie własnych danych są darmowe i mają darmowe zostać.'));
        $this->assertFalse($this->jestZaprzeczeniem('Wszystko tutaj napisali ludzie, którzy to gotują.'));
        $this->assertFalse($this->jestZaprzeczeniem('Ludzie, nie treści. Tu są ludzie, którzy gotują na co dzień.'));
        $this->assertFalse($this->jestZaprzeczeniem('Spokój. Wpisy osób, które obserwujesz, stoją w kolejności, w jakiej je dodały. Bez rankingu popularności.'));
    }

    // ---------------------------------------------------------------
    // 3. Zapis nazwy: dwukolorowo i tylko tam, gdzie kolor w ogóle jest
    // ---------------------------------------------------------------

    /**
     * Nazwa serwisu w tej sekcji stoi jako `<x-kuking-word />`, czyli
     * dwukolorowo — decyzja B2 (`docs/brand/GLOS_MARKI.md` §2): zapis
     * dwukolorowy obowiązuje wszędzie, także jako nazwa serwisu w tekście
     * bieżącym.
     *
     * CZEGO TEN TEST NIE DUBLUJE. Tego, że nazwa NIE wchodzi do `alt`,
     * `title`, `aria-label`, `<title>`, `meta` ani JSON-LD, pilnuje
     * `TekstyWedlugCopyStyleTest::test_nazwa_nie_wchodzi_tam_gdzie_koloru_nie_ma`
     * — skanem po wszystkich widokach, więc szerzej, niż zrobiłby to test
     * jednej strony. Drugi strażnik tej samej rzeczy tylko rozmywałby
     * odpowiedzialność: przy zmianie reguły trzeba by znaleźć oba.
     *
     * Zostaje tu wyłącznie to, czego tamten test nie sprawdza — że nazwa
     * w TEJ sekcji w ogóle jest i jest dwukolorowa. Liczby wystąpień też nie
     * liczymy: „raz na akapit" pilnuje
     * `test_nazwa_nie_powtarza_sie_w_jednym_bloku_tekstu`, a sufitu na ekran
     * nie ma (B2 go zniósł).
     */
    public function test_nazwa_w_sekcji_jest_dwukolorowa(): void
    {
        $this->assertStringContainsString(
            'class="kuking-word"',
            $this->sekcjaZobowiazan(),
            'Sekcja zobowiązań straciła dwukolorowy zapis nazwy. Decyzja B2 '
            .'(`docs/brand/GLOS_MARKI.md` §2): w tekście bieżącym nazwa serwisu '
            .'idzie przez `<x-kuking-word />`, nie jako napis „Kuking".',
        );
    }

    // ---------------------------------------------------------------
    // Narzędzia
    // ---------------------------------------------------------------

    private function stronaOKuking(): string
    {
        return (string) $this->get(route('about'))->assertOk()->getContent();
    }

    /**
     * Sama sekcja zobowiązań: od jej nagłówka do następnego `<h2>`.
     * Węższy zakres niż `bezStopki()`, bo „darmowe" i „reklamy" padają na tej
     * stronie także w akapicie o Garnku.pl.
     */
    private function sekcjaZobowiazan(): string
    {
        $html = $this->bezStopki($this->stronaOKuking());

        $od = mb_strpos($html, self::NAGLOWEK);

        $this->assertNotFalse(
            $od,
            'Nie znalazłem nagłówka sekcji zobowiązań („'.self::NAGLOWEK.'"). '
            .'Jeśli sekcja dostała nowy tytuł, popraw kotwicę w tym teście '
            .'razem z widokiem — i pamiętaj, że tytuł też ma być twierdzeniem.',
        );

        $reszta = mb_substr($html, $od + mb_strlen(self::NAGLOWEK));
        $koniec = mb_strpos($reszta, '<h2');
        $sekcja = $koniec === false ? $reszta : mb_substr($reszta, 0, $koniec);

        $this->assertGreaterThan(
            400,
            mb_strlen($sekcja),
            'Wycięta sekcja zobowiązań jest podejrzanie krótka — kotwica trafia '
            .'w inne miejsce, niż myślisz.',
        );

        return $sekcja;
    }

    /**
     * Teksty wszystkich pozycji list w TREŚCI strony — czyli wewnątrz
     * `<article class="prose">`. Nawigacja, prawa szyna i stopka też są
     * listami i ich pozycje nie są zdaniami do przeczytania.
     *
     * @return list<string>
     */
    private function pozycjeListWTresci(): array
    {
        $dokument = new \DOMDocument;
        $dokument->loadHTML(
            '<?xml encoding="UTF-8">'.$this->stronaOKuking(),
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        $wezly = (new \DOMXPath($dokument))->query('//article[contains(@class, "prose")]//li');

        $this->assertNotFalse($wezly, 'Zapytanie XPath się nie wykonało.');

        $teksty = [];

        foreach ($wezly as $wezel) {
            $tekst = trim(preg_replace('/\s+/u', ' ', (string) $wezel->textContent) ?? '');

            if ($tekst !== '') {
                $teksty[] = $tekst;
            }
        }

        return $teksty;
    }

    /**
     * Czy zdanie OTWIERA SIĘ zaprzeczeniem — bo o to właściciel prosił:
     * główna myśl ma być twierdzeniem.
     *
     * Świadomie patrzymy tylko na POCZĄTEK. „Ludzie, nie treści" i „Bez
     * rankingu popularności" na końcu dłuższego zdania są poprawne i zostają;
     * zaprzeczeniem w roli głównej myśli jest to, które stoi pierwsze.
     *
     * I Świadomie patrzymy TYLKO NA PUNKTY LISTY, nie na akapity. Akapit
     * wprowadzający sekcję niesie zatwierdzone brzmienie „bez opłat i bez
     * reklam" (decyzja B1, `GLOS_MARKI.md` §6) i ma prawo się tak zaczynać:
     * jest zobowiązaniem, którego łamanie byłoby widoczne. Gdyby detektor
     * szedł po akapitach, wywróciłby zdanie, które właściciel wybrał sam —
     * i to jest różnica między pilnowaniem reguły a pilnowaniem składni.
     */
    private function jestZaprzeczeniem(string $tekst): bool
    {
        return preg_match('/^\s*(?:Nie|Bez|Nigdy|Żaden|Żadn\p{L}+|Nikt|Niczego)\b/u', $tekst) === 1;
    }

    /**
     * Stopka jest obudową strony, nie jej treścią — ten sam wzorzec i ten
     * sam powód co w `TekstyWedlugCopyStyleTest::bezStopki()`: stopka stoi
     * na każdej stronie i niesie licznik „{n} kuKINGów", więc asercja na
     * całym dokumencie przechodziłaby z powodu obudowy.
     */
    private function bezStopki(string $html): string
    {
        $start = mb_strpos($html, '<footer class="site-footer">');

        if ($start === false) {
            return $html;
        }

        $koniec = mb_strpos($html, '</footer>', $start);

        return $koniec === false
            ? mb_substr($html, 0, $start)
            : mb_substr($html, 0, $start).mb_substr($html, $koniec + mb_strlen('</footer>'));
    }

    /** Kawałek dokumentu od kotwicy do najbliższego `$do`. */
    private function wycinekOd(string $html, string $od, string $do): string
    {
        $start = mb_strpos($html, $od);

        $this->assertNotFalse($start, "Nie znalazłem kotwicy „{$od}” na stronie.");

        $koniec = mb_strpos($html, $do, $start);

        return $koniec === false
            ? mb_substr($html, $start)
            : mb_substr($html, $start, $koniec - $start + mb_strlen($do));
    }
}
