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

    /**
     * Odnośniki, które stopka miała PRZED przebudową i ma mieć nadal — dla każdego.
     *
     * „O Kuking" jest dziś „O kuKING" i NIE jest jednym napisem w HTML-u:
     * dwukolorowy zapis nazwy (`docs/brand/GLOS_MARKI.md` §2) rozbija słowo na
     * `ku` + `<strong>KING</strong>` plus wersję dla czytnika ekranu. Dlatego
     * sprawdzamy tu część STAŁĄ etykiety razem z adresem, a samo słowo —
     * osobnym testem niżej. Wpisanie tu „O kuKING" oblewałoby na czymś,
     * czego w HTML-u nigdy nie ma.
     */
    private const ODNOSNIKI_DLA_KAZDEGO = [
        'kontakt' => 'Napisz do nas',
        'about' => 'O ',
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

    /**
     * Hasło marki nie było odnośnikiem i nie miało nim zostać — sprawdzamy,
     * że przeżyło przebudowę.
     *
     * Nazwa w haśle jest zapisana dwukolorowo (GLOS_MARKI §2), więc w HTML-u
     * stoi komponent, a nie napis „Kuking". Sprawdzamy trzy rzeczy osobno:
     * że akapit hasła istnieje, że nazwa jest w nim komponentem (a nie
     * zwykłym tekstem, który by ominął dwukolorowy zapis) i że resztę hasła
     * człowiek przeczyta.
     */
    public function test_stopka_ma_haslo_marki(): void
    {
        $odpowiedz = $this->get(route('landing'))->assertOk();

        $odpowiedz->assertSee('<p class="site-footer-haslo"><span class="kuking-word">', false);
        $odpowiedz->assertSeeText('gotujemy po swojemu.');
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
        // Etykieta czytana Z KONFIGURACJI, nie wpisana tu na pamięć. Ten test
        // pilnuje, że etap produktu STOI w metryczce — a nie że akurat dziś
        // brzmi „Alfa 0.1". Wersja z definicji się zmienia (cyfra rośnie przy
        // każdej widocznej zmianie), a strażnik, który trzeba poprawiać przy
        // każdym wydaniu, zostaje prędzej czy później poprawiony bezmyślnie.
        $etap = (string) config('kuking.wersja.etykieta');

        $this->assertNotSame('', $etap, 'Konfiguracja nie podaje etapu produktu.');

        $this->assertMatchesRegularExpression(
            '/class="site-version-etap"[^>]*>'.preg_quote($etap, '/').'/',
            $tresc,
            'Etap produktu („'.$etap.'”) powinien stać w metryczce wersji.',
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
