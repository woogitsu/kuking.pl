<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zapisany wpis miał w zeszycie odnośnik do listy zeszytów, a nie akcję
 * usunięcia — mimo że `collections.unsave-post` działał od dawna (issue #776).
 *
 * `tests/Feature/ZeszytPrzyjmujeWpisyTest::test_wpis_da_sie_wyjac_z_zeszytu`
 * dowodzi tylko, że endpoint reaguje na bezpośredni DELETE — nie że
 * człowiek ma jak go dotknąć. Ten test szuka PRAWDZIWEGO formularza w HTML
 * (nie woła trasy z pominięciem widoku) i dopiero przez niego usuwa.
 *
 * `collections.save-post` i `collections.unsave-post` mają TEN SAM adres
 * (`/wpisy/{post}/zapisz`, różni je tylko metoda) — dlatego szukamy formularza
 * po realnym spoofingu `_method=DELETE`, nie po samym tekście adresu.
 */
final class ZeszytUsuwaZapisanyWpisTest extends TestCase
{
    use RefreshDatabase;

    public function test_w_zeszycie_jest_prawdziwy_formularz_usuwajacy_zapisany_wpis(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertRedirect();

        $zeszyt = $basia->defaultCollection();
        $html = $this->actingAs($basia)->get(route('collections.show', $zeszyt))->assertOk()->getContent();

        $this->assertDeleteFormExists($html, route('collections.unsave-post', $wpis), $zeszyt->getKey());

        // Ekran, w którym stoi ten wpis, NIE oferuje już samego odnośnika do
        // listy zeszytów w jego miejscu (to był stary, martwy stan).
        $this->assertStringNotContainsString('Masz to w zeszycie', $html);
        $this->assertStringContainsString('Usuń z tego zeszytu', $html);

        $this->actingAs($basia)
            ->delete(route('collections.unsave-post', $wpis), ['collection_id' => $zeszyt->getKey()])
            ->assertRedirect();

        $this->assertSame(0, $zeszyt->posts()->whereKey($wpis->getKey())->count());
    }

    public function test_usuniecie_z_jednego_zeszytu_zostawia_wpis_w_drugim(): void
    {
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $a = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'A', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'B', 'visibility' => 'private']);
        $a->posts()->attach($wpis->getKey());
        $b->posts()->attach($wpis->getKey());

        $html = $this->actingAs($basia)->get(route('collections.show', $a))->assertOk()->getContent();
        $this->assertDeleteFormExists($html, route('collections.unsave-post', $wpis), $a->getKey());

        $this->actingAs($basia)
            ->delete(route('collections.unsave-post', $wpis), ['collection_id' => $a->getKey()])
            ->assertRedirect();

        $this->assertSame(0, $a->posts()->whereKey($wpis->getKey())->count());
        $this->assertSame(1, $b->posts()->whereKey($wpis->getKey())->count());
    }

    public function test_obcy_widz_publicznego_zeszytu_nie_dostaje_akcji_usuwania(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $obcy = $this->user('obcy');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Publiczny', 'visibility' => 'public']);
        $zeszyt->posts()->attach($wpis->getKey());

        $html = $this->actingAs($obcy)->get(route('collections.show', $zeszyt))->assertOk()->getContent();

        $this->assertStringNotContainsString('Usuń z tego zeszytu', $html);
        $this->assertFalse(
            $this->hasDeleteForm($html, route('collections.unsave-post', $wpis)),
            'Obcy widz dostał formularz usuwający zawartość cudzego zeszytu.',
        );
    }

    /** Szuka formularza z podanym adresem, spoofingiem DELETE i właściwym collection_id. */
    private function assertDeleteFormExists(string $html, string $action, string $collectionId): void
    {
        $found = false;
        foreach ($this->deleteForms($html, $action) as $form) {
            if ($this->hiddenValue($form, 'collection_id') === $collectionId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Nie znaleziono formularza DELETE na {$action} z collection_id={$collectionId}.");
    }

    private function hasDeleteForm(string $html, string $action): bool
    {
        return $this->deleteForms($html, $action) !== [];
    }

    /** @return list<\DOMElement> */
    private function deleteForms(string $html, string $action): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($dom);
        $forms = [];
        foreach (self::elementyDom($xpath->query('//form[@action="'.$action.'"]')) as $form) {
            if ($this->hiddenValue($form, '_method') === 'DELETE') {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    private function hiddenValue(\DOMElement $form, string $name): ?string
    {
        $xpath = new \DOMXPath($form->ownerDocument);
        $nodes = $xpath->query('.//input[@name="'.$name.'"]', $form);

        return $nodes->length > 0 ? self::elementDom($nodes->item(0))->getAttribute('value') : null;
    }
}
