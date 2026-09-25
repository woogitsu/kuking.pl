<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NowyZeszytDokonczenieTest extends TestCase
{
    use RefreshDatabase;

    public function test_od_tresci_przez_nowy_zeszyt_do_swiadomego_zapisu(): void
    {
        $owner = $this->user();
        foreach ([Recipe::factory()->create(), Post::factory()->create()] as $content) {
            $html = $this->actingAs($owner)->get($content->url())->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $links = (new \DOMXPath($dom))->query('//a[normalize-space(.)="Załóż nowy zeszyt"]');
            $this->assertSame(1, $links->length);
            $createUrl = self::elementDom($links->item(0))->getAttribute('href');
            parse_str((string) parse_url($createUrl, PHP_URL_QUERY), $context);
            $this->assertSame((string) $content->id, $context['save_id'] ?? null, 'Droga zakładania zeszytu musi zachować konkretną treść.');
            $page = $this->get($createUrl)->assertOk();
            $page->assertSee('name="save_id" value="'.$content->id.'"', false);
            $created = $this->from($createUrl)->post(route('collections.store'), [...$context, 'name' => 'Nowy '.$content->id, 'visibility' => 'private'])->assertRedirect();
            $book = $owner->collections()->where('name', 'Nowy '.$content->id)->firstOrFail();
            $this->assertSame(0, $book->recipes()->count() + $book->posts()->count());
            $continuation = $this->get($created->headers->get('Location'))->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$continuation->getContent());
            $forms = (new \DOMXPath($dom))->query('//form[@data-dokoncz-zapis]');
            $this->assertSame(1, $forms->length);
            $form = self::elementDom($forms->item(0));
            $fields = [];
            foreach (self::elementyDom((new \DOMXPath($dom))->query('.//input[@name]', $form)) as $input) {
                $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            $this->post($form->getAttribute('action'), $fields)->assertRedirect(route('collections.show', $book));
            $this->assertSame(1, $book->recipes()->count() + $book->posts()->count());
            $this->get(route('collections.show', $book))->assertOk()->assertDontSee('data-dokoncz-zapis', false);
        }
    }

    public function test_blad_nazwy_zachowuje_dane_i_cel_a_dwie_karty_nie_zamieniaja_celow(): void
    {
        $owner = $this->user();
        $a = Recipe::factory()->create();
        $b = Recipe::factory()->create();
        $contextA = ['save_type' => 'recipe', 'save_id' => $a->id];
        $contextB = ['save_type' => 'recipe', 'save_id' => $b->id];
        $url = route('collections.index', $contextA);
        $this->actingAs($owner)->get($url)->assertOk();
        $this->get(route('collections.index', $contextB))->assertOk();
        $data = [...$contextA, 'name' => 'X', 'description' => 'Mój opis', 'visibility' => 'public'];
        $this->from($url)->post(route('collections.store'), $data)->assertSessionHasErrors('name')->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Mój opis')->assertSee('name="save_id" value="'.$a->id.'"', false)->assertSee('value="public"', false);
        $data['name'] = 'Zeszyt z pierwszej karty';
        $created = $this->post(route('collections.store'), $data)->assertRedirect();
        $this->get($created->headers->get('Location'))->assertOk()->assertSee($a->title)->assertDontSee($b->title);
        $this->from($url)->post(route('collections.store'), $data)->assertSessionHasErrors('name');
        $this->assertSame(1, $owner->collections()->count());
    }

    public function test_niedostepna_tresc_nie_ujawnia_tytulu_i_nie_blokuje_utworzenia_zeszytu(): void
    {
        $owner = $this->user();
        $recipe = Recipe::factory()->create(['title' => 'Tajny tytuł spoza zeszytu']);
        $context = ['save_type' => 'recipe', 'save_id' => $recipe->id];
        $this->actingAs($owner)->get(route('collections.index', $context))->assertOk();
        $recipe->update(['visibility' => 'private']);
        $created = $this->post(route('collections.store'), [...$context, 'name' => 'Nowy pusty', 'visibility' => 'private'])->assertRedirect();
        $this->get($created->headers->get('Location'))->assertOk()->assertSee('Ta treść nie jest już dostępna.')->assertDontSee($recipe->title)->assertDontSee('data-dokoncz-zapis', false);
        $book = $owner->collections()->firstOrFail();
        $this->post(route('collections.save', $recipe->slug), ['collection_id' => $book->id, 'open_collection' => 1])->assertForbidden();
        $this->assertSame(0, $book->recipes()->count());
        $recipe->delete();
        $this->get($created->headers->get('Location'))->assertOk()->assertDontSee($recipe->title);
    }

    public function test_zwykle_zakladanie_i_obcy_zeszyt_nie_dostaja_formularza_dokonczenia(): void
    {
        $owner = $this->user();
        $created = $this->actingAs($owner)->post(route('collections.store'), ['name' => 'Bez kontekstu', 'visibility' => 'public'])->assertRedirect();
        $this->get($created->headers->get('Location'))->assertOk()->assertDontSee('data-dokoncz-zapis', false);
        $recipe = Recipe::factory()->create();
        $other = $this->user();
        $this->actingAs($other)->get($created->headers->get('Location').'?save_type=recipe&save_id='.$recipe->id)->assertOk()->assertDontSee('data-dokoncz-zapis', false);
        $this->post(route('collections.save', $recipe->slug), ['collection_id' => $owner->collections()->first()->id, 'open_collection' => 1])->assertSessionHasErrors('collection_id');
        $this->get(route('collections.index', ['save_type' => 'recipe', 'save_id' => 'nie-uuid']))->assertOk();
    }
}
