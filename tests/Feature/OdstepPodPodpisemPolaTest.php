<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * ODSTĘP POD PODPISEM POLA — issue #445.
 *
 * ZGŁOSZENIE: „etykieta przyklejona do pola". Odstęp pod podpisem dostawała
 * w praktyce PODPOWIEDŹ (`.field-help + .field-input`, 12 px), a pole bez
 * podpowiedzi nie dostawało nic własnego — zostawało mu tylko 8 px z reguły
 * `label { margin-bottom: var(--spacing-2) }` w warstwie base, czyli
 * DOKŁADNIE TYLE, ILE DZIELI PODPIS OD JEGO WŁASNEGO WYJAŚNIENIA.
 *
 * ZMIERZONE w Chromium 141 („dół etykiety → góra ramki pola", pola bez
 * podpowiedzi, szerokości 320 / 360 / 390 / 414 px — wszystkie cztery tak
 * samo, bo to odstęp typograficzny, nie układ):
 *
 *     ekran                        czcionka zwykła   czcionka przeglądarki 200%
 *     /login („Hasło")                 8 → 12 px            16 → 24 px
 *     /ustawienia/profil               8 → 12 px            16 → 24 px
 *     /ustawienia/bezpieczenstwo       8 → 12 px            16 → 24 px
 *     /dodaj/przepis („Nazwa…")        8 → 12 px            16 → 24 px
 *
 * A pola Z PODPOWIEDZIĄ zostają nietknięte: 8 / 12 px (16 / 24 px przy
 * czcionce 200%) przed zmianą i po niej.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  SPROSTOWANIE DO OPISU ZGŁOSZENIA — bo liczba z niego nie była prawdziwa
 * ══════════════════════════════════════════════════════════════════════
 * Komentarz w `tokens.css` (i za nim opis #445) mówił „zmierzone 0 px,
 * np. «Nowe hasło» na /ustawienia". Zmierzone jest 8 px, nie 0 —
 * a „Nowe hasło" na `/ustawienia/bezpieczenstwo` MA podpowiedź, więc było
 * akurat jednym z pól z odstępem 12 px. Usterka jest realna, ale dotyczy
 * innych pól tego ekranu: „Obecne hasło" i „Powtórz nowe hasło". Dlatego
 * ten test bierze za wzorzec właśnie je, a „Nowe hasło" trzyma jako
 * KONTROLĘ DODATNIĄ — pole, którego ta zmiana nie ma prawa ruszyć.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO ARKUSZ I DOKUMENT, TAK JAK W RytmFormularzaKomentarzaTest
 * ══════════════════════════════════════════════════════════════════════
 * Reguła wisi na SĄSIEDZTWIE (D-158), więc sprawdzanie samego arkusza
 * pilnowałoby połowy: wstawienie czegokolwiek między etykietę a pole
 * wyłącza odstęp, nie ruszając ani jednej linii CSS-a. I odwrotnie —
 * sprawdzanie samego dokumentu nie zauważy skasowania reguły.
 *
 * CZEGO TEN TEST NIE DOWODZI: że 12 px wygląda dobrze. Piksele mierzy się
 * w przeglądarce (liczby wyżej); tu pilnujemy, żeby reguła istniała, miała
 * na czym działać i żeby pole z podpisem I podpowiedzią nie dostało obu
 * odstępów naraz.
 */
class OdstepPodPodpisemPolaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /** Treść arkusza BEZ komentarzy — nad regułą stoi jej opis, pełen selektorów. */
    private function css(): string
    {
        $tresc = (string) file_get_contents(resource_path('css/tokens.css'));

        return (string) preg_replace('#/\*.*?\*/#s', '', $tresc);
    }

    /**
     * Reguły arkusza jako pary [lista selektorów, deklaracje].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function reguly(): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $this->css(), $trafienia, PREG_SET_ORDER);

        $reguly = [];

        foreach ($trafienia as $regula) {
            $reguly[] = [
                (string) preg_replace('/\s+/', ' ', trim($regula[1])),
                (string) preg_replace('/\s+/', ' ', trim($regula[2])),
            ];
        }

        // Pułapka 2 z docs/PULAPKI_TESTOW.md: skan, który nic nie czyta,
        // przechodzi każdą asercję „nie znalazłem nic złego".
        // Zmierzone 12.09.2026: to wyrażenie czyta z `tokens.css` 79 reguł (nie umie
        // w zagnieżdżenie, więc `@layer`/`@media` są tu regułą zewnętrzną, nie zbiorem).
        // Próg jest niżej, bo ma łapać ZNIKNIĘCIE arkusza, a nie skok o kilka reguł.
        $this->assertGreaterThan(
            50,
            count($reguly),
            'Z `resources/css/tokens.css` wyszło mniej niż pięćdziesiąt reguł — arkusz się '.
            'przeniósł albo wyrażenie przestało go czytać. Test nie sprawdza wtedy niczego.',
        );

        return $reguly;
    }

    /** Dokument ekranu (samo `<main>`) razem z XPathem po nim. */
    private function ekran(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$this->trescEkranu($html), LIBXML_NOERROR | LIBXML_NOWARNING);

        return [$dom, new DOMXPath($dom)];
    }

    /** Bezpośredni poprzednik pola do wpisywania — etykieta albo podpowiedź. */
    private function poprzednikPola(DOMXPath $xpath, DOMElement $pole): ?DOMElement
    {
        $poprzednie = $xpath->query('preceding-sibling::*[1]', $pole);
        $this->assertInstanceOf(DOMNodeList::class, $poprzednie);

        $poprzednik = $poprzednie->item(0);

        return $poprzednik instanceof DOMElement ? $poprzednik : null;
    }

    /** Pole do wpisywania stojące w tym samym `.field`, co etykieta o danym początku. */
    private function poleZPodpisem(DOMXPath $xpath, string $poczatekPodpisu): DOMElement
    {
        $pola = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " field ")]'
            ."[label[starts-with(normalize-space(.), \"{$poczatekPodpisu}\")]]"
            .'/*[contains(concat(" ", normalize-space(@class), " "), " field-input ")]',
        );

        $this->assertInstanceOf(DOMNodeList::class, $pola);
        $this->assertSame(
            1,
            $pola->length,
            'Na tym ekranie nie ma dokładnie jednego pola o podpisie zaczynającym się od '
            .'„'.$poczatekPodpisu.'" (znalazłem '.$pola->length.'). Pusty wynik przechodzi każdą '
            .'asercję o sąsiedztwie — popraw przygotowanie ekranu, nie asercję.',
        );

        $pole = $pola->item(0);
        $this->assertInstanceOf(DOMElement::class, $pole);

        return $pole;
    }

    private function klasy(DOMElement $element): array
    {
        return preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
    }

    /**
     * Reguła dająca odstęp polu stojącemu ZARAZ POD PODPISEM — i to ona,
     * a nie warstwa base, ma o tym odstępie decydować.
     */
    #[Test]
    public function test_odstep_pod_podpisem_deklaruje_pole_i_idzie_z_tokenu(): void
    {
        $pasujace = array_values(array_filter(
            $this->reguly(),
            fn (array $regula): bool => preg_match(
                '/\.field\s*>\s*label[^,{]*\+\s*\.field-input/',
                $regula[0],
            ) === 1,
        ));

        $this->assertNotEmpty(
            $pasujace,
            'W `resources/css/tokens.css` nie ma reguły odsuwającej pole od stojącego '.
            'BEZPOŚREDNIO nad nim podpisu (`.field > label … + .field-input`). Bez niej '.
            'pole bez podpowiedzi zostaje z 8 px z warstwy base — dokładnie tyle, ile '.
            'dzieli podpis od jego WŁASNEJ podpowiedzi, czyli rzecz odrębna stoi tak '.
            'samo blisko jak rzecz powiązana (#445).',
        );

        $deklaracje = implode(' ', array_column($pasujace, 1));

        $this->assertMatchesRegularExpression(
            '/(?<![\w-])margin-top\s*:\s*var\(--spacing-[1-9]\d*\)/',
            $deklaracje,
            'Odstęp pod podpisem nie jest `margin-top` z tokenu `--spacing-N`. '.
            'STRONA PARY: dolna (D-154) — górna, czyli etykieta, niczego pod sobą nie '.
            'dokłada. TOKEN, NIE PIKSELE: to odstęp typograficzny, więc ma urosnąć razem '.
            'z pismem przy czcionce przeglądarki 200% (D-082, D-107). '.
            "Zastane deklaracje: {$deklaracje}",
        );
    }

    /**
     * SEDNO #445: pole, które ma I PODPIS, I PODPOWIEDŹ, dostaje JEDEN odstęp.
     *
     * Gwarancję daje tu kształt arkusza, nie ostrożność autora widoku: obie
     * sytuacje obsługuje JEDNA deklaracja, a wybiera między nimi sąsiedztwo
     * — bezpośrednim poprzednikiem pola jest albo podpowiedź, albo etykieta,
     * nigdy oboje. Rozbicie tego na dwie reguły z dwiema wartościami byłoby
     * pierwszym krokiem do 12 + 12 px.
     */
    #[Test]
    public function test_podpis_i_podpowiedz_daja_jeden_odstep_a_nie_dwa(): void
    {
        $zMarginesem = array_values(array_filter(
            $this->reguly(),
            fn (array $regula): bool => str_contains($regula[0], '.field-input')
                && preg_match('/(?<![\w-])margin-top\s*:/', $regula[1]) === 1,
        ));

        $this->assertCount(
            1,
            $zMarginesem,
            'Górny margines pola do wpisywania ustawia w `tokens.css` więcej niż jedna '.
            'reguła. Przy dwóch regułach nic nie broni już przed polem, które dostaje '.
            'odstęp pod podpisem I odstęp pod podpowiedzią — a te odstępy się zsumują. '.
            'Zastane listy selektorów: '.implode(' || ', array_column($zMarginesem, 0)),
        );

        [$selektory, $deklaracje] = $zMarginesem[0];

        $this->assertMatchesRegularExpression(
            '/\.field-help\s*\+\s*\.field-input/',
            $selektory,
            'Ta jedna reguła przestała obejmować pole stojące pod podpowiedzią (#433). '.
            "Zastana lista selektorów: {$selektory}",
        );
        $this->assertMatchesRegularExpression(
            '/\.field\s*>\s*label[^,{]*\+\s*\.field-input/',
            $selektory,
            'Ta jedna reguła przestała obejmować pole stojące pod samym podpisem (#445). '.
            "Zastana lista selektorów: {$selektory}",
        );

        $this->assertSame(
            1,
            preg_match_all('/(?<![\w-])margin-top\s*:/', $deklaracje),
            'Reguła ustawia `margin-top` więcej niż raz — wartość ma być JEDNA, żeby '.
            'nie dało się wprowadzić dwóch różnych odstępów przez jedną zmianę. '.
            "Zastane deklaracje: {$deklaracje}",
        );
    }

    /**
     * …a tu to samo od strony dokumentu: na ekranie, na którym stoją OBA
     * rodzaje pól, każde ma nad sobą DOKŁADNIE JEDNEGO sąsiada z tej pary.
     *
     * Bez tej asercji poprzednia pilnowałaby kształtu reguły, a nie tego, że
     * reguła ma na czym zadziałać (D-158): wstawienie czegokolwiek między
     * podpis a pole gasi odstęp, nie ruszając arkusza.
     */
    #[Test]
    public function test_nad_polem_stoi_albo_podpis_albo_podpowiedz_nigdy_oboje(): void
    {
        $czlowiek = $this->user('gospodyni');

        [, $xpath] = $this->ekran(
            (string) $this->actingAs($czlowiek)->get(route('settings.security'))->assertOk()->getContent(),
        );

        // Pole BEZ podpowiedzi — to ono jest usterką z #445.
        $bezPodpowiedzi = $this->poprzednikPola($xpath, $this->poleZPodpisem($xpath, 'Obecne hasło'));

        $this->assertTrue(
            $bezPodpowiedzi instanceof DOMElement && $bezPodpowiedzi->tagName === 'label',
            'Nad polem „Obecne hasło" nie stoi już bezpośrednio jego podpis, tylko '.
            ($bezPodpowiedzi instanceof DOMElement
                ? '<'.$bezPodpowiedzi->tagName.' class="'.$bezPodpowiedzi->getAttribute('class').'">'
                : 'nic').
            '. Odstęp daje reguła z `+`, więc od tej zmiany podpis znów skleja się '.
            'z ramką pola, a w arkuszu nic nie drgnęło.',
        );

        // KONTROLA DODATNIA: pole Z podpowiedzią ma nad sobą podpowiedź, czyli
        // reguła „pod podpisem" go NIE dotyczy i odstępy się nie sumują.
        $zPodpowiedzia = $this->poprzednikPola($xpath, $this->poleZPodpisem($xpath, 'Nowe hasło'));

        $this->assertTrue(
            $zPodpowiedzia instanceof DOMElement && in_array('field-help', $this->klasy($zPodpowiedzia), true),
            'Nad polem „Nowe hasło" nie stoi już jego podpowiedź, tylko '.
            ($zPodpowiedzia instanceof DOMElement
                ? '<'.$zPodpowiedzia->tagName.' class="'.$zPodpowiedzia->getAttribute('class').'">'
                : 'nic').
            '. Gdyby stał tam podpis, pole dostałoby odstęp pod podpisem PLUS własny '.
            'odstęp podpowiedzi — czyli dokładnie to, czego #445 zabrania.',
        );

        $this->assertNotSame(
            $bezPodpowiedzi?->tagName,
            $zPodpowiedzia?->tagName,
            'Oba pola mają nad sobą to samo — ekran przestał mieć obie odmiany pola '.
            'naraz, więc ten test nie porównuje już niczego.',
        );
    }

    /**
     * PODPIS SCHOWANY PRZED OKIEM NIE DOSTAJE ODSTĘPU.
     *
     * `:not(.visually-hidden)` w regule ma konkretny cel w tym repozytorium:
     * `/admin/kuking-na-dzis` podpisuje pole notatki wyłącznie dla czytnika
     * ekranu. Pusty pas 12 px pod napisem, którego nie widać, byłby odstępem
     * bez rzeczy, którą oddziela — a w liście kilkudziesięciu wierszy
     * zsumowałby się w ekran przewijania.
     *
     * Test ma DWIE połowy z tego samego powodu co reszta pliku: warunek
     * w arkuszu bez takiej etykiety w dokumencie byłby zabezpieczeniem
     * przed niczym, a etykieta bez warunku — usterką.
     */
    #[Test]
    public function test_podpis_schowany_przed_okiem_nie_dostaje_odstepu(): void
    {
        $zMarginesem = array_values(array_filter(
            $this->reguly(),
            fn (array $regula): bool => preg_match(
                '/\.field\s*>\s*label[^,{]*\+\s*\.field-input/',
                $regula[0],
            ) === 1,
        ));

        $this->assertNotEmpty($zMarginesem, 'Brak reguły odstępu pod podpisem — patrz test wyżej.');

        $this->assertMatchesRegularExpression(
            '/\.field\s*>\s*label:not\(\.visually-hidden\)\s*\+\s*\.field-input/',
            $zMarginesem[0][0],
            'Reguła odstępu pod podpisem nie wyłącza podpisów schowanych przed okiem. '.
            'Bez `:not(.visually-hidden)` każdy wiersz `/admin/kuking-na-dzis` dostaje '.
            '12 px pustki pod napisem, którego nie widać. '.
            "Zastana lista selektorów: {$zMarginesem[0][0]}",
        );

        // …i druga połowa: taka etykieta naprawdę stoi zaraz nad polem.
        //
        // Ekran wybiera spośród ŚWIEŻYCH wpisów i osób, które coś pokazały —
        // bez danych renderuje pusty stan, a pusty stan przechodzi każdą
        // asercję o sąsiedztwie (pułapka 2 z docs/PULAPKI_TESTOW.md).
        Post::factory()->create(['author_id' => $this->user('kucharka')->getKey()]);

        [, $xpath] = $this->ekran(
            (string) $this->actingAs($this->moderator())
                ->get(route('admin.daily-board'))
                ->assertOk()
                ->getContent(),
        );

        $schowane = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " field ")]'
            .'/label[contains(concat(" ", normalize-space(@class), " "), " visually-hidden ")]'
            .'[following-sibling::*[1][contains(concat(" ", normalize-space(@class), " "), " field-input ")]]',
        );

        $this->assertInstanceOf(DOMNodeList::class, $schowane);
        $this->assertGreaterThan(
            0,
            $schowane->length,
            'Na `/admin/kuking-na-dzis` nie ma już pola z podpisem schowanym przed okiem, '.
            'stojącym zaraz nad polem. Wyjątek `:not(.visually-hidden)` nie ma wtedy czego '.
            'pilnować — albo usuń go z arkusza razem z tym testem, albo przywróć ekran. '.
            'Uwaga: pusta lista osób i wpisów też daje zero — sprawdź, czy ekran ma dane.',
        );
    }
}
