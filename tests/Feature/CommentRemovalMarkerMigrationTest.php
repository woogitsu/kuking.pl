<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommentRemovalMarkerMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_column_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_09_18_110000_add_body_removed_at_to_comments.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('comments', 'body_removed_at'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('comments', 'body_removed_at'));
    }

    public function test_rollback_preserves_existing_removal_marker_even_on_soft_deleted_comment(): void
    {
        $comment = Comment::factory()->create(['body_removed_at' => now(), 'deleted_at' => now()]);
        $migration = require database_path('migrations/2026_09_18_110000_add_body_removed_at_to_comments.php');
        try {
            $migration->down();
            $this->fail('Rollback must not discard removal markers.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Nie można cofnąć', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('comments', 'body_removed_at'));
        $this->assertNotNull(Comment::withTrashed()->findOrFail($comment->id)->getAttribute('body_removed_at'));
    }
}
