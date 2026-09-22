<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TagiInlineMigracjaEksportTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_18_000000_add_manual_origin_to_post_tags.php';

    public function test_rollback_odmawia_dla_inline_takze_w_usunietym_miekko_wpisie(): void
    {
        $post = app(PublishPost::class)->handle($this->user(), '#chleb');
        $post->delete();
        $migration = require base_path(self::MIGRATION);
        try {
            $migration->down();
            $this->fail('Rollback utracił pochodzenie inline.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('istnieją tagi wyłącznie z opisu', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('post_tags', 'dodany_recznie'));
        $this->assertDatabaseHas('post_tags', ['post_id' => $post->id, 'dodany_recznie' => false]);
    }

    public function test_rollback_i_ponowna_migracja_sa_bezstratne_dla_recznych(): void
    {
        $post = Post::factory()->create(['body' => '#dawny-token']);
        $tag = Tag::factory()->create();
        $post->tags()->attach($tag->id, ['position' => 0]);
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('post_tags', 'dodany_recznie'));
            $this->assertDatabaseHas('post_tags', ['post_id' => $post->id, 'tag_id' => $tag->id, 'position' => 0]);
        } finally {
            $migration->up();
        }
        $this->assertTrue($post->tags()->firstOrFail()->pivot->dodany_recznie);
        $this->assertSame('#dawny-token', $post->fresh()->body);
        $this->assertSame(1, DB::table('post_tags')->count());
    }

    public function test_eksport_wlasnego_prywatnego_wpisu_zawiera_obydwa_pochodzenia_bez_cudzych_tagow(): void
    {
        $user = $this->user();
        app(PublishPost::class)->handle($user, '#chleb', visibility: 'private', tagNames: ['zupa']);
        app(PublishPost::class)->handle($this->user(), '#cudzy', visibility: 'private');
        $export = app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), now());
        $this->assertCount(1, $export['wpisy']);
        $this->assertSame('private', $export['wpisy'][0]['widocznosc']);
        $this->assertSame('#chleb', $export['wpisy'][0]['tresc']);
        $tags = $export['wpisy'][0]['tagi'];
        $this->assertSame(['zupa', 'chleb'], array_column($tags, 'nazwa'));
        $this->assertSame([true, false], array_column($tags, 'dodany_recznie'));
        $this->assertSame(['id', 'nazwa', 'slug', 'dodany_recznie'], array_keys($tags[0]));
        $this->assertSame(Tag::where('name', 'zupa')->value('id'), $tags[0]['id']);
    }
}
