<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Pomiar zatwierdzonych danych, bez transakcji otaczającej fixture. */
class TagiAtomowyZapisTest extends TestCase
{
    use DatabaseMigrations;

    public function test_awaria_usuwania_nie_pozostawia_czesciowego_zapisu(): void
    {
        $user = $this->user();
        $a = Tag::factory()->create();
        $b = Tag::factory()->create();
        TagPromotion::create(['tag_id' => $b->id, 'position' => 0]);
        $user->followedTags()->attach($a->id, ['created_at' => now()]);
        $form = $this->actingAs($user)->get(route('settings.tags'))->assertOk();
        preg_match('/name="form_scope"\s+value="([^"]+)"/', $form->getContent(), $match);
        $data = ['tags' => [$b->id], 'form_scope' => $match[1] ?? null];
        $this->assertSame(0, DB::transactionLevel());

        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_starts_with($query->sql, 'delete from "tag_follows"')) {
                throw new \RuntimeException('FIXTURE_DETACH_FAILED');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->put(route('settings.tags.update'), $data);
            $this->fail('Nie uruchomiono kontrolowanej awarii usuwania.');
        } catch (\RuntimeException $e) {
            $this->assertSame('FIXTURE_DETACH_FAILED', $e->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame(0, DB::transactionLevel());
        // Nowe połączenie czyta wyłącznie to, co rzeczywiście zatwierdzono.
        DB::purge();
        $this->assertSame([$a->id], $user->followedTags()->pluck('tags.id')->all(), 'Pozostał częściowy zapis zamiast początkowego A.');

        $this->put(route('settings.tags.update'), $data)->assertRedirect(route('settings.tags'));
        DB::purge();
        $this->assertSame([$b->id], $user->followedTags()->pluck('tags.id')->all());
    }
}
