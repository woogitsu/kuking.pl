<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresja #2276 (BP-03): wersja 1 przepisu, który przed publikacją był
 * szkicem, jest w historii „Pierwszą publikacją", a nie „Aktualizacją
 * przepisu".
 *
 * Najczęstsza droga w produkcie: kreator zapisuje szkic (czasem kilka razy),
 * a potem człowiek klika „Opublikuj". `PublishRecipe` rozpoznawał pierwszą
 * publikację po `$existing === null`, więc tu — gdzie wiersz przepisu już
 * był — publiczny ekran historii pokazywał pierwszą wersję jak poprawkę
 * wersji, której nie ma.
 */
class PierwszaPublikacjaSzkicuWHistoriiTest extends TestCase
{
    use RefreshDatabase;

    private const SKLADNIKI = [['text' => '1 kura rosołowa'], ['text' => '2 marchewki']];

    private const KROKI = [['instruction' => 'Zalej kurę zimną wodą i gotuj na małym ogniu przez trzy godziny.']];

    public function test_pierwsza_publikacja_szkicu_jest_w_historii_pierwsza_publikacja(): void
    {
        $basia = $this->user('basia');
        $publikuj = app(PublishRecipe::class);
        $dane = ['title' => 'Rosół babci Zofii', 'visibility' => 'public'];

        $szkic = $publikuj->handle($basia, $dane, self::SKLADNIKI, self::KROKI, publish: false);
        // Drugi zapis szkicu (np. „Zapisz szkic" po poprawce) też wersji nie tworzy.
        $szkic = $publikuj->handle($basia, $dane, self::SKLADNIKI, self::KROKI, publish: false, existing: $szkic->fresh());
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);
        $this->assertSame(0, RecipeVersion::query()->where('recipe_id', $szkic->getKey())->count());

        $publikuj->handle($basia, $dane, self::SKLADNIKI, self::KROKI, publish: true, existing: $szkic->fresh());

        $wersja = RecipeVersion::query()->where('recipe_id', $szkic->getKey())->sole();
        $this->assertSame(1, (int) $wersja->version_number);
        $this->assertSame(
            'Pierwsza publikacja',
            $wersja->change_note,
            'Wersja 1 przepisu, który był szkicem, jest w historii podpisana jak poprawka opublikowanego przepisu.',
        );
    }

    /**
     * Druga strona tej samej granicy: ponowna publikacja ZE ZMIANĄ przepisu,
     * który ma już historię, nadal jest „Aktualizacją przepisu" — poprawka
     * nie może zamienić każdej publikacji w „pierwszą".
     */
    public function test_ponowna_publikacja_szkicu_po_pierwszej_jest_aktualizacja(): void
    {
        $basia = $this->user('basia');
        $publikuj = app(PublishRecipe::class);

        $szkic = $publikuj->handle($basia, ['title' => 'Rosół babci Zofii', 'visibility' => 'public'], self::SKLADNIKI, self::KROKI, publish: false);
        $przepis = $publikuj->handle($basia, ['title' => 'Rosół babci Zofii', 'visibility' => 'public'], self::SKLADNIKI, self::KROKI, publish: true, existing: $szkic->fresh());
        $publikuj->handle($basia, ['title' => 'Rosół babci Zofii z lubczykiem', 'visibility' => 'public'], self::SKLADNIKI, self::KROKI, publish: true, existing: $przepis->fresh());

        $opisy = RecipeVersion::query()
            ->where('recipe_id', $szkic->getKey())
            ->orderBy('version_number')
            ->pluck('change_note')
            ->all();

        $this->assertSame(['Pierwsza publikacja', 'Aktualizacja przepisu'], $opisy);
    }
}
