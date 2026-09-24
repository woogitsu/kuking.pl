<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #956 — licznik „ile razy ugotowany przez innych" liczy się JEDNYM
 * zapytaniem dla wszystkich przepisów, nie osobnym COUNT na każdy.
 *
 * Do 24 września `CollectUserExportData::recipes()` wołało w mapperze
 * `$recipe->cookedEvents()->count()`. Konto z N przepisami robiło N
 * dodatkowych zapytań do `cooked_events` — dokładnie u najbardziej
 * aktywnych osób, w zadaniu z limitem 900 s na wspólnej kolejce.
 *
 * Test nie przypina absolutnej liczby zapytań (ta zmienia się z każdą nową
 * sekcją paczki). Porównuje dwa konta różniące się TYLKO liczbą przepisów:
 * stały narzut jest dozwolony, wzrost proporcjonalny do przepisów — nie.
 * Kontrola ujemna: przywrócenie `cookedEvents()->count()` w mapperze daje
 * 30 zapytań do `cooked_events` więcej przy 30 przepisach niż przy 3.
 */
class EksportLiczyWykonaniaZbiorczoTest extends TestCase
{
    use RefreshDatabase;

    public function test_liczba_zapytan_nie_rosnie_z_liczba_przepisow(): void
    {
        $kilka = $this->queriesFor($this->authorWithRecipes('basia', 3));
        $duzo = $this->queriesFor($this->authorWithRecipes('zenek', 30));

        $this->assertSame(
            $this->touchingCookedEvents($kilka),
            $this->touchingCookedEvents($duzo),
            'Zapytania do cooked_events nie mogą rosnąć z liczbą przepisów.',
        );
        $this->assertSame(count($kilka), count($duzo), 'Kolektor nie może pytać bazy osobno o każdy przepis.');
    }

    /**
     * Kontrola DODATNIA: zbiorczy licznik oddaje te same wartości co dawny
     * COUNT per przepis — dla zera, jednego i wielu wykonań.
     */
    public function test_licznik_wykonan_jest_poprawny_dla_zera_jednego_i_wielu(): void
    {
        $basia = $this->user('basia');
        $zero = Recipe::factory()->for($basia, 'author')->create(['title' => 'Zero', 'published_at' => now()->subDays(3)]);
        $jeden = Recipe::factory()->for($basia, 'author')->create(['title' => 'Jeden', 'published_at' => now()->subDays(2)]);
        $wiele = Recipe::factory()->for($basia, 'author')->create(['title' => 'Wiele', 'published_at' => now()->subDay()]);

        CookedEvent::factory()->for($jeden)->create();
        CookedEvent::factory()->count(4)->for($wiele)->create();

        $recipes = collect($this->collect($basia)['przepisy'])
            ->mapWithKeys(fn (array $r): array => [$r['tytul'] => $r['ile_razy_ugotowany_przez_innych']]);

        $this->assertSame(['Zero' => 0, 'Jeden' => 1, 'Wiele' => 4], $recipes->all());
        $this->assertSame($zero->cookedEvents()->count(), $recipes['Zero']);
        $this->assertSame($wiele->cookedEvents()->count(), $recipes['Wiele']);
    }

    private function authorWithRecipes(string $username, int $count): User
    {
        $user = $this->user($username);

        Recipe::factory()->count($count)->for($user, 'author')->create()
            ->each(fn (Recipe $recipe) => CookedEvent::factory()->count(2)->for($recipe)->create());

        return $user;
    }

    /** @return list<string> */
    private function queriesFor(User $user): array
    {
        $user = User::query()->findOrFail($user->getKey());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $data = $this->collect($user);
        DB::disableQueryLog();

        $this->assertNotEmpty($data['przepisy']);

        return array_column(DB::getQueryLog(), 'query');
    }

    /** @param list<string> $queries */
    private function touchingCookedEvents(array $queries): int
    {
        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'cooked_events')));
    }

    /** @return array<string, mixed> */
    private function collect(User $user): array
    {
        return (new CollectUserExportData)->handle($user, new ExportPhotoPlan($user), Carbon::now());
    }
}
