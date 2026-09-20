<?php

declare(strict_types=1);

use App\Domain\Comments\Actions\PublishComment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Odtwarzalny render do oglądu; HTML zapisuje wyłącznie w runtime. */
class NotificationRenderProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_long_question_titles(): void
    {
        $this->assertSame('kuking_flota_gpt-pytania-widoki', DB::connection()->getDatabaseName());
        config(['kuking.questions.enabled' => true]);
        $owner = $this->user();
        $actor = $this->user(null, ['display_name' => 'Anna']);
        foreach (['Dlaczego ciasto opada?', 'Żółć <em>ciasto</em> & sos '.str_repeat('ą', 140)] as $title) {
            $question = Post::factory()->question()->create(['author_id' => $owner->id, 'title' => $title, 'body' => null]);
            app(PublishComment::class)->handle($actor, $question, 'Dodaj trochę mąki.');
        }
        $html = $this->actingAs($owner)->get(route('notifications.index'))->assertOk()->getContent();
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = '/build/'.$manifest['resources/css/app.css']['file'];
        $html = str_replace('</head>', '<link rel="stylesheet" href="'.$css.'"></head>', $html);
        file_put_contents(public_path('question-notifications-probe.html'), $html);
    }
}
