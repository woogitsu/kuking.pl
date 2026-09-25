<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\UnansweredContent;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KolejkaGospodarzaTest extends TestCase
{
    use RefreshDatabase;

    public function test_przepis_i_wykonanie_znikaja_dopiero_po_cudzej_odpowiedzi(): void
    {
        $host = $this->moderator();
        $author = $this->user();
        $cook = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $event = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $cook->id]);
        foreach ([['przepisy', 'recipe_id', $recipe, $author], ['ugotowane', 'cooked_event_id', $event, $cook]] as [$type, $column, $subject, $owner]) {
            $url = route('admin.unanswered', ['typ' => $type]);
            $this->actingAs($host)->get($url)->assertOk()->assertViewHas('items', fn ($items) => $items->pluck('id')->contains($subject->id));
            $own = Comment::factory()->create([$column => $subject->id, 'post_id' => null, 'author_id' => $owner->id]);
            $this->get($url)->assertViewHas('items', fn ($items) => $items->pluck('id')->contains($subject->id));
            $response = Comment::factory()->create([$column => $subject->id, 'post_id' => null, 'author_id' => $host->id, 'parent_id' => $own->id]);
            $this->get($url)->assertViewHas('items', fn ($items) => ! $items->pluck('id')->contains($subject->id));
            $response->delete();
            $this->get($url)->assertViewHas('items', fn ($items) => $items->pluck('id')->contains($subject->id));
        }
    }

    public function test_prywatny_przepis_i_jego_wykonanie_nie_trafiaja_do_panelu(): void
    {
        $host = $this->moderator();
        $public = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $private = Recipe::factory()->create(['author_id' => $this->user()->id, 'visibility' => 'private']);
        $visible = CookedEvent::factory()->create(['recipe_id' => $public->id, 'user_id' => $this->user()->id]);
        $hidden = CookedEvent::factory()->create(['recipe_id' => $private->id, 'user_id' => $this->user()->id]);
        foreach ([['przepisy', $public, $private], ['ugotowane', $visible, $hidden]] as [$type, $yes, $no]) {
            $this->actingAs($host)->get(route('admin.unanswered', ['typ' => $type]))->assertOk()
                ->assertViewHas('items', fn ($items) => $items->pluck('id')->contains($yes->id) && ! $items->pluck('id')->contains($no->id));
        }
    }

    public function test_wykonanie_czeka_od_publikacji_a_nie_daty_gotowania(): void
    {
        $host = $this->moderator();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $older = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $this->user()->id, 'created_at' => now()->subDays(2)]);
        $newer = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $this->user()->id, 'cooked_at' => now()->subYear()]);
        $this->actingAs($host)->get(route('admin.unanswered', ['typ' => 'ugotowane']))->assertOk()
            ->assertViewHas('items', fn ($items) => $items->pluck('id')->all() === [$older->id, $newer->id]);
    }

    public function test_reczny_post_nie_omija_prywatnosci_po_otwarciu_listy(): void
    {
        $host = $this->moderator();
        $post = Post::factory()->create(['author_id' => $this->user()->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        $this->actingAs($host)->get(route('admin.unanswered'))->assertOk()->assertViewHas('wpisy', fn ($items) => $items->contains('id', $post->id));
        $post->update(['visibility' => 'private']);
        $this->post(route('admin.unanswered.reply', $post), ['body' => 'Odpowiedz'])->assertNotFound();
        $this->assertSame(0, $post->allComments()->count());
        $post->update(['visibility' => 'public']);
        $this->post(route('admin.unanswered.reply', $post), ['body' => 'Odpowiedz'])->assertRedirect();
        $this->assertSame(1, $post->allComments()->count());
    }

    public function test_mediana_liczy_odzew_a_nie_wlasne_dopiski(): void
    {
        $host = $this->moderator();
        $owner = $this->user();
        $post = Post::factory()->create(['author_id' => $owner->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subHours(10)]);
        Comment::factory()->create(['post_id' => $post->id, 'author_id' => $owner->id, 'created_at' => now()->subHours(9)]);
        $queue = app(UnansweredContent::class);
        $this->assertNull($queue->medianPostResponseHours($host));
        $comment = Comment::factory()->create(['post_id' => $post->id, 'author_id' => $host->id, 'created_at' => now()->subHours(6)]);
        $this->assertSame(4.0, $queue->medianPostResponseHours($host));
        $comment->delete();
        $this->assertNull($queue->medianPostResponseHours($host));
    }

    /**
     * Regresja #372: mediana zakładki „Wpisy” liczyła także pytania, więc
     * szybko obsłużone pytanie zaniżało czas reakcji na dania. Każda zakładka
     * ma teraz własną medianę.
     */
    public function test_mediana_wpisow_liczy_tylko_dania_a_pytania_maja_wlasna(): void
    {
        config(['kuking.questions.enabled' => true]);
        $host = $this->moderator();
        $dish = Post::factory()->create(['author_id' => $this->user()->id, 'published_at' => now()->subHours(10)]);
        Comment::factory()->create(['post_id' => $dish->id, 'author_id' => $host->id, 'created_at' => now()->subHours(6)]);
        $question = Post::factory()->question()->create(['author_id' => $this->user()->id, 'published_at' => now()->subHours(10)]);
        Comment::factory()->create(['post_id' => $question->id, 'author_id' => $host->id, 'created_at' => now()->subHours(9)]);

        $queue = app(UnansweredContent::class);
        $this->assertSame(4.0, $queue->medianPostResponseHours($host));
        $this->assertSame(1.0, $queue->medianQuestionResponseHours($host));

        $this->actingAs($host)->get(route('admin.unanswered'))->assertOk()->assertViewHas('medianaReakcji', 4.0);
        $this->get(route('admin.unanswered', ['typ' => 'pytania']))->assertOk()
            ->assertViewHas('medianaReakcji', 1.0)
            ->assertSee('Mediana oczekiwania na odpowiedź: 1 h', false)
            ->assertSee('mediana dla pytań z ostatnich 30 dni', false);
    }

    /** Dopisek pod cudzym komentarzem nie jest odpowiedzią na pytanie — tak jak w kolejce `questions()`. */
    public function test_mediana_pytan_liczy_tylko_glowne_odpowiedzi(): void
    {
        $host = $this->moderator();
        $answered = Post::factory()->question()->create(['author_id' => $this->user()->id, 'published_at' => now()->subHours(10)]);
        Comment::factory()->create(['post_id' => $answered->id, 'author_id' => $host->id, 'created_at' => now()->subHours(9)]);
        $owner = $this->user();
        $onlyReply = Post::factory()->question()->create(['author_id' => $owner->id, 'published_at' => now()->subHours(10)]);
        $own = Comment::factory()->create(['post_id' => $onlyReply->id, 'author_id' => $owner->id, 'created_at' => now()->subHours(9)]);
        Comment::factory()->create(['post_id' => $onlyReply->id, 'author_id' => $host->id, 'parent_id' => $own->id, 'created_at' => now()->subHours(8)]);

        $queue = app(UnansweredContent::class);
        $this->assertSame(1.0, $queue->medianQuestionResponseHours($host));
        $this->assertTrue($queue->questions($host)->whereKey($onlyReply->id)->exists());
        $this->assertNull($queue->medianPostResponseHours($host));
    }

    public function test_odpowiedz_z_istniejacego_formularza_zamyka_nowe_kolejki(): void
    {
        $host = $this->moderator();
        $owner = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $owner->id]);
        $event = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $this->user()->id]);
        foreach ([['przepisy', route('recipes.comment', $recipe), $recipe], ['ugotowane', route('cooked.comment', $event), $event]] as [$type, $url, $subject]) {
            $this->actingAs($host)->get(route('admin.unanswered', ['typ' => $type]))->assertViewHas('items', fn ($items) => $items->contains('id', $subject->id));
            $this->post($url, ['body' => 'Dziękuję za opis przygotowania.'])->assertRedirect();
            $this->get(route('admin.unanswered', ['typ' => $type]))->assertViewHas('items', fn ($items) => ! $items->contains('id', $subject->id));
        }
    }
}
