<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\RemoveUnavailableFromCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Domain\Notifications\Actions\NotifyRecipeSaved;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * „Wyjmij niedostępne zapisy” wycofuje udział osoby w NIEPRZECZYTANYM
 * zbiorczym powiadomieniu autora (#2205), tak samo jak zwykłe wyjęcie
 * przepisu z zeszytu (#906).
 *
 * Przed poprawką `RemoveUnavailableFromCollection` kasowała wiersze
 * `collection_items` bezpośrednio i omijała `NotifyRecipeSaved::cofnij()`:
 * po czyszczeniu identyfikator osoby zostawał w `data.savers`, choć jej
 * ostatni zapis zniknął. Przeczytana historia zostaje bez zmian.
 */
final class WyjecieNiedostepnychCofaUdzialWPowiadomieniuTest extends TestCase
{
    use RefreshDatabase;

    public function test_ostatnia_kopia_jedynej_osoby_usuwa_nieprzeczytane_powiadomienie(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        $this->assertSame(1, $this->ilePartii($autor));

        $przepis->update(['visibility' => 'private']);
        $this->assertSame(1, $this->wyjmij($osoba, $osoba->defaultCollection()));

        $this->assertSame(0, $this->ilePartii($autor));
        $this->assertFalse($osoba->defaultCollection()->recipes()->withTrashed()->whereKey($przepis->getKey())->exists());
    }

    public function test_przy_innych_zapisujacych_agregat_zachowuje_ich_bez_zmiany(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        $druga = $this->user('druga_zapisujaca');
        $trzecia = $this->user('trzecia_zapisujaca');
        app(SaveRecipeToCollection::class)->handle($druga, $przepis);
        app(SaveRecipeToCollection::class)->handle($trzecia, $przepis);
        $this->assertSame(
            [$osoba->getKey(), $druga->getKey(), $trzecia->getKey()],
            $this->jednaPartia($autor)->data['savers'],
        );

        $przepis->update(['visibility' => 'private']);
        $this->wyjmij($osoba, $osoba->defaultCollection());

        $partia = $this->jednaPartia($autor);
        $this->assertSame([$druga->getKey(), $trzecia->getKey()], $partia->data['savers']);
        $this->assertSame(1, $partia->data['others_count']);
        $this->assertSame($druga->getKey(), $partia->actor_id);
    }

    public function test_ten_sam_przepis_w_drugim_wlasnym_zeszycie_zostawia_udzial(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        $drugi = $this->zeszyt($osoba, 'Na święta');
        app(SaveRecipeToCollection::class)->handle($osoba, $przepis, $drugi);
        // Kontrola dodatnia: przepis leży w dwóch zeszytach, a powiadomienie jest jedno.
        $this->assertSame(2, $osoba->collections()->whereHas('recipes', fn ($q) => $q->whereKey($przepis->getKey()))->count());
        $this->assertSame(1, $this->ilePartii($autor));

        $przepis->update(['visibility' => 'private']);
        $this->assertSame(1, $this->wyjmij($osoba, $osoba->defaultCollection()));

        // W drugim zeszycie przepis nadal jest, więc udział zostaje.
        $this->assertDatabaseHas('collection_items', ['collection_id' => $drugi->getKey(), 'recipe_id' => $przepis->getKey()]);
        $this->assertSame([$osoba->getKey()], $this->jednaPartia($autor)->data['savers']);

        // Wyjęcie także drugiej kopii domyka sprawę.
        $this->assertSame(1, $this->wyjmij($osoba, $drugi));
        $this->assertSame(0, $this->ilePartii($autor));
    }

    public function test_przeczytane_powiadomienie_jest_historia_i_nie_jest_ruszane(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        $druga = $this->user('druga_zapisujaca');
        app(SaveRecipeToCollection::class)->handle($druga, $przepis);
        Notification::query()->where('user_id', $autor->getKey())->update(['read_at' => now()]);
        $przed = Notification::query()->where('user_id', $autor->getKey())->firstOrFail()->getAttributes();

        $przepis->update(['visibility' => 'private']);
        $this->assertSame(1, $this->wyjmij($osoba, $osoba->defaultCollection()));

        $po = Notification::query()->where('user_id', $autor->getKey())->firstOrFail();
        $this->assertSame($przed['data'], $po->getAttributes()['data']);
        $this->assertSame([$osoba->getKey(), $druga->getKey()], $po->data['savers']);
        $this->assertNotNull($po->read_at);
    }

    public function test_mieszana_partia_przepisow_i_wpisow_czyszczona_bez_kompensacji_dla_wpisow(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        $drugiPrzepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $zostaje = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        app(SaveRecipeToCollection::class)->handle($osoba, $drugiPrzepis);
        app(SaveRecipeToCollection::class)->handle($osoba, $zostaje);
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $osoba->defaultCollection()->posts()->attach($wpis->getKey());
        $this->assertSame(3, $this->ilePartii($autor));

        $przepis->update(['visibility' => 'private']);
        $drugiPrzepis->delete();
        $wpis->delete();

        $this->assertSame(3, $this->wyjmij($osoba, $osoba->defaultCollection()));

        // Wycofane oba niedostępne przepisy, widoczny zostaje.
        $this->assertSame(1, $this->ilePartii($autor));
        $this->assertSame([$osoba->getKey()], Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('data->recipe_id', $zostaje->getKey())
            ->firstOrFail()->data['savers']);
        $this->assertSame(0, $osoba->defaultCollection()->posts()->withTrashed()->count());
    }

    public function test_powtorzone_identyfikatory_w_partii_sa_bezpieczne(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        $druga = $this->user('druga_zapisujaca');
        app(SaveRecipeToCollection::class)->handle($druga, $przepis);
        $osoba->defaultCollection()->recipes()->detach($przepis->getKey());

        app(NotifyRecipeSaved::class)->cofnijJesliNigdzieNieZostalDlaWielu(
            $osoba,
            [$przepis->getKey(), $przepis->getKey(), $przepis->getKey()],
        );

        $this->assertSame([$druga->getKey()], $this->jednaPartia($autor)->data['savers']);
    }

    public function test_konflikt_odcisku_niczego_nie_wyjmuje_i_nie_zmienia_powiadomien(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        $przepis->update(['visibility' => 'private']);
        $zeszyt = $osoba->defaultCollection();
        $odcisk = $this->odcisk($zeszyt, $osoba);

        // Między otwarciem ekranu a potwierdzeniem przepis wraca.
        $przepis->update(['visibility' => 'public']);
        $inny = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'private']);
        $zeszyt->recipes()->attach($inny->getKey());

        $this->actingAs($osoba)
            ->from(route('collections.show', $zeszyt))
            ->delete(route('collections.unavailable.destroy', $zeszyt), ['zakres' => $odcisk])
            ->assertSessionHasErrors('zakres');

        $this->assertSame(2, $zeszyt->recipes()->count());
        $this->assertSame([$osoba->getKey()], $this->jednaPartia($autor)->data['savers']);
    }

    public function test_wyjatek_w_kompensacji_wycofuje_cala_transakcje(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        $przepis->update(['visibility' => 'private']);
        $zeszyt = $osoba->defaultCollection();
        $odcisk = $this->odcisk($zeszyt, $osoba);

        // Awaria dokładnie w kroku kompensacji: usunięcie jedynej partii
        // (prawdziwa ścieżka `NotifyRecipeSaved::cofnij()`) rzuca wyjątek.
        Notification::deleting(static function (): void {
            throw new RuntimeException('awaria kompensacji');
        });

        try {
            app(RemoveUnavailableFromCollection::class)->handle($osoba, $zeszyt, $odcisk);
            $this->fail('Wyjątek w kompensacji miał wyjść na zewnątrz.');
        } catch (RuntimeException $e) {
            $this->assertSame('awaria kompensacji', $e->getMessage());
        }

        // Ani pivot, ani powiadomienie nie zmieniły się: wszystko albo nic.
        $this->assertDatabaseHas('collection_items', ['collection_id' => $zeszyt->getKey(), 'recipe_id' => $przepis->getKey()]);
        $this->assertSame([$osoba->getKey()], $this->jednaPartia($autor)->data['savers']);
    }

    public function test_niedostepnosc_przez_blokade_tez_wycofuje_udzial(): void
    {
        [$autor, $osoba, $przepis] = $this->zapisanyPrzepis();
        app(BlockUser::class)->handle($autor, $osoba);

        $this->assertSame(1, $this->wyjmij($osoba, $osoba->defaultCollection()));

        $this->assertSame(0, $this->ilePartii($autor));
    }

    /** @return array{0: User, 1: User, 2: Recipe} */
    private function zapisanyPrzepis(): array
    {
        $autor = $this->user('autor_przepisu');
        $osoba = $this->user('zapisujaca_osoba');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);

        app(SaveRecipeToCollection::class)->handle($osoba, $przepis);

        return [$autor, $osoba, $przepis];
    }

    private function wyjmij(User $osoba, Collection $zeszyt): int
    {
        return app(RemoveUnavailableFromCollection::class)
            ->handle($osoba, $zeszyt, $this->odcisk($zeszyt, $osoba));
    }

    private function zeszyt(User $wlasciciel, string $nazwa): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => 'private']);
    }

    private function odcisk(Collection $zeszyt, User $kto): string
    {
        $zawartosc = new WidocznaZawartoscZeszytu;

        return $zawartosc->odcisk($zeszyt, $zawartosc->niedostepne($zeszyt, $kto));
    }

    private function ilePartii(User $autor): int
    {
        return Notification::query()->where('user_id', $autor->getKey())->where('type', Notification::TYPE_SAVED)->count();
    }

    private function jednaPartia(User $autor): Notification
    {
        $partie = Notification::query()->where('user_id', $autor->getKey())->where('type', Notification::TYPE_SAVED)->get();
        $this->assertCount(1, $partie);

        return $partie->first();
    }
}
