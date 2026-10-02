<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Skrót do jednego własnego zeszytu na ekranie „Moje” (#2542).
 */
final class UlubionyZeszytSkrotTest extends TestCase
{
    use RefreshDatabase;

    private User $basia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basia = $this->user('basia');
    }

    private function zeszyt(User $wlasciciel, string $nazwa = 'Obiady na co dzień'): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => 'private']);
    }

    private function ustawSkrot(Collection $zeszyt): void
    {
        $this->basia->forceFill(['ulubiony_zeszyt_id' => $zeszyt->getKey()])->save();
    }

    public function test_gosc_jest_odsylany_do_logowania_i_nic_nie_ustawia(): void
    {
        $zeszyt = $this->zeszyt($this->basia);

        $this->post(route('collections.shortcut.store', $zeszyt))->assertRedirect(route('login'));
        $this->delete(route('collections.shortcut.destroy', $zeszyt))->assertRedirect(route('login'));

        $this->assertNull($this->basia->fresh()->ulubiony_zeszyt_id);
    }

    public function test_cudzy_zeszyt_daje_403_i_nie_ustawia_skrotu(): void
    {
        $cudzy = $this->zeszyt($this->user('zofia'));
        $cudzy->forceFill(['visibility' => 'public'])->save();

        $this->actingAs($this->basia)->post(route('collections.shortcut.store', $cudzy))->assertForbidden();
        $this->actingAs($this->basia)->delete(route('collections.shortcut.destroy', $cudzy))->assertForbidden();

        $this->assertNull($this->basia->fresh()->ulubiony_zeszyt_id);
    }

    public function test_wspolny_zeszyt_w_ktorym_jestem_wspoltworca_daje_403(): void
    {
        $wspolny = $this->zeszyt($this->user('zofia'), 'Rodzinny');
        DB::table('collection_members')->insert([
            'collection_id' => $wspolny->getKey(),
            'user_id' => $this->basia->getKey(),
            'created_at' => now(),
        ]);

        $this->actingAs($this->basia)->post(route('collections.shortcut.store', $wspolny))->assertForbidden();

        $this->assertNull($this->basia->fresh()->ulubiony_zeszyt_id);
    }

    public function test_wlasciciel_ustawia_skrot_i_widzi_go_w_moje(): void
    {
        $zeszyt = $this->zeszyt($this->basia);

        $this->actingAs($this->basia)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertSee('Ustaw jako skrót w »Moje«', false)
            ->assertDontSee('Usuń skrót');

        $this->actingAs($this->basia)
            ->post(route('collections.shortcut.store', $zeszyt))
            ->assertRedirect(route('collections.show', $zeszyt));

        $this->assertSame((string) $zeszyt->getKey(), (string) $this->basia->fresh()->ulubiony_zeszyt_id);

        $this->actingAs($this->basia->fresh())->get(route('collections.show', $zeszyt))
            ->assertSee('Usuń skrót')
            ->assertDontSee('Ustaw jako skrót w »Moje«', false);

        $this->actingAs($this->basia->fresh())->get(route('collections.index'))
            ->assertOk()
            ->assertSee('data-rola="skrot-do-zeszytu"', false)
            ->assertSee('Otwórz: Obiady na co dzień')
            ->assertSee(route('collections.show', $zeszyt), false);
    }

    public function test_brak_skrotu_nie_pokazuje_niczego_w_moje(): void
    {
        $this->zeszyt($this->basia);

        $this->actingAs($this->basia)->get(route('collections.index'))
            ->assertOk()
            ->assertDontSee('data-rola="skrot-do-zeszytu"', false)
            ->assertDontSee('Otwórz: ');
    }

    public function test_drugi_skrot_zastepuje_pierwszy_i_jest_najwyzej_jeden(): void
    {
        $a = $this->zeszyt($this->basia, 'Zeszyt A');
        $b = $this->zeszyt($this->basia, 'Zeszyt B');

        $this->actingAs($this->basia)->post(route('collections.shortcut.store', $a));
        $this->actingAs($this->basia->fresh())->post(route('collections.shortcut.store', $b));

        $this->assertSame((string) $b->getKey(), (string) $this->basia->fresh()->ulubiony_zeszyt_id);
        $this->actingAs($this->basia->fresh())->get(route('collections.index'))
            ->assertSee('Otwórz: Zeszyt B')
            ->assertDontSee('Otwórz: Zeszyt A');
    }

    public function test_usuniecie_skrotu_zostawia_zeszyt(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->ustawSkrot($zeszyt);

        $this->actingAs($this->basia)
            ->delete(route('collections.shortcut.destroy', $zeszyt))
            ->assertRedirect(route('collections.show', $zeszyt));

        $this->assertNull($this->basia->fresh()->ulubiony_zeszyt_id);
        $this->assertNotNull(Collection::find($zeszyt->getKey()));
    }

    public function test_przycisk_ze_starej_karty_nie_kasuje_nowszego_skrotu(): void
    {
        $a = $this->zeszyt($this->basia, 'Zeszyt A');
        $b = $this->zeszyt($this->basia, 'Zeszyt B');
        $this->ustawSkrot($b);

        $this->actingAs($this->basia)->delete(route('collections.shortcut.destroy', $a));

        $this->assertSame((string) $b->getKey(), (string) $this->basia->fresh()->ulubiony_zeszyt_id);
    }

    public function test_usuniety_zeszyt_nie_zostawia_martwego_odnosnika_w_moje(): void
    {
        $zeszyt = $this->zeszyt($this->basia);
        $this->zeszyt($this->basia, 'Inny');
        $this->ustawSkrot($zeszyt);

        $this->actingAs($this->basia)->delete(route('collections.destroy', $zeszyt))->assertRedirect(route('collections.index'));

        $this->assertNull($this->basia->fresh()->ulubiony_zeszyt_id);
        $this->actingAs($this->basia->fresh())->get(route('collections.index'))
            ->assertOk()
            ->assertDontSee('Otwórz: ');
    }

    public function test_cudzy_zeszyt_wpisany_do_bazy_nie_pokazuje_sie_w_moje(): void
    {
        $cudzy = $this->zeszyt($this->user('zofia'), 'Sekretny Zofii');
        // Omija akcję — odczyt ma i tak zawęzić do właściciela (obrona w głąb).
        DB::table('users')->where('id', $this->basia->getKey())->update(['ulubiony_zeszyt_id' => $cudzy->getKey()]);

        $this->actingAs($this->basia->fresh())->get(route('collections.index'))
            ->assertOk()
            ->assertDontSee('Sekretny Zofii')
            ->assertDontSee('Otwórz: ');
    }

    public function test_skrot_dziala_niezaleznie_od_strony_listy(): void
    {
        // „Zzz” wypada za pierwszą stroną listy (30 na stronę, sort po nazwie).
        for ($i = 1; $i <= 32; $i++) {
            $this->zeszyt($this->basia, sprintf('Zeszyt %02d', $i));
        }
        $ostatni = $this->zeszyt($this->basia, 'Zzz ostatni');
        $this->ustawSkrot($ostatni);

        $this->actingAs($this->basia->fresh())->get(route('collections.index'))
            ->assertOk()
            ->assertSee('Otwórz: Zzz ostatni');
    }

    public function test_zmiana_nazwy_jest_widoczna_w_skrocie(): void
    {
        $zeszyt = $this->zeszyt($this->basia, 'Stara nazwa');
        $this->ustawSkrot($zeszyt);
        $zeszyt->update(['name' => 'Nowa nazwa']);

        $this->actingAs($this->basia->fresh())->get(route('collections.index'))
            ->assertSee('Otwórz: Nowa nazwa')
            ->assertDontSee('Otwórz: Stara nazwa');
    }

    public function test_pole_nie_jest_w_fillable(): void
    {
        $this->assertNotContains('ulubiony_zeszyt_id', (new User)->getFillable());
        $this->assertNotContains('ulubiony_zeszyt_id', (new Collection)->getFillable());
    }

    public function test_eksport_podaje_nazwe_zeszytu_skrotu_a_bez_skrotu_null(): void
    {
        $paczka = fn (): array => app(CollectUserExportData::class)
            ->handle($this->basia->fresh(), new ExportPhotoPlan($this->basia->fresh()), Carbon::now());

        $this->assertNull($paczka()['konto']['zeszyt_skrot_w_moje']);

        $this->ustawSkrot($this->zeszyt($this->basia, 'Obiady niedzielne'));

        $this->assertSame('Obiady niedzielne', $paczka()['konto']['zeszyt_skrot_w_moje']);
    }

    public function test_wymazanie_konta_zeruje_skrot(): void
    {
        $this->ustawSkrot($this->zeszyt($this->basia));

        $this->basia->markForDeletion();
        app(EraseAccountData::class)->handle($this->basia->fresh());

        $this->assertNull($this->basia->fresh()->ulubiony_zeszyt_id);
    }
}
