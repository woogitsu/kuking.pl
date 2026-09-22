<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Krok porcji: setne, nie ułamki (decyzja właściciela z 20.09.2026, #750).
 *
 * STAN ZASTANY (pomiar stanowiska `gpt/skladniki`)
 * Formularz deklarował `step="0.5"`, a walidacja serwera dopuszczała
 * `1.25` — pole było więc NIEPRAWIDŁOWE wobec własnej deklaracji
 * (`checkValidity()` w przeglądarce zwracał `false` dla wartości, którą
 * baza już miała). Przy trzech miejscach po przecinku `1.255` zapisywało
 * się po cichu jako `1.26`, bez słowa dla autora.
 *
 * DECYZJA
 * Krok to 0,01 (setne) — tyle, ile udźwignie kolumna `servings`,
 * `decimal(6,2)`. `1.25` przechodzi (istniejące dane w bazie się nie
 * psują), `1.255` jest ODRZUCONE komunikatem mówiącym, co wpisać zamiast
 * cichego zaokrąglenia.
 *
 * CO PILNUJE TEN PLIK
 *  1. `1.25` przechodzi walidację i zapisuje się bez zmian.
 *  2. `1.255` jest odrzucone, komunikat jest po polsku i mówi, co zrobić.
 *  3. Wpisana wartość NIE ZNIKA po odrzuceniu (old() ją oddaje).
 *  4. `step` w wyrenderowanym HTML-u (`0.01`) zgadza się z tym, co
 *     naprawdę przepuszcza walidacja — na obu stronach, które stawiają
 *     to pole zwykłym POST-em: dodawanie jednostronicowe i edycja.
 */
final class PorcjeKrokSetnychTest extends TestCase
{
    use RefreshDatabase;

    private function podstawoweDane(): array
    {
        return [
            'title' => 'Zupa krem z dyni',
            'skladniki_tekst' => '',
            'przygotowanie_tekst' => 'Ugotuj dynię do miękkości i zblenduj z bulionem.',
            'visibility' => 'public',
            'action' => 'publish',
        ];
    }

    public function test_setne_1_25_przechodzi_i_zapisuje_sie_bez_zmian(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('recipes.store'), [...$this->podstawoweDane(), 'servings' => '1.25'])
            ->assertSessionHasNoErrors();

        $przepis = Recipe::where('title', 'Zupa krem z dyni')->firstOrFail();

        $this->assertSame(1.25, (float) $przepis->servings);
    }

    public function test_trzy_miejsca_po_przecinku_sa_odrzucone_komunikatem_co_zrobic(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('recipes.store'), [...$this->podstawoweDane(), 'servings' => '1.255'])
            ->assertSessionHasErrors('servings');

        $komunikat = app('session.store')->get('errors')->getBag('default')->first('servings');

        $this->assertNotSame('', $komunikat);
        $this->assertStringNotContainsString('validation.', $komunikat, 'Nie wolno pokazać surowego klucza tłumaczenia.');

        // Komunikat mówi, CO ZROBIĆ (AGENTS.md §5) — nie tylko, że jest źle.
        $mowiCoZrobic = str_contains($komunikat, 'wpisz')
            || str_contains($komunikat, 'Wpisz')
            || str_contains($komunikat, 'zamiast');
        $this->assertTrue($mowiCoZrobic, "Komunikat nie mówi, co zrobić: \"{$komunikat}\"");

        $this->assertNull(
            Recipe::where('title', 'Zupa krem z dyni')->first(),
            '1.255 nie miało prawa zapisać się po cichu jako 1.26.',
        );
    }

    public function test_wpisana_wartosc_nie_znika_po_odrzuceniu(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('recipes.store'), [...$this->podstawoweDane(), 'servings' => '1.255'])
            ->assertSessionHasErrors('servings');

        $this->assertSame('1.255', app('session.store')->getOldInput('servings'));
    }

    #[DataProvider('stronyZPolemPorcji')]
    public function test_step_w_html_zgadza_sie_z_walidacja(string $trasa, callable $zbudujTrase): void
    {
        $basia = $this->user('basia');
        $url = $zbudujTrase($basia);

        $html = (string) $this->actingAs($basia)->get($url)->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $pole = '//input[@name="servings"]';

        $this->assertSame(1, $xpath->query($pole)->length, "Brak pola servings na stronie: {$trasa}");
        $this->assertSame('0.01', $xpath->evaluate('string('.$pole.'/@step)'), "step nie zgadza się z walidacją serwera na: {$trasa}");
        $this->assertSame('0.5', $xpath->evaluate('string('.$pole.'/@min)'));
    }

    public static function stronyZPolemPorcji(): array
    {
        return [
            'dodawanie jednostronicowe' => [
                'dodaj/przepis/jedna-strona',
                fn () => route('recipes.create.simple'),
            ],
            'edycja przepisu' => [
                'edycja przepisu',
                function ($autor) {
                    $przepis = Recipe::factory()->for($autor, 'author')->create(['servings' => 1.25]);

                    return route('recipes.edit', $przepis);
                },
            ],
        ];
    }
}
