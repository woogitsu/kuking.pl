<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\UnansweredContent;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnansweredQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_sees_question_queue_and_counter(): void
    {
        config(['kuking.questions.enabled' => true]);
        $host = $this->user('moderator', ['role' => User::ROLE_MODERATOR]);
        $totp = app(TwoFactorAuthenticator::class);
        $host->beginTwoFactorSetup($totp->generateSecret());
        $host->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));
        $post = Post::factory()->question()->create();
        $this->actingAs($host->refresh())->get(route('admin.unanswered', ['typ' => 'pytania']))
            ->assertOk()->assertSee($post->title)->assertSee('Czeka na odpowiedź (1)')
            ->assertSee($post->url().'#komentarze', false);
        config(['kuking.questions.enabled' => false]);
        $this->get(route('admin.unanswered', ['typ' => 'pytania']))->assertNotFound();
    }

    public function test_own_answer_and_nested_conversation_do_not_clear_host_question_queue(): void
    {
        config(['kuking.questions.enabled' => true]);
        $host = $this->user('gospodarz');
        $owner = $this->user('pytajacy');
        $other = $this->user('odpowiadajacy');
        $post = Post::factory()->question()->create(['author_id' => $owner->id]);
        $own = Comment::factory()->create(['post_id' => $post->id, 'author_id' => $owner->id]);
        Comment::factory()->create(['post_id' => $post->id, 'author_id' => $other->id, 'parent_id' => $own->id]);
        $queue = new UnansweredContent;
        $this->assertTrue($queue->questions($host)->whereKey($post)->exists());
        $answer = Comment::factory()->create(['post_id' => $post->id, 'author_id' => $other->id]);
        $this->assertFalse($queue->questions($host)->whereKey($post)->exists());
        app(BlockUser::class)->handle($owner, $other);
        $this->assertTrue($queue->questions($host)->whereKey($post)->exists());
    }

    public function test_queue_excludes_dishes_private_questions_and_disabled_department(): void
    {
        config(['kuking.questions.enabled' => true]);
        $host = $this->user('gospodarz');
        Post::factory()->create();
        Post::factory()->question()->private()->create();
        $post = Post::factory()->question()->create();
        $queue = new UnansweredContent;
        $this->assertSame([$post->id], $queue->questions($host)->pluck('id')->all());
        config(['kuking.questions.enabled' => false]);
        $this->assertSame([], $queue->questions($host)->pluck('id')->all());
    }
}
