<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pasek górny na telefonie (issue #80).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * `.topbar-inner` był flexem BEZ zawijania, z logotypem i dwoma
 * pełnotekstowymi przyciskami. Zmierzone w Chromium przy oknie 360×740:
 * `scrollWidth` 386 dla gościa i 493 dla zalogowanego. Cała strona przewijała
 * się w bok NA KAŻDYM EKRANIE, „Załóż konto" było ucięte w połowie słowa,
 * a „Dodaj" — główna akcja produktu — leżało całkowicie poza ekranem.
 *
 * DLACZEGO TEN TEST NIE ZASTĘPUJE POMIARU W PRZEGLĄDARCE
 * Przewijania w bok nie da się stwierdzić z HTML-a: to wynik UŁOŻENIA strony,
 * a nie jej treści. Prawdziwym automatem jest pomiar w `scripts/dostepnosc.mjs`
 * (`documentElement.scrollWidth <= clientWidth` przy 320/360/414/768 px),
 * podpięty pod `./scripts/check.sh --dostepnosc`. Bez naprawy zgłasza on
 * 72 przepełnienia; z naprawą zero.
 *
 * Ten plik pilnuje tego, co WIDAĆ W ŹRÓDLE i co jest najłatwiej cofnąć jedną
 * „drobną poprawką" w belce: reguł układu wpisanych z powrotem w atrybut
 * `style`, powrotu drugiego przycisku na telefon, zamiany napisu na samą
 * ikonę i „naprawy" przez `overflow-x: hidden`.
 */
class PasekGornyNaTelefonieTest extends TestCase
{
    use RefreshDatabase;

    private function css(string $plik): string
    {
        $sciezka = resource_path('css/'.$plik);

        $this->assertFileExists($sciezka);

        $tresc = (string) file_get_contents($sciezka);

        $this->assertNotSame('', trim($tresc), "Arkusz {$plik} jest pusty — test sprawdzałby pustkę.");

        /*
         * KOMENTARZE PRECZ, ZANIM COKOLWIEK SPRAWDZIMY.
         *
         * W tym repozytorium komentarze cytują to, czego w kodzie być NIE MOŻE
         * („świadomie nie używamy overflow-x: hidden"). Asercja szukająca
         * takiego ciągu w surowym pliku trafiałaby we własne uzasadnienie
         * i świeciła na czerwono przy poprawnym kodzie — albo, po odwróceniu,
         * na zielono przy zepsutym.
         */
        return (string) preg_replace('~/\*.*?\*/~s', '', $tresc);
    }

    /** Fragment arkusza od podanego selektora do końca jego bloku. */
    private function regula(string $css, string $selektor): string
    {
        $start = strpos($css, $selektor);

        $this->assertNotFalse($start, "W arkuszu nie ma już reguły „{$selektor}” — układ paska się zmienił.");

        $koniec = strpos($css, '}', $start);

        $this->assertNotFalse($koniec);

        return substr($css, $start, $koniec - $start);
    }

    public function test_pasek_gorny_zawija_zamiast_rozpychac_strone(): void
    {
        $css = $this->css('app.css');

        $this->assertStringContainsString(
            'flex-wrap: wrap',
            $this->regula($css, '.topbar-inner {'),
            'Pasek górny znowu nie zawija. Przy 360 px logotyp i przyciski nie '
            .'mieszczą się w jednym wierszu i cała strona przewija się w bok '
            .'(WCAG 2.2 AA, 1.4.10 Reflow).',
        );

        $this->assertStringContainsString(
            'flex-wrap: wrap',
            $this->regula($css, '.topbar-actions {'),
            'Grupa akcji w pasku nie zawija — dwa przyciski przy skali tekstu '
            .'150% nie zmieszczą się obok siebie nawet w osobnym wierszu.',
        );
    }

    public function test_dolna_nawigacja_zawija_zamiast_nachodzic_na_siebie(): void
    {
        $this->assertStringContainsString(
            'flex-wrap: wrap',
            $this->regula($this->css('app.css'), '.bottom-nav {'),
            'Pasek dolny wrócił do sztywnych pięciu kolumn. Przy skali tekstu '
            .'150% podpis „Szukaj" potrzebuje ~78 px, a kolumna przy 320 px '
            .'ma 64 — podpisy wychodzą poza swoje kolumny i nachodzą na siebie.',
        );
    }

    public function test_dlugie_slowo_lamie_sie_zamiast_rozpychac_strone(): void
    {
        $this->assertStringContainsString(
            'overflow-wrap: break-word',
            $this->regula($this->css('tokens.css'), '  body {'),
            'Zniknęło łamanie długich słów. Powiadomienie „X ugotowała/ugotował '
            .'z Twojego przepisu" wypycha wtedy stronę do 366 px przy oknie 320.',
        );
    }

    /**
     * `overflow-x: hidden` na <body> jest tu ZAKAZANE.
     *
     * To jest najbardziej kusząca „naprawa" tego błędu i najgorsza z możliwych.
     * Ukrywa objaw (pasek przewijania), zostawiając treść poza ekranem — a przy
     * okazji ustawia `scrollWidth` równe `clientWidth`, przez co automat
     * z `scripts/dostepnosc.mjs` przestaje cokolwiek wykrywać. Zepsuty układ
     * świeciłby wtedy na zielono.
     */
    public function test_nie_zamiatamy_przewijania_pod_dywan(): void
    {
        foreach (['app.css', 'tokens.css'] as $plik) {
            $css = preg_replace('/\s+/', ' ', $this->css($plik));

            foreach (['body { overflow-x: hidden', 'html { overflow-x: hidden', 'html, body { overflow-x: hidden'] as $zakazane) {
                $this->assertStringNotContainsString(
                    $zakazane,
                    (string) $css,
                    "W {$plik} pojawiło się „{$zakazane}”. To nie jest naprawa przewijania w bok, "
                    .'tylko wyłączenie automatu, który je wykrywa.',
                );
            }
        }
    }

    public function test_pasek_nie_ma_regul_ukladu_wpisanych_w_atrybut_style(): void
    {
        $html = $this->actingAs($this->user('basia'))->get(route('home'))->assertOk()->getContent();

        $pasek = $this->wytnijPasek($html);

        $this->assertStringContainsString(
            'class="topbar-actions"',
            $pasek,
            'Grupa akcji straciła klasę. Reguły układu wpisane w atrybut `style` '
            .'nie da się uzależnić od szerokości ekranu — a to było źródłem #80.',
        );

        $this->assertStringNotContainsString(
            'display:flex',
            $pasek,
            'W pasku wróciło `display:flex` wpisane w atrybut. Zapytanie o media '
            .'nie działa w atrybucie `style`, więc pasek znowu nie umie zawinąć.',
        );
    }

    /**
     * „Dodaj" znika z paska GÓRNEGO na telefonie, ale NIE znika z ekranu.
     *
     * To jest sedno naprawy: zamiast ściskać dwa pełnotekstowe przyciski
     * w belce, jedna pozycja przenosi się tam, gdzie na telefonie i tak jest —
     * do paska dolnego, z ikoną ORAZ podpisem.
     */
    public function test_dodaj_zostaje_dostepne_z_paska_dolnego(): void
    {
        $html = $this->actingAs($this->user('basia'))->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'topbar-desktop-only',
            $this->wytnijPasek($html),
            'Przycisk „Dodaj" wrócił do paska górnego na każdej szerokości — '
            .'razem z „Powiadomieniami" nie mieści się na telefonie.',
        );

        $pasekDolny = $this->wytnijZnacznik($html, 'nav', 'bottom-nav');

        $this->assertStringContainsString(
            'Dodaj',
            $pasekDolny,
            'Główna akcja produktu zniknęła z paska dolnego. Na telefonie nie '
            .'ma jej wtedy nigdzie.',
        );

        $this->assertStringContainsString(
            route('add'),
            $pasekDolny,
            'W pasku dolnym jest napis „Dodaj", ale nie prowadzi do dodawania.',
        );
    }

    /**
     * IKONA NIGDY NIE JEST SAMA (AGENTS.md §5).
     *
     * Najprostszym sposobem zmieszczenia belki w 360 px byłoby zdjęcie napisów
     * przy ikonach. Ten test zamyka tę drogę raz na zawsze — dla obu pasków
     * i dla gościa, i dla zalogowanego.
     */
    public function test_kazda_pozycja_obu_paskow_ma_widoczny_napis(): void
    {
        $widoki = [
            'gość' => $this->get(route('landing'))->assertOk()->getContent(),
            'zalogowany' => $this->actingAs($this->user('basia'))->get(route('home'))->assertOk()->getContent(),
        ];

        foreach ($widoki as $kto => $html) {
            foreach (['topbar-actions', 'bottom-nav'] as $pasek) {
                $fragment = $pasek === 'topbar-actions'
                    ? $this->wytnijPasek($html)
                    : $this->wytnijZnacznik($html, 'nav', 'bottom-nav');

                if ($fragment === '') {
                    // Gość nie ma paska dolnego — to jest poprawne, nie brak.
                    continue;
                }

                preg_match_all('~<a\b[^>]*>(.*?)</a>~s', $fragment, $linki);

                $this->assertNotEmpty($linki[1], "Pasek „{$pasek}” ({$kto}) nie ma ani jednego odnośnika.");

                foreach ($linki[1] as $wnetrze) {
                    // Napis TYLKO dla czytnika ekranu nie liczy się jako podpis:
                    // chodzi o to, co widać, a nie o to, co słychać.
                    $bezUkrytych = preg_replace('~<span class="visually-hidden">.*?</span>~s', '', $wnetrze);
                    $widocznyTekst = trim(html_entity_decode(strip_tags((string) $bezUkrytych)));

                    $this->assertNotSame(
                        '',
                        $widocznyTekst,
                        "W pasku „{$pasek}” ({$kto}) jest pozycja bez widocznego napisu. "
                        .'Sama ikona nie jest opisem akcji (AGENTS.md §5).',
                    );
                }
            }
        }
    }

    private function wytnijPasek(string $html): string
    {
        return $this->wytnijZnacznik($html, 'div', 'topbar-actions');
    }

    /** Zawartość pierwszego znacznika o podanej klasie, razem z nim samym. */
    private function wytnijZnacznik(string $html, string $znacznik, string $klasa): string
    {
        $start = strpos($html, '<'.$znacznik.' class="'.$klasa);

        if ($start === false) {
            return '';
        }

        $koniec = strpos($html, '</'.$znacznik.'>', $start);

        $this->assertNotFalse($koniec, "Znacznik <{$znacznik} class=\"{$klasa}\"> nie jest domknięty.");

        return substr($html, $start, $koniec - $start);
    }
}
