<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uzytkownik_moze_zglosic_wpis(): void
    {
        $autor = $this->user('autor');
        $zglaszajacy = $this->user('zglaszajacy');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($zglaszajacy)
            ->post(route('reports.store', ['type' => 'post', 'id' => $post->getKey()]), [
                'reason' => 'spam',
                'details' => 'Reklama suplementów.',
            ])
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('reports', [
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_podwojne_zgloszenie_nie_tworzy_duplikatu(): void
    {
        $autor = $this->user('autor');
        $zglaszajacy = $this->user('zglaszajacy');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $dane = ['reason' => 'spam'];

        $this->actingAs($zglaszajacy)->post(route('reports.store', ['type' => 'post', 'id' => $post->getKey()]), $dane);
        $this->actingAs($zglaszajacy)->post(route('reports.store', ['type' => 'post', 'id' => $post->getKey()]), $dane);

        $this->assertSame(1, Report::count());
    }

    public function test_zwykly_uzytkownik_nie_widzi_panelu_moderacji(): void
    {
        $basia = $this->user('basia');

        // 404, nie 403 — panel moderacji nie potwierdza, że istnieje.
        $this->actingAs($basia)->get(route('admin.reports'))->assertNotFound();
    }

    public function test_moderator_widzi_kolejke_zgloszen(): void
    {
        $moderator = $this->moderator();

        $this->actingAs($moderator)->get(route('admin.reports'))->assertOk();
    }

    public function test_decyzja_moderatora_zapisuje_uzasadnienie_i_ukrywa_tresc(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autor');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $report = Report::create([
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $report), [
                'action' => 'hide',
                'reason_code' => 'spam_link',
                'note' => 'Link afiliacyjny bez związku z przepisem.',
                'user_message' => 'Ukryliśmy Twój wpis, bo zawierał link reklamowy.',
            ])
            ->assertRedirect();

        // Uzasadnienie musi zostać zapisane — bez niego nie da się
        // rozpatrzyć odwołania (DSA art. 17).
        $this->assertDatabaseHas('moderation_actions', [
            'report_id' => $report->getKey(),
            'action' => 'hide',
            'reason_code' => 'spam_link',
        ]);

        $this->assertSame(Post::STATUS_HIDDEN, $post->fresh()->status);
        $this->assertSame(Report::STATUS_RESOLVED, $report->fresh()->status);
    }

    public function test_decyzja_wymaga_powodu(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autor');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $report = Report::create([
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $report), ['action' => 'remove'])
            ->assertSessionHasErrors('reason_code');
    }
}
