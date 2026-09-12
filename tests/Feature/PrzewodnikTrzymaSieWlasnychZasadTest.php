<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\WzorceRodzaju;
use Tests\TestCase;

/**
 * Przewodnik po języku produktu trzyma się własnych zasad (issue #38).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * `docs/brand/COPY_STYLE.md` §6 to tabele GOTOWYCH TEKSTÓW DO WKLEJENIA —
 * miejsce, z którego ludzie kopiują napisy prosto do widoku. Do tego issue
 * stały tam frazy, które ten sam dokument w §2 nazywa błędem i które produkt
 * naprawił przy #274: „Za rok będziesz mogła tu wrócić i zobaczyć, co wtedy
 * gotowałaś" oraz „Możesz być pierwsza albo pierwszy". Przegląd znalazł
 * jeszcze trzy: „Ugotowałeś z tego przepisu?", „Zrobiłem coś po swojemu?"
 * i „Przygotowaliśmy paczkę ze wszystkim, co tu wrzuciłaś" — a poza
 * `COPY_STYLE.md` kolejne pięć w `BRAND_EXTENDED.md` i `MASCOT_CONCEPT.md`.
 *
 * Przyczyna, dla której nikt tego nie złapał, jest jedna i prosta:
 * `TekstyNiePrzypisujaPlciTest` i `TekstyWedlugCopyStyleTest` skanują
 * PRODUKT — `resources/views`, `resources/legal`, `lang/`, napisy w PHP —
 * i ani jeden z nich nie czyta `docs/`. Przewodnik był więc jedynym miejscem
 * w repozytorium, gdzie własne zasady wolno było łamać bezkarnie. Akurat
 * w tym, z którego się kopiuje: pierwsza osoba, która przeniosłaby wiersz
 * z §6 do widoku, oblałaby `php artisan test` napisem wziętym z dokumentu
 * nazywającego siebie wiążącym.
 *
 * DLACZEGO OSOBNY PLIK, A NIE DOPISEK DO `TekstyWedlugCopyStyleTest`
 * Tamten test mierzy co innego niż ten, mimo wspólnej nazwy dokumentu
 * w tytule. Tamten bierze POWIERZCHNIĘ PRODUKTU i sprawdza ją w całości:
 * każdy widok, każdy napis, wyrenderowany formularz, kontrast czerwieni
 * policzony z arkusza stylów. Dokument jest tam źródłem reguły, nie
 * przedmiotem badania. Tutaj przedmiotem badania jest sam dokument, a całą
 * trudność robi coś, czego w produkcie nie ma w ogóle: PRZEWODNIK CYTUJE
 * ZŁE FRAZY CELOWO. „❌ Możesz być pierwsza albo pierwszy" w §2 jest tam po
 * to, żeby uczyć, i ma zostać. Skan, który nie odróżnia wzoru do wklejenia
 * od cytatu odrzuconego, zablokowałby pisanie o błędach — czyli zabrałby
 * dokumentowi połowę jego wartości dydaktycznej.
 *
 * To rozróżnienie jest osobnym mechanizmem (`wzoryZTresci()` niżej), z własną
 * kontrolą w obie strony, i to ono, a nie lista reguł, jest tu treścią pliku.
 * Wstawione do `TekstyWedlugCopyStyleTest` byłoby ciałem obcym: tamten plik
 * nie ma powodu wiedzieć, co znaczy „❌" w bloku ```text.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE SPRAWDZA
 * Prozy przewodnika. Akapit „pusta sekcja komentarzy («możesz być pierwsza
 * albo pierwszy»)" w §2 opisuje usterkę i cytuje ją dosłownie — to jest
 * poprawny tekst dokumentacji, nie wzór do skopiowania. Skanowane są
 * WYŁĄCZNIE cztery rodzaje miejsc wymienione przy `wzoryZTresci()`.
 * Nie sprawdzamy też, czy wiersz z §6 zgadza się z napisem w widoku —
 * to jest przegląd redakcyjny, nie reguła dająca się policzyć.
 */
