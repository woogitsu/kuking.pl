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
     * Lista kontrolna zmiany: zobowiązanie => dwa fragmenty, które je niosą.
     *
     * DWA FRAGMENTY, NIE JEDEN, na każde zobowiązanie. Sam nagłówek pozycji
     * („Cały ekran należy do gotowania") jest hasłem; zobowiązanie niesie
     * dopiero zdanie pod nim. Gdyby test pilnował tylko hasła, można by
     * skasować zdanie i zostawić samo hasło — i przeszedłby.
     *
     * Pierwsze cztery pozycje to te same zobowiązania, które stały tu
     * w formie zaprzeczonej. Dwa ostatnie dołożył właściciel tą samą decyzją:
     * nigdy reklamy, nigdy płatny dostęp.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ZOBOWIAZANIA = [
        'bez opłat — dostęp jest i zostanie darmowy' => [
            'Zawsze za darmo',
            'darmowe i mają darmowe zostać',
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
        $this->assertStringContainsString(
            'O Kuking',
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
     * dwukolorowo. Zapis dwukolorowy NIESIE KOLOR, więc ma sens wyłącznie
     * w treści widocznej — w `<title>` i w `meta` byłby znacznikiem
     * wklejonym w tekst, który nikomu nic nie pokaże.
     */
    public function test_nazwa_w_sekcji_jest_dwukolorowa_a_w_naglowku_dokumentu_zwyczajna(): void
    {
        $html = $this->stronaOKuking();

        $this->assertStringContainsString(
            'class="kuking-word"',
            $this->sekcjaZobowiazan(),
            'Sekcja zobowiązań straciła dwukolorowy zapis nazwy — decyzja '
            .'właściciela: w tekście bieżącym nazwa serwisu idzie przez '
            .'`<x-kuking-word />`.',
        );

        foreach (['<title>', '<meta name="description"'] as $kotwica) {
            $this->assertStringNotContainsString(
                'kuking-word',
                $this->wycinekOd($html, $kotwica, '>'),
                "Dwukolorowy zapis nazwy trafił do `{$kotwica}`. Tam nie ma koloru, "
                .'więc zostaje sam znacznik w tekście — w `title`, `meta`, `alt`, '
                .'`aria-label` i JSON-LD piszemy zwyczajnie „Kuking".',
            );
        }
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
