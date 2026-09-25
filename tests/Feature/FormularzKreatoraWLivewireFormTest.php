<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Livewire\Forms\PrzepisForm;
use App\Models\Recipe;
use App\Support\KreatorPrzepisu\KrokOPrzepisie;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Issue #1387, krok 3 — pola trudności, widoczności i „Skąd ten przepis”
 * kreatora przepisu w Livewire Form Object (`PrzepisForm`, właściwość `$form`).
 *
 * Zmienia się ścieżka pól w Livewire (`visibility` → `form.visibility`),
 * a więc klucze błędów i cele odnośników z podsumowania błędów
 * (`#f-form-visibility`). Te testy pilnują, że po tej zmianie:
 *  - odnośnik z podsumowania prowadzi do istniejącego pola i fokus ma gdzie
 *    wylądować (także przy grupie wyboru, która dotąd nie miała `id`),
 *  - skok z innego kroku prosi o fokus na tym samym identyfikatorze,
 *  - edycja istniejącego przepisu nie gubi widoczności ani pochodzenia,
 *  - karta sprzed wdrożenia nie nadpisze przepisu pustym formularzem.
 *
 * Fokusu w prawdziwej przeglądarce ten test nie mierzy — sprawdza to, od czego
 * fokus zależy: `href`, `id`, `tabindex`, ARIA i zdarzenie `kreator-fokus-pole`.
 */
class FormularzKreatoraWLivewireFormTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'recipe-wizard';

    public function test_kazde_pole_kroku_stoi_albo_w_formularzu_albo_w_komponencie(): void
    {
        $komponent = Livewire::actingAs($this->user('podzial_pol'))->test(self::COMPONENT)->instance();

        $this->assertInstanceOf(PrzepisForm::class, $komponent->form);

        foreach (KrokOPrzepisie::POLA as $pole) {
            $wFormularzu = in_array($pole, PrzepisForm::POLA, true);

            $this->assertSame($wFormularzu, property_exists($komponent->form, $pole), "Pole {$pole}: formularz.");
            $this->assertSame(! $wFormularzu, property_exists($komponent, $pole), "Pole {$pole}: komponent — nie może stać w dwóch miejscach naraz.");
        }
    }

    public function test_blad_pola_tekstowego_formularza_prowadzi_do_pola_z_tym_samym_id(): void
    {
        $komponent = Livewire::actingAs($this->user('blad_tekstu'))->test(self::COMPONENT)
            ->set('title', 'Pierogi')
            ->set('form.source_person', str_repeat('a', 121))
            ->assertHasErrors('form.source_person')
            // Błędny tekst zostaje w polu — dane nigdy nie znikają.
            ->assertSet('form.source_person', str_repeat('a', 121))
            ->assertSet('saveState', 'error');

        $xpath = $this->xpath($komponent->html());

        $this->assertSame(1, $xpath->query('//div[contains(@class, "error-summary")]//a[@href="#f-form-source_person"]')->length);

        $pole = $xpath->query('//*[@id="f-form-source_person"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $pole, 'Cel odnośnika z podsumowania musi istnieć w HTML.');
        $this->assertSame('true', $pole->getAttribute('aria-invalid'));
        $this->assertStringContainsString('f-form-source_person-error', $pole->getAttribute('aria-describedby'));
        $this->assertStringContainsString(
            'Zostaw najwyżej 120 znaków',
            (string) $xpath->query('//*[@id="f-form-source_person-error"]')->item(0)?->textContent,
        );
    }

    public function test_blad_grupy_wyboru_ma_cel_ktory_przyjmie_fokus(): void
    {
        $komponent = Livewire::actingAs($this->user('blad_grupy'))->test(self::COMPONENT)
            ->set('title', 'Pierogi')
            ->set('form.visibility', 'wszyscy-na-swiecie')
            ->assertHasErrors('form.visibility')
            ->assertSet('saveState', 'error');

        $xpath = $this->xpath($komponent->html());

        $this->assertSame(1, $xpath->query('//div[contains(@class, "error-summary")]//a[@href="#f-form-visibility"]')->length);

        $grupa = $xpath->query('//fieldset[@id="f-form-visibility"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $grupa, 'Grupa wyboru bez `id` = odnośnik z podsumowania prowadzi donikąd.');
        $this->assertSame('-1', $grupa->getAttribute('tabindex'), 'Bez tabindex="-1" odnośnik przewinie stronę, ale nie przeniesie fokusu.');
        $this->assertSame('true', $grupa->getAttribute('aria-invalid'));
        $this->assertSame('f-form-visibility-error', $grupa->getAttribute('aria-describedby'));
        $this->assertSame(
            'Zaznacz, kto ma widzieć ten przepis.',
            trim((string) $xpath->query('//*[@id="f-form-visibility-error"]')->item(0)?->textContent),
        );
    }

    public function test_grupa_wyboru_bez_bledu_nie_przechwytuje_fokusu(): void
    {
        $xpath = $this->xpath(Livewire::actingAs($this->user('bez_bledu'))->test(self::COMPONENT)->html());

        foreach (['difficulty', 'visibility', 'source_type'] as $pole) {
            $grupa = $xpath->query("//fieldset[@id='f-form-{$pole}']")->item(0);

            $this->assertInstanceOf(DOMElement::class, $grupa, "Grupa {$pole} ma stały identyfikator.");
            $this->assertFalse($grupa->hasAttribute('tabindex'), "Grupa {$pole} bez błędu nie jest przystankiem fokusu.");
            $this->assertFalse($grupa->hasAttribute('aria-invalid'));
        }
    }

    public function test_skok_z_innego_kroku_prosi_o_fokus_na_polu_formularza(): void
    {
        $komponent = Livewire::actingAs($this->user('skok_formularz'))->test(self::COMPONENT)
            ->set('title', 'Pierogi')
            ->set('form.source_url', 'ftp://example.invalid/przepis')
            ->assertHasErrors('form.source_url')
            ->set('step', 3);

        $this->assertSame(1, $komponent->instance()->stepForKey('form.source_url'));
        $this->assertSame(1, $komponent->instance()->stepForKey('form.visibility'));

        // Na kroku 3 pola nie ma w HTML, więc podsumowanie daje przycisk skoku,
        // a nie `href` donikąd (issue #747).
        $xpath = $this->xpath($komponent->html());
        $this->assertSame(0, $xpath->query('//a[@href="#f-form-source_url"]')->length);
        $przycisk = $xpath->query('//div[contains(@class, "error-summary")]//button[contains(@class, "error-summary-link")]');
        $this->assertSame(1, $przycisk->length);
        $this->assertStringContainsString('http://', (string) $przycisk->item(0)?->textContent);
        $this->assertStringContainsString("jumpToError(&#039;form.source_url&#039;)", $komponent->html());

        $komponent->call('jumpToError', 'form.source_url')
            ->assertSet('step', 1)
            ->assertDispatched('kreator-fokus-pole', pole: 'f-form-source_url')
            ->assertSet('form.source_url', 'ftp://example.invalid/przepis');

        $this->assertInstanceOf(DOMElement::class, $this->xpath($komponent->html())->query('//*[@id="f-form-source_url"]')->item(0));
    }

    public function test_edycja_przepisu_nie_gubi_widocznosci_ani_pochodzenia(): void
    {
        $autor = $this->user('edycja_formularz');
        $przepis = app(PublishRecipe::class)->handle($autor, [
            'title' => 'Pierogi',
            'visibility' => 'private',
            'difficulty' => 'hard',
            'source_type' => 'family',
            'source_person' => 'od mamy',
            'source_note' => 'Na Wigilię.',
            'family_since_year' => 1974,
        ], [], [['instruction' => 'Gotuj.']], true);

        Livewire::actingAs($autor)->test(self::COMPONENT, ['recipeId' => $przepis->getKey()])
            ->assertSet('form.visibility', 'private')
            ->assertSet('form.difficulty', 'hard')
            ->assertSet('form.source_type', 'family')
            ->assertSet('form.source_person', 'od mamy')
            ->assertSet('form.source_note', 'Na Wigilię.')
            ->assertSet('form.family_since_year', '1974')
            ->set('title', 'Pierogi ruskie')
            ->assertHasNoErrors()
            ->assertSet('saveState', 'saved');

        $po = Recipe::findOrFail($przepis->getKey());
        $this->assertSame('Pierogi ruskie', $po->title);
        $this->assertSame(
            ['private', 'hard', 'family', 'od mamy', 'Na Wigilię.', 1974],
            [$po->visibility, $po->difficulty, $po->source_type, $po->source_person, $po->source_note, $po->family_since_year],
        );
    }

    public function test_migawka_sprzed_formularza_nie_zapisuje_niczego(): void
    {
        $komponent = Livewire::actingAs($this->user('stara_karta'))->test(self::COMPONENT)->instance();

        // Bieżąca migawka przechodzi.
        $komponent->hydrate();

        // Migawka sprzed kroku 3 nie ma `wersjaStanu`, więc Livewire zostawia
        // wartość domyślną z klasy. Taka karta nie może dojść do autozapisu.
        $komponent->wersjaStanu = 0;

        try {
            $komponent->hydrate();
            $this->fail('Stara migawka przeszła przez hydrate() — autozapis nadpisałby przepis pustym formularzem.');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->getStatusCode());
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dom);
    }
}
