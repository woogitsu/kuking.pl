<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stopka w poziomach (issue #205).
 *
 * Stopka przeszła z jednego rzędu odnośników na kilka poziomów: kolumny
 * pogrupowane tematycznie, a pod nimi cienki pasek techniczny (wersja +
 * przełącznik motywu). Grupowanie MIAŁO NIE zmienić zestawu odnośników —
 * ten test pilnuje właśnie tego: że żaden z odnośników, które stopka miała
 * przed przebudową, nie zniknął, i że „Twoje zgłoszenia" nadal pokazuje się
 * wyłącznie zalogowanym.
 *
 * Rozmiar metryczki wersji (8 px) i sama ikona przełącznika motywu bez
 * widocznego napisu to ŚWIADOME odstępstwo od AGENTS.md §5, zapisane jako
 * docs/DECISIONS.md, D-051 — te dwie rzeczy mają WŁASNE testy w
 * `WyborMotywuTest` (nazwa dostępna przełącznika) i niżej w tym pliku
 * (widoczność metryczki). Nie są tu ponownie odradzane w kodzie — decyzja
 * już zapadła, ten plik tylko pilnuje, żeby nie cofnęła się po cichu dalej,
 * niż właściciel się zgodził.
 */
class StopkaPoziomyTest extends TestCase
{
    use RefreshDatabase;

    /** Odnośniki, które stopka miała PRZED przebudową i ma mieć nadal — dla każdego. */
    private const ODNOSNIKI_DLA_KAZDEGO = [
        'kontakt' => 'Napisz do nas',
        'about' => 'O Kuking',
        'help' => 'Pomoc',
        'rules' => 'Zasady',
        'terms' => 'Regulamin',
        'privacy' => 'Prywatność',
        'zglos.nielegalna' => 'Zgłoś nielegalną treść',
    ];

    public function test_stopka_goscia_ma_wszystkie_odnosniki_sprzed_przebudowy(): void
    {
        $odpowiedz = $this->get(route('landing'))->assertOk();

        foreach (self::ODNOSNIKI_DLA_KAZDEGO as $trasa => $tekst) {
            $odpowiedz->assertSee('href="'.route($trasa).'"', false);
            $odpowiedz->assertSee($tekst);
        }

        // Gość nie ma żadnych zgłoszeń — odnośnik nie ma się mu pokazywać.
        $odpowiedz->assertDontSee('href="'.route('reports.mine').'"', false);
    }

    public function test_stopka_zalogowanego_ma_wszystkie_odnosniki_i_twoje_zgloszenia(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)
            ->get(route('home'))
            ->assertOk();

        foreach (self::ODNOSNIKI_DLA_KAZDEGO as $trasa => $tekst) {
            $odpowiedz->assertSee('href="'.route($trasa).'"', false);
            $odpowiedz->assertSee($tekst);
        }

        $odpowiedz
            ->assertSee('href="'.route('reports.mine').'"', false)
            ->assertSee('Twoje zgłoszenia');
    }

    /** Hasło marki nie było odnośnikiem i nie miało nim zostać — sprawdzamy, że przeżyło przebudowę. */
    public function test_stopka_ma_haslo_marki(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('Kuking — gotujemy po swojemu.');
    }

    /**
     * Metryczka wersji jest widoczna ZAWSZE (D-051): rozmiar 8 px jest
     * świadomym wyjątkiem, ukrycie nie. Sprawdzamy, że etykieta stoi w HTML-u
     * i że nie jest schowana ani atrybutem `hidden`, ani `display: none`
     * inline — dokładnie to, co dziś jest prawdą, ma zostać prawdą.
     */
    public function test_metryczka_wersji_jest_widoczna_nie_schowana(): void
    {
        $tresc = $this->get(route('landing'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="site-version"', $tresc);
        $this->assertMatchesRegularExpression(
            '/class="site-version-etap"[^>]*>Alfa 0\.1/',
            $tresc,
            'Etap produktu („Alfa 0.1") powinien stać w metryczce wersji.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/class="site-version"[^>]*\bhidden\b/',
            $tresc,
            'Metryczka wersji ma atrybut hidden — D-051 pozwala na mały rozmiar, nie na ukrycie.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/class="site-version"[^>]*style="[^"]*display:\s*none/',
            $tresc,
            'Metryczka wersji jest ukryta inline style — D-051 pozwala na mały rozmiar, nie na ukrycie.',
        );
    }

    /**
     * Kolumny stopki są pogrupowane w `<nav aria-label="...">`, każdy
     * z jawną nazwą tematu — to jest to, co pozwala czytnikowi ekranu
     * pominąć całą grupę jednym gestem (patrz `$rail` wyżej w layoucie,
     * ten sam wzorzec). Test pilnuje, że grupowanie nie zniknie po cichu
     * przy kolejnej zmianie stopki.
     */
    public function test_stopka_ma_pogrupowane_kolumny_odnosnikow(): void
    {
        $tresc = $this->get(route('landing'))
            ->assertOk()
            ->getContent();

        foreach (['O serwisie', 'Pomoc i kontakt', 'Sprawy formalne'] as $etykietaGrupy) {
            $this->assertStringContainsString(
                'aria-label="'.$etykietaGrupy.'"',
                $tresc,
                "Brak grupy stopki z aria-label=\"{$etykietaGrupy}\".",
            );
        }
    }
}
