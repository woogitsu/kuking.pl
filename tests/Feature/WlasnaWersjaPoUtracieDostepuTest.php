<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\ZrobWlasnaWersje;
use App\Models\AuditLogEntry;
use App\Models\Block;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Zrób swoją wersję" sprawdza dostęp do oryginału na ŚWIEŻYM stanie, w tej
 * samej transakcji co kopia (issue #2323).
 *
 * CO SIĘ DZIAŁO
 * `ZrobWlasnaWersje::handle()` pytało `RecipePolicy::fork` PRZED transakcją,
 * na modelu podanym z zewnątrz (kontroler wczytał go na początku żądania),
 * a składniki i kroki kopiowało z tego samego, starego modelu. Zmiana, która
 * zatwierdziła się w międzyczasie — przepis przełączony na „tylko ja",
 * ukryty lub zdjęty przez moderację, blokada, ban autora — nie zatrzymywała
 * kopii: pełna treść lądowała w szkicu osoby, która już nie miała prawa jej
 * widzieć.
 *
 * JAK TO MIERZYMY NA JEDNYM POŁĄCZENIU
 * Model wczytujemy, kiedy dostęp JEST; potem zmieniamy stan w bazie (tak,
 * jakby równoległe żądanie zdążyło się zatwierdzić) i dopiero wtedy wołamy
 * akcję ze starym modelem. Wyścig z transakcją W TOKU mierzy
 * `tests/Dwa/WlasnaWersjaKontraUtrataDostepuNaDwochPolaczeniachTest.php`.
 */
class WlasnaWersjaPoUtracieDostepuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Recipe} */
    private function przygotuj(): array
    {
        $autor = $this->user('basia2323');
        $kopiujacy = $this->user('jan2323');

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Rosół babci Zofii',
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => 'kura rosołowa', 'position' => 1]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 1, 'instruction' => 'Gotuj trzy godziny na małym ogniu.']);

        // Stary model — wczytany, kiedy dostęp był, dokładnie jak w kontrolerze.
        $staryModel = Recipe::query()->whereKey($przepis->getKey())->firstOrFail();
        $this->assertTrue($kopiujacy->can('fork', $staryModel), 'Przygotowanie: przed zmianą kopia jest dozwolona.');

        return [$autor, $kopiujacy, $staryModel];
    }

    /** @return iterable<string, array{string}> */
    public static function utratyDostepu(): iterable
    {
        foreach (['prywatny', 'ukryty', 'zdjety', 'autor_blokuje', 'kopiujacy_blokuje', 'autor_zbanowany', 'kopiujacy_zbanowany'] as $zmiana) {
            yield $zmiana => [$zmiana];
        }
    }

    #[DataProvider('utratyDostepu')]
    public function test_kopia_odmawia_po_zatwierdzonej_utracie_dostepu(string $zmiana): void
    {
        [$autor, $kopiujacy, $staryModel] = $this->przygotuj();

        // Zmiana „z równoległego żądania" — prosto w bazie, bez dotykania
        // modeli, które akcja dostaje na wejściu.
        match ($zmiana) {
            'prywatny' => DB::table('recipes')->where('id', $staryModel->getKey())->update(['visibility' => 'private']),
            'ukryty' => DB::table('recipes')->where('id', $staryModel->getKey())->update(['status' => Recipe::STATUS_HIDDEN]),
            'zdjety' => DB::table('recipes')->where('id', $staryModel->getKey())->update(['deleted_at' => now()]),
            'autor_blokuje' => Block::create(['blocker_id' => $autor->getKey(), 'blocked_id' => $kopiujacy->getKey()]),
            'kopiujacy_blokuje' => Block::create(['blocker_id' => $kopiujacy->getKey(), 'blocked_id' => $autor->getKey()]),
            'autor_zbanowany' => DB::table('users')->where('id', $autor->getKey())->update(['status' => User::STATUS_BANNED]),
            'kopiujacy_zbanowany' => DB::table('users')->where('id', $kopiujacy->getKey())->update(['status' => User::STATUS_BANNED]),
            default => throw new \LogicException('Nieobsłużony wariant w match.'),
        };

        $odmowa = null;

        try {
            app(ZrobWlasnaWersje::class)->handle($kopiujacy, $staryModel);
        } catch (AuthorizationException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Kopia powstała po zatwierdzonej utracie dostępu ('.$zmiana.').');

        // Ani szkicu, ani skopiowanej treści, ani wpisu audytu.
        $this->assertSame(0, Recipe::withTrashed()->where('author_id', $kopiujacy->getKey())->count());
        $this->assertSame(0, RecipeIngredient::query()->where('ingredient_text', 'kura rosołowa')->where('recipe_id', '!=', $staryModel->getKey())->count());
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'recipe.forked')->count());
    }

    public function test_kontrola_dodatnia_bez_zmiany_kopia_powstaje_ze_swiezej_tresci(): void
    {
        [, $kopiujacy, $staryModel] = $this->przygotuj();

        // Zmiana, która dostępu NIE odbiera: autor poprawił tytuł i krok.
        // Kopia ma powstać — i to z treści zatwierdzonej, nie ze starego modelu.
        DB::table('recipes')->where('id', $staryModel->getKey())->update(['title' => 'Rosół babci Zofii, poprawiony']);
        DB::table('recipe_steps')->where('recipe_id', $staryModel->getKey())->update(['instruction' => 'Gotuj cztery godziny.']);

        $wersja = app(ZrobWlasnaWersje::class)->handle($kopiujacy, $staryModel);

        $this->assertTrue($wersja->wasRecentlyCreated);
        $this->assertSame('Rosół babci Zofii, poprawiony', $wersja->title);
        $this->assertSame(['Gotuj cztery godziny.'], $wersja->steps()->pluck('instruction')->all());
        $this->assertSame((string) $staryModel->getKey(), (string) $wersja->forked_from_id);
    }
}
