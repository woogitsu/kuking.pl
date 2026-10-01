<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lista „Co mam w domu” nie wypycha strony w bok (WCAG 1.4.10, 320 px).
 *
 * Zmierzone `scripts/dostepnosc.mjs` (paczka S, #2393): przy 320 px
 * i czcionce przeglądarki 200% strona listy miała 332 px zamiast 320. Kolumna
 * tekstu karty produktu (nazwa, ilość, termin, stan) była elementem flex bez
 * `min-width: 0`, a dwa zagnieżdżone dopełnienia (sekcja i karta) zostawiały
 * na nią 140 px — element nie zwężał się poniżej najdłuższego wyrazu.
 *
 * Ten test pilnuje przyczyny w HTML-u (układu nie da się zmierzyć w PHP);
 * pomiar układu robi automat w przeglądarce.
 *
 * @bez-kontroli-dodatniej Sprawdza obecność klasy w wyrenderowanym HTML-u; zachowanie układu mierzy przeglądarka w scripts/dostepnosc.mjs.
 */
class SpizarniaListaNieWypychaStronyTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolumna_tekstu_karty_produktu_moze_sie_zwezic(): void
    {
        $ja = $this->user();
        $ja->pantryItems()->create(['name' => 'marchewki', 'quantity_note' => '6 sztuk']);

        $html = (string) $this->actingAs($ja)->get(route('pantry.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<li class="card" data-produkt>\s*<div class="flex flex-wrap[^"]*">\s*(?:<!--.*?-->\s*)?<div class="min-w-0">\s*<span class="text-lg">marchewki</span>~s',
            $html,
            'Kolumna tekstu karty produktu straciła `min-w-0` — przy 320 px i czcionce przeglądarki 200% lista wypchnie stronę w bok.',
        );
    }
}
