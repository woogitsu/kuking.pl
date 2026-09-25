<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\TagSuggester;
use App\Models\Tag;
use App\Support\FrazaWyszukiwania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Podpowiedzi tagów czytają frazę tak samo jak wyszukiwarka.
 *
 * `TagSuggester` miał własną kopię `SearchQuery::normalize()` i nie dostał
 * dwóch poprawek wyszukiwarki:
 *
 *  - #1050 — fraza z samych emoji ma dwa znaki, więc przechodziła próg,
 *    a po `Str::ascii()` zostawał pusty tekst. Trzecia gałąź zapytania
 *    dostawała `LIKE '%'` i podpowiadała pierwsze lepsze tagi;
 *  - #753 — `%` i `_` wchodziły do `LIKE` jako wzorzec, więc „%%"
 *    dopasowywało każdy tag.
 *
 * Obie reguły żyją teraz w `App\Support\FrazaWyszukiwania`, wspólnej
 * z wyszukiwarką.
 */
final class PodpowiedziTagowFrazaJakWyszukiwarkaTest extends TestCase
{
    use RefreshDatabase;

    private const FRAZA_EMOJI = '🍲🍲';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['zupa', 'zupa krem', 'sernik', 'pierogi'] as $nazwa) {
            Tag::create(['name' => $nazwa, 'normalized_name' => $nazwa, 'slug' => str_replace(' ', '-', $nazwa)]);
        }
    }

    public function test_fixture_przechodzi_prog_dlugosci_a_po_normalizacji_jest_pusty(): void
    {
        $this->assertGreaterThanOrEqual((int) config('kuking.tags.min_length'), mb_strlen(self::FRAZA_EMOJI));
        $this->assertSame('', FrazaWyszukiwania::normalizuj(self::FRAZA_EMOJI), 'Fixture nie znika w Str::ascii() — dobierz inny znak.');
    }

    public function test_fraza_pusta_po_normalizacji_nie_podpowiada_niczego_i_nie_pyta_bazy(): void
    {
        DB::enableQueryLog();

        $podpowiedzi = app(TagSuggester::class)->sugeruj(self::FRAZA_EMOJI);

        $this->assertSame([], $podpowiedzi->all(), 'Fraza z samych emoji podpowiedziała tagi — `LIKE` dostał pusty wzorzec.');
        $this->assertSame([], DB::getQueryLog(), 'Pusta po normalizacji fraza nie ma czego szukać w bazie.');
    }

    public function test_metaznaki_like_sa_doslownym_tekstem(): void
    {
        $this->assertSame([], app(TagSuggester::class)->sugeruj('%%')->all(), '„%%" dopasowało tagi jako wzorzec LIKE.');
        $this->assertSame([], app(TagSuggester::class)->sugeruj('z_p')->all(), '„_" zadziałało jak dowolny znak.');
    }

    /**
     * Kontrola dodatnia: te same tagi dalej się podpowiadają dla zwykłej
     * frazy — bez tego testy wyżej przeszłyby też przy zepsutym zapytaniu.
     */
    public function test_zwykla_fraza_nadal_podpowiada(): void
    {
        $this->assertSame(['zupa', 'zupa krem'], app(TagSuggester::class)->sugeruj('Zupa')->pluck('name')->all());
    }
}
