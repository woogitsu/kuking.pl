<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2881: usunięcie przepisu po wyborze partii wersji, przed końcowym DELETE.
 * Bariera trzyma rodzica; sprzątanie musi czekać na jego blokadę i zobaczyć
 * zatwierdzony kosz. Dane są zatwierdzane, bez RefreshDatabase (D-105).
 */
#[Group('dwa-polaczenia')]
final class UsunieciePrzepisuKontraRetencjaWersjiTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $przepisy = [];

    protected function tearDown(): void
    {
        if ($this->przepisy !== []) {
            DB::table('recipe_versions')->whereIn('recipe_id', $this->przepisy)->delete();
            DB::table('recipes')->whereIn('id', $this->przepisy)->delete();
        }

        parent::tearDown();
    }

    public function test_usuniecie_zatwierdzone_przed_kasowaniem_zachowuje_wersje(): void
    {
        $autor = $this->konto();
        $znacznik = bin2hex(random_bytes(5));
        $przepis = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Przepis retencji '.$znacznik,
            'slug' => 'przepis-retencji-'.$znacznik,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subYears(4),
        ]);
        $this->przepisy[] = (string) $przepis->getKey();

        $stara = null;
        foreach ([1, 2, 3, 4] as $numer) {
            $wersja = RecipeVersion::create([
                'recipe_id' => $przepis->getKey(),
                'editor_id' => $autor->getKey(),
                'version_number' => $numer,
                'change_note' => 'Wersja '.$numer,
                'snapshot' => ['title' => $przepis->title, 'summary' => '', 'ingredients' => [], 'steps' => []],
            ]);
            if ($numer === 1) {
                $stara = (string) $wersja->getKey();
                DB::table('recipe_versions')->where('id', $stara)->update(['created_at' => now()->subYears(4)]);
            }
        }
        $this->assertSame(4, RecipeVersion::where('recipe_id', $przepis->getKey())->count());

        $bariera = $this->bariera('SELECT 1 FROM recipes WHERE id = ? FOR UPDATE', [(string) $przepis->getKey()]);
        try {
            $sprzatanie = $this->wTle('sprzataj-wersje-2881', []);
            $this->czekajNaZablokowane(1);

            // Rodzic znika, gdy sprzątanie stoi już przy jego blokadzie.
            $zmieniono = $bariera->prepare('UPDATE recipes SET deleted_at = now() WHERE id = ?');
            $zmieniono->execute([(string) $przepis->getKey()]);
            $this->assertSame(1, $zmieniono->rowCount());
            $bariera->commit();

            $wynik = $sprzatanie->wynik();
            $this->assertBezZakleszczenia($wynik, 'retencja wersji kontra kosz');
            $this->assertTrue($wynik['ok'], 'Sprzątanie nie zakończyło się prawidłowo: '.$wynik['komunikat']);
            $this->assertSame(0, $wynik['wartosc']['skasowano'], 'KOSZ_2881_PRZELOT_PO_WYBORZE');
            $this->assertDatabaseHas('recipe_versions', ['id' => $stara]);
            $this->assertNotNull(Recipe::withTrashed()->findOrFail($przepis->getKey())->deleted_at);
        } finally {
            if ($bariera->inTransaction()) {
                $bariera->rollBack();
            }
        }
    }
}
