<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublishQuestionTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidTitles(): array
    {
        return [[''], ['         '], ['Za krótko'], [str_repeat('ą', 181)]];
    }

    #[DataProvider('invalidTitles')]
    public function test_invalid_title_does_not_create_a_post(string $title): void
    {
        config(['kuking.questions.enabled' => true]);
        try {
            app(PublishPost::class)->handle(author: $this->user('pytajacy'), body: 'Dodatkowy opis', questionTitle: $title);
            $this->fail('Niepoprawny tytuł nie może zostać opublikowany.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('od 10 do 180 znaków', $e->getMessage());
        }
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_three_distinct_tags_are_allowed_and_repeated_inline_tag_counts_once(): void
    {
        config(['kuking.questions.enabled' => true]);
        $post = app(PublishPost::class)->handle(author: $this->user('pytajacy'), body: '#zupa #obiad #zupa', tagNames: ['zupa', 'bulion'], questionTitle: 'Jak uratować przesoloną zupę?');
        $this->assertCount(3, $post->tags);
        $this->assertEqualsCanonicalizing(['zupa', 'obiad', 'bulion'], $post->tags->pluck('name')->all());
    }

    public function test_question_rejects_more_than_one_photo_in_domain(): void
    {
        config(['kuking.questions.enabled' => true]);
        $author = $this->user('pytajacy');
        $this->expectException(BladDlaCzlowieka::class);
        $this->expectExceptionMessage('jedno zdjęcie');
        app(PublishPost::class)->handle(author: $author, body: null, mediaIds: [(string) Str::uuid(), (string) Str::uuid()], questionTitle: 'Jak uratować przesoloną zupę?');
    }

    public function test_title_alone_can_be_published_and_retry_returns_same_question(): void
    {
        config(['kuking.questions.enabled' => true]);
        $author = $this->user('pytajacy');
        $key = (string) Str::uuid();
        $publisher = app(PublishPost::class);
        $first = $publisher->handle(author: $author, body: null, kluczWyslania: $key, questionTitle: '  Jak uratować przesoloną zupę?  ');
        $second = $publisher->handle(author: $author, body: null, kluczWyslania: $key, questionTitle: 'Jak uratować przesoloną zupę?');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(Post::KIND_QUESTION, $first->fresh()->kind);
        $this->assertSame('Jak uratować przesoloną zupę?', $first->title);
        $this->assertNull($first->body);
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_combined_manual_and_inline_tag_limit_rolls_back_new_tags(): void
    {
        config(['kuking.questions.enabled' => true]);
        $author = $this->user('pytajacy');
        $before = Tag::count();
        try {
            app(PublishPost::class)->handle(author: $author, body: '#zupa #obiad', tagNames: ['bulion', 'warzywa'], questionTitle: 'Jak uratować przesoloną zupę?');
            $this->fail('Cztery tagi powinny przerwać publikację pytania.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('najwyżej 3 tagi', $e->getMessage());
        }
        $this->assertSame($before, Tag::count());
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_disabled_feature_rejects_domain_publication(): void
    {
        config(['kuking.questions.enabled' => false]);
        $this->expectException(BladDlaCzlowieka::class);
        app(PublishPost::class)->handle(author: $this->user('pytajacy'), body: null, questionTitle: 'Jak uratować przesoloną zupę?');
    }
}
