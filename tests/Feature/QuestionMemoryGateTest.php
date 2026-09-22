<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wspomnienia\Wspomnienia;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuestionMemoryGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_question_is_not_selected_or_rendered_as_memory(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        config(['kuking.questions.enabled' => false]);
        $owner = $this->user('pytajacy');
        $question = Post::factory()->question()->private()->create([
            'author_id' => $owner->id, 'published_at' => now()->subYear(),
            'body' => 'Wyłączone pytanie nie może wrócić jako wspomnienie.',
        ]);
        $html = $this->actingAs($owner)->get(route('home'))->assertOk()->getContent();
        $this->assertStringNotContainsString($question->body, $html);
        $this->assertNull(app(Wspomnienia::class)->dlaOsoby($owner));
        $dish = Post::factory()->private()->create([
            'author_id' => $owner->id, 'published_at' => now()->subYears(2),
            'body' => 'Moje prywatne wspomnienie obiadu.',
        ]);
        foreach ([false, true] as $enabled) {
            config(['kuking.questions.enabled' => $enabled]);
            $this->assertSame($dish->id, app(Wspomnienia::class)->dlaOsoby($owner)?->id);
            $this->get(route('home'))->assertOk()->assertSee($dish->body);
        }
        config(['kuking.questions.enabled' => false]);
        $this->post(route('wspomnienia.ukryj', $dish))->assertRedirect();
        $this->assertNull(app(Wspomnienia::class)->dlaOsoby($owner));
        $dish->forceFill(['hide_as_memory' => false])->save();
        $owner->forceFill(['memories_enabled' => false])->save();
        $this->assertNull(app(Wspomnienia::class)->dlaOsoby($owner));
    }
}
