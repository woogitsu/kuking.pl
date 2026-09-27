<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/** Żądanie autora mija autoryzację, po czym czeka na decyzję moderatora. */
#[Group('dwa-polaczenia')]
final class WartosciOdzywczePoModeracjiTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $recipes = [];

    protected function tearDown(): void
    {
        if ($this->recipes !== []) {
            DB::table('recipes')->whereIn('id', $this->recipes)->delete();
        }

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function decyzjeModeratora(): array
    {
        return [
            'ukrycie' => [Recipe::STATUS_HIDDEN],
            'zdjęcie' => [Recipe::STATUS_REMOVED],
            'soft delete' => ['deleted'],
        ];
    }

    #[Test]
    #[DataProvider('decyzjeModeratora')]
    public function autor_czekajacy_na_blokade_nie_zapisuje_ustawienia_po_decyzji_moderatora(string $decyzja): void
    {
        $autor = $this->konto();
        $recipe = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Zupa po moderacji',
            'slug' => 'zupa-wartosci-'.bin2hex(random_bytes(6)),
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->fresh();
        $this->recipes[] = (string) $recipe->getKey();
        $this->assertTrue((bool) $recipe->pokazuj_wartosci_odzywcze);

        $bariera = $this->bariera('SELECT id FROM recipes WHERE id = ? FOR UPDATE', [(string) $recipe->getKey()]);
        try {
            $zadanie = $this->wTle('przelacz-wartosci-2112', [
                'autor' => (string) $autor->getKey(),
                'przepis' => (string) $recipe->getKey(),
            ]);
            // Żądanie przeszło route binding i Policy, a jego zapis naprawdę
            // stoi w kolejce PostgreSQL za blokadą tego samego przepisu.
            $this->czekajNaZablokowane(1);

            if ($decyzja === 'deleted') {
                $zmiana = $bariera->prepare('UPDATE recipes SET deleted_at = now() WHERE id = ?');
                $zmiana->execute([(string) $recipe->getKey()]);
            } else {
                $zmiana = $bariera->prepare('UPDATE recipes SET status = ? WHERE id = ?');
                $zmiana->execute([$decyzja, (string) $recipe->getKey()]);
            }
            $this->assertSame(1, $zmiana->rowCount());
            $bariera->commit();
        } finally {
            if ($bariera->inTransaction()) {
                $bariera->rollBack();
            }
        }

        $wynik = $zadanie->wynik();
        $this->assertBezZakleszczenia($wynik, 'przełącznik po moderacji');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(302, $wynik['wartosc']['status']);
        $this->assertNull($wynik['wartosc']['zapisane'], 'Autor nie może dostać komunikatu sukcesu.');
        $this->assertNotEmpty($wynik['wartosc']['blad'], 'Autor musi dostać czytelną odmowę.');

        $swiezy = Recipe::withTrashed()->findOrFail($recipe->getKey());
        $this->assertTrue((bool) $swiezy->pokazuj_wartosci_odzywcze);
        $this->assertSame($decyzja === 'deleted' ? Recipe::STATUS_PUBLISHED : $decyzja, $swiezy->status);
        $this->assertSame($decyzja === 'deleted', $swiezy->trashed());
    }
}
