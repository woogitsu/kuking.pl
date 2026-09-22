<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Wspomnienia\Wspomnienia;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

class PrywatnoscKomunikatyIWspomnieniaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    public function test_wylaczone_pytanie_nie_wraca_jako_wspomnienie(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        config(['kuking.questions.enabled' => false]);
        $user = $this->user();
        Post::factory()->create(['author_id' => $user->id, 'kind' => 'question', 'title' => 'Jak ugotować dawny rosół?', 'body' => 'Pytanie sprzed dwóch lat.', 'published_at' => now()->subYears(2)]);
        $this->assertNull(app(Wspomnienia::class)->dlaOsoby($user), 'Wyłączone pytanie wróciło jako wspomnienie.');
        $this->actingAs($user)->get(route('home'))->assertOk()->assertDontSee('wspomnienie-podpis')->assertDontSee('Pytanie sprzed dwóch lat.');

        $post = Post::factory()->create(['author_id' => $user->id, 'visibility' => 'private', 'body' => 'Mój prywatny rosół.', 'published_at' => now()->subYear()]);
        $this->assertSame($post->id, app(Wspomnienia::class)->dlaOsoby($user)?->id);
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('Mój prywatny rosół.', $this->trescEkranu($html));
        $post->forceFill(['hide_as_memory' => true])->save();
        $this->assertNull(app(Wspomnienia::class)->dlaOsoby($user));
    }

    public static function photoStates(): array
    {
        $states = [];
        foreach (['private', 'followers', 'public'] as $visibility) {
            foreach ([false, true] as $published) {
                $states[$visibility.'-'.(int) $published] = [$visibility, $published];
            }
        }

        return $states;
    }

    #[DataProvider('photoStates')]
    public function test_zapis_zdjec_nie_obiecuje_odbiorcow(string $visibility, bool $published): void
    {
        $user = $this->user();
        $media = Media::factory()->count(2)->create(['owner_id' => $user->id]);
        $post = app(PublishPost::class)->handle(author: $user, body: 'Zdjęcia obiadu.', mediaIds: $media->modelKeys(), visibility: $visibility);
        $this->actingAs($user)->get(route('posts.media.edit', $post))->assertOk();
        // Zmiana widoczności w innej karcie, już po otwarciu układu zdjęć.
        $current = $visibility === 'private' ? 'public' : 'private';
        foreach ([$visibility, $current] as $state) {
            $post->update(['visibility' => $state]);
            $response = $this->post(route('posts.media.update', $post), ['display_mode' => Post::DISPLAY_COLLAGE, ...($published ? ['wroc_do_wpisu' => '1'] : [])]);
            $response->assertRedirect();
            $html = $this->get($response->headers->get('Location'))->assertOk()->getContent();
            $this->assertStringContainsString('Układ zdjęć zapisany.', $this->trescEkranu($html));
            $this->assertStringNotContainsString('Tak zobaczą ten wpis inni', $html);
            $this->assertSame($state, $post->fresh()->visibility);
            $this->assertSame($media->modelKeys(), $post->fresh()->media->modelKeys());
            $response = $this->post(route('posts.media.update', $post), ['przenies_w_gore' => $media[1]->id]);
            $this->get($response->headers->get('Location'))->assertOk()->assertSee('Zdjęcie przesunięte w górę. Jest teraz 1 w kolejności.');
            $this->assertSame(array_reverse($media->modelKeys()), $post->fresh()->media->modelKeys());
            $this->post(route('posts.media.update', $post), ['przenies_w_dol' => $media[1]->id]);
        }
    }

    public static function blockStates(): array
    {
        return ['obserwowali' => [true, false, false], 'nie obserwowali' => [false, false, false], 'wzajemna blokada' => [true, true, false], 'konto zamknięte' => [true, false, true], 'konto zawieszone' => [true, false, false, true]];
    }

    #[DataProvider('blockStates')]
    public function test_zdjecie_blokady_wyjasnia_obserwowanie(bool $followed, bool $mutual, bool $closed, bool $suspended = false): void
    {
        $user = $this->user();
        $target = $this->user();
        if ($followed) {
            $user->following()->attach($target->id);
            $target->following()->attach($user->id);
        }
        app(BlockUser::class)->handle($user, $target);
        if ($mutual) {
            app(BlockUser::class)->handle($target, $user);
        }
        if ($closed) {
            $target->ban();
        }
        if ($suspended) {
            $target->suspend();
        }
        $response = $this->actingAs($user)->from(route('settings.privacy'))->delete(route('social.unblock', $target->profile->username));
        $response->assertRedirect(route('settings.privacy'));
        $this->assertFalse($user->fresh()->isFollowing($target));
        $this->assertFalse($target->fresh()->isFollowing($user));
        $this->assertDatabaseMissing('blocks', ['blocker_id' => $user->id, 'blocked_id' => $target->id]);
        $html = $this->get(route('settings.privacy'))->assertOk()->getContent();
        // Zdanie o niewznawianiu obserwowania weszło na main wcześniej (#791)
        // i ma tam własnego strażnika na dokładne brzmienie
        // (OdblokowanieNieWznawiaObserwowaniaTest). Nie powielamy go drugim
        // zdaniem o tym samym — mierzymy to samo, słowami z main.
        $this->assertStringContainsString('obserwowanie się nie wznawia samo', $this->trescEkranu($html));
        $this->assertStringContainsString('Jeśli na profilu tej osoby jest przycisk', $this->trescEkranu($html));
        if ($mutual) {
            $this->assertDatabaseHas('blocks', ['blocker_id' => $target->id, 'blocked_id' => $user->id]);
        }
    }
}
