<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\LockCommentContext;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pod napisem „Komentarz usunięty.” nie ma nowych odpowiedzi (G31, D-251).
 *
 * Napis zostaje tylko po to, żeby istniejące odpowiedzi nie straciły
 * kontekstu. Regresja z przeglądu PR #1458: po zamianie decyzji „Usuń”
 * na napis odpowiedź pod zdjętym korzeniem przechodziła, a na main była
 * odrzucana (`tests/Dwa/KomentarzBiezacyStanTest`, `root_remove`).
 *
 * Kontrola ujemna: usunąć warunek `body_removed_at` z
 * `LockCommentContext::handle()` — oblewają przypadki odpowiedzi; usunąć
 * `@unless($commentIsRemoved)` przy „Odpowiedz” — oblewa sprawdzenie widoku.
 */
class OdpowiedzPodNapisemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function przypadki(): array
    {
        return [
            'moderacja, odpowiedź pod korzeniem' => ['moderacja', false],
            'moderacja, odpowiedź pod odpowiedzią' => ['moderacja', true],
            'autor, odpowiedź pod korzeniem' => ['autor', false],
            'autor, odpowiedź pod odpowiedzią' => ['autor', true],
        ];
    }

    #[DataProvider('przypadki')]
    public function test_odpowiedz_pod_napisem_jest_odrzucona_z_komunikatem(string $kto, bool $podOdpowiedzia): void
    {
        [$post, $korzen, $istniejaca] = $this->watek();

        if ($kto === 'moderacja') {
            $this->actingAs($this->moderator())
                ->post(route('admin.z-urzedu.store', ['typ' => 'comment', 'id' => $korzen->getKey()]), [
                    'reason_code' => 'spam-reklama',
                    'user_message' => 'Komentarz reklamuje sklep i nie ma nic wspólnego z gotowaniem.',
                    'note' => 'Przegląd świeżych komentarzy.',
                ])
                ->assertSessionHasNoErrors();
        } else {
            app(DeleteComment::class)->handle($korzen->author, $korzen);
        }

        $this->assertNotNull($korzen->fresh()->body_removed_at, 'Warunek testu: korzeń ma być napisem.');

        $odpowiadajacy = $this->user('odpowiadajacy');
        $rodzic = $podOdpowiedzia ? $istniejaca : $korzen;

        $this->actingAs($odpowiadajacy)
            ->from($post->url())
            ->post(route('posts.comment', $post), [
                'body' => 'Nowa odpowiedź pod napisem.',
                'parent_id' => $rodzic->getKey(),
            ])
            ->assertRedirect($post->url())
            ->assertSessionHasErrors(['body' => LockCommentContext::UNAVAILABLE]);

        $this->assertFalse(Comment::withTrashed()->where('body', 'Nowa odpowiedź pod napisem.')->exists());
        $this->assertNotSoftDeleted($istniejaca);

        // Istniejąca odpowiedź zostaje z kontekstem, ale przycisku
        // „Odpowiedz” pod napisem nie ma — byłby martwy.
        $this->actingAs($odpowiadajacy)
            ->get($post->url())
            ->assertOk()
            ->assertSee(DeleteComment::DELETED_PLACEHOLDER)
            ->assertSee($istniejaca->body)
            ->assertDontSee('value="odpowiedz-'.$korzen->getKey().'"', false);
    }

    public function test_pod_zwyklym_komentarzem_odpowiedz_przechodzi_a_przycisk_jest(): void
    {
        [$post, $korzen] = $this->watek();
        $odpowiadajacy = $this->user('odpowiadajacy');

        $this->actingAs($odpowiadajacy)
            ->get($post->url())
            ->assertOk()
            ->assertSee('value="odpowiedz-'.$korzen->getKey().'"', false);

        $this->actingAs($odpowiadajacy)
            ->from($post->url())
            ->post(route('posts.comment', $post), [
                'body' => 'Zwykła odpowiedź.',
                'parent_id' => $korzen->getKey(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Comment::query()->where('body', 'Zwykła odpowiedź.')->where('parent_id', $korzen->getKey())->exists());
    }

    /** @return array{0: Post, 1: Comment, 2: Comment} */
    private function watek(): array
    {
        $post = Post::factory()->create(['author_id' => $this->user('wlasciciel')->getKey()]);
        $korzen = Comment::factory()->create([
            'author_id' => $this->user('autor')->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Korzeń wątku do zdjęcia.',
        ]);
        $istniejaca = Comment::factory()->create([
            'author_id' => User::factory()->create()->getKey(),
            'post_id' => $post->getKey(),
            'parent_id' => $korzen->getKey(),
            'body' => 'Istniejąca odpowiedź zostaje.',
        ]);

        return [$post, $korzen, $istniejaca];
    }
}