class PrzewodnikTrzymaSieWlasnychZasadTest extends TestCase
{
    /**
     * WYJĄTEK TYLKO DLA PRZEWODNIKA — hasło główne z wielkiej litery.
     *
     * `WzorceRodzaju::WYJATKI` zna zapis małą literą („Pokaż, co dziś
     * ugotowałeś"), bo tak stoi w produkcie. W `BRAND_EXTENDED.md` §4 to samo
     * utrwalone hasło jest nagłówkiem ekranu głównego i zaczyna zdanie:
     * „Co dziś ugotowałeś?". To ta sama fraza i ta sama decyzja marki
     * (COPY_STYLE.md §2: „«ugotowałeś» w haśle głównym jest już utrwalone
     * i zostaje"), więc wyjątek jest tu, a NIE we wzorcach wspólnych —
     * skan produktu nie ma prawa zrobić się przez to luźniejszy.
     *
     * @var array<string, string>
     */
    private const WYJATKI_PRZEWODNIKA = [
        'Co dziś ugotowałeś' => 'hasło główne na początku zdania, COPY_STYLE.md §2',
    ];

    /**
     * Podsekcje §6, w których obowiązuje rejestr „poważny" — tam nazwa
     * serwisu nie wchodzi w ogóle (COPY_STYLE.md §2, AGENTS.md §11).
     */
    private const NAGLOWKI_REJESTRU_POWAZNEGO = '/Błęd|moderac|prawn|nieodwracaln|usuni/iu';

    /** Gra słowem — dwukolorowy zapis. Zwykłe „Kuking" jest dozwolone wszędzie. */
    private const ZAPIS_GRY_SLOWEM = 'kuKING';

    // ---------------------------------------------------------------
    // 1. Rodzaj przypisany czytelnikowi
    // ---------------------------------------------------------------

