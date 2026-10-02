<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slug tagu ma CHECK `^[a-z0-9-]{1,40}$`. `wolnySlug()` doklejał „-N" do
 * 40-znakowej bazy, co dawało 42 znaki i SQLSTATE 23514 (500) — także bez
 * żadnego wyścigu, wystarczyła kolizja z istniejącym tagiem.
 */
final class SlugTaguMiesciSieW40ZnakachTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nazwa tagu ma najwyżej 30 znaków, ale transliteracja potrafi ją wydłużyć
     * („æ" → „ae"), więc slug sięga 40 znaków (a bez przycięcia nawet 60).
     */
    public function test_czterdziestoznakowy_slug_w_kolizji_dostaje_sufiks_i_miesci_sie_w_40_znakach(): void
    {
        $zajety = str_repeat('ae', 20);
        Tag::query()->create(['normalized_name' => 'zajety', 'name' => 'zajety', 'slug' => $zajety]);

        $tagi = app(ResolveTagsForPost::class)->handle([str_repeat('æ', 20)]);

        $this->assertCount(1, $tagi);
        $this->assertSame(str_repeat('ae', 19).'-2', $tagi[0]->slug);
    }

    public function test_slug_dluzszy_niz_40_znakow_po_transliteracji_jest_przyciety_bez_kolizji(): void
    {
        $tagi = app(ResolveTagsForPost::class)->handle([str_repeat('æ', 25)]);

        $this->assertCount(1, $tagi);
        $this->assertSame(str_repeat('ae', 20), $tagi[0]->slug);
    }

    public function test_przyciecie_nie_zostawia_myslnika_przed_sufiksem(): void
    {
        // Slug 40-znakowy z „-" na pozycji 38: przycięcie do 38 znaków kończy
        // się myślnikiem, który nie może zostać przed sufiksem.
        $zajety = str_repeat('ae', 18).'a-xx';
        Tag::query()->create(['normalized_name' => 'zajety', 'name' => 'zajety', 'slug' => $zajety]);

        $tagi = app(ResolveTagsForPost::class)->handle([str_repeat('æ', 18).'a xx']);

        $this->assertCount(1, $tagi);
        $this->assertSame(str_repeat('ae', 18).'a-2', $tagi[0]->slug);
    }
}
