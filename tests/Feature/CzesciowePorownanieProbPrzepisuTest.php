<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\Gotowanie\ProbyPrzepisu;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** #2817: brak wartości nie dowodzi zgodności wersji ani rzeczywistego czasu. */
final class CzesciowePorownanieProbPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const WERSJA_BEZ_ZMIAN = 'Wersja przepisu bez zmian względem poprzedniej próby.';

    private const CZAS_BEZ_ZMIAN = 'Rzeczywisty czas bez zmian względem poprzedniej próby.';

    private const BRAK_WERSJI = 'Brak danych do porównania wersji przepisu z poprzednią próbą.';

    private const BRAK_CZASU = 'Brak danych do porównania rzeczywistego czasu z poprzednią próbą.';

    private const PELNA_ZGODNOSC = 'Wersja i czas bez zmian względem poprzedniej próby.';

    private const BRAK_OBU = 'Brak danych do porównania wersji i czasu z poprzednią próbą.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, Recipe, array<int, RecipeVersion>} */
    private function przepisZWersjami(): array
    {
        $autor = $this->user('autor');
        $kucharz = $this->user('kucharz');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public',
        ]);
        $wersje = [
            1 => app(SnapshotRecipeVersion::class)->handle($przepis->fresh(), $autor, 'Pierwsza wersja'),
            2 => app(SnapshotRecipeVersion::class)->handle($przepis->fresh(), $autor, 'Druga wersja'),
        ];
        $this->assertSame(1, $wersje[1]->version_number);
        $this->assertSame(2, $wersje[2]->version_number);

        return [$kucharz, $przepis, $wersje];
    }

    private function proba(User $kucharz, Recipe $przepis, ?RecipeVersion $wersja, ?int $czas, int $kolejnosc): CookedEvent
    {
        $proba = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(),
            'actual_minutes' => $czas, 'cooked_at' => now()->subDays(50 - $kolejnosc),
            'note' => 'Własna notatka próby '.$kolejnosc,
        ]);
        $proba->forceFill(['recipe_version_id' => $wersja?->getKey()])->save();

        return $proba->refresh();
    }

    /** @return array{list<string>, string} */
    private function porownanieZKarty(string $html, CookedEvent $proba): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $karta = $xpath->query('//article[@id="proba-'.$proba->getKey().'"]')->item(0);
        $this->assertNotNull($karta, 'PRÓBY_2817_KARTA_MUSI_ISTNIEC');
        $wnioski = [];
        foreach ($xpath->query('.//div[@role="note"]/ul/li', $karta) as $wiersz) {
            $wnioski[] = trim($wiersz->textContent);
        }
        $this->assertNotSame([], $wnioski, 'PRÓBY_2817_WNIOSKI_MUSZA_ISTNIEC');

        return [$wnioski, preg_replace('/\s+/u', ' ', $karta->textContent) ?? ''];
    }

    /** @return iterable<string, array{?int, ?int, ?int, ?int, list<string>}> */
    public static function macierzDanych(): iterable
    {
        $wersje = [
            'V/V' => [1, 1, self::WERSJA_BEZ_ZMIAN],
            'V1/V2' => [1, 2, 'Wersja przepisu: 2 (poprzednio 1).'],
            'NULL/V' => [null, 1, self::BRAK_WERSJI],
            'V/NULL' => [1, null, self::BRAK_WERSJI],
            'NULL/NULL' => [null, null, self::BRAK_WERSJI],
        ];
        $czasy = [
            '45/45' => [45, 45, self::CZAS_BEZ_ZMIAN],
            '60/45' => [60, 45, 'Rzeczywisty czas: 45 min (poprzednio 60 min).'],
            'NULL/45' => [null, 45, self::BRAK_CZASU],
            '45/NULL' => [45, null, self::BRAK_CZASU],
            'NULL/NULL' => [null, null, self::BRAK_CZASU],
            '0/0' => [0, 0, self::CZAS_BEZ_ZMIAN],
            '0/45' => [0, 45, 'Rzeczywisty czas: 45 min (poprzednio 0 min).'],
            'NULL/0' => [null, 0, self::BRAK_CZASU],
            '0/NULL' => [0, null, self::BRAK_CZASU],
        ];
        foreach ($wersje as $nazwaWersji => [$staraWersja, $nowaWersja, $wniosekWersji]) {
            foreach ($czasy as $nazwaCzasu => [$staryCzas, $nowyCzas, $wniosekCzasu]) {
                $oczekiwane = [$wniosekWersji, $wniosekCzasu];
                if ($wniosekWersji === self::WERSJA_BEZ_ZMIAN && $wniosekCzasu === self::CZAS_BEZ_ZMIAN) {
                    $oczekiwane = [self::PELNA_ZGODNOSC];
                } elseif ($wniosekWersji === self::BRAK_WERSJI && $wniosekCzasu === self::BRAK_CZASU) {
                    $oczekiwane = [self::BRAK_OBU];
                }
                yield $nazwaWersji.'; '.$nazwaCzasu => [$staraWersja, $nowaWersja, $staryCzas, $nowyCzas, $oczekiwane];
            }
        }
    }

    /** @param list<string> $oczekiwane */
    #[DataProvider('macierzDanych')]
    public function test_macierz_domeny_i_http_nie_uznaje_braku_danych_za_zgodnosc(?int $staraWersja, ?int $nowaWersja, ?int $staryCzas, ?int $nowyCzas, array $oczekiwane): void
    {
        [$kucharz, $przepis, $wersje] = $this->przepisZWersjami();
        $starsza = $this->proba($kucharz, $przepis, $wersje[$staraWersja] ?? null, $staryCzas, 1);
        $nowsza = $this->proba($kucharz, $przepis, $wersje[$nowaWersja] ?? null, $nowyCzas, 2);
        $przed = [$starsza->getAttributes(), $nowsza->getAttributes()];
        $wynik = ProbyPrzepisu::dla($kucharz, $przepis);
        $this->assertSame(['To pierwsza zapisana próba.'], $wynik['roznice'][(string) $starsza->getKey()]);
        $this->assertSame($oczekiwane, $wynik['roznice'][(string) $nowsza->getKey()], 'PROBY_2817_OSOBNE_POLA_DOMENA');

        $html = (string) $this->actingAs($kucharz)->get(route('cooked.proby', $przepis->slug))->assertOk()->getContent();
        [$wnioski, $karta] = $this->porownanieZKarty($html, $nowsza);
        $this->assertSame($oczekiwane, $wnioski, 'PROBY_2817_OSOBNE_POLA_HTTP');
        if ($nowyCzas === null) {
            $this->assertStringContainsString('Czas: nie podano', $karta);
        } elseif ($nowyCzas === 0) {
            $this->assertStringContainsString('Zajęło mi 0 min', $karta);
            $this->assertStringNotContainsString('Czas: nie podano', $karta);
        }
        if ($nowaWersja === null) {
            $this->assertStringContainsString('Wersja przepisu: nieznana', $karta);
        }
        $this->assertSame($przed, [$starsza->fresh()->getAttributes(), $nowsza->fresh()->getAttributes()]);
        $this->assertSame(2, DB::table('cooked_events')->count());
    }

    /** @return iterable<string, array{?int, ?int, ?int, ?int, list<string>}> */
    public static function czescioweNaDrugiejStronie(): iterable
    {
        yield 'ta sama wersja, brak nowszego czasu' => [1, 1, 45, null, [self::WERSJA_BEZ_ZMIAN, self::BRAK_CZASU]];
        yield 'ten sam czas, brak starszej wersji' => [null, 1, 45, 45, [self::BRAK_WERSJI, self::CZAS_BEZ_ZMIAN]];
        yield 'inna wersja, brak nowszego czasu' => [1, 2, 45, null, ['Wersja przepisu: 2 (poprzednio 1).', self::BRAK_CZASU]];
        yield 'inny czas z zapisanym zerem, brak nowszej wersji' => [1, null, 0, 45, [self::BRAK_WERSJI, 'Rzeczywisty czas: 45 min (poprzednio 0 min).']];
    }

    /** @param list<string> $oczekiwane */
    #[DataProvider('czescioweNaDrugiejStronie')]
    public function test_http_czesciowe_porownanie_przechodzi_przez_granice_paginacji(?int $staraWersja, ?int $nowaWersja, ?int $staryCzas, ?int $nowyCzas, array $oczekiwane): void
    {
        [$kucharz, $przepis, $wersje] = $this->przepisZWersjami();
        for ($i = 1; $i < ProbyPrzepisu::NA_STRONE; $i++) {
            $this->proba($kucharz, $przepis, $wersje[1], 45, $i);
        }
        $starsza = $this->proba($kucharz, $przepis, $wersje[$staraWersja] ?? null, $staryCzas, ProbyPrzepisu::NA_STRONE);
        $nowsza = $this->proba($kucharz, $przepis, $wersje[$nowaWersja] ?? null, $nowyCzas, ProbyPrzepisu::NA_STRONE + 1);
        $html = (string) $this->actingAs($kucharz)->get(route('cooked.proby', ['recipe' => $przepis->slug, 'page' => 2]))->assertOk()->getContent();
        [$wnioski, $karta] = $this->porownanieZKarty($html, $nowsza);

        $this->assertSame($oczekiwane, $wnioski, 'PROBY_2817_OSOBNE_POLA_HTTP_PAGINACJA');
        $this->assertStringContainsString('Próba 13:', $karta);
        $this->assertStringNotContainsString('To pierwsza zapisana próba.', $karta);
        $this->assertStringNotContainsString('proba-'.$starsza->getKey(), $html);
        if ($nowyCzas === null) {
            $this->assertStringContainsString('Czas: nie podano', $karta);
        }
        if ($nowaWersja === null) {
            $this->assertStringContainsString('Wersja przepisu: nieznana', $karta);
        }
    }
}
