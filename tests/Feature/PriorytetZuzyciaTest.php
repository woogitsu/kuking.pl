<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\PriorytetZuzycia;
use App\Models\PantryItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reguła priorytetu „Zużyj w pierwszej kolejności” (#1903, D-333).
 *
 * „Dziś” jest podawane jawnie (2026-10-10), żeby granice grup były
 * sprawdzane na stałych datach, a strefę osobno sprawdza
 * `PriorytetZuzyciaStrefaTest`.
 */
class PriorytetZuzyciaTest extends TestCase
{
    use RefreshDatabase;

    private const DZIS = '2026-10-10';

    private function produkt(string $nazwa, ?string $termin, string $rodzaj = 'best_before', bool $mrozone = false, ?string $id = null): PantryItem
    {
        $produkt = new PantryItem;
        $produkt->forceFill([
            'id' => $id ?? (string) Str::uuid(),
            'name' => $nazwa,
            'expires_on' => $termin === null ? null : CarbonImmutable::parse($termin),
            'expiry_kind' => $termin === null ? null : $rodzaj,
            'frozen' => $mrozone,
        ]);

        return $produkt;
    }

    public function test_granice_grup_wczoraj_dzis_plus_3_plus_4(): void
    {
        $this->assertSame(PriorytetZuzycia::PO_TERMINIE, PriorytetZuzycia::grupa($this->produkt('a', '2026-10-09', 'use_by'), self::DZIS));
        $this->assertSame(PriorytetZuzycia::PILNE, PriorytetZuzycia::grupa($this->produkt('a', '2026-10-09'), self::DZIS), 'wczoraj');
        $this->assertSame(PriorytetZuzycia::PILNE, PriorytetZuzycia::grupa($this->produkt('a', '2026-10-10'), self::DZIS), 'dziś');
        $this->assertSame(PriorytetZuzycia::PILNE, PriorytetZuzycia::grupa($this->produkt('a', '2026-10-13'), self::DZIS), 'dziś + 3');
        $this->assertSame(PriorytetZuzycia::POZNIEJ, PriorytetZuzycia::grupa($this->produkt('a', '2026-10-14'), self::DZIS), 'dziś + 4');
    }

    public function test_brak_terminu_nigdy_nie_jest_pilny_a_mrozone_nie_ma_pilnosci(): void
    {
        $this->assertSame(PriorytetZuzycia::BEZ_TERMINU, PriorytetZuzycia::grupa($this->produkt('sól', null), self::DZIS));
        // Mrożony kurczak z terminem sprzed miesiąca nie zalewa góry listy.
        $this->assertSame(PriorytetZuzycia::MROZONE, PriorytetZuzycia::grupa($this->produkt('kurczak', '2026-09-10', mrozone: true), self::DZIS));
        $this->assertSame(PriorytetZuzycia::MROZONE, PriorytetZuzycia::grupa($this->produkt('groszek', null, mrozone: true), self::DZIS));
    }

    public function test_kolejnosc_w_sekcji_termin_potem_rodzaj_potem_nazwa_potem_id(): void
    {
        $produkty = [
            $this->produkt('szynka', '2026-10-12'),
            $this->produkt('mleko', '2026-10-11', 'best_before'),
            $this->produkt('jogurt', '2026-10-11', 'use_by'),
            $this->produkt('ser', '2026-10-09'),
            $this->produkt('Masło', '2026-10-11', 'use_by'),
            $this->produkt('jajka', '2026-10-11', 'use_by', id: 'ffffffff-ffff-4fff-8fff-ffffffffffff'),
            $this->produkt('jajka', '2026-10-11', 'use_by', id: '00000000-0000-4000-8000-000000000000'),
        ];

        $grupy = PriorytetZuzycia::pogrupuj($produkty, self::DZIS);

        $this->assertSame(
            ['ser', 'jajka', 'jajka', 'jogurt', 'Masło', 'mleko', 'szynka'],
            $grupy['pilne']->pluck('name')->all(),
            'Termin rosnąco; przy remisie „Należy zużyć do” przed „Najlepiej spożyć przed”; potem nazwa.',
        );
        $this->assertSame(
            ['00000000-0000-4000-8000-000000000000', 'ffffffff-ffff-4fff-8fff-ffffffffffff'],
            $grupy['pilne']->where('name', 'jajka')->pluck('id')->values()->all(),
            'Przy pełnym remisie rozstrzyga id — kolejność jest stała.',
        );

        // Ta sama lista w odwróconej kolejności wejścia daje ten sam wynik.
        $odwrotnie = PriorytetZuzycia::pogrupuj(array_reverse($produkty), self::DZIS);
        $this->assertSame($grupy['pilne']->pluck('id')->all(), $odwrotnie['pilne']->pluck('id')->all());
    }

    public function test_pozostale_sekcje_bez_terminu_alfabetycznie_pozniej_po_terminie(): void
    {
        $grupy = PriorytetZuzycia::pogrupuj([
            $this->produkt('żurek', null),
            $this->produkt('ananas', null),
            $this->produkt('ocet', '2026-11-20'),
            $this->produkt('mąka', '2026-10-30'),
            $this->produkt('groszek', '2026-12-01', mrozone: true),
        ], self::DZIS);

        $this->assertSame(['ananas', 'żurek'], $grupy['bez_terminu']->pluck('name')->all());
        $this->assertSame(['mąka', 'ocet'], $grupy['pozniej']->pluck('name')->all());
        $this->assertSame(['groszek'], $grupy['mrozone']->pluck('name')->all());
        $this->assertTrue($grupy['pilne']->isEmpty());
    }

    public function test_granica_pilnych_idzie_z_konfiguracji(): void
    {
        config(['kuking.pantry.pilne_dni' => 1]);

        $this->assertSame('2026-10-11', PriorytetZuzycia::granicaPilnych(self::DZIS));
        $this->assertSame(PriorytetZuzycia::POZNIEJ, PriorytetZuzycia::grupa($this->produkt('a', '2026-10-12'), self::DZIS));
        $this->assertStringContainsString('w ciągu 1 dnia', PriorytetZuzycia::regula());
    }

    public function test_linia_stanu_mowi_slowami_i_nie_ocenia_produktu(): void
    {
        $this->assertSame(
            'Termin minął 2 dni temu. Sprawdź produkt przed użyciem albo usuń go z listy.',
            PriorytetZuzycia::opisStanu($this->produkt('a', '2026-10-08'), self::DZIS),
        );
        $this->assertStringStartsWith('Termin minął wczoraj.', PriorytetZuzycia::opisStanu($this->produkt('a', '2026-10-09'), self::DZIS));
        $this->assertSame('Termin dziś.', PriorytetZuzycia::opisStanu($this->produkt('a', '2026-10-10'), self::DZIS));
        $this->assertSame('Termin jutro (11 października).', PriorytetZuzycia::opisStanu($this->produkt('a', '2026-10-11'), self::DZIS));
        $this->assertSame('Termin za 2 dni (12 października).', PriorytetZuzycia::opisStanu($this->produkt('a', '2026-10-12'), self::DZIS));
        $this->assertSame('Bez terminu.', PriorytetZuzycia::opisStanu($this->produkt('a', null), self::DZIS));
        $this->assertSame('W zamrażarce.', PriorytetZuzycia::opisStanu($this->produkt('a', '2026-10-12', mrozone: true), self::DZIS));
        $this->assertSame('Termin „Należy zużyć do” minął. Nie używaj tego produktu do gotowania. Możesz poprawić termin albo usunąć produkt z listy.', PriorytetZuzycia::opisStanu($this->produkt('a', '2026-10-09', 'use_by'), self::DZIS));

        // Żadne zdanie ze stanu ani z reguły nie obiecuje świeżości ani bezpieczeństwa.
        $wszystkie = [PriorytetZuzycia::regula()];
        foreach (['2026-10-01', '2026-10-10', '2026-10-12', '2026-12-31'] as $termin) {
            $wszystkie[] = PriorytetZuzycia::opisStanu($this->produkt('a', $termin), self::DZIS);
        }
        foreach ($wszystkie as $zdanie) {
            $this->assertDoesNotMatchRegularExpression('/śwież|bezpiecz|zepsut|marnuj|uratuj/iu', $zdanie);
        }
    }

    public function test_zdanie_na_start_wymienia_dwa_produkty_i_liczy_reszte(): void
    {
        $pogrupowane = fn (array $nazwy) => collect($nazwy)->map(fn (string $n) => $this->produkt($n, '2026-10-10'));

        $this->assertNull(PriorytetZuzycia::zdanieDlaStartu(collect()));
        $this->assertSame('Do zużycia w ciągu 3 dni: mleko.', PriorytetZuzycia::zdanieDlaStartu($pogrupowane(['mleko'])));
        $this->assertSame('Do zużycia w ciągu 3 dni: mleko i szynka.', PriorytetZuzycia::zdanieDlaStartu($pogrupowane(['mleko', 'szynka'])));
        $this->assertSame(
            'Do zużycia w ciągu 3 dni: mleko, szynka i jeszcze 2 produkty.',
            PriorytetZuzycia::zdanieDlaStartu($pogrupowane(['mleko', 'szynka', 'ser', 'jajka'])),
        );
        $this->assertSame(
            'Do zużycia w ciągu 3 dni: mleko, szynka i jeszcze 1 produkt.',
            PriorytetZuzycia::zdanieDlaStartu($pogrupowane(['mleko', 'szynka', 'ser'])),
        );
    }
}
