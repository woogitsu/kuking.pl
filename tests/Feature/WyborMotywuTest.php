<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jasny/ciemny wygląd — właściciel zgłosił, że telefon sam przełączał
 * stronę w tryb nocny, choć nikt o to nie prosił (docs/DECISIONS.md, D-019).
 *
 * Jasny jest teraz motywem DOMYŚLNYM dla każdego — nowego konta i gościa —
 * niezależnie od tego, co ustawia system operacyjny odwiedzającego. Ciemny
 * włącza się WYŁĄCZNIE na jawne życzenie, atrybutem `data-theme="dark"`
 * na `<html>` (`resources/views/components/layout.blade.php`), nigdy przez
 * `prefers-color-scheme`.
 */
class WyborMotywuTest extends TestCase
{
    use RefreshDatabase;

    public function test_domyslnie_nowe_konto_i_gosc_dostaja_jasny_motyw(): void
    {
        // Gość — bez logowania, bez ciasteczka.
        $this->get(route('landing'))
            ->assertOk()
            ->assertDontSee('data-theme', false);

        // Nowe konto — kolumna `theme` musi wyjść z migracji jako 'light'.
        $basia = $this->user('basia');

        $this->assertSame('light', $basia->theme);

        $this->actingAs($basia)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-theme', false);
    }

    public function test_zalogowany_zmienia_motyw_na_ciemny_i_to_sie_zapisuje_na_koncie(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('theme.update'), ['theme' => 'dark'])
            ->assertRedirect();

        $this->assertSame('dark', $basia->fresh()->theme);

