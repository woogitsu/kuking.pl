<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Strona przepisu i filtr „Do 30 minut" rozumieją niepełny czas tak samo (#1090).
 *
 * Przed poprawką przepis 10 min przygotowania + puste gotowanie pokazywał
 * na stronie „Około 10 min", a z szybkich wyników wypadał. Test patrzy na
 * to, co widzi człowiek (tekst na stronie i obecność w sekcji „szybkie"),
 * a nie na samą metodę modelu.
 *
 * Reguła: czas całkowity jest znany tylko przy OBU podanych czasach i sumie
 * większej od zera; puste pole to „nie wiem", jawne 0 to „tego etapu nie ma".
 */
class CzasCalkowityPrzepisuSpojnyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{?int, ?int, ?int}> przygotowanie, gotowanie, oczekiwany czas całkowity */
    public static function przypadki(): array
    {
        return [
            'oba puste' => [null, null, null],
            'samo przygotowanie' => [10, null, null],
            'samo gotowanie' => [null, 10, null],
            'gotowanie jawnie zero' => [10, 0, 10],
            'przygotowanie jawnie zero' => [0, 10, 10],
            'oba zero' => [0, 0, null],
            'dokładnie 30' => [10, 20, 30],
            '31 minut' => [11, 20, 31],
        ];
    }

    #[DataProvider('przypadki')]
    public function test_strona_i_filtr_szybkie_zgadzaja_sie(?int $przygotowanie, ?int $gotowanie, ?int $oczekiwany): void
    {
        $przepis = Recipe::factory()->for($this->user('autor'), 'author')->create([
            'title' => 'Zupa testowa',
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'prep_minutes' => $przygotowanie,
            'cook_minutes' => $gotowanie,
        ]);

        $strona = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        if ($oczekiwany === null) {
            $strona->assertDontSee('>Czas<', escape: false);
        } else {
            $strona->assertSee('Około '.$oczekiwany.' min', escape: false);
        }

        $szybkie = $this->get(route('search', ['q' => 'zupa', 'sekcja' => 'szybkie']))->assertOk();
        $wszystko = $this->get(route('search', ['q' => 'zupa', 'sekcja' => 'przepisy']))->assertOk();

        // Kontrola dodatnia: przepis w ogóle jest wyszukiwalny, więc jego
        // brak w „szybkich" wynika z filtra czasu, a nie z frazy.
        $wszystko->assertSee('Zupa testowa');

        $wLimicie = $oczekiwany !== null && $oczekiwany <= 30;

        if ($wLimicie) {
            $szybkie->assertSee('Zupa testowa');
        } else {
            $szybkie->assertDontSee('Zupa testowa');
        }

        $this->assertSame($oczekiwany, $przepis->fresh()->totalMinutes());
        $this->assertSame(
            $wLimicie,
            Recipe::query()->gotoweWCiagu(30)->whereKey($przepis->getKey())->exists(),
        );
    }
}
