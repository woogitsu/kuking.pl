<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PostQuestionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_18_100000_add_kind_and_title_to_posts.php');
    }

    public function test_database_default_preserves_an_existing_dish_across_migration(): void
    {
        $post = Post::factory()->private()->create(['body' => 'Stary wpis']);
        $before = DB::table('posts')->where('id', $post->id)->first();
        $this->migration()->down();

        try {
            $this->assertFalse(Schema::hasColumn('posts', 'kind'));
            $this->assertFalse(Schema::hasColumn('posts', 'title'));
        } finally {
            $this->migration()->up();
        }

        $this->assertEquals($before, DB::table('posts')->where('id', $post->id)->first());
        $id = DB::table('posts')->insertGetId(['author_id' => $post->author_id, 'body' => 'Bez modelu']);
        $raw = DB::table('posts')->where('id', $id)->first();
        $this->assertSame('dish', $raw->kind);
        $this->assertNull($raw->title);
    }

    public function test_model_and_factory_preserve_nullable_title_and_question(): void
    {
        $new = new Post;
        $this->assertSame('dish', $new->kind);
        $this->assertNull($new->title);
        $dish = Post::factory()->create()->refresh();
        $this->assertSame('dish', $dish->kind);
        $this->assertNull($dish->title);
        $question = Post::factory()->question()->create()->refresh();
        $this->assertSame('question', $question->kind);
        $this->assertSame('Jak upiec chrupiący chleb?', $question->title);
    }

    public function test_question_uses_existing_media_tags_and_comments(): void
    {
        $question = Post::factory()->question()->create();
        $media = Media::factory()->create(['owner_id' => $question->author_id]);
        $tag = Tag::factory()->create();
        $question->media()->attach($media->id, ['position' => 0]);
        $question->tags()->attach($tag->id, ['position' => 0, 'dodany_recznie' => true]);
        $comment = Comment::factory()->create(['post_id' => $question->id]);

        $question->refresh();
        $this->assertSame($media->id, $question->media->sole()->id);
        $this->assertSame(0, $question->media->sole()->pivot->position);
        $this->assertSame($tag->id, $question->tags->sole()->id);
        $this->assertTrue($question->tags->sole()->pivot->dodany_recznie);
        $this->assertSame($comment->id, $question->comments->sole()->id);
        $this->assertSame($comment->id, $question->allComments->sole()->id);
    }

    public function test_existing_http_publisher_does_not_accept_question_fields(): void
    {
        $author = $this->user('autor');
        $this->actingAs($author)->post(route('posts.store'), [
            'body' => 'Mój chleb na niedzielę.',
            'visibility' => 'public',
            'kind' => 'question',
            'title' => 'Jak upiec chrupiący chleb?',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $post = Post::query()->sole();
        $this->assertSame('dish', $post->kind);
        $this->assertNull($post->title);

        $this->put(route('posts.update', $post), [
            'body' => 'Poprawiony opis chleba.',
            'visibility' => 'public',
            'kind' => 'question',
            'title' => 'Jak upiec chrupiący chleb?',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('dish', $post->refresh()->kind);
        $this->assertNull($post->title);
        $this->assertSame('Poprawiony opis chleba.', $post->body);
    }

    public function test_question_feature_is_disabled_by_default(): void
    {
        $this->assertFalse(config('kuking.questions.enabled'));
    }

    public static function invalidVariants(): array
    {
        return [
            'unknown kind' => ['recipe', null],
            'null kind' => [null, null],
            'dish empty title' => ['dish', ''],
            'dish question title' => ['dish', 'Czy chleb już gotowy?'],
            'question null' => ['question', null],
            'question empty' => ['question', ''],
            'question whitespace' => ['question', " \t\n\r\v "],
            'question short' => ['question', str_repeat('ą', 9)],
            'question long' => ['question', str_repeat('ą', 181)],
            'trimmed short' => ['question', "\t\n".str_repeat('ą', 9)."\r\v "],
        ];
    }

    #[DataProvider('invalidVariants')]
    public function test_database_rejects_invalid_insert_and_update(?string $kind, ?string $title): void
    {
        $dish = Post::factory()->create();
        foreach (['insert', 'update'] as $operation) {
            $failure = null;
            try {
                DB::transaction(function () use ($operation, $dish, $kind, $title): void {
                    if ($operation === 'insert') {
                        DB::table('posts')->insert(['author_id' => $dish->author_id, 'kind' => $kind, 'title' => $title]);
                    } else {
                        DB::table('posts')->where('id', $dish->id)->update(['kind' => $kind, 'title' => $title]);
                    }
                });
            } catch (QueryException $exception) {
                $failure = $exception;
            }
            $this->assertNotNull($failure, $operation.' przyjął nieprawidłowy wariant.');
            $this->assertContains($failure->errorInfo[0], ['23514', '23502', '22001']);
            $this->assertSame(1, DB::table('posts')->count());
            $this->assertSame('dish', $dish->refresh()->kind);
            $this->assertNull($dish->title);
        }
    }

    public function test_database_accepts_character_boundaries_and_php_trim_whitespace(): void
    {
        $author = $this->user('autor');
        foreach ([str_repeat('ą', 10), str_repeat('ą', 180), "\t\n ".str_repeat('ą', 10)."\v\r"] as $title) {
            $id = DB::table('posts')->insertGetId(['author_id' => $author->id, 'kind' => 'question', 'title' => $title]);
            $this->assertSame($title, DB::table('posts')->where('id', $id)->value('title'));
        }
    }

    public function test_rollback_refuses_even_soft_deleted_questions_and_preserves_data(): void
    {
        $question = Post::factory()->question()->create();
        foreach ([false, true] as $deleted) {
            if ($deleted) {
                $question->delete();
            }
            $before = DB::table('posts')->where('id', $question->id)->first();
            $failure = null;
            try {
                $this->migration()->down();
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            $this->assertNotNull($failure);
            $this->assertStringContainsString('wycofaj kod aplikacji', $failure->getMessage());
            $this->assertTrue(Schema::hasColumn('posts', 'kind'));
            $this->assertTrue(Schema::hasColumn('posts', 'title'));
            $this->assertEquals($before, DB::table('posts')->where('id', $question->id)->first());
            $constraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'posts'::regclass AND conname IN ('posts_kind_check', 'posts_kind_title_check')");
            $this->assertCount(2, $constraints);
        }
    }
}
