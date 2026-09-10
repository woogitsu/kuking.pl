<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Rezerwa miejsca pod dolną belką (WCAG 2.2 AA — 2.4.11 Focus Not Obscured),
 * a przy okazji cena, której za nią NIE płacimy (2.5.8 Target Size Minimum).
 *
 * CO TU JEST DO ZEPSUCIA — I DLACZEGO AKURAT TO
 * Poprawka 2.4.11 ma dwie możliwe drogi i tylko jedna z nich jest darmowa.
 * Droga odrzucona: wstawić `.bottom-nav` w przepływ dokumentu
 * (`position: sticky`), żeby wysokość strony sama rosła o wysokość belki.
 * Naprawia 2.4.11 i ŁAMIE 2.5.8: axe pomija nakładki `fixed` przy liczeniu
 * sąsiadów (`findNearbyElms` porównuje kandydatów warunkiem
 * `selfIsFixed === isFixedPosition(vNeighbor)`), więc belka `sticky` staje
 * się zwykłym sąsiadem i przycina bezpieczne pole kliknięcia tego, co akurat
 * widać za nią. Zmierzone przy 320 px: „profil (własny)" 48 → 14,5 px
 * wolnego, „dodaj przepis" 55,9 → 10,1 px, „twoje tagi" 40 → 4,5 px.
 *
 * Droga wybrana: belka zostaje `fixed`, a rezerwę liczymy jawnie tokenem
 * `--rezerwa-pod-belka` i wydajemy go w DWÓCH miejscach, bo są to dwie różne
 * rzeczy: wypełnienie na końcu dokumentu (ostatni ekran da się wyprowadzić
 * spod belki) i `scroll-padding-bottom` (przewijanie fokusu w widok, które
 * przeglądarka robi sama po Tab, liczy się do krawędzi okna i nie wie, że
 * stoi tam nakładka). Wyprowadzenie liczb stoi w komentarzu przy tokenie.
 *
 * DLACZEGO TEN TEST ISTNIEJE OBOK POMIARU W PRZEGLĄDARCE
 * Prawdziwym automatem na 2.4.11 i 2.5.8 jest `scripts/dostepnosc.mjs` — tego
 * z HTML-a ani z CSS-a stwierdzić się nie da, bo to wynik UŁOŻENIA strony.
 * Ale ten pomiar chodzi w CI w jobie `dostepnosc`, WARUNKOWO: job sprawdza
 * `git diff` i odpala się tylko wtedy, gdy zmiana dotyka `resources/`,
 * `public/`, `scripts/dostepnosc.mjs` albo plików npm (patrz krok „zmiany"
 * w `.github/workflows/ci.yml`). Zmiana w samym `app/` przechodzi więc obok
 * niego — a wystarczy tam jedna edycja szablonu, żeby te reguły przestały
 * mieć sens. Ten plik chodzi w jobie `test`, czyli ZAWSZE, i pilnuje tego,
 * co widać w źródle: że rezerwa dalej jest liczona, dalej jest wydawana
 * w obu miejscach i że belka nie wróciła na `sticky`.
 */
class RezerwaPodDolnaBelkaTest extends TestCase
{
    /**
     * Arkusz bez komentarzy.
     *
     * KOMENTARZE PRECZ, ZANIM COKOLWIEK SPRAWDZIMY — ten arkusz cytuje
     * w komentarzach dokładnie to, czego w kodzie być nie może („DLACZEGO NIE
     * `position: sticky` NA BELCE"). Asercja szukająca `position: sticky`
     * w surowym pliku trafiałaby we własne uzasadnienie i świeciła na czerwono
     * przy poprawnym kodzie.
     */
    private function css(): string
    {
        $sciezka = resource_path('css/app.css');

        $this->assertFileExists($sciezka);

        $bezKomentarzy = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($sciezka));

        /*
         * PRÓG DŁUGOŚCI, BO TEST SKANUJĄCY PLIK PRZECHODZI TEŻ NA PUSTCE.
         * Gdyby `app.css` został przeniesiony albo wyczyszczony, wszystkie
         * asercje „nie zawiera sticky" niżej byłyby spełnione — i test
         * świeciłby na zielono nad nieistniejącym arkuszem. Arkusz ma dziś
         * ponad 60 000 znaków po zdjęciu komentarzy; próg jest ustawiony
         * nisko, żeby nie padał przy zwykłym sprzątaniu.
         */
        $this->assertGreaterThan(
            20000,
            strlen($bezKomentarzy),
            'Arkusz `app.css` jest po zdjęciu komentarzy podejrzanie krótki. '
            .'Test sprawdzałby pustkę zamiast reguł układu.',
        );

