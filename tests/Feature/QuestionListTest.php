<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Questions\QuestionList;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionListTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_filter_only_returns_questions_assigned_to_an_active_tag(): void
    {
        config(['kuking.questions.enabled' => true]);
        $tag = Tag::factory()->create();
        $hidden = Tag::factory()->hidden()->create();
        $question = Post::factory()->question()->create();
        $question->tags()->attach([$tag->id => ['position' => 0], $hidden->id => ['position' => 1]]);
        Post::factory()->question()->create();
        $dish = Post::factory()->create();
        $dish->tags()->attach($tag);
        $list = new QuestionList;

        $this->assertSame([$question->id], $list->query(null, false, $tag->slug)->pluck('id')->all());
        $this->assertSame([], $list->query(null, false, $hidden->slug)->pluck('id')->all());
        $this->assertSame([], $list->query(null, false, 'nieistniejacy-tag')->pluck('id')->all());
    }

    public function test_hidden_and_blocked_answers_do_not_count_for_the_viewer(): void
    {
        config(['kuking.questions.enabled' => true]);
        $viewer = $this->user('czytelnik');
        $respondent = $this->user('odpowiadajacy');
        $question = Post::factory()->question()->create();
        $answer = Comment::factory()->create(['post_id' => $question->id, 'author_id' => $respondent->id]);
        $list = new QuestionList;
        $this->assertSame(1, $list->query($viewer)->findOrFail($question->id)->getAttribute('answer_count'));

        $answer->update(['status' => Comment::STATUS_HIDDEN]);
        $this->assertSame(0, $list->query($viewer)->findOrFail($question->id)->getAttribute('answer_count'));
        $this->assertTrue($list->query($viewer, true)->whereKey($question)->exists());

        $answer->update(['status' => Comment::STATUS_PUBLISHED]);
        app(BlockUser::class)->handle($respondent, $viewer);
        $this->assertSame(0, $list->query($viewer)->findOrFail($question->id)->getAttribute('answer_count'));
        $this->assertTrue($list->query($viewer, true)->whereKey($question)->exists());
        $this->assertSame(1, $list->query(null)->findOrFail($question->id)->getAttribute('answer_count'));
    }

    public function test_cursor_does_not_duplicate_or_skip_questions_with_the_same_publication_time(): void
    {
        config(['kuking.questions.enabled' => true]);
        $questions = Post::factory()->question()->count(5)->create(['published_at' => now()->startOfSecond()]);
        $list = new QuestionList;
        $first = $list->query(null)->cursorPaginate(2);
        $second = $list->query(null)->cursorPaginate(2, ['*'], 'cursor', $first->nextCursor());
        $third = $list->query(null)->cursorPaginate(2, ['*'], 'cursor', $second->nextCursor());
        $actual = array_merge($first->pluck('id')->all(), $second->pluck('id')->all(), $third->pluck('id')->all());
        $this->assertSame($questions->sortByDesc('id')->pluck('id')->values()->all(), $actual);
        $this->assertNull($third->nextCursor());
    }

    public function test_count_and_unanswered_filter_share_visible_top_level_answers(): void
    {
        config(['kuking.questions.enabled' => true]);
        $question = Post::factory()->question()->create();
        $answer = Comment::factory()->create(['post_id' => $question->id]);
        Comment::factory()->create(['post_id' => $question->id, 'parent_id' => $answer->id]);
        $list = new QuestionList;

        $this->assertSame(1, $list->query(null)->findOrFail($question->id)->getAttribute('answer_count'));
        $this->assertFalse($list->query(null, true)->whereKey($question)->exists());
        $answer->delete();
        $this->assertSame(0, $list->query(null)->findOrFail($question->id)->getAttribute('answer_count'));
        $this->assertTrue($list->query(null, true)->whereKey($question)->exists());
    }

    public function test_list_excludes_dishes_private_questions_and_disabled_department(): void
    {
        config(['kuking.questions.enabled' => true]);
        Post::factory()->create();
        Post::factory()->question()->private()->create();
        $question = Post::factory()->question()->create();
        $list = new QuestionList;

        $this->assertSame([$question->id], $list->query(null)->pluck('id')->all());
        config(['kuking.questions.enabled' => false]);
        $this->assertSame([], $list->query(null)->pluck('id')->all());
        $this->assertDatabaseHas('posts', ['id' => $question->id]);
    }
}
