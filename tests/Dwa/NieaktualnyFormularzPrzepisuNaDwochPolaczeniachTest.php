<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Formularz odczytał rewizję 0. Druga sesja blokuje przepis, zapisuje rewizję
 * 1 i dopiero wtedy wpuszcza pierwszy zapis. Odczyt wersji przed lockiem
 * przepuściłby ten pierwszy zapis i nadpisał nowszy tytuł.
 */
#[Group('dwa-polaczenia')]
final class NieaktualnyFormularzPrzepisuNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $recipes = [];

    protected function tearDown(): void
    {
        if ($this->recipes !== []) {
            try {
                DB::table('recipe_versions')->whereIn('recipe_id', $this->recipes)->delete();
                DB::table('recipe_slug_redirects')->whereIn('recipe_id', $this->recipes)->delete();
                DB::table('recipes')->whereIn('id', $this->recipes)->delete();
            } catch (\Throwable $error) {
                fwrite(STDERR, "\nSprzątanie przepisów testu nie powiodło się: ".$error->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    public function test_zapis_czekajacy_na_blokade_odrzuca_formularz_starszy_od_zatwierdzonej_rewizji(): void
    {
        $author = $this->konto();
        $slug = 'zupa-konflikt-'.bin2hex(random_bytes(6));
        $recipe = Recipe::create([
            'author_id' => $author->id,
            'title' => 'Pierwotna zupa',
            'slug' => $slug,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $this->recipes[] = (string) $recipe->id;
        $this->assertSame(0, $recipe->fresh()->content_revision);

        $barrier = $this->bariera('SELECT id FROM recipes WHERE id = ? FOR UPDATE', [(string) $recipe->id]);

        try {
            $edit = $this->wTle('edytuj-przepis', [
                'autor' => (string) $author->id,
                'przepis' => (string) $recipe->id,
                'tytul' => 'Nieaktualny zapis',
                'skladnik' => 'stara marchew',
                'rewizja' => '0',
            ]);
            // Kontrola dodatnia: zapis NAPRAWDĘ stoi w kolejce po lock.
            $this->czekajNaZablokowane(1);

            $newer = $barrier->prepare('UPDATE recipes SET title = ?, content_revision = content_revision + 1 WHERE id = ?');
            $newer->execute(['Nowsza zupa', (string) $recipe->id]);
            $this->assertSame(1, $newer->rowCount());
            $barrier->commit();
        } finally {
            if ($barrier->inTransaction()) {
                $barrier->rollBack();
            }
        }

        $result = $edit->wynik();
        $this->assertBezZakleszczenia($result, 'zapis starego formularza');
        $this->assertFalse($result['ok'], 'Stary formularz nadpisał nowszy zapis.');
        $this->assertStringContainsString('zmienił się od otwarcia formularza', (string) $result['komunikat']);
        $this->assertSame('Nowsza zupa', $recipe->fresh()->title);
        $this->assertSame(1, $recipe->fresh()->content_revision);
        $this->assertSame(0, $recipe->ingredients()->count());
        $this->assertSame(0, $recipe->versions()->count());
    }
}