        return $bezKomentarzy;
    }

    /** Fragment arkusza od podanego selektora do końca jego bloku. */
    private function regula(string $css, string $selektor): string
    {
        $start = strpos($css, $selektor);

        $this->assertNotFalse($start, "W arkuszu nie ma już reguły „{$selektor}” — układ się zmienił.");

        $koniec = strpos($css, '}', $start);

        $this->assertNotFalse($koniec);

        return substr($css, $start, $koniec - $start);
    }

    public function test_dolna_belka_zostaje_poza_przeplywem_dokumentu(): void
    {
        $belka = $this->regula($this->css(), '.bottom-nav {');

        $this->assertStringContainsString(
            'position: fixed',
            $belka,
            'Dolna belka nie jest już `position: fixed`. Jeśli powodem jest '
            .'2.4.11 (Focus Not Obscured) — to nie jest droga: belka w przepływie '
            .'przestaje być dla axe nakładką i zaczyna przycinać pole kliknięcia '
            .'sąsiadów, czyli łamie 2.5.8 (Target Size). Rezerwę liczy '
            .'`--rezerwa-pod-belka`.',
        );

        $this->assertStringNotContainsString(
            'position: sticky',
            $belka,
            'Dolna belka wróciła na `position: sticky`. Zmierzone przy 320 px: '
            .'„profil (własny)" traci 48 → 14,5 px wolnego pola, „dodaj przepis" '
            .'55,9 → 10,1 px, „twoje tagi" 40 → 4,5 px — trzy naruszenia 2.5.8 '
            .'w axe, których przy `fixed` nie ma.',
        );
    }

    public function test_rezerwa_pod_belka_jest_wydana_w_obu_miejscach(): void
    {
        $css = $this->css();

        $this->assertStringContainsString(
            'scroll-padding-bottom: var(--rezerwa-pod-belka)',
            $css,
            'Zniknęło `scroll-padding-bottom`. Samo wypełnienie stopki ratuje '
            .'tylko ostatni ekran — przewijanie fokusu w widok po Tab liczy się '
            .'do krawędzi okna i bez tej deklaracji nie wie, że stoi tam belka.',
        );

        $stopka = $this->regula($css, '.site-footer {');

        $this->assertStringContainsString(
            'var(--rezerwa-pod-belka)',
            $stopka,
            'Dolne wypełnienie stopki nie jest już liczoną rezerwą. Stopka jest '
            .'ostatnia w dokumencie, więc to ona decyduje, czy fokus da się '
            .'wyprowadzić spod belki — nie ma już czego przewijać. Stała wartość '
            .'`--spacing-20` (80 px) nie wystarcza przy ustawieniu „tekst 140%" '
            .'(belka 105,5 px) ani przy czcionce przeglądarki 200% (376,2 px).',
        );
    }

    public function test_rezerwa_rosnie_razem_z_belka_czyli_ze_skala_tekstu(): void
    {
        $css = $this->css();

        preg_match_all('~--rezerwa-pod-belka:\s*([^;]+);~', $css, $definicje);

        /*
         * Trzy stopnie, nie jeden: domyślny, podbity przy bardzo dużym tekście
         * (próg `15rem` porównuje okno z KORZENIEM, nie z pikselami) i zerowy
         * od 64rem w górę, gdzie belki już nie ma.
         */
        $this->assertGreaterThanOrEqual(
            3,
            count($definicje[1]),
            'Token `--rezerwa-pod-belka` przestał mieć stopnie.',
        );

        $niezerowe = array_values(array_filter(
            array_map('trim', $definicje[1]),
            static fn (string $wartosc): bool => ! str_starts_with($wartosc, '0'),
        ));

        $this->assertNotEmpty($niezerowe, 'Wszystkie stopnie rezerwy są zerowe.');

        /*
         * TO JEST CAŁA TREŚĆ TEGO TESTU.
         *
         * Belka rośnie razem z `--user-text-scale` (mnoży tokeny `--text-*`
         * w `tokens.css`), a `rem` NIE — bo to ustawienie korzenia nie rusza.
         * Rezerwa zapisana samym `rem` stoi więc w miejscu dokładnie wtedy,
         * gdy belka jest najwyższa. Zmierzone przy 320 px i skali 140%: belka
         * 105,5 px, rezerwa 128 px, czyli 22,5 px luzu — mniej niż jedna nasza
         * kontrolka (48 px), więc element z fokusem nie miał gdzie stanąć nad
         * belką. CI złapało to jako 2.4.11 FAIL na 320 i 360 px, a 414 px
         * (luz 52,8 px) przeszło.
         */
        foreach ($niezerowe as $wartosc) {
            $this->assertStringContainsString(
                'var(--user-text-scale',
                $wartosc,
                "Stopień rezerwy „{$wartosc}” nie mnoży się przez `--user-text-scale`. "
                .'Belka rośnie z tym ustawieniem, a `rem` nie — luz nad belką zapadnie '
                .'się wtedy, gdy tekst jest największy, czyli u osoby, dla której ten '
                .'produkt jest robiony.',
            );
        }

        preg_match_all('~--rezerwa-pod-belka:\s*calc\(([0-9.]+)rem~', $css, $stopnie);

        $this->assertGreaterThanOrEqual(
            15.0,
            (float) max(array_map('floatval', $stopnie[1])),
            'Największy stopień rezerwy zszedł poniżej 15rem. Przy czcionce '
            .'przeglądarki 200% belka ma 376,2 px, a kontrolka 96 px — 13rem '
            .'(416 px) zostawiało tylko 39,8 px luzu.',
        );
    }
}
