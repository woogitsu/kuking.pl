<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Główka karty wpisu nie gubi nazwy autora przy powiększonej czcionce.
 *
 * CO SIĘ STAŁO
 * Przy czcionce przeglądarki ustawionej na 200% i oknie 320 albo 360 px
 * kolumna z nazwą autora i datą miała ZERO pikseli szerokości. Nazwa i data
 * nie znikały — rozsypywały się na słup pojedynczych liter pod awatarem,
 * bo tylko tyle mieściło się w kolumnie o zerowej szerokości.
 *
 * ZMIERZONE (Chromium 1194, `scripts/glowka-karty-wpisu.mjs`, `/home`,
 * trzy długości nazwy autora, cztery szerokości okna, dwa rozmiary pisma):
 *
 *   okno    czcionka   kolumna nazwy   wysokość główki   po poprawce
 *   320 px    100%       122 px          134–330 px      bez zmian
 *   320 px    200%         0 px        1570–5588 px      174 px · 438–1108 px
 *   360 px    200%        18 px        1521–5538 px      214 px · 389–891 px
 *   390 px    200%        48 px         771–2891 px      244 px · 389–779 px
 *   414 px    200%        72 px         566–2073 px      268 px · 339–674 px
 *
 * PRZYCZYNA NIE JEST W SZEROKOŚCI OKNA, TYLKO W TYM, CO ROŚNIE Z CZCIONKĄ,
 * A CO NIE. Przy podwojonej czcionce bazowej `rem` jest dwa razy większy,
 * więc wcięcie główki idzie z 20 na 40 px z każdej strony, odstęp z 12 na
 * 24 px, a przycisk menu z 48 na 96 px (`--control-height-min` to 3rem).
 * Awatar ma 52 px podane z widoku i zostaje przy 52 px. Na oknie 320 px daje
 * to 196 px rzeczy nieściśliwych w rzędzie szerokim na 176 px — a jedynym
 * elementem, który wolno ścisnąć, była kolumna z nazwą (`min-w-0` mówiło
 * dokładnie tyle: „mnie wolno"). Ściskała się do zera.
 *
 * CZEGO TEN TEST PILNUJE — TRZY RZECZY, KAŻDA OSOBNO
 * PHPUnit nie składa strony i pikseli tu nie zmierzy. Mierzalne są za to
 * wszystkie trzy części poprawki, a skasowanie każdej z nich osobno
 * przywraca usterkę:
 *
 *   1. kolumna z nazwą ma NAZWANĄ KLASĘ, a ta klasa ma w `app.css` regułę,
 *      która daje jej w rzędzie WŁASNĄ WAGĘ (niezerowy `flex-basis`)
 *      — bez tego kolumna znów przegrywa z przyciskiem i schodzi do zera;
 *   2. `.post-card-head` umie się zawinąć (`flex-wrap: wrap`) — bez tego
 *      nie ma dokąd zejść i rząd zaciska się dalej;
 *   3. próg, przy którym nazwa bierze cały rząd, jest podany w jednostce
 *      ZALEŻNEJ OD CZCIONKI (`em`), a nie w pikselach. To jest sedno:
 *      usterka zależy od rozmiaru pisma, więc próg podany w pikselach
 *      pilnowałby nie tej rzeczy i ten sam telefon byłby raz w porządku,
 *      a raz nie.
 *
 * CZEGO TEN TEST NIE OBIECUJE
 * Nie mierzy ani jednego piksela i nie zastąpi pomiaru w przeglądarce.
 * Szerokość kolumny, wysokość główki, cel dotknięcia menu i brak przewijania
 * w bok mierzy `scripts/glowka-karty-wpisu.mjs` — i tylko on. Ten test
 * pilnuje tego, co w arkuszu i w widoku da się zgubić jednym skasowanym
 * wierszem, tak żeby pomiar nie był jedyną linią obrony.
 *
 * CELU DOTKNIĘCIA MENU TEN TEST NIE DUBLUJE — 48 × 48 px pilnuje
 * `KartaWpisuTest::test_menu_karty_to_same_kropki_ale_czytnik_ekranu_nie_traci_nic`.
 */
class GlowkaKartyWpisuNieGubiNazwyAutoraTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Arkusz BEZ KOMENTARZY — nauka z PR #400 i #405 (i z
     * `PorzadkiWArkuszuKartyTest`). Nad tymi regułami stoi kilkadziesiąt
     * wierszy komentarza, w którym każdy selektor i każda deklaracja pada
     * z nazwy. Wzorzec „selektor, potem `{…}`" nie odróżnia reguły od jej
     * własnego opisu, więc test na surowym pliku przechodziłby na komentarzu
     * po skasowaniu kodu.
     */
    private function arkusz(): string
    {
        $tresc = (string) file_get_contents(resource_path('css/app.css'));

        // Pułapka 2 z `docs/PULAPKI_TESTOW.md`: skan, który nic nie czyta,
        // uznaje zero trafień za sukces. Arkusz karty ma dziś ponad 200 kB.
        $this->assertGreaterThan(
            50_000,
            strlen($tresc),
            '`resources/css/app.css` jest podejrzanie krótki — test czyta nie ten plik.',
        );

        return (string) preg_replace('#/\*.*?\*/#s', '', $tresc);
    }

    /**
     * Deklaracje reguły, której lista selektorów wymienia `$klasa` jako CAŁY
     * token. `(?![\w-])` jest tu całą robotą: bez niego `.post-card-head`
     * łapie się także na `.post-card-header-cokolwiek`.
     *
     * @return list<string> deklaracje każdej pasującej reguły, w kolejności z pliku
     */
    private function regulyDla(string $klasa, ?string $css = null): array
    {
        $css ??= $this->arkusz();

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $reguly, PREG_SET_ORDER);

        $znalezione = [];

        foreach ($reguly as $regula) {
            if (preg_match('/\.'.preg_quote($klasa, '/').'(?![\w-])/', $regula[1]) === 1) {
                $znalezione[] = trim($regula[2]);
            }
        }

        return $znalezione;
    }

    /**
     * Bloki `@media` z arkusza, z warunkiem i treścią. Nawiasy klamrowe
     * liczymy ręcznie, bo wyrażenie regularne nie umie ich sparować.
     *
     * @return list<array{warunek: string, tresc: string}>
     */
    private function blokiMedia(string $css): array
    {
        $bloki = [];
        $od = 0;

        while (($poczatek = strpos($css, '@media', $od)) !== false) {
            $klamra = strpos($css, '{', $poczatek);

            if ($klamra === false) {
                break;
            }

            $glebokosc = 0;
            $koniec = null;

            for ($i = $klamra, $n = strlen($css); $i < $n; $i++) {
                if ($css[$i] === '{') {
                    $glebokosc++;
                } elseif ($css[$i] === '}') {
                    $glebokosc--;

                    if ($glebokosc === 0) {
                        $koniec = $i;

                        break;
                    }
                }
            }

            if ($koniec === null) {
                break;
            }

            $bloki[] = [
                'warunek' => trim(substr($css, $poczatek + 6, $klamra - $poczatek - 6)),
                'tresc' => substr($css, $klamra + 1, $koniec - $klamra - 1),
            ];

            $od = $koniec + 1;
        }

        return $bloki;
    }

    /**
     * Klasy elementu, który w wyrenderowanej główce karty niesie nazwę autora
     * i datę. Szukamy go PRZEZ ODJĘCIE — to bezpośrednie dziecko główki,
     * które nie jest ani opakowaniem awatara, ani menu „…" — a nie po nazwie
     * klasy, bo to właśnie nazwa klasy jest tu przedmiotem sprawdzenia.
     *
     * @return list<string>
     */
    private function klasyKolumnyZNazwa(): array
    {
        $autorka = $this->user('malgorzata', ['display_name' => 'Małgorzata Wiśniewska-Kowalczyk']);

        Post::factory()->create(['author_id' => $autorka->getKey()]);

        $html = (string) $this->actingAs($autorka->fresh())
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dokument);

        $glowka = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' post-card-head ')]")?->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $glowka,
            'Na stronie głównej nie ma ani jednej `.post-card-head` — nie ma czego sprawdzać.',
        );

        // KONTROLA DODATNIA: to ma być główka TEJ karty, z nazwą tej autorki.
        // Bez niej test przeszedłby także wtedy, gdyby feed nie pokazał wpisu
        // i mierzylibyśmy pustą stronę (pułapka 4).
        $this->assertStringContainsString(
            'Małgorzata Wiśniewska-Kowalczyk',
            (string) $glowka->textContent,
            'W główce karty nie ma nazwy autorki — wyrenderowała się nie ta karta.',
        );

        $kandydaci = [];

        foreach ($glowka->childNodes as $dziecko) {
            if (! $dziecko instanceof DOMElement) {
                continue;
            }

            $klasy = preg_split('/\s+/', trim($dziecko->getAttribute('class'))) ?: [];
            $klasy = array_values(array_filter($klasy));

            // Opakowanie awatara i menu „…" odpadają: pierwsze ma w środku
            // `.avatar`, drugie jest `<details>`.
            $maAwatar = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' avatar ')]", $dziecko)?->length > 0;

            if ($maAwatar || strtolower($dziecko->tagName) === 'details') {
                continue;
            }

            $kandydaci[] = $klasy;
        }

        $this->assertCount(
            1,
            $kandydaci,
            'W główce karty wpisu nie ma dokładnie jednego bloku, który nie jest ani awatarem, '.
            'ani menu „…". Zmienił się kształt główki i strażnik patrzy w złe miejsce.',
        );

        $this->assertNotSame(
            [],
            $kandydaci[0],
            "Kolumna z nazwą autora i datą jest w główce karty elementem BEZ KLASY.\n".
            '`.post-card-head` jest `display: flex`, więc bez własnej reguły ta kolumna jest '.
            'jedynym ściśliwym elementem rzędu i przy czcionce 200% schodzi do ZERA pikseli — '.
            'nazwa i data rozsypują się na słup pojedynczych liter (zmierzone: główka wysoka '.
            "na 5588 px zamiast 330 px).\nNadaj jej klasę i opisz jej zachowanie w `app.css`.",
        );

        return $kandydaci[0];
    }

    public function test_kolumna_z_nazwa_i_data_liczy_sie_w_rzedzie_glowki(): void
    {
        $klasy = $this->klasyKolumnyZNazwa();
        $css = $this->arkusz();

        $zWaga = [];

        foreach ($klasy as $klasa) {
            foreach ($this->regulyDla($klasa, $css) as $deklaracje) {
                $podstawa = $this->podstawaFlex($deklaracje);

                if ($podstawa !== null && $podstawa > 0) {
                    $zWaga[] = $klasa;
                }
            }
        }

        $this->assertNotSame(
            [],
            $zWaga,
            'Kolumna z nazwą autora i datą (class="'.implode(' ', $klasy).'") nie ma w `app.css` '.
            "reguły z NIEZEROWYM `flex-basis`.\n".
            'Sam `min-width: 0` mówi tylko „mnie wolno ścisnąć" — i przy czcionce przeglądarki '.
            '200% rząd korzysta z tego pozwolenia do końca: awatar (52 px), odstępy i przycisk '.
            "menu (96 px) nie mieszczą się w karcie szerokiej na 256 px, więc kolumna dostaje 0 px.\n".
            'Niezerowa podstawa (`flex: 1 1 96px`) daje jej w rzędzie własną wagę — wtedy to '.
            'przycisk schodzi do drugiego rzędu, a nie nazwa autora do zera.',
        );

        // Kolumna ma też BRAĆ wolne miejsce, a nie siedzieć na swojej podstawie:
        // przy `flex-grow: 0` zwęziłaby się z 122 px do 96 px na każdym oknie
        // przy zwykłej czcionce, czyli poprawka jednego zepsułaby drugie.
        $rosnie = false;

        foreach ($klasy as $klasa) {
            foreach ($this->regulyDla($klasa, $css) as $deklaracje) {
                if ($this->wzrostFlex($deklaracje) > 0) {
                    $rosnie = true;
                }
            }
        }

        $this->assertTrue(
            $rosnie,
            'Kolumna z nazwą autora nie ma `flex-grow` większego od zera, więc przestaje brać '.
            'wolne miejsce w rzędzie. Przy zwykłej czcionce zwęża się wtedy do własnej podstawy '.
            '(zmierzone przed poprawką: 122 px przy oknie 320 px) i nazwa łamie się bez powodu.',
        );
    }

    public function test_glowka_karty_umie_zejsc_do_dwoch_rzedow(): void
    {
        $reguly = $this->regulyDla('post-card-head');

        $this->assertNotSame([], $reguly, 'W `app.css` nie ma reguły `.post-card-head`.');

        $zZawijaniem = array_values(array_filter(
            $reguly,
            fn (string $deklaracje): bool => preg_match('/flex-wrap\s*:\s*wrap/', $deklaracje) === 1,
        ));

        $this->assertNotSame(
            [],
            $zZawijaniem,
            "`.post-card-head` nie ma `flex-wrap: wrap`.\n".
            'Bez tego rząd główki nie ma dokąd zejść: przy czcionce 200% awatar, odstępy '.
            'i przycisk menu zajmują 196 px w rzędzie szerokim na 176 px, a nadmiar odbiera '.
            'się jedynemu ściśliwemu elementowi — kolumnie z nazwą autora, która schodzi do 0 px.',
        );
    }

    public function test_prog_zawijania_glowki_mierzy_czcionke_a_nie_piksele(): void
    {
        $klasy = $this->klasyKolumnyZNazwa();
        $css = $this->arkusz();

        $pasujace = [];

        foreach ($this->blokiMedia($css) as $blok) {
            foreach ($klasy as $klasa) {
                $reguly = $this->regulyDla($klasa, $blok['tresc']);

                foreach ($reguly as $deklaracje) {
                    if (preg_match('/flex-basis\s*:\s*100%/', $deklaracje) === 1) {
                        $pasujace[] = $blok['warunek'];
                    }
                }
            }
        }

        $this->assertNotSame(
            [],
            $pasujace,
            'W `app.css` nie ma zapytania medialnego, które przy ciasnym ekranie oddaje kolumnie '.
            'z nazwą autora (class="'.implode(' ', $klasy)."\") cały rząd (`flex-basis: 100%`).\n".
            'Bez tego przy czcionce 200% nazwa i data dzielą rząd z awatarem i z przyciskiem menu, '.
            'a zostaje im 98 px zamiast 174–268 px (zmierzone, `scripts/glowka-karty-wpisu.mjs`).',
        );

        foreach ($pasujace as $warunek) {
            $this->assertMatchesRegularExpression(
                '/\d\s*em\b/',
                $warunek,
                "Próg zawijania główki karty (`@media {$warunek}`) nie jest podany w `em`.\n".
                'To jest sedno tej poprawki: główka rozpada się nie przy wąskim oknie, tylko przy '.
                'DUŻEJ CZCIONCE w wąskim oknie — te same 320 px są w porządku przy czcionce 100% '.
                "i nie są przy 200%.\n".
                '`em` w zapytaniu medialnym to czcionka bazowa przeglądarki, czyli dokładnie ta '.
                'wielkość, o którą chodzi. Próg w pikselach nie zauważa jej w ogóle: albo zawija '.
                'główkę na telefonie, który tego nie potrzebuje, albo nie zawija na tym, który '.
                'potrzebuje.',
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\d\s*px\b/',
                $warunek,
                "Próg zawijania główki karty (`@media {$warunek}`) mierzy piksele. Patrz wyżej: ".
                'mierzoną wielkością ma być czcionka bazowa przeglądarki, a nie szerokość okna '.
                'w pikselach.',
            );
        }
    }

    /**
     * Podstawa (`flex-basis`) z deklaracji reguły — z własności długiej albo
     * z trzeciego członu skrótu `flex`. Zwraca `null`, gdy podstawy nie ma
     * albo gdy jest nią `auto` (czyli „licz się szerokością swojej treści",
     * a to jest stan sprzed poprawki).
     */
    private function podstawaFlex(string $deklaracje): ?float
    {
        if (preg_match('/(?<![\w-])flex-basis\s*:\s*([^;]+)/', $deklaracje, $trafienie) === 1) {
            return $this->dlugosc(trim($trafienie[1]));
        }

        if (preg_match('/(?<![\w-])flex\s*:\s*([^;]+)/', $deklaracje, $trafienie) === 1) {
            $czlony = preg_split('/\s+/', trim($trafienie[1])) ?: [];

            foreach ($czlony as $czlon) {
                $dlugosc = $this->dlugosc($czlon);

                if ($dlugosc !== null) {
                    return $dlugosc;
                }
            }
        }

        return null;
    }

    /** `flex-grow` z własności długiej albo z pierwszego członu skrótu `flex`. */
    private function wzrostFlex(string $deklaracje): float
    {
        if (preg_match('/(?<![\w-])flex-grow\s*:\s*([\d.]+)/', $deklaracje, $trafienie) === 1) {
            return (float) $trafienie[1];
        }

        if (preg_match('/(?<![\w-])flex\s*:\s*([\d.]+)/', $deklaracje, $trafienie) === 1) {
            return (float) $trafienie[1];
        }

        return 0.0;
    }

    /**
     * Wartość długości w pikselach albo `null`, gdy człon nie jest długością.
     * `auto`, `content` i gołe liczby (to `flex-grow`/`flex-shrink`) nie są
     * długością — i o to chodzi, bo żadne z nich nie daje kolumnie wagi.
     */
    private function dlugosc(string $czlon): ?float
    {
        if (preg_match('/^([\d.]+)(px|rem|em|ch)$/', $czlon, $trafienie) !== 1) {
            return null;
        }

        return (float) $trafienie[1];
    }
}