        // Widać to w KOLEJNYM żądaniu, nie tylko w bazie.
        $this->actingAs($basia)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-theme="dark"', false);
    }

    public function test_nieobslugiwana_wartosc_motywu_jest_odrzucana(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('theme.update'), ['theme' => 'niebieski'])
            ->assertStatus(302)
            ->assertSessionHasErrors('theme');

        $this->assertSame('light', $basia->fresh()->theme);
    }

    public function test_gosc_zmienia_motyw_i_wybor_przezywa_przeladowanie_strony(): void
    {
        $odpowiedz = $this->post(route('theme.update'), ['theme' => 'dark'])
            ->assertRedirect();

        $nazwaCiasteczka = (string) config('kuking.theme.cookie');

        $ciasteczko = collect($odpowiedz->headers->getCookies())
            ->first(static fn ($c) => $c->getName() === $nazwaCiasteczka);

        $this->assertNotNull(
            $ciasteczko,
            "Odpowiedź nie ustawiła ciasteczka „{$nazwaCiasteczka}” — gość straciłby wybór po zamknięciu karty.",
        );

        // Nowe „żądanie" — symulacja przeglądarki, która odsyła ciasteczko
        // dostane od serwera, tak jak zrobiłaby to przy odświeżeniu strony.
        $this->call('GET', route('landing'), [], [$nazwaCiasteczka => $ciasteczko->getValue()])
            ->assertOk()
            ->assertSee('data-theme="dark"', false);
    }

    /**
     * Przełącznik w stopce jest sama ikoną, bez widocznego napisu obok
     * (docs/DECISIONS.md, D-051 — świadomy wyjątek od AGENTS.md §5 na
     * decyzję właściciela, issue #205). `assertSee('Włącz ciemny wygląd')`
     * przestało więc sprawdzać cokolwiek widocznego — ten test sprawdza
     * zamiast tego, że przycisk NADAL MA nazwę dostępną (`aria-label`)
     * z dokładnie tym samym tekstem, co dawny widoczny napis. Regresja,
     * przed którą to broni: sama ikonka bez `aria-label` jest dla czytnika
     * ekranu przyciskiem bez nazwy — nienazwaną „kropką", której działania
     * nie da się zgadnąć.
     */
    public function test_przelacznik_w_stopce_ma_dostepna_nazwe_dla_goscia_i_zalogowanego(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('aria-label="Włącz ciemny wygląd"', false);

        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('aria-label="Włącz ciemny wygląd"', false);

        // Motyw ciemny → przycisk proponuje przejście na jasny, a nazwa
        // dostępna zmienia się razem z nim.
        $this->actingAs($basia)
            ->post(route('theme.update'), ['theme' => 'dark'])
            ->assertRedirect();

        $this->actingAs($basia)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('aria-label="Włącz jasny wygląd"', false);
    }

    /**
     * Kształt ikony pokazuje WYNIK kliknięcia, spójnie z tekstem
     * (docs/DECISIONS.md, D-051): jasny motyw → napis „Włącz ciemny
     * wygląd" → księżyc; ciemny motyw → napis „Włącz jasny wygląd" →
     * słońce. Sprawdzamy na kawałku ścieżki SVG unikalnym dla każdego
     * kształtu (`resources/views/components/ikona.blade.php`), bo obie
     * ikony renderują się jako zwykłe `<svg class="ikona">` bez innej
     * różnicy w znacznikach.
     *
     * Regresja, przed którą to broni: ktoś zostawia JEDEN kształt na oba
     * stany (np. przez pomyłkę przy kopiowaniu) — bez tego testu przeszłyby
     * wtedy zarówno test nazwy dostępnej, jak i test na `title`, bo żaden
     * z nich nie patrzy na samą ikonę.
     */
    public function test_ikona_przelacznika_zmienia_sie_razem_z_motywem(): void
    {
        $slonce = 'r="4.5"';
        $ksiezyc = 'M21 12.79A9 9';

        // Jasny (domyślny) — przycisk proponuje ciemny, więc pokazuje księżyc.
        $tresc = $this->get(route('landing'))->assertOk()->getContent();
        $this->assertStringContainsString($ksiezyc, $tresc);
        $this->assertStringNotContainsString($slonce, $tresc);

        // Ciemny — przycisk proponuje jasny, więc pokazuje słońce.
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('theme.update'), ['theme' => 'dark'])
            ->assertRedirect();

        $tresc = $this->actingAs($basia)->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString($slonce, $tresc);
        $this->assertStringNotContainsString($ksiezyc, $tresc);
    }

    /**
     * `title` niesie DOKŁADNIE ten sam tekst co `aria-label` — to jest
     * jedna z rzeczy, których nie wolno było poświęcić przy zamianie napisu
     * na samą ikonę (D-051): na telefonie nie ma najazdu kursorem, więc
     * `title` sam z siebie nikomu nie pomaga, ale musi się zgadzać z tym,
     * co czyta czytnik ekranu, a nie stać w sprzeczności.
     */
    public function test_przelacznik_w_stopce_ma_title_zgodny_z_aria_label(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('title="Włącz ciemny wygląd"', false);
    }

    /**
     * Arkusz stylów nie ma już reguły włączającej ciemny motyw z samego
     * `prefers-color-scheme` — jedyne wejście zostaje jawnym atrybutem.
     *
     * Komentarze w `tokens.css` TŁUMACZĄ tę decyzję i celowo wspominają
     * frazę „prefers-color-scheme" (opisując usterkę, którą ta decyzja
     * zamyka) — asercja na surowej treści trafiałaby więc we własne
     * uzasadnienie i oblewała niezależnie od tego, czy reguła istnieje.
     * Wycinamy blokowe komentarze CSS przed sprawdzeniem, tak jak
     * `BrakAtrybutowStyleWWidokachTest` wycina komentarze Blade.
     */
    public function test_arkusz_stylow_nie_wlacza_juz_ciemnego_motywu_z_samego_systemu(): void
    {
        $css = (string) file_get_contents(resource_path('css/tokens.css'));
        $bezKomentarzy = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertStringNotContainsString(
            'prefers-color-scheme',
            $bezKomentarzy,
            'tokens.css zawiera regułę @media (prefers-color-scheme), poza komentarzem — '
            .'ciemny motyw wciąż włączałby się sam, z systemu, bez jawnego wyboru użytkownika.',
        );

        // Pozytywna strona tej samej monety: jedyne wejście, jakie ma zostać.
        $this->assertStringContainsString(':root[data-theme="dark"]', $bezKomentarzy);
    }
}
