<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_mozna_opublikowac_wpis_z_samym_tekstem(): void
    {
        $basia = $this->user('basia');

        $response = $this->actingAs($basia)->post(route('posts.store'), [
            'body' => 'Rosół na niedzielę. Wyszedł złoty.',
            'visibility' => 'public',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('posts', [
            'author_id' => $basia->getKey(),
            'body' => 'Rosół na niedzielę. Wyszedł złoty.',
            'status' => Post::STATUS_PUBLISHED,
        ]);
    }

    public function test_mozna_opublikowac_wpis_z_samym_zdjeciem_bez_tekstu(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        $response = $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 1200, 900)],
            'visibility' => 'public',
        ]);

        $response->assertRedirect();

        $post = Post::first();
        $this->assertNotNull($post);
        $this->assertNull($post->body, 'Tekst nie może być wymagany przy zdjęciu.');
        $this->assertSame(1, $post->media()->count());
    }

    public function test_pusty_wpis_bez_zdjecia_i_bez_tekstu_jest_odrzucany(): void
    {
        $basia = $this->user('basia');

        $response = $this->actingAs($basia)->post(route('posts.store'), [
            'visibility' => 'public',
        ]);

        $response->assertSessionHasErrors('photos');
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_zdjecie_utracone_przy_rewalidacji_nie_tworzy_pustego_wpisu_ani_skutkow_ubocznych(): void
    {
        Queue::fake();
        $basia = $this->user('basiautracone');
        $zdjecie = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'status' => Media::STATUS_DELETED,
        ]);

        try {
            app(PublishPost::class)->handle(
                author: $basia,
                body: null,
                mediaIds: [(string) $zdjecie->getKey()],
            );
            $this->fail('Publikacja pustego wpisu miała zostać odrzucona.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(
                'Wybrane zdjęcie nie jest już dostępne. Wybierz je ponownie albo napisz kilka słów.',
                $e->getMessage(),
            );
        }

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('audit_log', 0);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNotPushed(PrzeanalizujTresc::class);
    }

    public function test_http_z_usunietym_zdjeciem_wraca_do_formularza_z_uczciwym_bledem(): void
    {
        Queue::fake();
        $basia = $this->user('basiahttp');
        $zdjecie = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'status' => Media::STATUS_DELETED,
        ]);

        $response = $this->actingAs($basia)
            ->from(route('posts.create'))
            ->post(route('posts.store'), [
                'media_ids' => [(string) $zdjecie->getKey()],
                'visibility' => Post::VISIBILITY_PUBLIC,
            ]);

        $response->assertRedirect(route('posts.create'));
        $response->assertSessionHasErrors([
            'photos' => 'Wybrane zdjęcie nie jest już dostępne. Wybierz je ponownie albo napisz kilka słów.',
        ]);
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('audit_log', 0);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNotPushed(PrzeanalizujTresc::class);
    }

    public function test_nieudana_publikacja_nie_gubi_wpisanego_tekstu(): void
    {
        $basia = $this->user('basia');

        $response = $this->actingAs($basia)->post(route('posts.store'), [
            'body' => 'Tekst, którego nie wolno zgubić',
            'visibility' => 'nieistniejaca-wartosc',
        ]);

        $response->assertSessionHasErrors('visibility');
        // Poprawne dane nie znikają — twardy wymóg z docs/UX_50_PLUS.md.
        $response->assertSessionHasInput('body', 'Tekst, którego nie wolno zgubić');
    }

    public function test_wpis_prywatny_nie_jest_widoczny_dla_innych(): void
    {
        $basia = $this->user('basia');
        $ktos = $this->user('ktos');

        $post = Post::factory()->private()->create(['author_id' => $basia->getKey()]);

        $this->actingAs($basia)->get(route('posts.show', $post))->assertOk();
        $this->actingAs($ktos)->get(route('posts.show', $post))->assertForbidden();
        $this->get(route('posts.show', $post))->assertForbidden();
    }

    public function test_wpis_dla_obserwujacych_widzi_tylko_obserwujacy(): void
    {
        $basia = $this->user('basia');
        $obserwujacy = $this->user('obserwujacy');
        $obcy = $this->user('obcy');

        $obserwujacy->following()->attach($basia->getKey(), ['created_at' => now()]);

        $post = Post::factory()->followersOnly()->create(['author_id' => $basia->getKey()]);

        $this->actingAs($obserwujacy)->get(route('posts.show', $post))->assertOk();
        $this->actingAs($obcy)->get(route('posts.show', $post))->assertForbidden();
    }

    public function test_nie_mozna_dolaczyc_cudzego_zdjecia_do_swojego_wpisu(): void
    {
        Storage::fake('public');

        $basia = $this->user('basia');
        $obcy = $this->user('obcy');

        $cudzeZdjecie = Media::factory()->create(['owner_id' => $obcy->getKey()]);

        $post = app(PublishPost::class)->handle(
            author: $basia,
            body: 'Próba podstawienia cudzego zdjęcia',
            mediaIds: [$cudzeZdjecie->getKey()],
        );

        $this->assertSame(0, $post->media()->count(), 'Cudze media_id nie może trafić do wpisu (IDOR).');
    }
}
