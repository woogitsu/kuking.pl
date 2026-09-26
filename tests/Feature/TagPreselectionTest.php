<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TagPreselectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_tag_survives_real_login_and_is_rendered_as_form_input(): void
    {
        $tag = Tag::factory()->create(['name' => 'Sernik']);
        $user = User::factory()->create()->refresh();
        $url = route('posts.create', ['tag' => $tag->slug]);

        $this->get($url)->assertRedirect(route('login'));
        $this->post(route('login'), ['login' => $user->email, 'password' => 'haslo-testowe-123'])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertViewHas('tagNames', ['Sernik'])
            ->assertSee('name="tag_names[]" value="Sernik"', false);
    }

    public function test_merged_tag_uses_only_an_active_canonical_destination(): void
    {
        $target = Tag::factory()->create();
        $old = Tag::factory()->create(['status' => Tag::STATUS_MERGED, 'merged_into_tag_id' => $target->id]);
        $this->actingAs(User::factory()->create()->refresh());
        $url = route('posts.create', ['tag' => $old->slug]);
        $this->get($url)->assertOk()->assertViewHas('tagNames', [$target->name]);

        // Od #996 baza nie pozwala ukryć celu scalenia (wyzwalacz
        // `tags_scalenie_jednym_skokiem_trg`). Obrona w kontrolerze zostaje,
        // bo po rollbacku tej migracji gwarancję trzyma znowu tylko PHP —
        // więc stan sprzed bariery odtwarzamy z wyłączonym wyzwalaczem.
        // ALTER TABLE jest w transakcji testu i cofa się razem z nią.
        DB::statement('ALTER TABLE tags DISABLE TRIGGER tags_scalenie_jednym_skokiem_trg');
        $target->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        DB::statement('ALTER TABLE tags ENABLE TRIGGER tags_scalenie_jednym_skokiem_trg');
        $this->get($url)->assertOk()->assertViewHas('tagNames', []);
    }

    public function test_unknown_hidden_and_malformed_tags_are_not_created_or_selected(): void
    {
        $hidden = Tag::factory()->hidden()->create();
        $this->actingAs(User::factory()->create()->refresh());
        foreach (['nieistniejacy-tag', $hidden->slug, ['sernik'], str_repeat('a', 201)] as $query) {
            $this->get(route('posts.create', ['tag' => $query]))->assertOk()->assertViewHas('tagNames', []);
        }
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_removing_last_tag_does_not_restore_it_from_return_url(): void
    {
        $tag = Tag::factory()->create();
        $url = route('posts.create', ['tag' => $tag->slug]);
        $this->actingAs(User::factory()->create()->refresh())->get($url)->assertOk();
        $this->from($url)->post(route('posts.store'), [
            'body' => 'Moje ciasto.', 'tag_names' => [$tag->name], 'usun_tag' => $tag->name,
        ])->assertRedirect($url.'#tagi')->assertSessionHasInput('tag_names', []);
        $this->get($url)->assertOk()->assertViewHas('tagNames', []);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_validation_keeps_user_selection_including_an_empty_selection(): void
    {
        $tag = Tag::factory()->create();
        $url = route('posts.create', ['tag' => $tag->slug]);
        $this->actingAs(User::factory()->create()->refresh());
        foreach ([['Inny tag'], []] as $selected) {
            $this->from($url)->post(route('posts.store'), [
                'body' => 'Moje ciasto.', 'tag_names' => $selected, 'visibility' => 'niepoprawna',
            ])->assertSessionHasErrors('visibility');
            $this->get($url)->assertOk()->assertViewHas('tagNames', $selected);
        }
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_selected_tag_can_be_published_through_the_existing_form(): void
    {
        $tag = Tag::factory()->create();
        $user = User::factory()->create()->refresh();
        $this->actingAs($user)->get(route('posts.create', ['tag' => $tag->slug]))
            ->assertOk()->assertViewHas('tagNames', [$tag->name]);
        $this->post(route('posts.store'), [
            'body' => 'Moje ciasto.', 'visibility' => 'public', 'tag_names' => [$tag->name],
        ])->assertRedirect();
        $post = Post::query()->where('author_id', $user->id)->sole();
        $this->assertSame([$tag->id], $post->tags()->pluck('tags.id')->all());
    }
}
