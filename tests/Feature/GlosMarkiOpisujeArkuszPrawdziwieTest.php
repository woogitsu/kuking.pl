<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dokumenty marki nie obiecują reguły CSS, której w arkuszu nie ma.
 *
 * SKĄD TO SIĘ WZIĘŁO — DOKUMENT WIĄŻĄCY PODAWAŁ WERSJĘ, KTÓRA OBLAŁA POMIAR
 * `docs/brand/GLOS_MARKI.md` §2 („Jedno miejsce w kodzie", punkt 3) mówił:
 * „**`white-space: nowrap`.** Słowo nie łamie się między «ku» i «KING»".
 * Arkusz w tym samym czasie deklarował `overflow-wrap: anywhere`, a komentarz
 * nad tą regułą mówił wprost, że `nowrap` BYŁ pierwszą wersją I OBLAŁ SKAN
 * DOSTĘPNOŚCI: na `/register` przy oknie 320 px i czcionce przeglądarki 200%
 * samo słowo brało 315 px zaczynając od x = 32, czyli strona przewijała się
 * w bok o 27 px — naruszenie WCAG 2.2 AA (1.4.10 Reflow).
 *
 * Czyli dokument obowiązujący podawał jako regułę dokładnie tę wersję, którą
 * pomiar odrzucił, i podawał razem z nią jej uzasadnienie. Następna osoba,
 * porządkując arkusz „zgodnie z dokumentacją", przywróciłaby `nowrap`
 * i zepsułaby Reflow — nie z niedbalstwa, a *czytając wiążący dokument*.
 * To ta sama choroba co w D-119: plik, który powtarza regułę zamiast odesłać
 * do niej, rozjeżdża się z nią cicho, a rozjazd wygląda na rozstrzygnięcie.
 *
 * DLACZEGO TEN TEST PILNUJE OBU STRON
 * Sprawdzenie „dokument nie mówi `nowrap`" złapałoby tylko jedną połowę.
 * Druga połowa jest groźniejsza: gdyby ktoś zmienił ARKUSZ z powrotem na
 * `nowrap`, dokument dalej mówiłby prawdę o swojej obietnicy, a produkt
 * przewijałby się w bok. Dlatego test parsuje obietnicę z dokumentu
 * i porównuje ją z tym, co naprawdę stoi w regule `.kuking-word` — więc
 * oblewa niezależnie od tego, którą stronę ktoś ruszy.
 *
 * DLACZEGO ZAKAZ NIE IDZIE NA SAMO SŁOWO „nowrap" W TEKŚCIE
 * Poprawiony punkt 3 MUSI wymienić `white-space: nowrap` — po to, żeby nikt
 * nie wrócił do wariantu, którego powód odrzucenia jest zmierzony. Zakaz na
 * wystąpienie tego napisu w dokumencie kazałby usunąć z dokumentacji jej
 * najważniejsze zdanie. Pilnowana jest więc **obietnica**, czyli zapis
 * w kształcie `` `.kuking-word { własność: wartość }` ``, a nie wzmianka.
 *
 * PUŁAPKA, NA KTÓREJ TEN PLIK BY SIĘ POTKNĄŁ — WZMIANKA W KOMENTARZU CSS
 * Nazwa `.kuking-word` pada w komentarzach `app.css` wielokrotnie, a komentarz
 * nad właściwą regułą ma kilkanaście linii i cytuje w środku zarówno
 * `white-space: nowrap`, jak i `overflow-wrap: anywhere`. Wzorzec „selektor,
 * potem `{…}`" doczytałby od wzmianki do najbliższej klamry i wziął ciało
 * CUDZEJ reguły — dokładnie to, na czym oblał `MinimalnyRozmiarTekstuTest`.
 * Arkusz czytamy więc PO wycięciu komentarzy i osobno sprawdzamy, że po
 * wycięciu żadnego nie zostało.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE
 *  - **Czy `overflow-wrap: anywhere` jest dobrym wyborem.** To rozstrzygnął
 *    pomiar zapisany przy regule w arkuszu. Ten test stoi przy pytaniu, czy
 *    dokument i arkusz mówią JEDNO — nie przy pytaniu, co powinny mówić.
 *  - **Pozostałych reguł `.kuking-word*`.** Kolor, `letter-spacing` i wyjątek
 *    `--bez-koloru` mają własnych strażników w `TekstyWedlugCopyStyleTest`.
 *  - **Renderowania.** Rozjazd jest między dwoma PLIKAMI i jest w nich
 *    widoczny; stawianie do tego przeglądarki dokładałoby minuty do każdego
 *    przebiegu CI i nie sprawdzało nic więcej.
 */
class GlosMarkiOpisujeArkuszPrawdziwieTest extends TestCase
{
    /**
     * Dokumenty marki, w których obietnica o tej regule może się pojawić.
     *
     * `COPY_STYLE.md` jest tu nie dlatego, że dziś taką obietnicę nosi (nie
     * nosi — sprawdzone 11 września 2026), ale dlatego że nosi DRUGĄ KOPIĘ
     * sekcji o zapisie nazwy. Kopia jest miejscem, w którym ta nieprawda
     * odrasta: tak samo README powtarzał nieprawdziwą tabelę stacku (D-104).
     */
    private const DOKUMENTY_MARKI = [
        'docs/brand/GLOS_MARKI.md',
        'docs/brand/COPY_STYLE.md',
    ];

    /**
     * Deklaracje, które przywracają przewijanie w bok zmierzone na `/register`.
     *
     * `white-space: nowrap` i `word-break: keep-all` zakazują łamania wyrazu
     * na zawsze; `overflow-wrap: normal` jest wartością domyślną, czyli
     * zdjęciem tej reguły bez zdjęcia jej z dokumentu.
     */
    private const ZAKAZUJACE_LAMANIA = [
        'white-space: nowrap',
        'word-break: keep-all',
        'overflow-wrap: normal',
    ];

    /** Własności CSS, którymi da się rozstrzygnąć łamanie tego wyrazu. */
    private const WLASNOSCI_LAMANIA = [
        'white-space',
        'overflow-wrap',
        'word-wrap',
        'word-break',
        'hyphens',
        'text-wrap',
    ];

    /**
     * Obietnice o regule `.kuking-word` znalezione w dokumentach marki.
     *
     * Obietnicą jest zapis w kształcie `` `.kuking-word { własność: wartość }` ``
     * — czyli zdanie, które podaje treść reguły, a nie takie, które o niej
     * wspomina. Zwraca pary: ścieżka dokumentu → deklaracja.
     *
     * @return array<int, array{plik: string, deklaracja: string}>
     */
    private function obietniceZDokumentow(): array
    {
        $obietnice = [];

        foreach (self::DOKUMENTY_MARKI as $plik) {
            $sciezka = base_path($plik);

            $this->assertFileExists(
                $sciezka,
                "Nie ma pliku {$plik}. Jeśli dokument zmienił nazwę, popraw listę ".
                'w tym teście — bez niej rozjazd dokumentu z arkuszem przestaje być pilnowany.',
            );

            $tresc = (string) file_get_contents($sciezka);

            preg_match_all(
                '/`\.kuking-word\s*\{\s*([a-z-]+)\s*:\s*([^;}]+?)\s*;?\s*\}`/',
                $tresc,
                $trafienia,
                PREG_SET_ORDER,
            );

            foreach ($trafienia as $trafienie) {
                $obietnice[] = [
                    'plik' => $plik,
                    'deklaracja' => $trafienie[1].': '.$trafienie[2],
                ];
            }
        }

        return $obietnice;
    }

    /**
     * `resources/css/app.css` bez komentarzy.
     *
     * Wycinamy wyłącznie `/* … *\/` — CSS nie zna `//`, a te znaki występują
     * w wartościach (`url()`).
     */
    private function arkuszBezKomentarzy(): string
    {
        $tresc = (string) file_get_contents(resource_path('css/app.css'));

        $bez = (string) preg_replace('#/\*.*?\*/#s', '', $tresc);

        $this->assertStringNotContainsString(
            'PIERWSZA WERSJA TEJ REGUŁY',
            $bez,
            'Komentarze nie zostały wycięte z arkusza, a nazwa `.kuking-word` pada '.
            'w nich wielokrotnie — wzorzec „selektor, potem {…}" czytałby wtedy '.
            'ciało cudzej reguły. To pułapka, na której oblał MinimalnyRozmiarTekstuTest.',
        );

        return $bez;
    }

    /**
     * WSZYSTKIE reguły arkusza — selektory i ciało.
     *
     * CO BYŁO PRZEDTEM — STRAŻNIK WIDZIAŁ CO DRUGĄ REGUŁĘ (issue #927)
     * Wzorzec zaczynał się od `(?:^|[}])`, czyli ZJADAŁ klamrę zamykającą
     * poprzedniej reguły. `preg_match_all` szuka kolejnego trafienia od końca
     * poprzedniego, więc separator, którego potrzebowała kolejna reguła, był
     * już skonsumowany: żeby dopasowanie w ogóle doszło do skutku, silnik
     * przeskakiwał do NASTĘPNEJ klamry `}` — a ta zamyka dopiero regułę nr 2.
     * Skan widział reguły 1, 3, 5, … Zmierzone na `resources/css/app.css`:
     * 233 reguły zamiast 479.
     *
     * To dokładnie ta klasa usterki, o której mówi `AGENTS.md` przy skanach
     * („skan, który nie znajduje żadnego pliku, przechodzi"): miernik podaje
     * wynik, nie widząc połowy wejścia.
     *
     * CZEGO TA USTERKA NIE ROBIŁA — SPRAWDZONE, NIE ZAŁOŻONE
     * Sama reguła `.kuking-word` wypadała po stronie WIDZIANEJ, więc trzy
     * sprawdzenia w tym pliku dawały prawdziwy wynik i zieleń była zasłużona.
     * Sprawdzono też, czy to kwestia parzystości, którą przesunęłaby jedna
     * reguła dopisana wyżej: nie jest — każdy blok `@media` zaczyna
     * naprzemienność od nowa, więc dopisek na górze arkusza nie zmieniał
     * widoczności tej reguły. Usterka polegała na ZASIĘGU skanu, nie na
     * wyniku, który dawał; zasięg zaś jest tym, co ten plik obiecuje
     * czytelnikowi swoją nazwą.
     *
     * CO JEST TERAZ
     * Separator sprawdzamy WSTECZ (`(?<=[{}])`), zerową długością — nic nie
     * znika z wejścia. Klamra otwierająca stoi w tym spojrzeniu obok
     * zamykającej, bo PIERWSZA reguła wewnątrz `@media { … }` nie ma przed
     * sobą żadnego `}`; bez niej skan gubił kolejne dziesięć reguł.
     *
     * Kontrola dodatnia: `test_skan_widzi_kazda_regule_a_nie_co_druga`
     * i `test_skan_czyta_caly_arkusz_marki`.
     *
     * @return list<array{selektory: list<string>, deklaracje: string}>
     */
    private function regulyArkusza(string $css): array
    {
        preg_match_all('/(?:^|(?<=[{}]))\s*([^{}@]+?)\s*\{([^{}]*)\}/s', $css, $reguly, PREG_SET_ORDER);

        return array_map(static fn (array $regula): array => [
            'selektory' => array_map('trim', explode(',', $regula[1])),
            'deklaracje' => trim($regula[2]),
        ], $reguly);
    }

    /**
     * Liczba reguł w arkuszu policzona BEZ wyrażenia regularnego — skanem
     * klamer. Służy wyłącznie za miarę odniesienia dla `regulyArkusza()`:
     * strażnik, który mierzy własny zasięg tym samym wzorcem, potwierdziłby
     * każdą swoją ślepotę.
     *
     * Regułą jest blok BEZ zagnieżdżonych klamer (liść), którego preludium nie
     * zaczyna się od `@` — czyli dokładnie to, co ma widzieć wzorzec.
     */
    private function liczbaRegulSkanemKlamer(string $css): int
    {
        $stos = [];
        $preludium = '';
        $reguly = 0;

        foreach (str_split($css) as $znak) {
            if ($znak === '{') {
                $stos[] = ['preludium' => trim($preludium), 'lisc' => true];
                $preludium = '';

                continue;
            }

            if ($znak === '}') {
                $blok = array_pop($stos);

                if ($blok !== null && $blok['lisc'] && $blok['preludium'] !== '' && ! str_starts_with($blok['preludium'], '@')) {
                    $reguly++;
                }

                if ($stos !== []) {
                    $stos[count($stos) - 1]['lisc'] = false;
                }

                $preludium = '';

                continue;
            }

            $preludium .= $znak;
        }

        return $reguly;
    }

    /**
     * Deklaracje reguły, której CAŁYM selektorem jest `.kuking-word`.
     *
     * Dopasowanie jest po pełnym selektorze, nie po zawieraniu nazwy: reguła
     * `.kuking-word strong` stoi w pliku WYŻEJ i pierwszy blok zawierający tę
     * nazwę to właśnie ona, więc szukanie „gdzieś w liście selektorów"
     * zwróciłoby kolor zamiast łamania wyrazu.
     */
    private function deklaracjeNazwy(string $css): string
    {
        $znalezione = [];

        foreach ($this->regulyArkusza($css) as $regula) {
            if (in_array('.kuking-word', $regula['selektory'], true)) {
                $znalezione[] = $regula['deklaracje'];
            }
        }

        $this->assertCount(
            1,
            $znalezione,
            'W `resources/css/app.css` ma stać dokładnie jedna reguła, której całym '.
            'selektorem jest `.kuking-word` — znalazłem '.count($znalezione).'. '.
            'Ta reguła rozstrzyga łamanie nazwy w środku wyrazu i jest opisana '.
            'w `docs/brand/GLOS_MARKI.md` §2 punkt 3; bez niej dokument obiecuje '.
            'zachowanie, którego nikt nie ustawia.',
        );

        return $znalezione[0];
    }

    public function test_dokumenty_marki_nie_podaja_zakazu_lamania_jako_reguly_nazwy(): void
    {
        $obietnice = $this->obietniceZDokumentow();

        $this->assertNotEmpty(
            $obietnice,
            'Żaden dokument marki nie podaje już reguły `.kuking-word` w kształcie '.
            '`` `.kuking-word { własność: wartość }` ``. Jeśli punkt 3 w §2 '.
            '`docs/brand/GLOS_MARKI.md` został przepisany, przepisz też ten test — '.
            'inaczej oba sprawdzenia niżej przechodzą, nie mierząc niczego.',
        );

        foreach ($obietnice as $obietnica) {
            $this->assertNotContains(
                $obietnica['deklaracja'],
                self::ZAKAZUJACE_LAMANIA,
                "{$obietnica['plik']} podaje jako regułę `.kuking-word` deklarację ".
                "`{$obietnica['deklaracja']}`, czyli zakaz łamania nazwy. Ten wariant ".
                'ZOSTAŁ ODRZUCONY PO POMIARZE: na `/register` przy 320 px i czcionce '.
                '200% słowo brało 315 px od x = 32, a strona przewijała się w bok '.
                'o 27 px — naruszenie WCAG 2.2 AA (1.4.10 Reflow). Obowiązuje '.
                '`overflow-wrap: anywhere`, a powód stoi przy regule w `app.css`.',
            );
        }
    }

    public function test_obietnica_z_dokumentu_stoi_naprawde_w_arkuszu(): void
    {
        $deklaracjeWArkuszu = $this->deklaracjeNazwy($this->arkuszBezKomentarzy());

        foreach ($this->obietniceZDokumentow() as $obietnica) {
            $this->assertStringContainsString(
                $obietnica['deklaracja'],
                $deklaracjeWArkuszu,
                "{$obietnica['plik']} obiecuje dla `.kuking-word` deklarację ".
                "`{$obietnica['deklaracja']}`, a w `resources/css/app.css` stoi: ".
                "`{$deklaracjeWArkuszu}`. Dokument i arkusz mówią dwie różne rzeczy — ".
                'popraw tę stronę, która jest nieprawdziwa. Rozstrzyga pomiar zapisany '.
                'w komentarzu nad regułą w arkuszu, nie zdanie w dokumencie.',
            );
        }
    }

    public function test_arkusz_nie_zakazuje_lamania_nazwy_na_zawsze(): void
    {
        $deklaracje = $this->deklaracjeNazwy($this->arkuszBezKomentarzy());

        $ustawia = array_filter(
            self::WLASNOSCI_LAMANIA,
            static fn (string $wlasnosc): bool => str_contains($deklaracje, $wlasnosc.':'),
        );

        $this->assertNotEmpty(
            $ustawia,
            'Reguła `.kuking-word` nie ustawia już ani jednej własności rozstrzygającej '.
            "łamanie wyrazu (zastane deklaracje: `{$deklaracje}`). Bez niej wraca ".
            'zachowanie domyślne, a `docs/brand/GLOS_MARKI.md` §2 punkt 3 dalej obiecuje '.
            'czytelnikowi konkretną regułę.',
        );

        foreach (self::ZAKAZUJACE_LAMANIA as $zakaz) {
            $this->assertStringNotContainsString(
                $zakaz,
                $deklaracje,
                "Reguła `.kuking-word` w `resources/css/app.css` deklaruje `{$zakaz}`. ".
                'Ten wariant oblał pomiar: na `/register` przy oknie 320 px i czcionce '.
                'przeglądarki 200% samo słowo brało 315 px zaczynając od x = 32, czyli '.
                'strona przewijała się w bok o 27 px — naruszenie WCAG 2.2 AA '.
                '(1.4.10 Reflow). Nazwa ma się łamać WYŁĄCZNIE wtedy, gdy inaczej '.
                'wyszłaby poza wiersz: `overflow-wrap: anywhere`.',
            );
        }
    }

    // ------------------------------------------------------------------
    // KONTROLA DODATNIA SKANU (issue #927)
    //
    // Bez niej ten plik jest strażnikiem, który mierzy połowę tego, co
    // deklaruje: trzy sprawdzenia wyżej pytają o JEDNĄ regułę, więc przechodzą
    // tak samo, gdy skan widzi co drugą — i przechodziły.
    // ------------------------------------------------------------------

    /**
     * Skan czyta KAŻDĄ regułę, nie co drugą.
     *
     * Arkusz jest tu syntetyczny i mały, bo o kontrolę dodatnią chodzi:
     * wiadomo z góry, co ma zostać znalezione, więc „znalazłem mniej" jest
     * odróżnialne od „w arkuszu tyle nie ma". `.b` i `.d` stoją na pozycjach
     * PARZYSTYCH — to je gubił wzorzec z `(?:^|[}])`. `.w-srodku` jest
     * pierwszą regułą wewnątrz `@media` i nie ma przed sobą żadnego `}`.
     */
    public function test_skan_widzi_kazda_regule_a_nie_co_druga(): void
    {
        $css = <<<'CSS'
        .a { color: red }
        .b { color: green }
        .c, .c-druga { color: blue }
        .d { color: black }
        @media (min-width: 40rem) {
            .w-srodku { color: white }
            .po-srodku { color: gray }
        }
        .e { color: pink }
        CSS;

        $selektory = [];

        foreach ($this->regulyArkusza($css) as $regula) {
            foreach ($regula['selektory'] as $selektor) {
                $selektory[] = $selektor;
            }
        }

        foreach (['.a', '.b', '.c', '.c-druga', '.d', '.w-srodku', '.po-srodku', '.e'] as $oczekiwany) {
            $this->assertContains(
                $oczekiwany,
                $selektory,
                "Skan nie widzi reguły `{$oczekiwany}`. Wzorzec, który zjada klamrę "
                .'zamykającą poprzedniej reguły, czyta co drugą (issue #927), '
                .'a strażnik na nim oparty milczy o połowie arkusza.',
            );
        }

        $this->assertCount(7, $this->regulyArkusza($css));
    }

    /**
     * Ta sama kontrola na PRAWDZIWYM arkuszu, przeciw mierze policzonej bez
     * wyrażenia regularnego. Syntetyczny arkusz wyżej nie złapałby usterki,
     * która ujawnia się dopiero na skali `app.css`.
     */
    public function test_skan_czyta_caly_arkusz_marki(): void
    {
        $css = $this->arkuszBezKomentarzy();

        $skanem = $this->liczbaRegulSkanemKlamer($css);

        $this->assertGreaterThan(
            400,
            $skanem,
            'Miara odniesienia naliczyła podejrzanie mało reguł w `resources/css/app.css`. '
            .'Zanim uwierzysz w wynik niżej, sprawdź, czy wycinanie komentarzy nie zjadło arkusza.',
        );

        $this->assertCount(
            $skanem,
            $this->regulyArkusza($css),
            'Wzorzec czytający `resources/css/app.css` widzi inną liczbę reguł niż skan '
            .'klamer. Tak wyglądał issue #927: wzorzec z `(?:^|[}])` widział 233 reguły '
            .'z 479, czyli co drugą, i strażnik oceniał arkusz, którego w połowie nie '
            .'przeczytał.',
        );
    }
}
