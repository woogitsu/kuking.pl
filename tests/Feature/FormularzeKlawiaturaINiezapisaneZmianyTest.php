<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dwie luki formularzy na telefonie i przy przejściu między edytorami.
 *
 * #947 — bez `interactive-widget=resizes-content` klawiatura ekranowa zmniejsza
 * w Chrome tylko visual viewport; `@media (max-height: 40rem)` z
 * `marka-rama.css` nie widzi wtedy niskiego okna i obie belki zostają przypięte.
 * Zoom nie może zostać przy tym zablokowany. Geometrię pola z fokusem i belek
 * przy zmniejszonym oknie (390×844, 844×390, 768×500) mierzy w Chromium
 * `scripts/przegladarka/klawiatura-belki.test.mjs` — razem z regułą
 * `@media (max-height: 25rem)` z `marka-rama.css`, która odpina belki przy
 * niskim oknie niezależnie od szerokości.
 *
 * #899 — oba odnośniki z „Dopisz szczegóły” do kreatora są zwykłym GET-em,
 * a kreator czyta przepis z bazy. Odnośniki muszą być podpięte pod
 * `resources/js/niezapisane-zmiany.js` (ramka z pytaniem, ukryta do czasu
 * zmiany), zdanie przy nich ma mówić o tym także bez skryptu, a formularz
 * odesłany z błędami — oznaczony jako niezapisany. Samo zachowanie po
 * kliknięciu sprawdzają `resources/js/niezapisane-zmiany.test.mjs` (logika)
 * i `scripts/przegladarka/niezapisane-zmiany.test.mjs` (klik w Chromium).
 *
 * Test chodzi po WYRENDEROWANYM HTML-u (XPath), nie po pliku Blade.
 */
final class FormularzeKlawiaturaINiezapisaneZmianyTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    public function test_viewport_zmniejsza_uklad_pod_klawiatura_i_nie_blokuje_zoomu(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $meta = $this->xpath($html)->query('//head/meta[@name="viewport"]');

        $this->assertSame(1, $meta->length, 'Strona ma mieć dokładnie jeden meta viewport.');
        $czesci = array_map('trim', explode(',', $meta->item(0)->getAttribute('content')));

        $this->assertContains('interactive-widget=resizes-content', $czesci);
        $this->assertContains('width=device-width', $czesci);
        foreach ($czesci as $czesc) {
            $this->assertDoesNotMatchRegularExpression('/^(user-scalable\s*=\s*(no|0)|maximum-scale)/i', $czesc, 'Zoomu nie wolno blokować (#947).');
        }
    }

    public function test_odnosniki_do_kreatora_pytaja_o_niezapisane_zmiany(): void
    {
        $autor = $this->user('kreator899');
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        $this->actingAs($autor);

        $html = $this->get(route('recipes.edit', $przepis))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $kreator = route('recipes.details', $przepis->slug);

        $this->assertSame(1, $xp->query('//form[@id="formularz-szczegolow"]')->length);
        $this->assertSame(0, $xp->query('//form[@id="formularz-szczegolow"][@data-niezapisane-od-serwera]')->length,
            'Świeżo otwarty formularz nie może być z góry oznaczony jako zmieniony.');

        $odnosniki = $xp->query('//a[@data-niezapisane-formularz]');
        $this->assertSame(2, $odnosniki->length, 'Oba odnośniki do kreatora (górny i przy składnikach) muszą pytać.');

        foreach ($odnosniki as $a) {
            /** @var DOMElement $a */
            $this->assertSame($kreator, $a->getAttribute('href'), 'Bez skryptu odnośnik nadal prowadzi do kreatora.');
            $this->assertSame('formularz-szczegolow', $a->getAttribute('data-niezapisane-formularz'));

            $ramka = $xp->query('//*[@id="'.$a->getAttribute('data-niezapisane-ostrzezenie').'"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $ramka, 'Odnośnik wskazuje ramkę, której nie ma.');
            $this->assertTrue($ramka->hasAttribute('hidden'), 'Ramka nie może pytać, zanim ktoś coś zmieni.');
            $this->assertStringContainsString('Masz niezapisane zmiany', $ramka->textContent);
            $this->assertStringContainsString('„Zapisz zmiany”', $ramka->textContent, 'Opublikowany przepis zapisuje się przyciskiem „Zapisz zmiany”.');
            $this->assertSame(1, $xp->query('.//button[@type="button"][@data-niezapisane-zostan]', $ramka)->length);
            $this->assertSame(1, $xp->query('.//a[@href="'.$kreator.'"]', $ramka)->length, 'Z ramki da się świadomie przejść dalej.');

            $this->assertStringContainsString('ostatnią zapisaną wersję', $a->parentNode->textContent,
                'Bez skryptu zdanie przy odnośniku mówi, że kreator nie ma niezapisanych zmian.');
        }
    }

    public function test_formularz_odeslany_z_bledami_jest_niezapisany_a_szkic_mowi_o_zapisie_szkicu(): void
    {
        $autor = $this->user('kreator899b');
        $szkic = Recipe::factory()->for($autor, 'author')->draft()->create();
        $this->actingAs($autor);

        $html = $this->from(route('recipes.edit', $szkic))
            ->followingRedirects()
            ->put(route('recipes.update', $szkic), ['title' => '', 'action' => 'draft'])
            ->assertOk()->getContent();
        $this->assertStringContainsString('formularz-szczegolow', $html, 'Po błędzie nie wróciliśmy na ekran szczegółów.');
        $xp = $this->xpath($html);

        $this->assertSame(1, $xp->query('//form[@id="formularz-szczegolow"][@data-niezapisane-od-serwera]')->length,
            'Po błędzie walidacji pola pokazują old(), którego nie ma w bazie — skrypt ma zawsze zapytać.');
        $this->assertStringContainsString('„Zapisz szkic”', $xp->query('//*[@id="ostrzezenie-kreatora-gora"]')->item(0)->textContent,
            'Szkic zapisuje się bez publikacji — ramka nie może kierować do „Opublikuj”.');
    }
}
