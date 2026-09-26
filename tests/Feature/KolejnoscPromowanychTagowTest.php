<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kolejność tagów promowanych jest jednoznaczna (#1308).
 *
 * Wyścig dwóch żądań sprawdza `tests/Dwa/KolejnoscPromowanychTagowRownolegleTest`.
 * Tu: remisy zastane sprzed poprawki (odczyt deterministyczny, przesunięcie
 * je naprawia) oraz to, że komunikat i dziennik mówią o zmianie tylko wtedy,
 * gdy naprawdę zaszła.
 */
class KolejnoscPromowanychTagowTest extends TestCase
{
    use RefreshDatabase;

    private function promowany(string $nazwa, int $pozycja, string $utworzono): Tag
    {
        $tag = Tag::create(['slug' => mb_strtolower($nazwa), 'name' => $nazwa, 'normalized_name' => mb_strtolower($nazwa)]);
        $promocja = new TagPromotion(['tag_id' => $tag->getKey(), 'position' => $pozycja]);
        $promocja->created_at = Carbon::parse($utworzono);
        $promocja->save();

        return $tag;
    }

    /** @return list<string> */
    private function kolejnosc(): array
    {
        return Tag::promowane()->pluck('name')->all();
    }

    public function test_zastany_remis_pozycji_ma_stala_kolejnosc(): void
    {
        // Kolejność wstawiania odwrotna do oczekiwanej — przy samym
        // `ORDER BY position` wynik zależałby od planu zapytania.
        $this->promowany('Zupy', 1, '2026-09-03 10:00');
        $this->promowany('Ciasta', 1, '2026-09-01 10:00');
        $this->promowany('Pierogi', 0, '2026-09-05 10:00');

        $this->assertSame(['Pierogi', 'Ciasta', 'Zupy'], $this->kolejnosc());
        $this->assertSame(
            $this->kolejnosc(),
            TagPromotion::query()->wKolejnosci()->with('tag')->get()->pluck('tag.name')->all(),
            'Panel i przesunięcie muszą widzieć tę samą kolejność.',
        );
    }

    public function test_przesuniecie_w_remisie_zamienia_miejsca_i_naprawia_pozycje(): void
    {
        $this->promowany('Ciasta', 1, '2026-09-01 10:00');
        $zupy = $this->promowany('Zupy', 1, '2026-09-03 10:00');

        // Dawny `przesun()` szukał sąsiada `position < 1` — w remisie nie
        // znajdował nikogo i mimo to meldował „Kolejność zmieniona".
        $this->actingAs($this->moderator())
            ->put(route('admin.tag-promotions.update', $zupy), ['w_gore' => '1'])
            ->assertSessionHas('status', 'Kolejność zmieniona.');

        $this->assertSame(['Zupy', 'Ciasta'], $this->kolejnosc());
        $this->assertSame([1, 2], TagPromotion::query()->wKolejnosci()->pluck('position')->all());
    }

    public function test_przesuniecie_na_skraju_nie_melduje_zmiany_ani_nie_pisze_dziennika(): void
    {
        $zupy = $this->promowany('Zupy', 1, '2026-09-01 10:00');
        $this->promowany('Ciasta', 2, '2026-09-02 10:00');

        $this->actingAs($this->moderator())
            ->put(route('admin.tag-promotions.update', $zupy), ['w_gore' => '1'])
            ->assertSessionHas('status', 'Kolejność bez zmian — ten tag jest już na skraju listy.');

        $this->assertSame(['Zupy', 'Ciasta'], $this->kolejnosc());
        $this->assertSame(0, AuditLogEntry::where('action', 'tag_promotion.reordered')->count());
    }

    /** Kontrola dodatnia poprzedniego testu: prawdziwe przesunięcie trafia do dziennika. */
    public function test_przesuniecie_z_sasiadem_pisze_dziennik(): void
    {
        $this->promowany('Zupy', 1, '2026-09-01 10:00');
        $ciasta = $this->promowany('Ciasta', 2, '2026-09-02 10:00');

        $this->actingAs($this->moderator())
            ->put(route('admin.tag-promotions.update', $ciasta), ['w_gore' => '1'])
            ->assertSessionHas('status', 'Kolejność zmieniona.');

        $this->assertSame(['Ciasta', 'Zupy'], $this->kolejnosc());
        $this->assertSame(1, AuditLogEntry::where('action', 'tag_promotion.reordered')->count());
    }

    public function test_zdjecie_juz_zdjetego_tagu_nie_pisze_dziennika(): void
    {
        $zupy = $this->promowany('Zupy', 1, '2026-09-01 10:00');
        $moderator = $this->moderator();

        $this->actingAs($moderator)->delete(route('admin.tag-promotions.destroy', $zupy));
        $this->actingAs($moderator)
            ->delete(route('admin.tag-promotions.destroy', $zupy))
            ->assertSessionHas('status', 'Tag „Zupy” nie był już na liście promowanych.');

        $this->assertSame(1, AuditLogEntry::where('action', 'tag_promotion.removed')->count());
    }
}
