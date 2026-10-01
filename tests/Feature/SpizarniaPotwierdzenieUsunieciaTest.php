<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PantryItem;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpizarniaPotwierdzenieUsunieciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pierwszy_klik_w_spizarni_rozwija_pytanie_zamiast_kasowac_produkt(): void
    {
        $user = $this->user();
        $produkt = $user->pantryItems()->create(['name' => 'Mąka pszenna', 'quantity_note' => '500 g']);
        $produkt->forceFill(['expires_on' => '2026-12-10', 'expiry_kind' => 'best_before', 'frozen' => true])->save();
        $przed = $produkt->refresh()->getAttributes();
        $response = $this->actingAs($user)->get(route('pantry.index'))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($dom);
        $details = $xpath->query('//details[@data-potwierdzenie-spizarni]');
        $this->assertNotFalse($details);
        $this->assertSame(1, $details->length, 'SPIZARNIA_2467_USUNIECIE_WYMAGA_PYTANIA: pierwszy klik musi rozwijać pytanie.');
        $element = $details->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);
        $this->assertFalse($element->hasAttribute('open'));
        $summary = $xpath->query('./summary', $element);
        $this->assertNotFalse($summary);
        $this->assertSame(1, $summary->length);
        $summaryElement = $summary->item(0);
        $this->assertInstanceOf(DOMElement::class, $summaryElement);
        $this->assertStringContainsString('Mąka pszenna', $summaryElement->textContent);
        $this->assertStringContainsString('Anuluj', $summaryElement->textContent);
        $formsInSummary = $xpath->query('./summary//form | ./summary//button | ./summary//a', $element);
        $this->assertNotFalse($formsInSummary);
        $this->assertSame(0, $formsInSummary->length);
        $forms = $xpath->query('./div/form', $element);
        $this->assertNotFalse($forms);
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame(route('pantry.destroy', $produkt), $form->getAttribute('action'));
        $methods = $xpath->query('./input[@name="_method" and @value="DELETE"]', $form);
        $tokens = $xpath->query('./input[@name="_token"]', $form);
        $this->assertNotFalse($methods);
        $this->assertNotFalse($tokens);
        $this->assertSame(1, $methods->length);
        $this->assertSame(1, $tokens->length);
        $this->assertSame($przed, $this->attributesFromDatabase($produkt));
        // Zwykły powrót do strony również nie kasuje ilości ani terminu.
        $this->get(route('pantry.index'))->assertOk();
        $this->assertSame($przed, $this->attributesFromDatabase($produkt));
    }

    /**
     * Każde wywołanie czyta nowy stan bazy po żądaniu HTTP.
     *
     * @phpstan-impure
     *
     * @return array<string, mixed>
     */
    private function attributesFromDatabase(PantryItem $produkt): array
    {
        return PantryItem::query()->findOrFail($produkt->getKey())->getAttributes();
    }

    public function test_potwierdzone_usuniecie_kasuje_tylko_wybrany_wlasny_produkt(): void
    {
        $user = $this->user();
        $produkt = $user->pantryItems()->create(['name' => 'Mąka']);
        $pozostaje = $user->pantryItems()->create(['name' => 'Jajka']);
        $this->actingAs($user)->delete(route('pantry.destroy', $produkt))->assertRedirect(route('pantry.index'));
        $this->assertDatabaseMissing('pantry_items', ['id' => $produkt->getKey()]);
        $this->assertDatabaseHas('pantry_items', ['id' => $pozostaje->getKey(), 'name' => 'Jajka']);
    }

    public function test_nazwa_w_pytaniu_jest_escapowana_a_cudzy_produkt_nie_jest_usuwany(): void
    {
        $user = $this->user();
        $nazwa = 'Mąka <script>alert("produkt")</script>';
        $produkt = $user->pantryItems()->create(['name' => $nazwa]);
        $this->actingAs($user)->get(route('pantry.index'))->assertOk()
            ->assertSee($nazwa)->assertDontSee('<script>alert("produkt")</script>', false);
        $cudzy = $this->user()->pantryItems()->create(['name' => 'Cudze jajka']);
        $this->delete(route('pantry.destroy', $cudzy))->assertForbidden();
        $this->assertDatabaseHas('pantry_items', ['id' => $cudzy->getKey()]);
        $this->assertDatabaseHas('pantry_items', ['id' => $produkt->getKey(), 'name' => $nazwa]);
    }
}
