<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Drugi zapis zajmuje slug po odczycie wolnego adresu, lecz przed INSERT-em.
 * To jest rzeczywisty konflikt dwóch połączeń, nie sztuczny wyjątek.
 */
#[Group('dwa-polaczenia')]
final class RownoleglySlugPrzepisuTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $przepisy = [];

    protected function tearDown(): void
    {
        if ($this->przepisy !== []) {
            DB::table('recipe_versions')->whereIn('recipe_id', $this->przepisy)->delete();
            DB::table('recipe_slug_redirects')->whereIn('recipe_id', $this->przepisy)->delete();
            DB::table('recipes')->whereIn('id', $this->przepisy)->delete();
        }

        parent::tearDown();
    }

    public function test_dwa_rownolegle_przepisy_zachowuja_swoje_slugi(): void
    {
        $this->sprawdzKonfliktSluga($this->konto(), $this->konto(), false);
    }

    public function test_konflikt_sluga_w_zewnetrznej_transakcji_cofa_tylko_savepoint(): void
    {
        // Konta muszą być zatwierdzone PRZED zewnętrzną transakcją;
        // konkurencyjny INSERT na drugim połączeniu sprawdza ich FK.
        $autor = $this->konto();
        $rywal = $this->konto();

        $slug = DB::transaction(function () use ($autor, $rywal): string {
            $slug = $this->sprawdzKonfliktSluga($autor, $rywal, true);
            $this->assertSame(1, DB::transactionLevel(), 'Ponowienie nie może zamknąć zewnętrznej transakcji.');

            return $slug;
        });

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(1, Recipe::query()->where('slug', $slug.'-2')->count(),
            'Przepis utworzony po rollbacku savepointu musi przetrwać commit zewnętrznej transakcji.');
    }

    private function sprawdzKonfliktSluga(User $autor, User $rywal, bool $zewnetrznaTransakcja): string
    {
        $tytul = 'Zupa wyścigowa '.bin2hex(random_bytes(6));
        $slug = Str::slug(Str::ascii($tytul));
        $drugiePolaczenie = $this->nowePolaczenie();
        $wstawionoRywala = false;

        Recipe::creating(function (Recipe $recipe) use ($drugiePolaczenie, $rywal, $tytul, $slug, $zewnetrznaTransakcja, &$wstawionoRywala): void {
            if ($wstawionoRywala || $recipe->title !== $tytul) {
                return;
            }

            $this->assertSame($slug, $recipe->slug);
            $this->assertSame($zewnetrznaTransakcja ? 2 : 1, DB::transactionLevel(),
                'Konflikt musi zajść wewnątrz transakcji zapisu, a w drugim wariancie wewnątrz savepointu.');
            $wstawionoRywala = true;

            // Autorzy są różni, więc ich blokady wierszy nie zasłonią
            // konfliktu na UNIQUE(recipes.slug). Drugi INSERT zatwierdza
            // się przed pierwszą próbą INSERT-u przez PublishRecipe.
            $stmt = $drugiePolaczenie->prepare(
                "INSERT INTO recipes (id, author_id, title, slug, visibility, status, source_type, created_at, updated_at)
                 VALUES (gen_random_uuid(), ?, ?, ?, 'private', 'draft', 'own', now(), now()) RETURNING id",
            );
            $stmt->execute([(string) $rywal->getKey(), $tytul, $slug]);
            $this->przepisy[] = (string) $stmt->fetchColumn();
        });

        $przepis = app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: ['title' => $tytul, 'visibility' => 'private', 'source_type' => Recipe::SOURCE_OWN],
        );
        $this->przepisy[] = (string) $przepis->getKey();

        $this->assertTrue($wstawionoRywala, 'Test musi wymusić konkurencyjny INSERT.');
        $this->assertSame($slug.'-2', $przepis->slug);
        $this->assertSame(1, Recipe::query()->where('slug', $slug)->count());
        $this->assertSame(1, Recipe::query()->where('slug', $slug.'-2')->count());

        return $slug;
    }

    public function test_inny_indeks_nie_jest_ponawiany_nawet_gdy_dane_zawieraja_nazwe_sluga(): void
    {
        $autor = $this->konto();
        $tytul = 'Kontrola innego indeksu '.bin2hex(random_bytes(6));
        $proby = 0;

        Recipe::creating(function (Recipe $recipe) use ($tytul, &$proby): void {
            if ($recipe->title !== $tytul) {
                return;
            }

            $proby++;
            throw new UniqueConstraintViolationException('pgsql', 'insert into recipes ...', [], new PDOException(
                'SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "inny_indeks_unique"'
                .' DETAIL: wartość pola zawiera "recipes_slug_unique"',
            ));
        });

        try {
            app(PublishRecipe::class)->handle(
                author: $autor,
                attributes: ['title' => $tytul, 'visibility' => 'private', 'source_type' => Recipe::SOURCE_OWN],
            );
            $this->fail('Naruszenie innego indeksu musi zostać przekazane wyżej.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('inny_indeks_unique', $e->getMessage());
        }

        $this->assertSame(1, $proby, 'Innego konfliktu nie wolno ponawiać.');
    }
}
