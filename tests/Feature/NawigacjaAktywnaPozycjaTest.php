<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bieżąca pozycja nawigacji (`aria-current="page"`) — UI kit v2, etap D.
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Pozycja „Dodaj" w pasku dolnym i w nawigacji bocznej podświetlała się
 * WYŁĄCZNIE na dokładnej trasie `/dodaj` (`request()->routeIs('add')`).
 * Cały proces dodawania ma jednak trzy dalsze kroki, każdy pod własną
 * nazwaną trasą: wybór zdjęcia (`posts.create`, `/dodaj/zdjecie`), kreator
 * przepisu (`recipes.create`, `/dodaj/przepis`) i jego wariant jednostronicowy
 * (`recipes.create.simple`). Na żadnym z tych trzech ekranów menu nie
 * pokazywało, gdzie jest użytkownik — mimo że wciąż jest w tym samym miejscu
 * serwisu, do którego przyszedł kliknięciem „Dodaj".
 *
 * Pozycja „Moje" ma dokładnie ten sam kształt trasy (jeden punkt wejścia,
 * kilka podstron) i od dawna używa dopasowania przez wzorzec
 * (`request()->routeIs('collections.*')`) — „Dodaj" był tu jedynym
 * wyjątkiem, nie świadomą decyzją.
 *
 * ZMIERZONE PRZEGLĄDARKĄ (Playwright, 320/390/768 px, gość i basia@example.test)
 * przed poprawką: na `/dodaj/zdjecie`, `/dodaj/przepis` i
 * `/dodaj/przepis/jedna-strona` żadna pozycja paska dolnego ani nawigacji
 * bocznej nie miała `aria-current="page"`. Poza tym jednym punktem menu
 * mobilne (pasek górny + pasek dolny) nie przewijało się w bok na żadnym
 * z jedenastu sprawdzonych ekranów, przy żadnej z trzech szerokości,
 * z tekstem 100% i 140%, w motywie jasnym i ciemnym.
 *
 * DLACZEGO SPRAWDZAMY OBA PASKI I KILKA EKRANÓW NARAZ
 * `layout.blade.php` renderuje się na każdej stronie serwisu — poprawka
 * widoczna tylko w jednym pasku albo tylko na jednej trasie zostawiłaby
 * dokładnie taki sam rozjazd na trasie sąsiedniej.
 */
class NawigacjaAktywnaPozycjaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wycina fragment pierwszego znacznika o podanej klasie (razem z nim).
     * Ten sam sposób co w `PasekGornyNaTelefonieTest`.
     */
    private function wytnijZnacznik(string $html, string $znacznik, string $klasa): string
    {
        $start = strpos($html, '<'.$znacznik.' class="'.$klasa);

        $this->assertNotFalse($start, "Nie znaleziono <{$znacznik} class=\"{$klasa}\"> na stronie.");

        $koniec = strpos($html, '</'.$znacznik.'>', $start);

        $this->assertNotFalse($koniec, "Znacznik <{$znacznik} class=\"{$klasa}\"> nie jest domknięty.");

        return substr($html, $start, $koniec - $start);
    }

    /** Nazwa pozycji z `aria-current="page"` w podanym fragmencie, albo null. */
    private function aktywnaPozycja(string $fragment): ?string
    {
        preg_match_all('~<a\b[^>]*>(.*?)</a>~s', $fragment, $linki, PREG_SET_ORDER);

        foreach ($linki as $link) {
            if (! str_contains($link[0], 'aria-current="page"')) {
                continue;
            }

            $tekst = trim(html_entity_decode(strip_tags((string) $link[1])));

            return $tekst;
        }

        return null;
    }

    /**
     * ILE pozycji fragmentu ma `aria-current="page"`.
     *
     * `aktywnaPozycja()` oddaje PIERWSZĄ znalezioną i o dwóch podświetlonych
     * naraz nie wie nic — a to jest osobna, realna awaria: czytnik ekranu
     * mówi wtedy „bieżąca strona" przy dwóch różnych miejscach serwisu,
     * a wzrokowo podświetlone są dwie pozycje menu. Zmierzone: po dopisaniu
     * `aria-current="page"` do KAŻDEJ pozycji paska dolnego asercja na
     * `aktywnaPozycja()` dla `/home` była dalej zielona, bo pierwszą pozycją
     * jest właśnie „Start".
     */
    private function liczbaAktywnych(string $fragment): int
    {
        return substr_count($fragment, 'aria-current="page"');
    }

    /**
     * Każdy krok procesu dodawania utrzymuje „Dodaj" jako pozycję bieżącą —
     * w OBU nawigacjach naraz, bo obie renderują się z tego samego
     * `layout.blade.php` i obie mają obowiązywać ten sam niezmiennik.
     */
    public function test_dodaj_zostaje_aktywne_w_calym_procesie_dodawania(): void
    {
        $uzytkownik = $this->user('basia');

        $trasy = [
            'add' => '/dodaj',
            'posts.create' => '/dodaj/zdjecie',
            'recipes.create' => '/dodaj/przepis',
            'recipes.create.simple' => '/dodaj/przepis/jedna-strona',
        ];

        foreach ($trasy as $nazwaTrasy => $adres) {
            $html = $this->actingAs($uzytkownik)->get($adres)->assertOk()->getContent();

            $paskiDoSprawdzenia = [
                'pasek dolny' => $this->wytnijZnacznik($html, 'nav', 'bottom-nav'),
                'nawigacja boczna' => $this->wytnijZnacznik($html, 'nav', 'side-nav'),
            ];

            foreach ($paskiDoSprawdzenia as $nazwaPaska => $fragment) {
                $this->assertSame(
                    'Dodaj',
                    $this->aktywnaPozycja($fragment),
                    "Trasa „{$nazwaTrasy}” ({$adres}): {$nazwaPaska} nie pokazuje „Dodaj” jako "
                    .'bieżącej pozycji. Użytkownik jest w środku procesu dodawania, a menu '
                    .'wygląda tak, jakby nie był nigdzie.',
                );
            }
        }
    }

    /**
     * Niezmiennik z drugiej strony: poza procesem dodawania „Dodaj" NIE jest
     * bieżącą pozycją, a właściwa pozycja jest — na kilku różnych ekranach,
     * nie tylko na `/home`.
     */
    public function test_kazdy_ekran_podswietla_wlasciwa_i_tylko_jedna_pozycje(): void
    {
        $uzytkownik = $this->user('basia');

        $oczekiwane = [
            '/home' => 'Start',
            '/szukaj' => 'Szukaj',
            '/zeszyt' => 'Moje',
            '/@'.$uzytkownik->profile->username => 'Profil',
        ];

        foreach ($oczekiwane as $adres => $podpis) {
            $html = $this->actingAs($uzytkownik)->get($adres)->assertOk()->getContent();

            $pasekDolny = $this->wytnijZnacznik($html, 'nav', 'bottom-nav');
            $nawigacjaBoczna = $this->wytnijZnacznik($html, 'nav', 'side-nav');

            $this->assertSame($podpis, $this->aktywnaPozycja($pasekDolny), "Pasek dolny na „{$adres}”.");
            $this->assertSame($podpis, $this->aktywnaPozycja($nawigacjaBoczna), "Nawigacja boczna na „{$adres}”.");

            // „I TYLKO JEDNĄ" z nazwy tego testu — dotąd nie było tego nigdzie.
            $this->assertSame(
                1,
                $this->liczbaAktywnych($pasekDolny),
                "Pasek dolny na „{$adres}” ma podświetloną więcej niż jedną pozycję.",
            );
            $this->assertSame(
                1,
                $this->liczbaAktywnych($nawigacjaBoczna),
                "Nawigacja boczna na „{$adres}” ma podświetloną więcej niż jedną pozycję.",
            );
        }
    }

    /**
     * Gość nie ma paska dolnego ani nawigacji bocznej — na ŻADNYM
     * z ekranów, które realnie ogląda przed założeniem konta, nie tylko
     * na stronie powitalnej.
     */
    public function test_gosc_nie_ma_menu_zalogowanego_na_zadnym_swoim_ekranie(): void
    {
        foreach (['landing', 'login', 'register', 'discover'] as $trasa) {
            $html = $this->get(route($trasa))->assertOk()->getContent();

            $this->assertStringNotContainsString(
                '<nav class="bottom-nav"',
                $html,
                "Trasa „{$trasa}”: gość dostał pasek dolny zalogowanego.",
            );

            $this->assertStringNotContainsString(
                '<nav class="side-nav"',
                $html,
                "Trasa „{$trasa}”: gość dostał nawigację boczną zalogowanego.",
            );
        }
    }

    /**
     * MENU DZIAŁA BEZ JAVASCRIPTU (AGENTS.md §5, zasada zlecenia #6).
     *
     * Test HTTP nie wykonuje JS w ogóle — więc sam fakt, że te asercje
     * przechodzą na zwykłym, serwerowym HTML-u, jest częścią dowodu. To,
     * czego test HTTP NIE złapie (czy strona faktycznie nie przewija się
     * w bok w przeglądarce), zmierzono osobno Playwrightem z
     * `javaScriptEnabled: false` — logowanie, przejście „Szukaj” i „Dodaj”
     * w pasku dolnym doprowadziły na właściwe adresy bez jednej linijki JS.
     * Ten test pilnuje tego, co widać w źródle i co najłatwiej cofnąć:
     * zamianę zwykłego odnośnika na coś, co bez skryptu nie prowadzi nigdzie.
     */
    public function test_pozycje_menu_sa_zwyklymi_odnosnikami_bez_javascriptu(): void
    {
        $html = $this->actingAs($this->user('basia'))->get('/home')->assertOk()->getContent();

        $pasekDolny = $this->wytnijZnacznik($html, 'nav', 'bottom-nav');
        $nawigacjaBoczna = $this->wytnijZnacznik($html, 'nav', 'side-nav');

        foreach (['pasek dolny' => $pasekDolny, 'nawigacja boczna' => $nawigacjaBoczna] as $nazwaPaska => $fragment) {
            preg_match_all('~<a\b[^>]*>~', $fragment, $znaczniki);

            $this->assertNotEmpty($znaczniki[0], "{$nazwaPaska}: brak odnośników.");

            foreach ($znaczniki[0] as $znacznik) {
                $this->assertMatchesRegularExpression(
                    '~\bhref="https?://[^"]+"~',
                    $znacznik,
                    "{$nazwaPaska}: odnośnik bez prawdziwego `href` — bez JS nie prowadzi nigdzie. {$znacznik}",
                );

                foreach (['onclick', 'x-on:click', '@click', 'data-toggle'] as $atrybutJs) {
                    $this->assertStringNotContainsString(
                        $atrybutJs,
                        $znacznik,
                        "{$nazwaPaska}: pozycja menu zależy od `{$atrybutJs}".'` — bez JS przestałaby działać.',
                    );
                }
            }
        }
    }
}
