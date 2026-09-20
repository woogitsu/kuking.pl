<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZawieszoneKontoPrywatneCzynnosciTest extends TestCase
{
    use RefreshDatabase;

    public static function socialPages(): array
    {
        return [['profile.show', 'autor926'], ['social.followers', 'gospodarz926'], ['social.following', 'gospodarz926'], ['discover', null]];
    }

    #[DataProvider('socialPages')]
    public function test_follow_form_respects_policy(string $route, ?string $parameter): void
    {
        $author = $this->user('autor926');
        $host = $this->user('gospodarz926');
        app(FollowUser::class)->handle($host, $author);
        app(FollowUser::class)->handle($author, $host);
        Post::factory()->create(['author_id' => $author->id]);
        $viewer = $this->user('widz926');
        $page = route($route, $parameter);
        $action = route('social.follow', 'autor926');
        $this->actingAs($viewer);
        $this->assertGreaterThan(0, $this->forms($this->get($page)->assertOk()->getContent(), $action));
        $viewer->suspend();
        $html = $this->actingAs($viewer)->get($page)->assertOk()->getContent();
        $this->assertStringContainsString(route('profile.show', 'autor926'), $html);
        $this->assertSame(0, $this->forms($html, $action), 'Zawieszone konto widzi formularz obserwowania.');
        $this->from($page)->post($action)->assertSessionHasErrors('konto');
        $this->assertFalse($viewer->fresh()->isFollowing($author));
    }

    public function test_post_comment_and_reply_forms_respect_policy(): void
    {
        $author = $this->user();
        $viewer = $this->user();
        $post = Post::factory()->create(['author_id' => $author->id]);
        Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id, 'body' => 'Istniejący komentarz.']);
        $action = route('posts.comment', $post);
        $page = route('posts.show', $post);
        $this->actingAs($viewer);
        $this->assertSame(2, $this->forms($this->get($page)->assertOk()->getContent(), $action));
        $viewer->suspend();
        $html = $this->actingAs($viewer)->get($page)->assertOk()->getContent();
        $this->assertStringContainsString('Istniejący komentarz.', $html);
        $this->assertSame(0, $this->forms($html, $action), 'Zawieszone konto widzi formularz komentarza lub odpowiedzi.');
        $this->post($action, ['body' => 'Nie publikuj.'])->assertSessionHasErrors('konto');
        $this->assertDatabaseMissing('comments', ['body' => 'Nie publikuj.']);
    }

    public function test_suspended_user_can_save_and_remove_recipes_and_posts_privately(): void
    {
        $author = $this->user();
        $viewer = $this->suspended();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $post = Post::factory()->create(['author_id' => $author->id]);
        $this->actingAs($viewer);
        foreach ([['collections.save', 'collections.unsave', $recipe->slug, 'recipe_id', $recipe->id], ['collections.save-post', 'collections.unsave-post', $post, 'post_id', $post->id]] as [$save, $remove, $parameter, $column, $id]) {
            $this->post(route($save, $parameter))->assertRedirect()->assertSessionHasNoErrors();
            $collection = $viewer->collections()->where('is_default', true)->sole();
            $this->assertSame('private', $collection->visibility);
            $this->assertDatabaseHas('collection_items', ['collection_id' => $collection->id, $column => $id]);
            $this->delete(route($remove, $parameter))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseMissing('collection_items', ['collection_id' => $collection->id, $column => $id]);
        }
        $this->assertDatabaseMissing('notifications', ['actor_id' => $viewer->id]);
    }

    public function test_suspended_user_can_create_private_notebook_and_delete_it(): void
    {
        $viewer = $this->suspended();
        $this->actingAs($viewer)->post(route('collections.store'), ['name' => 'Moje obiady', 'visibility' => 'private'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $collection = $viewer->collections()->where('name', 'Moje obiady')->sole();
        $this->delete(route('collections.destroy', $collection))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
    }

    public function test_suspension_does_not_allow_public_notebook_publication_or_foreign_notebook(): void
    {
        $author = $this->user();
        $viewer = $this->suspended();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $public = Collection::create(['owner_id' => $viewer->id, 'name' => 'Publiczny', 'visibility' => 'public']);
        $foreign = Collection::create(['owner_id' => $author->id, 'name' => 'Cudzy', 'visibility' => 'private']);
        $this->actingAs($viewer);
        $this->post(route('collections.store'), ['name' => 'Publiczny nowy', 'visibility' => 'public'])->assertForbidden();
        $this->post(route('collections.save', $recipe->slug), ['collection_id' => $public->id])->assertForbidden();
        $this->post(route('collections.save', $recipe->slug), ['collection_id' => $foreign->id])->assertSessionHasErrors('collection_id');
        $this->assertDatabaseMissing('collections', ['name' => 'Publiczny nowy']);
        $this->assertDatabaseMissing('collection_items', ['recipe_id' => $recipe->id]);
        $html = $this->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="'.$public->id.'"', $html);
    }

    public function test_suspended_user_can_mark_unmark_and_reset_only_current_recipe(): void
    {
        $viewer = $this->suspended();
        $recipe = Recipe::factory()->create();
        $step = RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 0, 'instruction' => 'Mieszaj.']);
        $key = 'gotowanie.'.$recipe->id.'.zrobione';
        $this->actingAs($viewer)->withSession(['gotowanie.inny.zrobione' => ['inny-krok']]);
        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas($key, [$step->id]);
        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 0])
            ->assertSessionHasNoErrors()->assertSessionHas($key, []);
        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 1]);
        $html = $this->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();
        $this->assertSame(1, $this->forms($html, route('cooking.reset', $recipe->slug)));
        $this->post(route('cooking.reset', $recipe->slug))->assertRedirect(route('cooking.show', $recipe->slug))
            ->assertSessionHasNoErrors()->assertSessionMissing($key)->assertSessionHas('gotowanie.inny.zrobione', ['inny-krok']);
    }

    public function test_private_permissions_do_not_bypass_content_visibility_or_publication_block(): void
    {
        $viewer = $this->suspended();
        $recipe = Recipe::factory()->create(['visibility' => 'private']);
        RecipeStep::create(['recipe_id' => $recipe->id, 'position' => 0, 'instruction' => 'Prywatny krok.']);
        $this->actingAs($viewer);
        $this->post(route('collections.save', $recipe->slug))->assertForbidden();
        $this->post(route('cooking.zaznacz', $recipe->slug), ['krok' => 1, 'zrobiono' => 1])->assertForbidden();
        $this->post(route('cooking.reset', $recipe->slug))->assertForbidden();
        $this->post(route('posts.store'), ['body' => 'Nie publikuj.'])->assertSessionHasErrors('konto');
        $this->assertDatabaseMissing('posts', ['body' => 'Nie publikuj.']);
    }

    private function suspended(): User
    {
        $user = $this->user();
        $user->suspend();

        return $user;
    }

    public function test_follow_permission_refreshes_between_reads_and_write(): void
    {
        $viewer = $this->user('widz926');
        $author = $this->user('autor926');
        $page = route('profile.show', 'autor926');
        $action = route('social.follow', 'autor926');
        $this->actingAs($viewer);
        $this->assertSame(1, $this->forms($this->get($page)->assertOk()->getContent(), $action));
        app(BlockUser::class)->handle($author, $viewer);
        $this->get($page)->assertForbidden();
        $this->post($action)->assertForbidden();
        $this->assertFalse($viewer->fresh()->isFollowing($author));
    }

    public function test_suspended_follower_has_no_unfollow_form_and_expired_suspension_restores_it(): void
    {
        $author = $this->user('autor926');
        $viewer = $this->user('widz926');
        app(FollowUser::class)->handle($viewer, $author);
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $viewer->suspend(now()->addDay());
        $this->actingAs($viewer);
        $action = route('social.unfollow', 'autor926');
        foreach ([route('profile.show', 'autor926'), route('recipes.show', $recipe->slug)] as $page) {
            $this->assertSame(0, $this->forms($this->get($page)->assertOk()->getContent(), $action));
        }
        $this->delete($action)->assertSessionHasErrors('konto');
        $this->assertTrue($viewer->fresh()->isFollowing($author));
        $this->travel(2)->days();
        $this->assertSame(1, $this->forms($this->get(route('profile.show', 'autor926'))->assertOk()->getContent(), $action));
    }

    public function test_private_notebook_choice_and_validation_preserve_entered_data(): void
    {
        $viewer = $this->suspended();
        $private = Collection::create(['owner_id' => $viewer->id, 'name' => 'Prywatny', 'visibility' => 'private']);
        $recipe = Recipe::factory()->create();
        $this->actingAs($viewer);
        $html = $this->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$private->id.'"', $html);
        $this->post(route('collections.save', $recipe->slug), ['collection_id' => $private->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('collection_items', ['collection_id' => $private->id, 'recipe_id' => $recipe->id]);
        $this->from(route('collections.index'))->post(route('collections.store'), ['name' => 'X', 'description' => 'Zachowaj mój opis.', 'visibility' => 'private'])
            ->assertSessionHasErrors('name')->assertSessionHasInput('description', 'Zachowaj mój opis.');
        $html = $this->get(route('collections.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Zachowaj mój opis.', $html);
        $this->assertStringNotContainsString('value="public"', $html);
    }

    public function test_suspended_user_cannot_save_posts_to_public_notebook_or_delete_foreign_one(): void
    {
        $viewer = $this->suspended();
        $author = $this->user();
        $public = Collection::create(['owner_id' => $viewer->id, 'name' => 'Publiczny', 'visibility' => 'public']);
        $foreign = Collection::create(['owner_id' => $author->id, 'name' => 'Cudzy', 'visibility' => 'private']);
        $post = Post::factory()->create(['author_id' => $author->id]);
        $this->actingAs($viewer)->post(route('collections.save-post', $post), ['collection_id' => $public->id])->assertForbidden();
        $this->delete(route('collections.destroy', $foreign))->assertForbidden();
        $this->assertDatabaseHas('collections', ['id' => $foreign->id]);
        $this->assertDatabaseMissing('collection_items', ['collection_id' => $public->id]);
    }

    public function test_reset_works_for_guest_and_active_user_and_unknown_recipe_is_missing(): void
    {
        $recipe = Recipe::factory()->create();
        $key = 'gotowanie.'.$recipe->id.'.zrobione';
        $this->withSession([$key => ['krok']])->post(route('cooking.reset', $recipe->slug))->assertSessionMissing($key);
        $this->actingAs($this->user())->withSession([$key => ['krok']])->post(route('cooking.reset', $recipe->slug))->assertSessionMissing($key);
        $this->post(route('cooking.reset', 'nie-ma-takiego-przepisu'))->assertNotFound();
    }

    public function test_closed_account_cannot_use_private_write_exceptions(): void
    {
        $viewer = $this->user();
        $viewer->ban();
        $recipe = Recipe::factory()->create();
        $this->actingAs($viewer)->post(route('collections.save', $recipe->slug))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseMissing('collections', ['owner_id' => $viewer->id]);
    }

    private function forms(string $html, string $action): int
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return (new DOMXPath($dom))->query('//form[@action="'.$action.'"]')->length;
    }
}
