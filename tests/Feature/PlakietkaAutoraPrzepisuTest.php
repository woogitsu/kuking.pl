<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WzorceRodzaju;
use Tests\TestCase;

/**
 * Plakietka „Autor przepisu” w rozmowie (F2, research 30.09.2026, D-333).
 *
 * Komentarz autora przepisu pod jego przepisem i pod wykonaniem tego
 * przepisu dostaje obok imienia jedno słowo tekstem, w formie z D-332
 * (neutralnie „Autor przepisu”). Każda asercja czyta KARTĘ jednego
 * komentarza (`komentarz-{id}`), nie cały dokument (PULAPKI_TESTOW.md §1),
 * a każde „nie ma” stoi obok „jest” w tym samym widoku (§4).
 */
final class PlakietkaAutoraPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function formy(): array
    {
        return [
            'żeńska' => [Profile::FORM_FEMININE, 'Autorka przepisu'],
            'męska' => [Profile::FORM_MASCULINE, 'Autor przepisu'],
            'neutralna' => [null, 'Autor przepisu'],
        ];
    }

    #[DataProvider('formy')]
    public function test_komentarz_autora_pod_wlasnym_przepisem_ma_plakietke_w_jego_formie(?string $forma, string $napis): void
    {
        [$marta, $basia, $sernik] = $this->scena($forma);
        $odAutora = $this->komentarz($marta, ['recipe_id' => $sernik->getKey()], 'Dziękuję, że piszesz.');
        $odBasi = $this->komentarz($basia, ['recipe_id' => $sernik->getKey()], 'Piekę w niedzielę.');

        $html = $this->actingAs($basia)->get(route('recipes.show', $sernik->slug))->assertOk()->getContent();

        $this->assertSame($napis, $this->plakietka($html, $odAutora));
        $this->assertNull($this->plakietka($html, $odBasi), 'Plakietkę dostał komentarz osoby, która nie jest autorem.');
    }

    public function test_odpowiedz_autora_pod_wykonaniem_jego_przepisu_ma_plakietke(): void
    {
        [$marta, $basia, $sernik] = $this->scena(Profile::FORM_FEMININE);
        $wykonanie = app(RecordCookedEvent::class)->handle($basia, $sernik, 'Wyszedł wysoki.');
        $odBasi = $this->komentarz($basia, ['cooked_event_id' => $wykonanie->getKey()], 'Następnym razem z rodzynkami.');
        $odpowiedzAutorki = $this->komentarz($marta, ['cooked_event_id' => $wykonanie->getKey(), 'parent_id' => $odBasi->getKey()], 'Pięknie wyszedł!');

        $html = $this->actingAs($basia)->get(route('cooked.show', $wykonanie))->assertOk()->getContent();

        $this->assertSame('Autorka przepisu', $this->plakietka($html, $odpowiedzAutorki));
        $this->assertNull($this->plakietka($html, $odBasi));
    }

    public function test_autor_pod_wykonaniem_cudzego_przepisu_nie_ma_plakietki(): void
    {
        [$marta, $basia] = $this->scena(null);
        $piernik = Recipe::factory()->create(['author_id' => $basia->getKey(), 'title' => 'Piernik Basi']);
        $wykonanie = app(RecordCookedEvent::class)->handle($marta, $piernik);
        $odMarty = $this->komentarz($marta, ['cooked_event_id' => $wykonanie->getKey()], 'Mój pierwszy piernik.');
        $odBasi = $this->komentarz($basia, ['cooked_event_id' => $wykonanie->getKey()], 'Cieszę się!');

        $html = $this->actingAs($marta)->get(route('cooked.show', $wykonanie))->assertOk()->getContent();

        $this->assertNull($this->plakietka($html, $odMarty), 'Autorka innego przepisu dostała plakietkę pod cudzym.');
        $this->assertSame('Autor przepisu', $this->plakietka($html, $odBasi));
    }

    public function test_przy_mojej_wersji_plakietke_ma_autor_wersji_nie_oryginalu(): void
    {
        [$marta, $basia, $sernik] = $this->scena(null);
        $wersja = Recipe::factory()->create(['author_id' => $basia->getKey(), 'title' => 'Sernik Marty po mojemu']);
        $wersja->forceFill(['forked_from_id' => $sernik->getKey(), 'forked_at' => now()])->save();
        $odMarty = $this->komentarz($marta, ['recipe_id' => $wersja->getKey()], 'Ciekawa zmiana.');
        $odBasi = $this->komentarz($basia, ['recipe_id' => $wersja->getKey()], 'Dziękuję.');

        $html = $this->get(route('recipes.show', $wersja->slug))->assertOk()->getContent();

        $this->assertSame('Autor przepisu', $this->plakietka($html, $odBasi));
        $this->assertNull($this->plakietka($html, $odMarty));
    }

    public function test_konto_autora_wymazane_nie_ma_plakietki(): void
    {
        [$marta, $basia, $sernik] = $this->scena(Profile::FORM_FEMININE);
        $odAutorki = $this->komentarz($marta, ['recipe_id' => $sernik->getKey()], 'Stary komentarz.');
        $odBasi = $this->komentarz($basia, ['recipe_id' => $sernik->getKey()], 'Kontrolny.');

        $przed = $this->get(route('recipes.show', $sernik->slug))->assertOk()->getContent();
        $this->assertSame('Autorka przepisu', $this->plakietka($przed, $odAutorki), 'Kontrola dodatnia przed wymazaniem.');

        $marta->markForDeletion();
        $this->assertTrue((new EraseAccountData)->handle($marta->refresh()));

        $html = $this->get(route('recipes.show', $sernik->slug))->assertOk()->getContent();
        $this->assertNotNull($this->karta($html, $odAutorki), 'Komentarz wymazanego konta zniknął — test mierzyłby co innego.');
        $this->assertNull($this->plakietka($html, $odAutorki));
        $this->assertNotNull($this->karta($html, $odBasi));
    }

    public function test_plakietka_nie_doklada_zapytan_na_komentarz(): void
    {
        [$marta, $basia, $sernik] = $this->scena(Profile::FORM_FEMININE);
        $this->komentarz($marta, ['recipe_id' => $sernik->getKey()], 'Pierwszy.');
        $this->komentarz($basia, ['recipe_id' => $sernik->getKey()], 'Drugi.');
        $jeden = $this->zapytania(fn () => $this->get(route('recipes.show', $sernik->slug))->assertOk());

        foreach (range(1, 6) as $i) {
            $this->komentarz($i % 2 === 0 ? $marta : $basia, ['recipe_id' => $sernik->getKey()], 'Kolejny '.$i.'.');
        }
        $html = '';
        $wiele = $this->zapytania(function () use ($sernik, &$html): void {
            $html = (string) $this->get(route('recipes.show', $sernik->slug))->assertOk()->getContent();
        });

        $this->assertSame(4, substr_count($html, 'badge-autor-przepisu'), 'Kontrola: plakietki naprawdę się renderują.');
        $this->assertSame($jeden, $wiele, "Liczba zapytań rośnie z liczbą komentarzy: {$jeden} → {$wiele}.");
    }

    public function test_plakietka_neutralna_jest_bez_rodzaju_czytelnika(): void
    {
        $this->assertSame([], WzorceRodzaju::trafienia('Autor przepisu'));
    }

    /** @return array{0: User, 1: User, 2: Recipe} */
    private function scena(?string $formaAutora): array
    {
        $marta = $this->user('marta', ['display_name' => 'Marta']);
        Profile::query()->whereKey($marta->getKey())->update(['form_of_address' => $formaAutora]);
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $sernik = Recipe::factory()->create(['author_id' => $marta->getKey(), 'title' => 'Sernik Marty']);

        return [$marta->refresh(), $basia, $sernik];
    }

    /** @param  array<string, string>  $gdzie */
    private function komentarz(User $autor, array $gdzie, string $tresc): Comment
    {
        return Comment::create([
            'author_id' => $autor->getKey(),
            'post_id' => null,
            'body' => $tresc,
            'status' => Comment::STATUS_PUBLISHED,
            ...$gdzie,
        ]);
    }

    /** Nagłówek jednego komentarza: od kotwicy do treści. */
    private function karta(string $html, Comment $komentarz): ?string
    {
        $wzor = '~id="komentarz-'.preg_quote((string) $komentarz->getKey(), '~').'".*?<p class="tekst-jak-napisano">~s';

        return preg_match($wzor, $html, $m) === 1 ? $m[0] : null;
    }

    private function plakietka(string $html, Comment $komentarz): ?string
    {
        $karta = $this->karta($html, $komentarz);
        $this->assertNotNull($karta, 'Nie ma karty komentarza '.$komentarz->body);

        return preg_match('~<span class="badge badge-autor-przepisu">([^<]+)</span>~', (string) $karta, $m) === 1 ? $m[1] : null;
    }

    private function zapytania(callable $co): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $co();
        $ile = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $ile;
    }
}
