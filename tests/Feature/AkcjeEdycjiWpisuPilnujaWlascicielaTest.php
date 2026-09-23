<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\ArrangePostMedia;
use App\Domain\Posts\Actions\EditPost;
use App\Domain\Posts\Actions\PublishPost;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Regresja #1048: kontroler nie może być jedyną granicą uprawnień. */
class AkcjeEdycjiWpisuPilnujaWlascicielaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasciciel_moze_bezposrednio_edytowac_wpis(): void
    {
        $owner = $this->user('wlasciciel');
        $post = app(PublishPost::class)->handle($owner, 'Stary opis', visibility: Post::VISIBILITY_PUBLIC, tagNames: ['obiad']);

        $changed = app(EditPost::class)->handle(
            actor: $owner,
            post: $post,
            body: 'Nowy opis',
            visibility: Post::VISIBILITY_PRIVATE,
            tagNames: ['kolacja'],
        );

        $this->assertSame('Nowy opis', $changed->body);
        $this->assertSame(Post::VISIBILITY_PRIVATE, $changed->visibility);
        $this->assertSame(['kolacja'], $changed->tags()->pluck('name')->all());
    }

    public function test_obcy_i_moderator_nie_moga_bezposrednio_edytowac_wpisu_ani_zostawic_czesci_zmian(): void
    {
        $owner = $this->user('wlasciciel');
        $post = app(PublishPost::class)->handle($owner, 'Stary opis', visibility: Post::VISIBILITY_PUBLIC, tagNames: ['obiad']);
        $beforePost = DB::table('posts')->where('id', $post->getKey())->first();
        $beforeTags = DB::table('post_tags')->where('post_id', $post->getKey())->orderBy('position')->get()->toArray();

        foreach ([$this->user('obcy'), User::factory()->create(['role' => User::ROLE_MODERATOR])] as $actor) {
            try {
                app(EditPost::class)->handle(
                    actor: $actor,
                    post: $post,
                    body: 'Cudza zmiana',
                    visibility: Post::VISIBILITY_PRIVATE,
                    tagNames: ['cudza'],
                );
                $this->fail('Akcja pozwoliła zmienić cudzy wpis.');
            } catch (AuthorizationException) {
                $this->assertEquals($beforePost, DB::table('posts')->where('id', $post->getKey())->first());
                $this->assertEquals($beforeTags, DB::table('post_tags')->where('post_id', $post->getKey())->orderBy('position')->get()->toArray());
                $this->assertDatabaseMissing('tags', ['name' => 'cudza']);
            }
        }
    }

    public function test_wlasciciel_moze_bezposrednio_ustawic_kolejnosc_i_wyglad_zdjec(): void
    {
        [$owner, $post, $mediaIds] = $this->postWithMedia();

        app(ArrangePostMedia::class)->handle(
            actor: $owner,
            post: $post,
            orderedMediaIds: array_reverse($mediaIds),
            displayMode: Post::DISPLAY_COLLAGE,
        );

        $this->assertSame(Post::DISPLAY_COLLAGE, $post->fresh()->display_mode);
        $this->assertSame(array_reverse($mediaIds), $post->fresh()->media->pluck('id')->all());
    }

    public function test_obcy_i_moderator_nie_moga_bezposrednio_przestawic_zdjec_ani_zostawic_czesci_zmian(): void
    {
        [, $post, $mediaIds] = $this->postWithMedia();
        $beforePost = DB::table('posts')->where('id', $post->getKey())->first();
        $beforeMedia = DB::table('post_media')->where('post_id', $post->getKey())->orderBy('position')->get()->toArray();

        foreach ([$this->user('obcy'), User::factory()->create(['role' => User::ROLE_MODERATOR])] as $actor) {
            try {
                app(ArrangePostMedia::class)->handle(
                    actor: $actor,
                    post: $post,
                    orderedMediaIds: array_reverse($mediaIds),
                    displayMode: Post::DISPLAY_COLLAGE,
                );
                $this->fail('Akcja pozwoliła zmienić zdjęcia cudzego wpisu.');
            } catch (AuthorizationException) {
                $this->assertEquals($beforePost, DB::table('posts')->where('id', $post->getKey())->first());
                $this->assertEquals($beforeMedia, DB::table('post_media')->where('post_id', $post->getKey())->orderBy('position')->get()->toArray());
            }
        }
    }

    /** @return array{User, Post, list<string>} */
    private function postWithMedia(): array
    {
        $owner = $this->user('wlasciciel');
        $mediaIds = Media::factory()->count(3)->create(['owner_id' => $owner->getKey()])->pluck('id')->all();
        $post = app(PublishPost::class)->handle($owner, 'Obiad', mediaIds: $mediaIds);

        return [$owner, $post, $mediaIds];
    }
}
