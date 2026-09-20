<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\KolejkiPanelu;
use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LicznikKolejkiGospodarzaTest extends TestCase
{
    use RefreshDatabase;

    public function test_jeden_cache_przechowuje_rozne_kolejki_dwoch_gospodarzy(): void
    {
        $first = $this->moderator();
        $second = $this->moderator();
        $publicAuthor = $this->user();
        $followedAuthor = $this->user();
        Recipe::factory()->create(['author_id' => $publicAuthor->id]);
        $followers = Recipe::factory()->create(['author_id' => $followedAuthor->id, 'visibility' => 'followers']);
        CookedEvent::factory()->create(['recipe_id' => $followers->id, 'user_id' => $this->user()->id]);
        DB::table('follows')->insert(['follower_id' => $first->id, 'followed_id' => $followedAuthor->id, 'created_at' => now()]);

        $counts = app(KolejkiPanelu::class);
        $counts->przelicz();
        $this->assertSame(3, $counts->liczby($first)['bez_odpowiedzi']);
        $this->assertSame(1, $counts->liczby($second)['bez_odpowiedzi']);
        $this->assertSame(0, $counts->liczby()['bez_odpowiedzi']);
        $this->assertSame(0, $counts->liczby($this->moderator())['bez_odpowiedzi']);

        DB::table('blocks')->insert([
            ['blocker_id' => $followedAuthor->id, 'blocked_id' => $first->id, 'created_at' => now()],
            ['blocker_id' => $second->id, 'blocked_id' => $publicAuthor->id, 'created_at' => now()],
        ]);
        $counts->przelicz();
        $this->assertSame(1, $counts->liczby($first)['bez_odpowiedzi']);
        $this->assertSame(0, $counts->liczby($second)['bez_odpowiedzi']);
    }

    public function test_stary_cache_nie_ujawnia_globalnej_liczby_niewidocznych_tresci(): void
    {
        $host = $this->moderator();
        Cache::forever('panel:kolejki', ['bez_odpowiedzi' => 99, 'zgloszenia' => 2]);
        $counts = app(KolejkiPanelu::class)->liczby($host);
        $this->assertSame(0, $counts['bez_odpowiedzi']);
        $this->assertSame(2, $counts['zgloszenia']);
    }
}
