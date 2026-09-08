<?php

declare(strict_types=1);
namespace Tests\Audit;

use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaginationProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwie_strony_kolejki_nie_powtarzaja_zgloszen_przy_remisie_sekundy(): void
    {
        $moderator = $this->moderator();
        $author = $this->user('audit_author');
        $post = Post::factory()->create(['author_id' => $author->id]);
        for ($i = 0; $i < 100; $i++) {
            Report::create(['target_type' => 'post', 'target_id' => $post->id, 'reason' => 'spam', 'status' => 'open', 'created_at' => '2026-09-08 12:00:00']);
        }
        // created_at nie jest mass-assignable; ustawiamy fixture jawnie w bazie.
        DB::table('reports')->update(['created_at' => '2026-09-08 12:00:00']);
        $this->actingAs($moderator);
        $out = [];
        foreach (['default', 'sequential_scan'] as $mode) {
            if ($mode === 'sequential_scan') {
                DB::statement('SET LOCAL enable_indexscan = off');
                DB::statement('SET LOCAL enable_indexonlyscan = off');
                DB::statement('SET LOCAL enable_bitmapscan = off');
            }
            $a = $this->get(route('admin.reports', ['page' => 1]));
            $b = $this->get(route('admin.reports', ['page' => 2]));
            $a->assertOk();
            $b->assertOk();
            $ids1 = $a->viewData('reports')->getCollection()->pluck('id')->all();
            $ids2 = $b->viewData('reports')->getCollection()->pluck('id')->all();
            $control1 = Report::where('status', 'open')->latest()->orderByDesc('id')->paginate(25, ['*'], 'page', 1)->pluck('id')->all();
            $control2 = Report::where('status', 'open')->latest()->orderByDesc('id')->paginate(25, ['*'], 'page', 2)->pluck('id')->all();
            $query = Report::where('status', 'open')->latest()->limit(25);
            $out[$mode] = ['page1' => $ids1, 'page2' => $ids2, 'duplicates' => array_values(array_intersect($ids1, $ids2)), 'control_duplicates' => array_values(array_intersect($control1, $control2)), 'sql' => $query->toSql(), 'plan' => DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())];
            $this->assertSame([], $out[$mode]['control_duplicates']);
        }
        file_put_contents(base_path('audit-evidence/pagination.json'), json_encode($out, JSON_PRETTY_PRINT));
        $this->assertSame([], $out['default']['duplicates'], 'Domyslny plan: powtarza sprawy miedzy stronami');
        $this->assertSame([], $out['sequential_scan']['duplicates'], 'Dopuszczalny plan sekwencyjny: powtarza sprawy miedzy stronami');
    }
}
