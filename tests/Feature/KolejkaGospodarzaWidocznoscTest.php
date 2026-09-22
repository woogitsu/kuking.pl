<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\UnansweredContent;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KolejkaGospodarzaWidocznoscTest extends TestCase
{
    use RefreshDatabase;

    public function test_blokada_odpowiadajacego_z_odbiorca_dziala_w_obie_strony(): void
    {
        $host = $this->moderator();
        $queue = app(UnansweredContent::class);
        foreach ([false, true] as $reverse) {
            $owner = $this->user();
            $responder = $this->user();
            $recipe = Recipe::factory()->create(['author_id' => $owner->id]);
            $event = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $owner->id]);
            foreach (['recipe_id' => $recipe->id, 'cooked_event_id' => $event->id] as $column => $id) {
                Comment::factory()->create(['post_id' => null, $column => $id, 'author_id' => $responder->id]);
            }
            $this->assertFalse($queue->recipes($host)->whereKey($recipe->id)->exists());
            $this->assertFalse($queue->cooked($host)->whereKey($event->id)->exists());
            $this->block($reverse ? $responder : $owner, $reverse ? $owner : $responder);
            $this->assertTrue($queue->recipes($host)->whereKey($recipe->id)->exists());
            $this->assertTrue($queue->cooked($host)->whereKey($event->id)->exists());
        }
    }

    public function test_blokada_tylko_z_gospodarzem_nie_uniewaznia_odzewu_dla_autora(): void
    {
        $host = $this->moderator();
        $responder = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        Comment::factory()->create(['post_id' => null, 'recipe_id' => $recipe->id, 'author_id' => $responder->id]);
        $this->block($host, $responder);
        $this->assertFalse(app(UnansweredContent::class)->recipes($host)->whereKey($recipe->id)->exists());
    }

    public function test_odpowiedz_pod_ukrytym_lub_usunietym_korzeniem_nie_zamyka_kolejki(): void
    {
        $host = $this->moderator();
        $owner = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $owner->id]);
        $parent = Comment::factory()->create(['post_id' => null, 'recipe_id' => $recipe->id, 'author_id' => $owner->id]);
        Comment::factory()->create(['post_id' => null, 'recipe_id' => $recipe->id, 'author_id' => $host->id, 'parent_id' => $parent->id]);
        $queue = app(UnansweredContent::class);
        $this->assertFalse($queue->recipes($host)->whereKey($recipe->id)->exists());
        $parent->update(['status' => Comment::STATUS_HIDDEN]);
        $this->assertTrue($queue->recipes($host)->whereKey($recipe->id)->exists());
        $parent->update(['status' => Comment::STATUS_PUBLISHED]);
        $parent->delete();
        $this->assertTrue($queue->recipes($host)->whereKey($recipe->id)->exists());
    }

    public function test_status_autora_odpowiedzi_nie_jest_statusem_odbiorcy(): void
    {
        $host = $this->moderator();
        $responder = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        Comment::factory()->create(['post_id' => null, 'recipe_id' => $recipe->id, 'author_id' => $responder->id]);
        foreach ([User::STATUS_BANNED => true, User::STATUS_PENDING_DELETE => true, User::STATUS_SUSPENDED => false, User::STATUS_ERASED => false] as $status => $waiting) {
            $this->setStatus($responder, $status);
            $this->assertSame($waiting, app(UnansweredContent::class)->recipes($host)->whereKey($recipe->id)->exists(), $status);
        }
    }

    public function test_zamkniety_autor_przepisu_lub_kucharz_nie_czeka_na_odpowiedz(): void
    {
        $host = $this->moderator();
        $author = $this->user();
        $cook = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $event = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $cook->id]);
        $queue = app(UnansweredContent::class);
        foreach ([User::STATUS_BANNED, User::STATUS_PENDING_DELETE, User::STATUS_ERASED] as $status) {
            $this->setStatus($author, $status);
            $this->assertFalse($queue->recipes($host)->whereKey($recipe->id)->exists());
            $this->assertFalse($queue->cooked($host)->whereKey($event->id)->exists());
            $this->setStatus($author, User::STATUS_ACTIVE);
            $this->setStatus($cook, $status);
            $this->assertTrue($queue->recipes($host)->whereKey($recipe->id)->exists());
            $this->assertFalse($queue->cooked($host)->whereKey($event->id)->exists());
            $this->setStatus($cook, User::STATUS_ACTIVE);
        }
    }

    public function test_obserwowanie_otwiera_przepis_i_wykonanie_ale_blokada_ma_pierwszenstwo(): void
    {
        $host = $this->moderator();
        $author = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'visibility' => 'followers']);
        $event = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $this->user()->id]);
        $queue = app(UnansweredContent::class);
        $this->assertFalse($queue->recipes($host)->whereKey($recipe->id)->exists());
        $this->assertFalse($queue->cooked($host)->whereKey($event->id)->exists());
        DB::table('follows')->insert(['follower_id' => $host->id, 'followed_id' => $author->id, 'created_at' => now()]);
        $this->assertTrue($queue->recipes($host)->whereKey($recipe->id)->exists());
        $this->assertTrue($queue->cooked($host)->whereKey($event->id)->exists());
        $this->block($author, $host);
        $this->assertFalse($queue->recipes($host)->whereKey($recipe->id)->exists());
        $this->assertFalse($queue->cooked($host)->whereKey($event->id)->exists());
    }

    public function test_wykonanie_szkicu_ukrytego_i_usunietego_przepisu_nie_przecieka_przez_moderatora(): void
    {
        $host = $this->moderator();
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id]);
        $event = CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $this->user()->id]);
        $queue = app(UnansweredContent::class);
        $this->assertTrue($queue->cooked($host)->whereKey($event->id)->exists());
        foreach (['draft', 'hidden'] as $status) {
            $recipe->update(['status' => $status]);
            $this->assertFalse($queue->cooked($host)->whereKey($event->id)->exists());
        }
        $recipe->update(['status' => Recipe::STATUS_PUBLISHED]);
        $recipe->delete();
        $this->assertFalse($queue->cooked($host)->whereKey($event->id)->exists());
    }

    public function test_pokaz_wiecej_zachowuje_typ_a_link_otwiera_wlasciwe_komentarze(): void
    {
        $host = $this->moderator();
        $author = $this->user();
        $cook = $this->user();
        $recipes = Recipe::factory()->count(26)->create(['author_id' => $author->id]);
        foreach ($recipes as $recipe) {
            CookedEvent::factory()->create(['recipe_id' => $recipe->id, 'user_id' => $cook->id]);
        }
        foreach (['przepisy', 'ugotowane'] as $type) {
            $first = $this->actingAs($host)->get(route('admin.unanswered', ['typ' => $type]))->assertOk();
            $items = $first->viewData('items');
            $this->assertCount(25, $items);
            $next = $items->nextPageUrl();
            $this->assertNotNull($next);
            $this->assertStringContainsString('typ='.$type, $next);
            $first->assertSee('Pokaż więcej')->assertSee($next);
            $subject = $items->first();
            $url = $type === 'przepisy' ? route('recipes.show', $subject->slug) : route('cooked.show', $subject);
            $first->assertSee($url.'#komentarze');
            $second = $this->get($next)->assertOk();
            $remaining = $second->viewData('items');
            $this->assertCount(1, $remaining);
            $this->assertFalse($remaining->hasMorePages());
            $this->assertEmpty(array_intersect($items->pluck('id')->all(), $remaining->pluck('id')->all()));
        }
    }

    private function setStatus(User $user, string $status): void
    {
        $user->forceFill(['status' => $status, 'data_erased_at' => $status === User::STATUS_ERASED ? now() : null])->save();
        $this->assertSame($status, $user->fresh()->status);
    }

    private function block(User $blocker, User $blocked): void
    {
        DB::table('blocks')->insert(['blocker_id' => $blocker->id, 'blocked_id' => $blocked->id, 'created_at' => now()]);
    }
}
