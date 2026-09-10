<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\TagPromotionSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rejestracja → pierwszy krok onboardingu (docs/research/AUDYT_60_PLUS.md,
 * ranking napraw pkt 3; kontrakt projektowy „register-onboarding-copy",
 * pozycja 9 listy „Co dopisać do scripts/dostepnosc.mjs").
 *
 * PROBLEM, KTÓRY TE TESTY PILNUJĄ
 * Rejestracja mówiła „Cztery pola i gotowe", po czym `RegisterController`
 * przekierowywał wprost na `onboarding.interests`, gdzie stało „Krok 1 z 3".
 * Dla człowieka, któremu przed chwilą powiedziano „gotowe", widok kolejnego
 * wieloetapowego kreatora czyta się jak „jednak coś nie wyszło" — mimo że
 * konto już istnieje i onboarding jest w całości opcjonalny
 * (`OnboardingController` — „Każdy krok da się pominąć i żaden nie blokuje
 * korzystania z serwisu").
 *
 * SCOPING przez `preg_match` na fragmencie strony wskazanym klasą CSS
 * dodaną w widoku — nie na całym HTML-u. Obie strony mają słowo „konto"
 * i „krok" w kilku miejscach (nagłówki, przyciski), więc asercja na całej
 * stronie złapałaby literę z zupełnie innego miejsca ekranu.
 */
class RejestracjaOnboardingKopiaTest extends TestCase
{
    use RefreshDatabase;

    private function fragmentPoKlasie(string $html, string $tag, string $klasa): string
    {
        $wzorzec = '/<'.$tag.'\b[^>]*\bclass="[^"]*\b'.preg_quote($klasa, '/').'\b[^"]*"[^>]*>(.*?)<\/'.$tag.'>/s';

        $this->assertSame(
            1,
            preg_match($wzorzec, $html, $trafienie),
            "Nie znalazłem <{$tag} class=\"{$klasa}\"> w odpowiedzi — asercja treści nie ma czego sprawdzić.",
        );

        return $trafienie[1];
    }

    // -----------------------------------------------------------------
    // Regresja 3c, część 1 — rejestracja zapowiada, co będzie dalej
    // -----------------------------------------------------------------

    public function test_rejestracja_zapowiada_ze_kolejne_kroki_sa_opcjonalne(): void
    {
        $response = $this->get('/register');
        $response->assertOk();

        $zapowiedz = $this->fragmentPoKlasie($response->getContent(), 'p', 'rejestracja-zapowiedz');

        $this->assertStringContainsString('gotowe', mb_strtolower($zapowiedz));
        $this->assertStringContainsString('opcjonalne', mb_strtolower($zapowiedz));
    }

    // -----------------------------------------------------------------
    // Regresja 3c, część 2 / Kontrakt projektowy 60+ (audyt, pozycja 9)
    // -----------------------------------------------------------------

    /**
     * Pierwszy krok onboardingu MUSI powiedzieć wprost: konto już istnieje,
     * dalsze ustawienia są opcjonalne. Sprawdzone na WARIANCIE Z TAGAMI
     * (`TagPromotionSeeder`), bo to jest droga, którą naprawdę przechodzi
     * nowe konto na produkcji — pusta lista promowanych tagów to osobny,
     * awaryjny stan tego samego ekranu.
     */
    public function test_pierwszy_krok_onboardingu_mowi_ze_konto_juz_istnieje_i_krok_jest_opcjonalny(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(TagPromotionSeeder::class);

        $nowa = $this->user('nowa');

        $response = $this->actingAs($nowa)->get(route('onboarding.interests'));
        $response->assertOk();

        $status = $this->fragmentPoKlasie($response->getContent(), 'p', 'onboarding-status-konta');
        $statusNiskieLitery = mb_strtolower($status);

        $this->assertStringContainsString('już działa', $statusNiskieLitery, 'Ekran nie mówi wprost, że konto już istnieje.');
        $this->assertStringContainsString('opcjonalne', $statusNiskieLitery, 'Ekran nie mówi wprost, że kroki są opcjonalne.');
    }

    /**
     * KONTRAKT PROJEKTOWY 60+ — „Pomiń" musi zostać WIDOCZNE, nie tylko
     * obecne w DOM-ie (np. schowane przez CSS albo `hidden`).
     */
    public function test_kontrakt_pomin_zostaje_widoczne_na_pierwszym_kroku_onboardingu(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(TagPromotionSeeder::class);

        $nowa = $this->user('nowa');

        $html = $this->actingAs($nowa)->get(route('onboarding.interests'))->getContent();

        $this->assertSame(
            1,
            preg_match('/<a\b([^>]*)>Pomiń ten krok<\/a>/s', $html, $trafienie),
            'Link „Pomiń ten krok" zniknął z pierwszego ekranu onboardingu.',
        );

        $atrybuty = $trafienie[1];

        $this->assertStringNotContainsString('hidden', $atrybuty, 'Link „Pomiń" ma atrybut/klasę ukrywającą go.');
        $this->assertDoesNotMatchRegularExpression(
            '/style="[^"]*display\s*:\s*none/i',
            $atrybuty,
            'Link „Pomiń" jest schowany przez `display: none` w stylu inline.',
        );
    }
}
