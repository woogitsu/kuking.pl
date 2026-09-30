<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Support\FrazaWyszukiwania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #2331: emoji na brzegu frazy znika w `Str::ascii()` i zostawia
 * spację — „Basia 🍲" dawało wzorzec `%basia %` i nie znajdowało konta
 * „Basia". Brzegi przycinamy PO normalizacji, a emoji w środku frazy
 * wypada razem ze swoim odstępem (pełna poprawka #2331; szczegółowe sceny
 * po każdej ścieżce szukania — `FrazaZEmojiNaBrzeguTest`). Fraza nie jest
 * obcinana (#885).
 */
final class FrazaZEmojiNaBrzeguZnajdujeOsobeTest extends TestCase
{
    use RefreshDatabase;

    public function test_emoji_na_koncu_albo_na_poczatku_nie_gubi_osoby(): void
    {
        $this->user('basia', ['display_name' => 'Basia']);
        $this->user('zenon', ['display_name' => 'Zenon']);
        $szukaj = app(SearchQuery::class);

        // Kontrola dodatnia: bez emoji Basia jest.
        $this->assertSame(['basia'], $this->nazwy($szukaj, 'Basia'));

        foreach (['Basia 🍲', '🍲 Basia', '🍲 Basia 🍲'] as $fraza) {
            $this->assertSame(['basia'], $this->nazwy($szukaj, $fraza), 'Fraza „'.$fraza.'" nie znalazła osoby.');
        }
    }

    public function test_emoji_w_srodku_wypada_z_odstepem_a_jeden_znak_po_przycieciu_to_za_malo(): void
    {
        $this->assertSame('basia', FrazaWyszukiwania::normalizuj(' Basia 🍲 '));
        // Emoji w środku wypada razem ze swoim odstępem (#2331) — fraza nie
        // jest przy tym obcinana (#885).
        $this->assertSame('basia nowak', FrazaWyszukiwania::normalizuj('Basia 🍲 Nowak'));

        // „a 🍲" to po przycięciu jeden znak — tak samo za krótka jak „a".
        $this->assertFalse(SearchQuery::jestPrzeszukiwalna('a 🍲'));
        $this->assertTrue(SearchQuery::jestPrzeszukiwalna('ab 🍲'));
    }

    /** @return list<string> */
    private function nazwy(SearchQuery $szukaj, string $fraza): array
    {
        return $szukaj->people($fraza)->pluck('username')->all();
    }
}
