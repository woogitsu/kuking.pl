<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1381: pole „Chcesz coś dopisać?" w formularzu zgłoszenia treści
 * mówiło o limicie 2000 znaków dopiero po odrzuceniu przez serwer. Teraz
 * limit stoi przy polu od początku (bez JavaScriptu — test czyta sam HTML)
 * i jest powiązany z polem przez `aria-describedby`. Granica 2000/2001
 * sprawdzona realnym POST-em — liczba na ekranie ma się zgadzać z walidacją.
 */
class LimitDopiskuZgloszeniaWidocznyTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(): Post
    {
        return Post::factory()->create(['author_id' => $this->user('autor1381')->getKey()]);
    }

    public function test_limit_jest_widoczny_i_powiazany_z_polem_przed_wyslaniem(): void
    {
        $wpis = $this->wpis();

        $html = $this->actingAs($this->user('widz1381'))
            ->get(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $pole = self::elementDom($xpath->query('//textarea[@name="details"]')->item(0), 'Kontrola testu: pole dopisku musi być w formularzu.');

        $opisy = preg_split('/\s+/', trim($pole->getAttribute('aria-describedby')));
        $zdaniaOpisu = [];
        foreach ($opisy as $id) {
            $el = $xpath->query('//*[@id="'.$id.'"]')->item(0);
            $zdaniaOpisu[] = $el ? trim($el->textContent) : '';
        }

        $this->assertContains('Najwyżej 2000 znaków.', $zdaniaOpisu,
            'Limit ma być w opisie pola (aria-describedby), nie tylko gdzieś na stronie.');
        // Licznik informuje, nie obcina wklejonego tekstu (jak w #762).
        $this->assertFalse($pole->hasAttribute('maxlength'));
        $this->assertSame('2000', $pole->getAttribute('data-licznik'));
    }

    public function test_2000_znakow_przechodzi_a_2001_wraca_z_calym_tekstem(): void
    {
        $wpis = $this->wpis();
        $widz = $this->user('zglaszajacy1381');
        $adres = route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]);

        $zaDlugi = str_repeat('ż', 2001);
        $this->actingAs($widz)
            ->from(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]))
            ->post($adres, ['reason' => 'spam', 'details' => $zaDlugi])
            ->assertSessionHasErrors('details')
            ->assertSessionHasInput('details', $zaDlugi);
        $this->assertDatabaseCount('reports', 0);

        $this->actingAs($widz)
            ->post($adres, ['reason' => 'spam', 'details' => str_repeat('ż', 2000)])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reports', 1);
    }
}
