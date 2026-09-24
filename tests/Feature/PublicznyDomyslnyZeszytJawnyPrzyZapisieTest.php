<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publiczny domyślny zeszyt ujawnia także PRZYSZŁE szybkie zapisy (issue #1400).
 *
 * „Zapisuję” bez wyboru zeszytu trafia do domyślnego zeszytu. Formularz edycji
 * mówił tylko o dzisiejszej zawartości, a sam przycisk nie mówił, dokąd zapisuje.
 * Człowiek, który raz świadomie pokazał „Zapisane”, kolejnymi kliknięciami
 * pokazywał innym zalogowanym więcej, niż zamierzał.
 *
 * Testy sprawdzają wyrenderowany HTML i pełną drogę przez trasy — nie czytają
 * źródeł. Kontrola dodatnia: `scripts/kontrole-negatywne-alfa08.py`
 * („Edycja domyślnego zeszytu bez skutku dla przyszłych zapisów”).
 */
final class PublicznyDomyslnyZeszytJawnyPrzyZapisieTest extends TestCase
{
    use RefreshDatabase;

    private const PRZYSZLE = 'i wszystko, co zapiszesz tu później';

    private const CEL = 'Ten zeszyt widzą inne zalogowane osoby.';

    public function test_edycja_domyslnego_zeszytu_mowi_o_przyszlych_szybkich_zapisach(): void
    {
        $wlasciciel = $this->user('wlasciciel_domyslny');
        $domyslny = $wlasciciel->defaultCollection();
        $inny = $wlasciciel->collections()->create(['name' => 'Na święta', 'visibility' => 'private']);

        $this->actingAs($wlasciciel)->get(route('collections.edit', $domyslny))
            ->assertOk()
            ->assertSee('Przycisk „Zapisuję” wkłada tu każdy przepis i wpis', false)
            ->assertSee(self::PRZYSZLE, false);

        // Zwykły zeszyt nie jest celem szybkiego zapisu — zostaje prosty.
        $this->actingAs($wlasciciel)->get(route('collections.edit', $inny))
            ->assertOk()
            ->assertDontSee(self::PRZYSZLE, false);
    }

    public function test_pelna_droga_publiczny_domyslny_szybki_zapis_i_powrot_do_prywatnego(): void
    {
        $wlasciciel = $this->user('wlasciciel_zapisow');
        $obcy = $this->user('obcy_widz');
        $domyslny = $wlasciciel->defaultCollection();

        $przepis = Recipe::factory()->create(['title' => 'Pierogi ruskie babci']);
        $wpis = Post::factory()->create(['body' => 'Upiekłam dziś chleb na zakwasie.']);
        // Własny prywatny wpis właściciela — jemu wolno go zapisać, obcemu nie wolno go zobaczyć.
        $prywatny = Post::factory()->private()->create([
            'author_id' => $wlasciciel->getKey(),
            'body' => 'Notatka tylko dla mnie o schabie.',
        ]);

        // Wariant prywatny: przy szybkim przycisku nie ma dodatkowego zdania.
        $this->actingAs($wlasciciel)->get($przepis->url())->assertOk()->assertDontSee(self::CEL, false);
        $this->actingAs($wlasciciel)->get($wpis->url())->assertOk()->assertDontSee(self::CEL, false);

        $this->actingAs($wlasciciel)->patch(route('collections.update', $domyslny), [
            'name' => $domyslny->name,
            'visibility' => 'public',
        ])->assertRedirect(route('collections.show', $domyslny));

        // Wariant publiczny: przycisk mówi, dokąd zapisze i kto to zobaczy.
        $this->assertSzybkiZapisNazywaCel($przepis->url(), route('collections.save', $przepis->slug), 'cel-zapisu-'.$przepis->getKey(), $domyslny->name);
        $this->assertSzybkiZapisNazywaCel($wpis->url(), route('collections.save-post', $wpis), 'cel-zapisu-wpis-'.$wpis->getKey(), $domyslny->name);

        // Szybkie zapisy BEZ collection_id — dokładnie to, co wysyła przycisk.
        $this->from($przepis->url())->post(route('collections.save', $przepis->slug))->assertSessionHasNoErrors();
        $this->from($wpis->url())->post(route('collections.save-post', $wpis))->assertSessionHasNoErrors();
        $this->from($prywatny->url())->post(route('collections.save-post', $prywatny))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('collection_items', ['collection_id' => $domyslny->getKey(), 'recipe_id' => $przepis->getKey()]);
        $this->assertDatabaseHas('collection_items', ['collection_id' => $domyslny->getKey(), 'post_id' => $wpis->getKey()]);
        $this->assertDatabaseHas('collection_items', ['collection_id' => $domyslny->getKey(), 'post_id' => $prywatny->getKey()]);

        // Obcy zalogowany widzi to, do czego ma prawo — i nic ponad to.
        $this->actingAs($obcy)->get(route('collections.show', $domyslny))
            ->assertOk()
            ->assertSee('Pierogi ruskie babci')
            ->assertSee('Upiekłam dziś chleb na zakwasie.')
            ->assertDontSee('Notatka tylko dla mnie o schabie.');

        // Powrót do „Tylko ja” zamyka dostęp, zapisy zostają.
        $this->actingAs($wlasciciel)->patch(route('collections.update', $domyslny), [
            'name' => $domyslny->name,
            'visibility' => 'private',
        ])->assertRedirect(route('collections.show', $domyslny));

        $this->actingAs($obcy)->get(route('collections.show', $domyslny))->assertForbidden();
        $this->assertDatabaseCount('collection_items', 3);
        $this->actingAs($wlasciciel)->get(route('collections.show', $domyslny))
            ->assertOk()
            ->assertSee('Pierogi ruskie babci');

        // Po powrocie do prywatnego przycisk znów jest prosty.
        $inny = Recipe::factory()->create();
        $this->actingAs($wlasciciel)->get($inny->url())->assertOk()->assertDontSee(self::CEL, false);
    }

    private function assertSzybkiZapisNazywaCel(string $strona, string $akcja, string $id, string $nazwa): void
    {
        $html = $this->get($strona)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);

        // Szybki formularz: ta akcja, bez wyboru zeszytu.
        $formularze = $xpath->query('//form[@action="'.$akcja.'"][not(.//input[@name="collection_id"])]');
        $this->assertCount(1, $formularze, 'Szybki formularz „Zapisuję” jest na stronie.');
        $formularz = $formularze->item(0);

        $wskazowka = $xpath->query('.//p[@id="'.$id.'"]', $formularz);
        $this->assertCount(1, $wskazowka, 'Wskazówka stoi przy szybkim przycisku.');
        $tekst = $wskazowka->item(0)->textContent;
        $this->assertStringContainsString('„'.$nazwa.'”', $tekst);
        $this->assertStringContainsString(self::CEL, $tekst);

        $this->assertCount(1, $xpath->query('.//button[@type="submit"][@aria-describedby="'.$id.'"]', $formularz),
            'Czytnik ekranu czyta wskazówkę razem z przyciskiem.');
    }
}
