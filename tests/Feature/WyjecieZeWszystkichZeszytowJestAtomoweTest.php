<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * „Wyjmij ze wszystkich moich zeszytów” jest jedną operacją (issue #1384).
 *
 * CO BYŁO ŹLE
 * `remove()` w obu akcjach (przepis i wpis) robiło osobny `detach()` dla
 * każdego zeszytu, bez wspólnej transakcji. Awaria przy drugim odpięciu
 * zostawiała pierwsze zatwierdzone: wiersz z notatką znikał, a człowiek
 * dostawał błąd zamiast zdania, co zniknęło, a co zostało.
 *
 * JAK MIERZYMY
 * `DB::beforeExecuting()` przerywa DRUGI `delete from "collection_items"`
 * PRZED jego wykonaniem — prawdziwa klasa, prawdziwe zapytanie, bez atrap.
 * Baza zostaje zdrowa (wyjątek jest po stronie PHP), więc asercje po awarii
 * czytają rzeczywisty stan, a nie „transaction aborted”.
 *
 * Kontrola ujemna: `scripts/kontrole-negatywne-alfa08.py`, wpis #1384 —
 * zdjęcie `DB::transaction` z `remove()` zostawia pierwsze odpięcie i test
 * awarii pada.
 *
 * CZEGO TEN PLIK NIE DOWODZI
 * Niczego o dwóch połączeniach (`docs/PULAPKI_TESTOW.md` §6): równoległe
 * dodanie do zeszytu w trakcie wyjęcia to osobny wyścig, którego sama
 * transakcja nie rozstrzyga i którego ta zmiana nie obiecuje.
 */
class WyjecieZeWszystkichZeszytowJestAtomoweTest extends TestCase
{
    use RefreshDatabase;

    /** Przerywa drugie odpięcie z `collection_items`, jeden raz. */
    private function zepsujDrugieOdpiecie(): void
    {
        $odpiec = 0;

        DB::beforeExecuting(function (string $zapytanie) use (&$odpiec): void {
            if (! str_starts_with($zapytanie, 'delete from "collection_items"')) {
                return;
            }

            $odpiec++;

            if ($odpiec === 2) {
                throw new RuntimeException('awaria drugiego odpięcia wymuszona testem');
            }
        });
    }

    /**
     * Dwa własne zeszyty z notatkami i jeden cudzy — ten sam przepis/wpis.
     *
     * @return array{0: User, 1: list<Collection>, 2: Collection}
     */
    private function trzyZeszyty(string $kolumna, string $id): array
    {
        $basia = $this->user('basia_1384');
        $obca = $this->user('obca_1384');

        $wlasne = [];

        foreach (['A' => 'mniej soli', 'B' => 'dla Ani bez orzechów'] as $nazwa => $notatka) {
            $zeszyt = $basia->collections()->create(['name' => $nazwa, 'visibility' => 'private']);
            DB::table('collection_items')->insert([
                'collection_id' => $zeszyt->getKey(),
                $kolumna => $id,
                'note' => $notatka,
                'created_at' => now()->subDays(10),
            ]);
            $wlasne[] = $zeszyt;
        }

        $cudzy = $obca->collections()->create(['name' => 'Cudzy', 'visibility' => 'private']);
        DB::table('collection_items')->insert([
            'collection_id' => $cudzy->getKey(),
            $kolumna => $id,
            'note' => 'cudza notatka',
            'created_at' => now()->subDays(3),
        ]);

        return [$basia, $wlasne, $cudzy];
    }

    /** @return array<string, array{note: mixed, created_at: mixed}> */
    private function wiersze(string $kolumna, string $id): array
    {
        return DB::table('collection_items')
            ->where($kolumna, $id)
            ->orderBy('collection_id')
            ->get(['collection_id', 'note', 'created_at'])
            ->mapWithKeys(fn ($w) => [(string) $w->collection_id => ['note' => $w->note, 'created_at' => $w->created_at]])
            ->all();
    }

    /** @return array<string, array{0: string}> */
    public static function rodzaje(): array
    {
        return [
            'przepis' => ['przepis'],
            'wpis' => ['wpis'],
        ];
    }

    /** @return array{0: string, 1: string, 2: callable(User, ?Collection): array} */
    private function rodzaj(string $rodzaj): array
    {
        if ($rodzaj === 'przepis') {
            $przepis = Recipe::factory()->create();

            return ['recipe_id', (string) $przepis->getKey(),
                fn (User $u, ?Collection $c = null): array => app(SaveRecipeToCollection::class)->remove($u, $przepis, $c)];
        }

        $wpis = Post::factory()->create();

        return ['post_id', (string) $wpis->getKey(),
            fn (User $u, ?Collection $c = null): array => app(SavePostToCollection::class)->remove($u, $wpis, $c)];
    }

    #[DataProvider('rodzaje')]
    public function test_awaria_drugiego_odpiecia_zostawia_wszystkie_zapisy_z_notatkami(string $rodzaj): void
    {
        [$kolumna, $id, $wyjmij] = $this->rodzaj($rodzaj);
        [$basia] = $this->trzyZeszyty($kolumna, $id);

        $przed = $this->wiersze($kolumna, $id);
        $this->assertCount(3, $przed);

        $this->zepsujDrugieOdpiecie();

        $zlapany = null;

        try {
            $wyjmij($basia, null);
        } catch (RuntimeException $e) {
            $zlapany = $e;
        }

        $this->assertNotNull($zlapany, 'Awaria nie przerwała wyjęcia — test nie zmierzył tego, co miał.');
        $this->assertStringContainsString('awaria drugiego odpięcia', $zlapany->getMessage());

        // CAŁE ZNALEZISKO #1384: przed poprawką zostawały tu 2 wiersze
        // (pierwszy własny zeszyt już bez przepisu i bez notatki).
        $this->assertSame($przed, $this->wiersze($kolumna, $id),
            'Po awarii w środku wyjęcia część zapisów zniknęła — wyjęcie nie jest atomowe (#1384).');
    }

    /**
     * KONTROLA DODATNIA: bez awarii schodzą oba własne, cudzy zostaje,
     * wynik mówi „2”, a ponowienie mówi „0”.
     */
    #[DataProvider('rodzaje')]
    public function test_pelne_wyjecie_zdejmuje_oba_wlasne_i_zostawia_cudzy(string $rodzaj): void
    {
        [$kolumna, $id, $wyjmij] = $this->rodzaj($rodzaj);
        [$basia, , $cudzy] = $this->trzyZeszyty($kolumna, $id);

        $zdjete = $wyjmij($basia, null);

        $this->assertCount(2, $zdjete);
        $this->assertSame(['dla Ani bez orzechów', 'mniej soli'],
            collect($zdjete)->pluck('note')->sort()->values()->all(),
            'Zdjęte wiersze mają nieść notatki — z nich korzysta „Przywróć do zeszytu”.');
        $this->assertSame([(string) $cudzy->getKey()], array_keys($this->wiersze($kolumna, $id)));

        $this->assertSame([], $wyjmij($basia, null), 'Ponowienie nie ma już czego zdejmować.');
    }

    /**
     * Wskazany zeszyt zachowuje wąski zakres — transakcja go nie rozszerza.
     */
    #[DataProvider('rodzaje')]
    public function test_wyjecie_ze_wskazanego_zeszytu_zdejmuje_tylko_ten(string $rodzaj): void
    {
        [$kolumna, $id, $wyjmij] = $this->rodzaj($rodzaj);
        [$basia, $wlasne] = $this->trzyZeszyty($kolumna, $id);

        $zdjete = $wyjmij($basia, $wlasne[0]);

        $this->assertSame([(string) $wlasne[0]->getKey()], array_column($zdjete, 'collection_id'));
        $this->assertCount(2, $this->wiersze($kolumna, $id));
        $this->assertArrayHasKey((string) $wlasne[1]->getKey(), $this->wiersze($kolumna, $id));
    }
}
