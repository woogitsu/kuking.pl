<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\GenerateRecipeSlug;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * #2403 (DB-004): `GenerateRecipeSlug::zapisz()` ponawia zapis WYŁĄCZNIE po
 * naruszeniu `recipes_slug_unique`, z twardym limitem prób, a każdy inny błąd
 * bazy oddaje bez zmian. Wyścig na dwóch połączeniach mierzy
 * `tests/Dwa/SlugPrzepisuNieKolidujeTest.php`.
 *
 * Kontrole ujemne (wykonane):
 *  - ponowienie wyłączone → pierwszy test oblewa się 23505;
 *  - warunek na nazwę indeksu usunięty → drugi test widzi 2 próby zamiast 1;
 *  - limit prób podniesiony do 50 → trzeci test widzi 50 prób zamiast pięciu.
 */
final class SlugPrzepisuPonawiaTylkoPrzyKolizjiSluguTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor, string $slug, ?string $klucz = null): Recipe
    {
        $przepis = new Recipe([
            'author_id' => $autor->getKey(),
            'title' => 'Rosół',
            'slug' => $slug,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_DRAFT,
        ]);
        $przepis->forceFill(['klucz_wyslania' => $klucz])->save();

        return $przepis;
    }

    public function test_kolizja_slugu_w_trakcie_zapisu_daje_kolejny_wolny_slug(): void
    {
        $autor = User::factory()->create();
        $proby = [];

        $przepis = app(GenerateRecipeSlug::class)->zapisz(
            'Rosół',
            function (string $slug) use ($autor, &$proby): Recipe {
                $proby[] = $slug;

                // Pierwsza próba: konkurent zdążył zająć ten sam slug.
                if (count($proby) === 1) {
                    $this->przepis(User::factory()->create(), $slug);
                }

                return $this->przepis($autor, $slug);
            },
        );

        $this->assertSame(['rosol', 'rosol-2'], $proby);
        $this->assertSame('rosol-2', $przepis->slug);
        $this->assertSame(1, Recipe::query()->where('title', 'Rosół')->count(), 'Savepoint cofnął nieudaną próbę razem z konkurentem.');
    }

    public function test_inne_naruszenie_unikalnosci_nie_jest_ponawiane_ani_maskowane(): void
    {
        $autor = User::factory()->create();
        $klucz = (string) Str::uuid();
        $this->przepis($autor, 'cos-innego', $klucz);
        $proby = 0;

        try {
            app(GenerateRecipeSlug::class)->zapisz('Rosół', function (string $slug) use ($autor, $klucz, &$proby): Recipe {
                $proby++;

                return $this->przepis($autor, $slug, $klucz);
            });
            $this->fail('Naruszenie recipes_one_per_klucz_wyslania musi dojść do wołającego.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('recipes_one_per_klucz_wyslania', $e->getMessage());
        }

        $this->assertSame(1, $proby, 'Cudzego naruszenia unikalności nie wolno ponawiać.');
    }

    public function test_ponawianie_ma_twardy_limit_prob(): void
    {
        $autor = User::factory()->create();
        $proby = 0;

        try {
            app(GenerateRecipeSlug::class)->zapisz('Rosół', function (string $slug) use ($autor, &$proby): Recipe {
                $proby++;
                // Konkurent za każdym razem zajmuje dokładnie ten slug.
                $this->przepis(User::factory()->create(), $slug);

                return $this->przepis($autor, $slug);
            });
            $this->fail('Po wyczerpaniu prób błąd kolizji musi dojść do wołającego.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('recipes_slug_unique', $e->getMessage());
        }

        $this->assertSame(GenerateRecipeSlug::MAKSYMALNA_LICZBA_PROB, $proby);
    }

    public function test_inny_wyjatek_przechodzi_bez_ponowienia(): void
    {
        $proby = 0;
        $komunikat = null;

        try {
            app(GenerateRecipeSlug::class)->zapisz('Rosół', function () use (&$proby): Recipe {
                $proby++;
                throw new RuntimeException('awaria');
            });
        } catch (RuntimeException $e) {
            $komunikat = $e->getMessage();
        }

        $this->assertSame([1, 'awaria'], [$proby, $komunikat]);
    }
}
