<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TRYB PANELU — menu wyłącznie z narzędziami moderacji.
 *
 * Prośba właściciela, dosłownie: „dać oddzielny przycisk, który pokaże tylko
 * menu admina, bez przycisków typowych dla użytkownika (moje, profil itp.)".
 *
 * JAK TO DZIAŁA
 * Tryb wynika ze ŚCIEŻKI: moderator na `/admin/**` dostaje menu panelu
 * (spis ekranów panelu + wyjście „Wróć do Kuking"), a poza `/admin/**`
 * zwykłe menu serwisu z osobną pozycją „Otwórz panel moderacji". Nie ma tu
 * ani skryptu, ani stanu w sesji — uzasadnienie stoi w komentarzu
 * w `components/layout.blade.php`.
 *
 * CZEGO PILNUJE TEN PLIK
 * Regresje w takim menu są ciche: pozycja użytkownika, która wróci do trybu
 * panelu, wygląda niewinnie, a wyjście, które z niego zniknie, zamyka
 * moderatora w panelu — jedno i drugie widać dopiero, gdy się na to patrzy.
 * Osobno i najważniejsze: NIC z tego nie może wyciec do HTML-a zwykłego
 * użytkownika ani gościa. `EnsureUserIsModerator` oddaje z `/admin/**` 404
 * właśnie po to, żeby panel nie potwierdzał obcym, że istnieje — menu, które
 * zostawia w kodzie strony nazwę sekcji albo klasę trybu, cofa tę decyzję
 * bez jednej linijki zmiany w middleware.
 *
 * Nazwy metod z przedrostkiem `test_`, nie atrybut `#[Test]`: w tym
 * repozytorium atrybut bez importu powoduje, że PHPUnit po cichu POMIJA
 * metodę (patrz komentarz w `PanelModeracjiWMenuTest`).
 */
class TrybPaneluWMenuTest extends TestCase
{
    use RefreshDatabase;

    /** Pozycje menu przeznaczone dla użytkownika — w trybie panelu nie ma ich wcale. */
    private const POZYCJE_UZYTKOWNIKA = ['Start', 'Szukaj', 'Dodaj', 'Moje', 'Profil'];

    /** Ekrany panelu — ten sam spis co w `PanelModeracjiWMenuTest`. */
    private const POZYCJE_PANELU = [
        'Bez odpowiedzi',
        'Zgłoszenia',
        'Odwołania',
        'Tablica na dziś',
        'Tagi promowane',
        'Wiadomości do nas',
    ];

    /** Ten sam sposób wycinania fragmentu co `NawigacjaAktywnaPozycjaTest`. */
    private function wytnijBoczna(string $html): string
    {
        $start = strpos($html, '<nav class="side-nav');
        $this->assertNotFalse($start, 'Brak nawigacji bocznej na stronie.');

        $koniec = strpos($html, '</nav>', $start);
        $this->assertNotFalse($koniec, 'Nawigacja boczna nie jest domknięta.');

        return substr($html, $start, $koniec - $start);
    }

    /**
     * SEDNO PROŚBY: w trybie panelu menu pokazuje narzędzia moderacji
     * i ANI JEDNEJ pozycji użytkownika.
     */
    public function test_moderator_w_panelu_widzi_tylko_menu_panelu(): void
    {
        $moderator = $this->moderator();

        $html = $this->actingAs($moderator)->get(route('admin.reports'))->assertOk()->getContent();
        $boczna = $this->wytnijBoczna((string) $html);

        foreach (self::POZYCJE_PANELU as $pozycja) {
            $this->assertStringContainsString(
                $pozycja,
                $boczna,
                "W trybie panelu brakuje ekranu panelu „{$pozycja}”.",
            );
        }

        foreach (self::POZYCJE_UZYTKOWNIKA as $pozycja) {
            $this->assertStringNotContainsString(
                $pozycja,
                $boczna,
                "Menu panelu pokazuje pozycję użytkownika „{$pozycja}” — a miało pokazywać "
                .'WYŁĄCZNIE narzędzia moderacji.',
            );
        }

        // „Napisz do nas" to formularz dla osoby proszącej o pomoc. Moderator
        // w panelu jest po drugiej stronie tego formularza („Wiadomości do nas").
        $this->assertStringNotContainsString('Napisz do nas', $boczna);

        // Zostaje to, bez czego panel przestaje być używalny: powiadomienia
        // (przychodzą tam zgłoszenia), ustawienia (2FA jest warunkiem wejścia
        // na `/admin/**`) i wylogowanie.
        $this->assertStringContainsString('Powiadomienia', $boczna);
        $this->assertStringContainsString('Ustawienia', $boczna);
        $this->assertStringContainsString('Wyloguj się', $boczna);
    }

    /**
     * Tryb panelu obowiązuje na KAŻDYM ekranie `/admin/**`, nie tylko na
     * zgłoszeniach. Trasy bierzemy z routera, nie z listy przepisanej tutaj —
     * siódmy ekran panelu, dodany kiedyś bez tego, ma ten test wywrócić.
     */
    public function test_kazdy_ekran_panelu_ma_menu_bez_pozycji_uzytkownika(): void
    {
        $moderator = $this->moderator();

        $trasy = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($trasa): bool => in_array('GET', $trasa->methods(), true))
            ->filter(fn ($trasa): bool => str_starts_with((string) $trasa->uri(), 'admin/'))
            ->filter(fn ($trasa): bool => ! str_contains((string) $trasa->uri(), '{'))
            ->map(fn ($trasa): string => (string) $trasa->uri())
            ->values();

        $this->assertGreaterThanOrEqual(
            6,
            $trasy->count(),
            'Router oddał mniej niż sześć bezparametrowych ekranów panelu — czytam złe trasy.',
        );

        foreach ($trasy as $uri) {
            $html = $this->actingAs($moderator)->get('/'.$uri)->assertOk()->getContent();
            $boczna = $this->wytnijBoczna((string) $html);

            $this->assertStringContainsString(
                'Wróć do Kuking',
                $boczna,
                'Ekran /'.$uri.' nie ma wyjścia z trybu panelu.',
            );

            foreach (self::POZYCJE_UZYTKOWNIKA as $pozycja) {
                $this->assertStringNotContainsString(
                    $pozycja,
                    $boczna,
                    'Ekran /'.$uri." pokazuje w menu pozycję użytkownika „{$pozycja}”.",
                );
            }
        }
    }

    /**
     * Z trybu panelu widać drogę powrotną — i to w dwóch miejscach, bo na
     * telefonie menu boczne i pasek dolny to dwie różne rzeczy.
     */
    public function test_w_trybie_panelu_widac_powrot_do_serwisu(): void
    {
        $moderator = $this->moderator();

        $html = (string) $this->actingAs($moderator)->get(route('admin.reports'))->assertOk()->getContent();
        $boczna = $this->wytnijBoczna($html);

        $this->assertMatchesRegularExpression(
            '~<a class="side-nav-item side-nav-powrot" href="[^"]*/home">\s*<svg.*?</svg>\s*Wróć do Kuking\s*</a>~s',
            $boczna,
            'W menu panelu nie ma wyjścia „Wróć do Kuking” prowadzącego na Start.',
        );

        // Pasek dolny (telefon): jedna pozycja i jest nią wyjście. Bez tego
        // moderator na telefonie zostaje z paskiem pełnym przycisków, których
        // w tym trybie ma nie być — albo bez paska w ogóle.
        $this->assertStringContainsString(
            'class="bottom-nav bottom-nav-panel"',
            $html,
            'Telefon w trybie panelu nie ma własnego paska dolnego.',
        );
        $this->assertStringContainsString('Wróć do Kuking', $html);
        $this->assertStringNotContainsString(
            'bottom-nav-item-glowna',
            $html,
            'Pasek dolny w trybie panelu pokazuje główną akcję „Dodaj”.',
        );
    }

    /**
     * Poza panelem nic się nie zmienia: moderator ma normalne menu serwisu,
     * a wejście w tryb panelu to OSOBNA, widoczna pozycja (nie ukryty gest
     * ani odnośnik zrobiony z nagłówka sekcji).
     */
    public function test_moderator_poza_panelem_ma_normalne_menu_i_wejscie_do_panelu(): void
    {
        $moderator = $this->moderator();

        $boczna = $this->wytnijBoczna(
            (string) $this->actingAs($moderator)->get('/home')->assertOk()->getContent(),
        );

        foreach (self::POZYCJE_UZYTKOWNIKA as $pozycja) {
            $this->assertStringContainsString(
                $pozycja,
                $boczna,
                "Poza panelem zniknęła zwykła pozycja menu „{$pozycja}”.",
            );
        }

        $this->assertMatchesRegularExpression(
            '~<a class="side-nav-item side-nav-wejscie" href="[^"]*'.preg_quote(
                (string) parse_url(route('admin.reports'), PHP_URL_PATH),
                '~',
            ).'">\s*<svg.*?</svg>\s*Otwórz panel moderacji\s*</a>~s',
            $boczna,
            'Brak widocznego wejścia „Otwórz panel moderacji” w normalnym menu moderatora.',
        );

        // Nagłówek sekcji zostaje NAGŁÓWKIEM, nie zamienia się w odnośnik:
        // etykieta grupy dla czytnika ekranu i przycisk to dwie różne role
        // i dwa różne teksty.
        $this->assertStringContainsString(
            '<h2 class="side-nav-moderacja-naglowek" id="side-nav-moderacja-naglowek">Panel moderacji</h2>',
            $boczna,
        );

        // Poza panelem wyjście nie ma czego robić w menu.
        $this->assertStringNotContainsString('Wróć do Kuking', $boczna);
    }

    /**
     * BEZPIECZEŃSTWO: zwykły użytkownik nie widzi ŚLADU trybu panelu —
     * ani przycisku wejścia, ani nazwy sekcji, ani klas. Asercja na całą
     * treść strony, nie na samo menu.
     */
    public function test_zwykly_uzytkownik_nie_widzi_sladu_trybu_panelu(): void
    {
        $uzytkownik = $this->user('basia');

        $html = (string) $this->actingAs($uzytkownik)->get('/home')->assertOk()->getContent();

        foreach ($this->sladyPanelu() as $slad) {
            $this->assertStringNotContainsString(
                $slad,
                $html,
                "Zwykły użytkownik widzi w kodzie strony ślad panelu: „{$slad}”.",
            );
        }

        // I dalej dostaje z panelu 404, a nie 403 — ten PR nie rusza uprawnień
        // ani tras, i ten test ma to udowodnić, a nie założyć.
        $this->actingAs($uzytkownik)->get(route('admin.reports'))->assertNotFound();
    }

    /** To samo dla gościa: strona powitalna nie zdradza, że panel istnieje. */
    public function test_gosc_nie_widzi_sladu_trybu_panelu(): void
    {
        $html = (string) $this->get(route('landing'))->assertOk()->getContent();

        foreach ($this->sladyPanelu() as $slad) {
            $this->assertStringNotContainsString(
                $slad,
                $html,
                "Gość widzi w kodzie strony ślad panelu: „{$slad}”.",
            );
        }

        // Gość dostaje z panelu przekierowanie na logowanie, nie 404: `auth`
        // stoi w tej grupie PRZED `moderator` i kończy sprawę wcześniej.
        // 404 zamiast 403 dotyczy osoby ZALOGOWANEJ bez uprawnień (patrz test
        // wyżej) — gościowi i tak nie mówimy niczego o panelu.
        $this->get(route('admin.reports'))->assertRedirect(route('login'));
    }

    /**
     * `aria-current="page"` działa w OBU trybach i zawsze wskazuje dokładnie
     * jedną pozycję. To jedyna rzecz w tym menu, która mówi „tu jesteś", więc
     * jej brak w nowym trybie byłby cichą stratą.
     */
    public function test_aria_current_dziala_w_obu_trybach(): void
    {
        $moderator = $this->moderator();

        // Tryb serwisu — bieżące jest „Start".
        $boczna = $this->wytnijBoczna(
            (string) $this->actingAs($moderator)->get('/home')->assertOk()->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '~<a class="side-nav-item" href="[^"]*"\s+aria-current="page"\s*>.*?Start</a>~s',
            $boczna,
            'W menu serwisu pozycja „Start” nie jest oznaczona jako bieżąca.',
        );
        $this->assertSame(1, substr_count($boczna, 'aria-current="page"'));

        // Tryb panelu — bieżące są „Zgłoszenia", a wejście/wyjście nigdy
        // nie udają bieżącego ekranu.
        $boczna = $this->wytnijBoczna(
            (string) $this->actingAs($moderator)->get(route('admin.reports'))->assertOk()->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '~<a class="side-nav-item" href="[^"]*"\s+aria-current="page"\s*>.*?Zgłoszenia</a>~s',
            $boczna,
            'W trybie panelu pozycja „Zgłoszenia” nie jest oznaczona jako bieżąca.',
        );
        $this->assertSame(1, substr_count($boczna, 'aria-current="page"'));
    }

    /**
     * Menu panelu ma własną nazwę dla czytnika ekranu. „Nawigacja główna"
     * w trybie, w którym nie ma ani jednej pozycji głównej, byłoby nieprawdą.
     */
    public function test_menu_panelu_ma_wlasna_etykiete_dla_czytnika_ekranu(): void
    {
        $moderator = $this->moderator();

        $html = (string) $this->actingAs($moderator)->get(route('admin.reports'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Nawigacja panelu moderacji"', $html);
        $this->assertStringContainsString('aria-label="Wyjście z panelu moderacji"', $html);
    }

    /**
     * Ślady, których w kodzie strony obcej osoby nie może być ANI JEDNEGO.
     * Osobna metoda, bo ten sam spis obowiązuje użytkownika i gościa.
     *
     * @return list<string>
     */
    private function sladyPanelu(): array
    {
        return [
            'Panel moderacji',
            'Otwórz panel moderacji',
            'Wróć do Kuking',
            'data-tryb-panelu',
            'side-nav-moderacja',
            'side-nav-wejscie',
            'side-nav-powrot',
            'bottom-nav-panel',
            'panel-pasek',
            '/admin/',
        ];
    }
}
