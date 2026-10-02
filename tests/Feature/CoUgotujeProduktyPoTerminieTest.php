<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lista złożona wyłącznie z produktów po „Należy zużyć do” (#2453, #2659):
 * ekran mówi, że je pomijamy, i prowadzi do listy — zamiast mylącego
 * „żaden przepis nie ma niczego z Twojej listy”. Reguły doboru (D-333) bez zmian.
 */
class CoUgotujeProduktyPoTerminieTest extends TestCase
{
    use RefreshDatabase;

    private const KOMUNIKAT = 'Wszystkie Twoje produkty są po terminie';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function produkt(User $osoba, string $nazwa, ?string $termin): void
    {
        $produkt = $osoba->pantryItems()->create(['name' => $nazwa]);
        DB::table('pantry_items')->where('id', $produkt->getKey())->update([
            'expires_on' => $termin,
            'expiry_kind' => $termin === null ? null : 'use_by',
        ]);
    }

    public function test_lista_tylko_z_produktami_po_terminie_ma_osobny_komunikat_i_link_do_listy(): void
    {
        $osoba = $this->user();
        $this->produkt($osoba, 'mleko', '2026-09-01');

        $odpowiedz = $this->actingAs($osoba)->get(route('pantry.cook'))->assertOk();

        $odpowiedz->assertSee(self::KOMUNIKAT)
            ->assertSee('Produkty po terminie „Należy zużyć do” pomijamy przy doborze przepisów', false)
            ->assertSee('Sprawdź listę „Co mam w domu”', false)
            ->assertSee('href="'.route('pantry.index').'"', false)
            ->assertDontSee('Żaden przepis nie ma jeszcze niczego z Twojej listy');
    }

    public function test_lista_z_produktem_w_terminie_ale_bez_przepisu_dalej_mowi_o_braku_przepisu(): void
    {
        $osoba = $this->user();
        $this->produkt($osoba, 'mleko', '2026-09-01');
        $this->produkt($osoba, 'jajka', '2026-10-30');

        $this->actingAs($osoba)->get(route('pantry.cook'))->assertOk()
            ->assertDontSee(self::KOMUNIKAT)
            ->assertSee('Żaden przepis nie ma jeszcze niczego z Twojej listy');
    }

    public function test_produkt_po_terminie_obok_pasujacego_przepisu_nie_daje_komunikatu_o_terminie(): void
    {
        $osoba = $this->user();
        $this->produkt($osoba, 'jajka', '2026-10-30');
        $this->produkt($osoba, 'mleko', '2026-09-01');
        $przepis = Recipe::factory()->create(['title' => 'Jajecznica 2659']);
        $przepis->ingredients()->create(['position' => 0, 'ingredient_text' => 'jajka']);

        $this->actingAs($osoba)->get(route('pantry.cook'))->assertOk()
            ->assertSee('Jajecznica 2659')
            ->assertDontSee(self::KOMUNIKAT);
    }
}
