<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', fn (Blueprint $table) => $table->unique(['author_id', 'id'], 'posts_author_id_id_unique'));
        Schema::create('first_post_events', function (Blueprint $table): void {
            $table->uuid('author_id')->primary();
            $table->uuid('post_id')->nullable();
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
        });
        // PostgreSQL 16+: kasowanie nośnika nie kasuje pamięci zdarzenia ani autora.
        DB::statement('ALTER TABLE first_post_events ADD CONSTRAINT first_post_events_author_post_foreign FOREIGN KEY (author_id, post_id) REFERENCES posts (author_id, id) ON DELETE SET NULL (post_id)');
        DB::statement('INSERT INTO first_post_events (author_id, post_id) '.$this->reconstructionSql(), $this->bindings());
    }

    public function down(): void
    {
        // D-088: up musi odtworzyć dokładnie ten sam nośnik, także NULL.
        $lost = DB::selectOne('SELECT EXISTS (SELECT 1 FROM first_post_events e LEFT JOIN ('.$this->reconstructionSql().') restored ON restored.author_id = e.author_id WHERE restored.author_id IS NULL OR restored.post_id IS DISTINCT FROM e.post_id) AS lost', $this->bindings());
        if ($lost->lost) {
            throw new RuntimeException('Nie można cofnąć pamięci pierwszego wkładu: zachowane dane nie odtworzą zdarzeń. Zachowaj first_post_events i przygotuj ręczny plan przeniesienia danych.');
        }
        Schema::dropIfExists('first_post_events');
        Schema::table('posts', fn (Blueprint $table) => $table->dropUnique('posts_author_id_id_unique'));
    }

    /** Migawka reguły wdrożeniowej, niezależna od przyszłych modeli i akcji. */
    private function reconstructionSql(): string
    {
        return <<<'SQL'
            WITH host AS (
                SELECT users.id FROM users JOIN profiles ON profiles.user_id = users.id
                WHERE lower(profiles.username) = lower(?) LIMIT 1
            ), proven AS (
                SELECT DISTINCT ON (n.actor_id) n.actor_id AS author_id, p.id AS post_id
                FROM notifications n JOIN users u ON u.id = n.actor_id
                LEFT JOIN posts p ON p.id::text = n.data->>'post_id' AND p.author_id = n.actor_id
                WHERE n.type = 'post.first'
                ORDER BY n.actor_id, n.created_at, n.id
            ), available AS (
                SELECT DISTINCT ON (p.author_id) p.author_id, p.id AS post_id
                FROM posts p JOIN users u ON u.id = p.author_id
                WHERE p.status = 'published' AND p.published_at IS NOT NULL
                  AND u.status NOT IN ('banned', 'pending_delete', 'erased')
                  AND (? OR p.kind = 'dish')
                  AND (
                    (NOT EXISTS (SELECT 1 FROM host) AND p.visibility = 'public')
                    OR EXISTS (
                        SELECT 1 FROM host h WHERE h.id <> p.author_id
                        AND (p.visibility = 'public' OR (p.visibility = 'followers' AND EXISTS (
                            SELECT 1 FROM follows f WHERE f.follower_id = h.id AND f.followed_id = p.author_id
                        )))
                        AND NOT EXISTS (SELECT 1 FROM blocks b WHERE
                            (b.blocker_id = h.id AND b.blocked_id = p.author_id)
                            OR (b.blocker_id = p.author_id AND b.blocked_id = h.id))
                    )
                  )
                ORDER BY p.author_id, p.published_at, p.id
            )
            SELECT author_id, post_id FROM proven
            UNION ALL
            SELECT a.author_id, a.post_id FROM available a
            WHERE NOT EXISTS (SELECT 1 FROM proven WHERE proven.author_id = a.author_id)
            SQL;
    }

    private function bindings(): array
    {
        return [trim((string) config('kuking.community.host_username')), (bool) config('kuking.questions.enabled', false)];
    }
};
