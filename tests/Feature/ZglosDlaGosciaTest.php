<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #2221: pomoc obiecuje „Zgłoś” przy treści, a gość nie widział niczego.
 *
 * Zwykłe zgłoszenie wymaga konta (trasa za `auth`), więc gość dostaje
 * odnośnik, który to mówi wprost, oraz — przy przepisie i wpisie — drogę
 * bez konta dla treści niezgodnej z prawem. Zalogowany zachowuje szybkie
 * „Zgłoś” bez dopisków.
 */
class ZglosDlaGosciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_widzi_droge_zgloszenia_przy_przepisie(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringContainsString(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]), $html);
        $this->assertStringContainsString('Zgłoś (po zalogowaniu)', $html);
        $this->assertStringContainsString(route('zglos.nielegalna'), $html);
    }

    public function test_gosc_widzi_droge_zgloszenia_przy_wpisie(): void
    {
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $html = (string) $this->get($wpis->url())->assertOk()->getContent();

        $this->assertStringContainsString(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]), $html);
        $this->assertStringContainsString('Zgłoś ten wpis (po zalogowaniu)', $html);
        $this->assertStringContainsString(route('zglos.nielegalna'), $html);
    }

    public function test_gosc_widzi_droge_zgloszenia_przy_komentarzu(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $komentarz = Comment::factory()->create([
            'post_id' => $wpis->getKey(),
            'author_id' => $this->user('komentujaca')->getKey(),
        ]);

        $html = (string) $this->get($wpis->url())->assertOk()->getContent();

        $this->assertStringContainsString(route('reports.create', ['type' => 'comment', 'id' => $komentarz->getKey()]), $html);
    }

    public function test_odnosnik_goscia_prowadzi_na_logowanie_a_nie_w_403(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $this->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]))
            ->assertRedirect(route('login'));
    }

    public function test_zalogowany_nie_dostaje_dopiskow_dla_goscia(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $html = (string) $this->actingAs($this->user('widz'))
            ->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringContainsString(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]), $html);
        $this->assertStringNotContainsString('Zgłoś (po zalogowaniu)', $html);
        $this->assertStringNotContainsString('data-zglos-goscia', $html);
    }

    public function test_pomoc_i_kontakt_nie_obiecuja_zgloszenia_bez_logowania(): void
    {
        foreach (['help', 'kontakt'] as $trasa) {
            $html = (string) $this->get(route($trasa))->assertOk()->getContent();

            $this->assertStringContainsString('Po zalogowaniu przy każdym przepisie i komentarzu', $html, $trasa);
            $this->assertStringContainsString(route('zglos.nielegalna'), $html, $trasa);
        }
    }
}
