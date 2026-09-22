<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Decyzje właściciela z 20.09.2026 do #858 — punkty 1 i 2.
 *
 * DECYZJA 1: filtr i „Pokaż kolejne…” mają WŁASNY koszyk limitera,
 * 300 żądań / 10 minut — osobny od zapisu (`ustawienia`, 30/10), bo dziś
 * dzielą z nim jeden budżet. Człowiek, który przegląda tagi szukając
 * właściwego (filtruje, doładowuje, filtruje inaczej), zjada ten sam
 * budżet, co zapis — a zapis ma zostać przy 30/10 bez rozluźnienia.
 *
 * DECYZJA 2: odmowa 429 nie może skasować zaznaczonych, jeszcze
 * niezapisanych tagów. To osobna zasada, niezależna od liczb w limicie —
 * AGENTS.md §5, „poprawne dane nigdy nie znikają". Hojny koszyk tylko
 * odsuwa moment trafienia w limit, nie usuwa go.
 */
class TagiPrzegladanieOsobnyLimiterTest extends TestCase
{
    use RefreshDatabase;

    private int $pozycja = 0;

    private function promowany(string $nazwa): Tag
    {
        $tag = Tag::factory()->create([
            'name' => $nazwa,
            'normalized_name' => Tag::znormalizujNazwe($nazwa),
        ]);
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $this->pozycja++]);

        return $tag;
    }

    private function otworz(User $user): string
    {
        return $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();
    }

    private function polaUkryte(string $html, string $nazwa): ?string
    {
        preg_match('/<input type="hidden" name="'.preg_quote($nazwa, '/').'" value="([^"]*)"/', $html, $m);

        return $m[1] ?? null;
    }

    private function filtruj(User $user, string $html, string $szukaj): TestResponse
    {
        return $this->actingAs($user)->put(route('settings.tags.przegladaj'), [
            'akcja' => 'filtruj',
            'form_scope' => $this->polaUkryte($html, 'form_scope'),
            'ile' => $this->polaUkryte($html, 'ile'),
            'szukaj' => $szukaj,
            'tags' => [],
        ]);
    }

    private function zapisz(User $user, string $html, array $tagi): TestResponse
    {
        return $this->actingAs($user)->put(route('settings.tags.update'), [
            'akcja' => 'zapisz',
            'form_scope' => $this->polaUkryte($html, 'form_scope'),
            'ile' => $this->polaUkryte($html, 'ile'),
            'szukaj' => '',
            'tags' => $tagi,
        ]);
    }

    /**
     * DECYZJA 1, CZĘŚĆ A: przeglądanie (filtrowanie) przeżywa więcej niż
     * 30 żądań na 10 minut — próg zapisu. Dziś oba dzielą koszyk
     * `ustawienia` (30,10), więc 31. żądanie filtrujące odbija się o 429
     * dokładnie tak samo jak zapis.
     */
    public function test_przegladanie_przezywa_wiecej_niz_trzydziesci_zadan(): void
    {
        $user = $this->user();
        $this->promowany('Zupa pomidorowa');
        $strona = $this->otworz($user);

        $odpowiedz = null;
        for ($i = 0; $i < 50; $i++) {
            $odpowiedz = $this->filtruj($user, $strona, 'zupa');
        }

        $this->assertNotNull($odpowiedz);
        $odpowiedz->assertStatus(302, 'Pięćdziesiąte żądanie przeglądania odbiło się o limit zapisu — koszyki nie są rozdzielone.');
    }

    /**
     * DECYZJA 1, CZĘŚĆ B: zapis ZOSTAJE przy 30/10 — jego ochrona się nie
     * rozluźnia. Kontrola metody pomiaru: gdyby ktoś przy okazji podniósł
     * limit zapisu, ten test by to złapał.
     */
    public function test_zapis_nadal_odbija_sie_o_trzydziesci_na_dziesiec_minut(): void
    {
        $user = $this->user();
        $tag = $this->promowany('Zupa pomidorowa');
        $strona = $this->otworz($user);

        $odpowiedz = null;
        for ($i = 0; $i <= 30; $i++) {
            $odpowiedz = $this->zapisz($user, $strona, [$tag->getKey()]);
        }

        $this->assertNotNull($odpowiedz);
        $odpowiedz->assertStatus(429, 'Limit zapisu zniknął albo się rozluźnił — ma zostać przy 30/10.');
    }

    /**
     * DECYZJA 2: prawdziwa odmowa 429 na przeglądaniu nie kasuje zaznaczeń,
     * które człowiek zrobił, ale jeszcze nie zapisał. Wymuszamy PRAWDZIWY
     * 429 (nie atrapę) tym samym sposobem co reszta limitera w tej bazie
     * kodu (`LimitZapytanNieZjadaTekstuTest`) i sprawdzamy, co zostaje
     * w odpowiedzi.
     */
    public function test_429_przy_przegladaniu_nie_kasuje_zaznaczonych_tagow(): void
    {
        $user = $this->user();
        $zupa = $this->promowany('Zupa pomidorowa');
        $ciasto = $this->promowany('Ciasto drożdżowe');
        $strona = $this->otworz($user);
        $token = $this->polaUkryte($strona, 'form_scope');
        $ile = $this->polaUkryte($strona, 'ile');

        $wyslijZZaznaczeniem = function () use ($user, $token, $ile, $zupa): TestResponse {
            return $this->actingAs($user)->put(route('settings.tags.przegladaj'), [
                'akcja' => 'filtruj',
                'form_scope' => $token,
                'ile' => $ile,
                'szukaj' => 'ciasto',
                // Człowiek zdążył zaznaczyć zupę, zanim przefiltrował na
                // „ciasto" — to zaznaczenie NIE jest jeszcze zapisane.
                'tags' => [$zupa->getKey()],
            ]);
        };

        $odpowiedz = null;
        for ($i = 0; $i < 400; $i++) {
            $odpowiedz = $wyslijZZaznaczeniem();
            if ($odpowiedz->getStatusCode() === 429) {
                break;
            }
        }

        $this->assertNotNull($odpowiedz);
        $odpowiedz->assertStatus(429, 'Test nie wymusił prawdziwej odmowy 429 — kontrola metody pomiaru zawiodła.');

        // Zaznaczenie zupy ma zostać WIDOCZNE na odzyskanym ekranie —
        // niezależnie od tego, że filtr akurat pokazywałby samo ciasto.
        $odpowiedz->assertSee((string) $zupa->getKey(), escape: false);
        $odpowiedz->assertSee('Wyślij jeszcze raz');

        // Zapis jeszcze się nie wydarzył — 429 odbija PRZED kontrolerem.
        $this->assertFalse($user->fresh()->isFollowingTag($zupa));
        $this->assertFalse($user->fresh()->isFollowingTag($ciasto));
    }
}
