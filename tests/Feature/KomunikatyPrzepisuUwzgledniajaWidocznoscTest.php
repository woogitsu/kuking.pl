<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KomunikatyPrzepisuUwzgledniajaWidocznoscTest extends TestCase
{
    use RefreshDatabase;

    public static function widocznosci(): array
    {
        return [
            'private' => ['private', 'Przepis zapisany. Widzisz go tylko Ty.', 'Ten przepis widzisz tylko Ty.'],
            'followers' => ['followers', 'Przepis opublikowany dla osób, które Cię obserwują.', 'Ten przepis widzisz Ty oraz osoby, które Cię obserwują.'],
            'public' => ['public', 'Przepis opublikowany. Teraz ktoś może z niego ugotować.', null],
        ];
    }

    #[DataProvider('widocznosci')]
    public function test_post_potwierdza_rzeczywista_widocznosc(string $visibility, string $status, ?string $help): void
    {
        $author = $this->user('autor530');
        $this->actingAs($author)->post(route('recipes.store'), [
            'title' => 'Przepis z formularza',
            'przygotowanie_tekst' => 'Gotuj.',
            'visibility' => $visibility,
            'action' => 'publish',
        ])->assertSessionHasNoErrors()->assertRedirect()
            ->assertSessionHas('status', $status.' Możesz jeszcze dopisać szczegóły — wybierz „Dopisz szczegóły”.');

        $this->sprawdzStroneIDostep(Recipe::sole(), $visibility, $status, $help);
    }

    #[DataProvider('widocznosci')]
    public function test_livewire_potwierdza_rzeczywista_widocznosc(string $visibility, string $status, ?string $help): void
    {
        $author = $this->user('autor530');
        Livewire::actingAs($author)->test('recipe-wizard')
            ->set('title', 'Przepis z kreatora')->set('visibility', $visibility)
            ->set('steps.0.instruction', 'Gotuj.')->call('publish')
            ->assertHasNoErrors()->assertRedirect();
        $this->assertSame($status, session('status'));
        $this->sprawdzStroneIDostep(Recipe::sole(), $visibility, $status, $help);
    }

    private function sprawdzStroneIDostep(Recipe $recipe, string $visibility, string $status, ?string $help): void
    {
        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->status);
        $this->assertNotNull($recipe->published_at);
        $this->assertSame($visibility, $recipe->visibility);
        $url = route('recipes.show', $recipe);
        $page = $this->get($url)->assertOk()->assertSee($status);
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new \DOMXPath($doc);
        $links = $xpath->query('//a[@href="'.route('recipes.edit', $recipe).'"]');
        $this->assertSame(1, $links->length);
        $this->assertSame('Dopisz szczegóły', trim($links->item(0)->textContent));
        if ($help !== null) {
            $page->assertSee($help)->assertDontSee('data-podziel-sie', false)
                ->assertDontSee('Teraz ktoś może z niego ugotować.')
                ->assertDontSee('widzą tylko wybrane osoby')->assertDontSee('pustą stronę');
        } else {
            $page->assertSee('data-podziel-sie', false)->assertDontSee('podziel-sie-niedostepne', false);
        }

        $follower = $this->user('obserwujacy530');
        app(FollowUser::class)->handle($follower, $recipe->author);
        $other = $this->user('obcy530');
        $this->actingAs($follower)->get($url)->assertStatus($visibility === 'private' ? 403 : 200);
        $this->actingAs($other)->get($url)->assertStatus($visibility === 'public' ? 200 : 403);
        Auth::logout();
        $this->get($url)->assertStatus($visibility === 'public' ? 200 : 403);
    }

    #[DataProvider('widocznosci')]
    public function test_wspolna_instrukcja_wpisu_tez_rozroznia_widocznosc(string $visibility, string $status, ?string $help): void
    {
        $author = $this->user('wpis530');
        $post = Post::factory()->create(['author_id' => $author->getKey(), 'visibility' => $visibility]);
        $page = $this->actingAs($author)->get(route('posts.show', $post))->assertOk();
        if ($help !== null) {
            $page->assertSee(str_replace('przepis', 'wpis', $help))
                ->assertDontSee('data-podziel-sie', false)->assertDontSee('pustą stronę');
        } else {
            $page->assertSee('data-podziel-sie', false);
        }
    }
}