    /**
     * Główna reguła tego issue: wzór do wklejenia nie przypisuje czytelnikowi
     * płci. Wzorce są TE SAME, którymi mierzony jest produkt
     * (`Tests\Support\WzorceRodzaju`) — inaczej dokument i kod rozjechałyby
     * się po pierwszej poprawce wzorca.
     */
    public function test_gotowe_teksty_nie_przypisuja_czytelnikowi_plci(): void
    {
        $winowajcy = [];

        foreach ($this->wzoryPrzewodnika() as $wzor) {
            foreach (WzorceRodzaju::trafienia($wzor['tekst'], self::WYJATKI_PRZEWODNIKA) as $trafienie) {
                $winowajcy[] = $wzor['plik'].':'.$wzor['nr'].' ['.$wzor['skad'].'] → '
                    .trim(explode(' → ', $trafienie, 2)[1] ?? $wzor['tekst']);
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie(
            $winowajcy,
            'docs/brand/COPY_STYLE.md §2 zakazuje form rodzajowych w tekście DO czytelnika '
            ."i zakazuje wypisywania obu form obok siebie.\n"
            .'PRZEBUDUJ ZDANIE (czas teraźniejszy, rzeczownik, bezokolicznik) — nigdy nie '
            .'dopisuj formy żeńskiej obok męskiej. Jeśli ten tekst żyje już w widoku, '
            .'przepisz do przewodnika brzmienie Z KODU: dokument ma iść za produktem.',
        ));
    }

    // ---------------------------------------------------------------
    // 2. Emoji i wykrzyknik
    // ---------------------------------------------------------------

    /**
     * §4, cztery reguły techniczne: „Zero emoji w tekstach interfejsu",
     * „Jeden wykrzyknik na ekran, najwyżej. Zwykle zero."
     *
     * Wzór do wklejenia jest JEDNYM napisem, więc wykrzyknik w nim znaczy
     * wykrzyknik na ekranie — i jest tu traktowany jak błąd. `BRAND_EXTENDED.md`
     * §4 mówi o tym twardziej niż `COPY_STYLE.md`: „zero wykrzykników (twardy
     * zakaz w produkcie)".
     */
    public function test_gotowe_teksty_nie_uzywaja_emoji_ani_wykrzyknika(): void
    {
        $winowajcy = [];

        foreach ($this->wzoryPrzewodnika() as $wzor) {
            foreach ($this->trafieniaZnakow($wzor['tekst']) as $powod) {
                $winowajcy[] = $wzor['plik'].':'.$wzor['nr'].' ['.$powod.'] → '.$wzor['tekst'];
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie(
            $winowajcy,
            'docs/brand/COPY_STYLE.md §4: zero emoji, najwyżej jeden wykrzyknik (zwykle zero), '
            .'bez wielokropka zawieszającego napięcie na końcu zdania.',
        ));
    }

    // ---------------------------------------------------------------
    // 3. Nazwa serwisu w rejestrze „poważnym"
    // ---------------------------------------------------------------

    /**
     * §2: `kuKING` nigdy w komunikacie błędu, w wiadomości moderacyjnej,
     * w tekście prawnym ani przy rzeczy nieodwracalnej. Produktu pilnuje
     * tego pięć testów w `TekstyWedlugCopyStyleTest`; tu pilnujemy WZORU,
     * z którego te napisy się bierze.
     *
     * Zwykłe „Kuking" jest w tych miejscach dozwolone i ma przechodzić —
     * zakaz dotyczy dwukolorowej gry słowem, nie nazwy serwisu.
     */
    public function test_gotowe_teksty_w_rejestrze_powaznym_nie_uzywaja_gry_slowem(): void
    {
        $winowajcy = [];

        foreach ($this->wzoryPrzewodnika() as $wzor) {
            if (preg_match(self::NAGLOWKI_REJESTRU_POWAZNEGO, $wzor['sekcja']) !== 1) {
                continue;
            }

            if (str_contains($wzor['tekst'], self::ZAPIS_GRY_SLOWEM)) {
                $winowajcy[] = $wzor['plik'].':'.$wzor['nr'].' ['.$wzor['sekcja'].'] → '.$wzor['tekst'];
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienie(
            $winowajcy,
            'docs/brand/COPY_STYLE.md §2: `kuKING` nie wchodzi do błędu, moderacji, tekstu '
            .'prawnego ani do potwierdzenia rzeczy nieodwracalnej. W tych miejscach piszemy '
            .'zwyczajnie „Kuking".',
        ));
    }

    // ---------------------------------------------------------------
    // 4. KONTROLA DODATNIA — czy ten skan w ogóle coś czyta
    // ---------------------------------------------------------------

    /**
     * PUŁAPKA 4 z `docs/PULAPKI_TESTOW.md`: asercja „tej frazy tu nie ma"
     * przechodzi także nad pustym plikiem, nad zmienioną ścieżką i nad
     * dokumentem, z którego ktoś wyciął §6. Trzy testy wyżej są dokładnie
     * tego rodzaju, więc bez tej kontroli nie dowodzą niczego.
     *
     * Sprawdzamy cztery rzeczy naraz: pliki są, wzorów jest dużo, konkretne
     * ZNANE wzory są w zbiorze (kontrola dodatnia), a konkretne ZNANE cytaty
     * odrzucone w nim nie są (kontrola ujemna rozróżnienia). Liczby są
     * zmierzone 12 września 2026, nie oszacowane, i celowo są progiem
     * z zapasem — mają obleć przy zniknięciu sekcji, nie przy dopisaniu wiersza.
     */
    public function test_skan_przewodnika_naprawde_czyta_wzory_i_omija_cytaty_odrzucone(): void
    {
        $wzory = $this->wzoryPrzewodnika();

        $this->assertGreaterThanOrEqual(
            4,
            count($this->plikiPrzewodnika()),
            'Skan nie znalazł plików przewodnika — sprawdź ścieżkę docs/brand/*.md. '
            .'Test, który nic nie czyta, niczego nie pilnuje.',
        );

        // Zmierzone: 277 wzorów w czterech plikach (12.09.2026).
        $this->assertGreaterThan(
            150,
            count($wzory),
            'Skan przewodnika znalazł zbyt mało wzorów do wklejenia — sekcja zniknęła, '
            .'zmienił się format tabel albo parser przestał rozpoznawać bloki ```text.',
        );

        // Sam §6 — to jego dotyczy issue #38 i to on ma nie zniknąć niezauważony.
        $zSzostki = array_filter(
            $wzory,
            static fn (array $w): bool => $w['plik'] === 'COPY_STYLE.md' && $w['skad'] === 'tabela-wklejenie',
        );

        // Zmierzone: 168 komórek w tabelach §6 (12.09.2026).
        $this->assertGreaterThan(
            80,
            count($zSzostki),
            '§6 „Gotowe teksty — do wklejenia" nie daje wzorów. Jeśli nagłówek sekcji '
            .'zmienił brzmienie, popraw rozpoznawanie sekcji w `wzoryZTresci()` — inaczej '
            .'trzy testy wyżej są zielone nad niesprawdzonym dokumentem.',
        );

        $teksty = array_map(static fn (array $w): string => $w['tekst'], $wzory);

        // KONTROLA DODATNIA: te wzory MUSZĄ być widziane przez skan.
        $musiWidziec = [
            'Zostań kuKINGiem — bez opłat i bez reklam',   // §6, tabela
            'Napisz kilka słów',                            // §6, tabela
            'Szkic zapisany.',                              // §6, tabela
            'Jeszcze nic tu nie ma',                        // §6, puste stany
            'Wyszło. I to się liczy.',                      // §4, blok ```text z ✅
            'Przycisk osoby:  Obserwuj',                    // §5, blok ```text bez znacznika
        ];

        foreach ($musiWidziec as $tekst) {
            $this->assertContains(
                $tekst,
                $teksty,
                "Skan nie widzi wzoru do wklejenia: „{$tekst}”. Reguły wyżej nie pilnują "
                .'wtedy tego, co miały pilnować.',
            );
        }

        // KONTROLA UJEMNA ROZRÓŻNIENIA: cytaty odrzucone mają zostać poza skanem,
        // inaczej test zablokuje pisanie o błędach.
        $niesmieWidziec = [
            // Frazy dobrane tak, żeby KAŻDA występowała w dokumencie WYŁĄCZNIE
            // jako cytat odrzucony. „Możesz być pierwsza albo pierwszy" by się
            // tu nie nadało: do tej poprawki stało jednocześnie w §2 z „❌"
            // i w tabeli §6 jako wzór, więc oblanie nie mówiłoby, czy zepsuty
            // jest parser, czy dokument (pułapka 8 z docs/PULAPKI_TESTOW.md —
            // czerwień bez przeczytanej przyczyny nie jest informacją).
            'Przypominaj mi, co gotowałam w tym dniu',      // §2, wiersz z ❌
            'Hej! 🎉 Zróbmy to razem!',                     // §4, wiersz z ❌
            'Jesteś prawdziwym kuKINGiem!',                 // GLOS_MARKI §1, wiersz z ❌
            'Zajmie minutę',                                // proza, opis usuniętego dopisku
        ];

        foreach ($niesmieWidziec as $tekst) {
            foreach ($teksty as $widziany) {
                $this->assertStringNotContainsString(
                    $tekst,
                    $widziany,
                    "Skan wziął cytat odrzucony za wzór do wklejenia: „{$tekst}”. "
                    .'Przewodnik ma prawo cytować złe frazy — popraw `wzoryZTresci()`, '
                    .'nie dokument.',
                );
            }
        }
    }

    /**
     * DRUGA KONTROLA — na samych regułach, nie na ścieżkach.
     *
     * Reguła, która nic nie łapie, jest zielona dokładnie tak samo jak reguła
     * pilnująca czystego dokumentu (pułapki 2 i 3). Dwie z trzech reguł wyżej
     * nie mają dziś w przewodniku ANI JEDNEGO trafienia — wykrzyknik i nazwa
     * w rejestrze poważnym — więc bez tego testu nie wiadomo, czy w ogóle
     * działają. Zdania niżej to albo brzmienia sprzed tej poprawki, albo
     * usterki dopisane ręcznie w takiej postaci, w jakiej mogłyby wrócić.
     */
    public function test_reguly_lapia_podstawione_usterki_i_przepuszczaja_poprawne_wzory(): void
    {
        $zle = [
            'Za rok będziesz mogła tu wrócić i zobaczyć, co wtedy gotowałaś.',
            'Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo pierwszy.',
            'Ugotowałeś z tego przepisu?',
            'Zrobiłem coś po swojemu?',
            'Przygotowaliśmy paczkę ze wszystkim, co tu wrzuciłaś.',
            'Twój zeszyt czeka tam, gdzie go zostawiłeś.',
            'Zacznij od jednego zdjęcia — resztę dopiszesz, kiedy będziesz chciał.',
        ];

        foreach ($zle as $zdanie) {
            $this->assertNotSame(
                [],
                WzorceRodzaju::trafienia($zdanie, self::WYJATKI_PRZEWODNIKA),
                "Reguła rodzaju przepuściła wzór sprzed poprawki: „{$zdanie}”.",
            );
        }

        $dobre = [
            'Za rok zobaczysz tu, co gotujesz dzisiaj.',
            'Jeszcze nikt tu nic nie napisał. Napisz pierwszy komentarz.',
            'Gotujesz z tego przepisu?',
            'Coś po swojemu?',
            'Przygotowaliśmy paczkę ze wszystkim, co tu masz.',
            'Pokaż, co dziś ugotowałeś.',   // hasło główne — wyjątek z WzorceRodzaju
            'Co dziś ugotowałeś?',          // to samo hasło z wielkiej litery — wyjątek przewodnika
            'Dodaj zdjęcie tego, co ugotowałeś',
        ];

        foreach ($dobre as $zdanie) {
            $this->assertSame(
                [],
                WzorceRodzaju::trafienia($zdanie, self::WYJATKI_PRZEWODNIKA),
                "Reguła rodzaju zapaliła się na poprawnym wzorze: „{$zdanie}”.",
            );
        }

        $zleZnaki = [
            'Świetna robota! 🎉' => 'emoji i wykrzyknik',
            'Jesteś na fali!' => 'wykrzyknik',
            'Zaraz zobaczysz…' => 'wielokropek na końcu',
            'Zaraz zobaczysz...' => 'wielokropek z trzech kropek',
        ];

        foreach ($zleZnaki as $zdanie => $dlaczego) {
            $this->assertNotSame(
                [],
                $this->trafieniaZnakow($zdanie),
                "Reguła znaków przepuściła {$dlaczego}: „{$zdanie}”.",
            );
        }

        $dobreZnaki = [
            'Gotowe. To Twój pierwszy wpis — od teraz masz swoje archiwum.',
            '…albo najpierw się rozejrzeć.',  // wielokropek otwierający cytat, nie zawieszenie
            'Napisz kilka słów',
        ];

        foreach ($dobreZnaki as $zdanie) {
            $this->assertSame(
                [],
                $this->trafieniaZnakow($zdanie),
                "Reguła znaków zapaliła się na poprawnym wzorze: „{$zdanie}”.",
            );
        }

        // Rejestr poważny: ta sama treść przechodzi albo oblewa w zależności
        // WYŁĄCZNIE od sekcji, w której stoi — i to jest cała ta reguła.
        $dokument = <<<'MD'
            ## 6. Gotowe teksty — do wklejenia

            ### Wejście i konto

            | Miejsce | Tekst |
            |---|---|
            | nagłówek rejestracji | Zostań kuKINGiem |

            ### Błędy — poziom „poważny", zero żartów

            | Sytuacja | Tekst |
            |---|---|
            | zdjęcie za duże | To zdjęcie waży za dużo dla kuKING. Wybierz mniejsze. |
            | brak internetu | Kuking potrzebuje internetu. Sprawdź Wi-Fi. |
            MD;

        $wSekcjach = [];

        foreach ($this->wzoryZTresci($dokument, 'PRÓBKA.md') as $wzor) {
            if (preg_match(self::NAGLOWKI_REJESTRU_POWAZNEGO, $wzor['sekcja']) === 1
                && str_contains($wzor['tekst'], self::ZAPIS_GRY_SLOWEM)) {
                $wSekcjach[] = $wzor['tekst'];
            }
        }

        $this->assertSame(
            ['To zdjęcie waży za dużo dla kuKING. Wybierz mniejsze.'],
            $wSekcjach,
            'Reguła rejestru poważnego albo nie widzi nazwy w komunikacie błędu, albo '
            .'zapala się na „Zostań kuKINGiem" z sekcji, w której nazwa jest dozwolona, '
            .'albo myli zwykłe „Kuking" z grą słowem.',
        );
    }

    /**
     * TRZECIA KONTROLA — cały potok na dokumencie podstawionym w całości.
     *
     * Dwa testy wyżej mierzą reguły osobno. Ten sprawdza to, co naprawdę
     * zawodzi w praktyce: czy potok „przeczytaj plik → rozpoznaj wzór →
     * zmierz regułą" zapala się na dokumencie, który wygląda dokładnie tak
     * jak `COPY_STYLE.md` przed tą poprawką — i czy NIE zapala się na jego
     * cytacie odrzuconym stojącym dwa wiersze wyżej. To jest jedyny test,
     * który oblałby się także wtedy, gdyby ktoś zepsuł sam parser.
     */
    public function test_potok_lapie_usterke_w_dokumencie_i_nie_lapie_cytatu_odrzuconego(): void
    {
        $dokument = <<<'MD'
            ## 2. Jak piszemy

            ```text
            ❌ Możesz być pierwsza albo pierwszy
            ✅ Napisz pierwszy komentarz
            ```

            Pusta sekcja komentarzy mówiła „możesz być pierwsza albo pierwszy".

            ## 6. Gotowe teksty — do wklejenia

            ### Puste stany

            | Miejsce | Tekst |
            |---|---|
            | brak komentarzy | Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo pierwszy. |
            MD;

        $trafienia = [];

        foreach ($this->wzoryZTresci($dokument, 'PRÓBKA.md') as $wzor) {
            foreach (WzorceRodzaju::trafienia($wzor['tekst'], self::WYJATKI_PRZEWODNIKA) as $_) {
                $trafienia[] = $wzor['skad'].': '.$wzor['tekst'];
            }
        }

        $this->assertSame(
            ['tabela-wklejenie: Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo pierwszy.'],
            $trafienia,
            'Potok albo nie widzi usterki w tabeli §6 (wtedy cały ten plik jest atrapą), '
            .'albo policzył cytat z „❌" lub zdanie z prozy jako wzór do wklejenia '
            .'(wtedy blokuje pisanie o błędach).',
        );
    }

    // ---------------------------------------------------------------
    // Narzędzia
    // ---------------------------------------------------------------

    /**
     * Wszystkie wzory do wklejenia ze wszystkich plików przewodnika.
     *
     * @return list<array{plik: string, nr: int, tekst: string, skad: string, sekcja: string}>
     */
    private function wzoryPrzewodnika(): array
    {
        $wzory = [];

        foreach ($this->plikiPrzewodnika() as $plik) {
            $wzory = array_merge(
                $wzory,
                $this->wzoryZTresci((string) file_get_contents($plik), basename($plik)),
            );
        }

        return $wzory;
    }

    /** @return list<string> */
    private function plikiPrzewodnika(): array
    {
        $pliki = glob(base_path('docs/brand/*.md')) ?: [];

        sort($pliki);

        return array_values($pliki);
    }

    /**
     * ROZRÓŻNIENIE, NA KTÓRYM STOI CAŁY TEN PLIK: co jest wzorem do
     * wklejenia, a co cytatem, który dokument odrzuca albo opisuje.
     *
     * Wzorem jest tylko to, co przewodnik podaje jako tekst gotowy do
     * przeniesienia na ekran. Są cztery takie miejsca i każde ma swój
     * znacznik W SAMYM DOKUMENCIE — nie zgadujemy z treści zdania:
     *
     *   1. `fence-ptaszek` — wiersz z „✅" w bloku ```text. Znacznik zdejmujemy,
     *      inaczej sam „✅" oblewałby regułę emoji.
     *   2. `fence-goly`   — wiersz bez znacznika w bloku ```text. Tak zapisane
     *      są teksty sekcji „kuKINGi na dziś" (§5) i przykłady w GLOS_MARKI.
     *   3. `tabela-wklejenie` — komórka tabeli w sekcji, której NAGŁÓWEK mówi
     *      „do wklejenia" (COPY_STYLE §6, MASCOT_CONCEPT §6.4). Sekcja
     *      obejmuje podnagłówki niższego poziomu, bo w §6 wszystkie tabele
     *      stoją pod `###`.
     *   4. `tabela-dobrze` — kolumna „Dobrze" w tabeli dobrze/źle
     *      (BRAND_EXTENDED §4). Kolumna „Źle" jest wtedy pomijana z definicji.
     *   5. `cytat-wzorzec` — wiersz cytatu blokowego w sekcji „Wzorce"
     *      (BRAND_EXTENDED §5.2, szablony e-maili).
     *
     * NIE jest wzorem: wiersz z „❌" i jego wcięte wyjaśnienie pod spodem,
     * komórka skreślona (`~~…~~`), cała proza dokumentu, wszystkie pozostałe
     * tabele (w §5 i §8 są to tabele NAZW ODRZUCONYCH — wzięcie ich za wzór
     * byłoby odwróceniem sensu) i bloki innych języków niż ```text (```blade
     * to kod, nie napis).
     *
     * @return list<array{plik: string, nr: int, tekst: string, skad: string, sekcja: string}>
     */
    private function wzoryZTresci(string $tresc, string $plik): array
    {
        $wzory = [];
        $wBloku = false;
        $jezykBloku = '';
        $poOdrzuconym = false;
        $sekcja = '';
        $poziomWklejenia = null;
        $doWklejenia = false;
        $naglowekTabeli = [];
        $wTabeli = false;

        foreach (explode("\n", $tresc) as $indeks => $surowa) {
            $nr = $indeks + 1;
            $linia = rtrim($surowa);

            // Heredoc w teście jest wcięty; w pliku nie jest. Wcięcie bloku
            // zdejmujemy raz, na całej linii, żeby jedno i drugie czytało się
            // tak samo — ale PAMIĘTAMY, czy linia była wcięta względem bloku,
            // bo wcięcie w bloku ```text znaczy „wyjaśnienie do wiersza wyżej".
            $bezWciecia = ltrim($linia);

            if (str_starts_with($bezWciecia, '```')) {
                if ($wBloku) {
                    $wBloku = false;
                    $jezykBloku = '';
                } else {
                    $wBloku = true;
                    $jezykBloku = trim(substr($bezWciecia, 3));
                    $poOdrzuconym = false;
                }

                continue;
            }

            if ($wBloku) {
                if ($jezykBloku !== 'text' || $bezWciecia === '') {
                    $poOdrzuconym = false;

                    continue;
                }

                if (str_starts_with($bezWciecia, '❌')) {
                    $poOdrzuconym = true;

                    continue;
                }

                if (str_starts_with($bezWciecia, '✅')) {
                    $poOdrzuconym = false;
                    $wzory[] = $this->wzor($plik, $nr, trim(mb_substr($bezWciecia, 1)), 'fence-ptaszek', $sekcja);

                    continue;
                }

                // Wiersz wcięty względem poprzedniego to wyjaśnienie, nie napis.
                // Pod „❌" wyjaśnia błąd, pod „✅" wyjaśnia, dlaczego jest dobrze —
                // ani jedno, ani drugie nie trafia na ekran.
                if ($this->wciety($linia)) {
                    continue;
                }

                $poOdrzuconym = false;
                $wzory[] = $this->wzor($plik, $nr, $bezWciecia, 'fence-goly', $sekcja);

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/u', $bezWciecia, $naglowek) === 1) {
                $poziom = strlen($naglowek[1]);
                $sekcja = trim($naglowek[2]);
                $wTabeli = false;

                if (preg_match('/wklejeni/iu', $sekcja) === 1) {
                    $doWklejenia = true;
                    $poziomWklejenia = $poziom;
                } elseif ($poziomWklejenia !== null && $poziom <= $poziomWklejenia) {
                    $doWklejenia = false;
                    $poziomWklejenia = null;
                }

                continue;
            }

            if (str_starts_with($bezWciecia, '|')) {
                if (preg_match('/^\|[\s\-:|]+\|$/', $bezWciecia) === 1) {
                    continue;
                }

                $komorki = array_map('trim', explode('|', trim($bezWciecia, '|')));

                if (! $wTabeli) {
                    $wTabeli = true;
                    $naglowekTabeli = $komorki;

                    continue;
                }

                foreach ($komorki as $kolumna => $komorka) {
                    if ($komorka === '' || str_starts_with($komorka, '❌') || str_starts_with($komorka, '~~')) {
                        continue;
                    }

                    $czyDobrze = preg_match('/^\*{0,2}Dobrze/iu', $naglowekTabeli[$kolumna] ?? '') === 1;

                    if ($doWklejenia) {
                        $wzory[] = $this->wzor($plik, $nr, $komorka, 'tabela-wklejenie', $sekcja);
                    } elseif ($czyDobrze) {
                        $wzory[] = $this->wzor($plik, $nr, $komorka, 'tabela-dobrze', $sekcja);
                    }
                }

                continue;
            }

            $wTabeli = false;

            // Cytat blokowy liczy się jako wzór TYLKO w sekcji „Wzorce"
            // (BRAND_EXTENDED §5.2, szablony e-maili). W sekcji „do wklejenia"
            // cytat blokowy jest komentarzem redakcyjnym — tak stoi ślad
            // przeglądu pod §6 `COPY_STYLE.md` — i wzięcie go za wzór
            // zablokowałoby opisywanie własnych poprawek.
            if (str_starts_with($bezWciecia, '>') && ! $doWklejenia && preg_match('/Wzorce/u', $sekcja) === 1) {
                $cytat = trim(ltrim($bezWciecia, '>'));

                if ($cytat !== '') {
                    $wzory[] = $this->wzor($plik, $nr, $cytat, 'cytat-wzorzec', $sekcja);
                }
            }
        }

        return $wzory;
    }

    /**
     * Czy linia jest wcięta — w bloku ```text znaczy to „wyjaśnienie do
     * wiersza wyżej", nie osobny napis.
     *
     * Heredoc w tym pliku ma wcięcie wspólne dla wszystkich linii i PHP
     * zdejmuje je przy odczycie, więc próbka dokumentu czyta się tak samo
     * jak plik z dysku, który wcięcia nie ma.
     */
    private function wciety(string $linia): bool
    {
        return preg_match('/^\s/', $linia) === 1;
    }

    /**
     * @return array{plik: string, nr: int, tekst: string, skad: string, sekcja: string}
     */
    private function wzor(string $plik, int $nr, string $tekst, string $skad, string $sekcja): array
    {
        return ['plik' => $plik, 'nr' => $nr, 'tekst' => $tekst, 'skad' => $skad, 'sekcja' => $sekcja];
    }

    /**
     * Emoji, wykrzyknik i wielokropek zawieszający napięcie.
     *
     * Wielokropek liczy się TYLKO na końcu napisu. Przewodnik używa go też
     * na początku cytatu („…albo najpierw się rozejrzeć"), żeby pokazać, że
     * zdanie jest urwanym fragmentem — to jest zapis redakcyjny, nie chwyt
     * z §4.
     *
     * @return list<string>
     */
    private function trafieniaZnakow(string $tekst): array
    {
        $powody = [];

        if (preg_match('/\p{Extended_Pictographic}/u', $tekst) === 1) {
            $powody[] = 'emoji';
        }

        if (str_contains($tekst, '!')) {
            $powody[] = 'wykrzyknik';
        }

        if (preg_match('/(?:\.\.\.|…)\s*[”"„]?$/u', trim($tekst)) === 1) {
            $powody[] = 'wielokropek';
        }

        return $powody;
    }

    /** @param list<string> $winowajcy */
    private function wyjasnienie(array $winowajcy, string $regula): string
    {
        return "Gotowy tekst do wklejenia łamie zasadę z tego samego dokumentu:\n"
            .implode("\n", $winowajcy)."\n\n".$regula."\n"
            .'Jeśli to CYTAT, który dokument świadomie odrzuca — oznacz go „❌" w bloku '
            .'```text albo zostaw w prozie. Skan czyta wyłącznie wzory do wklejenia '
            .'(patrz `wzoryZTresci()`), więc pisanie o błędach jest dozwolone.';
    }
}
