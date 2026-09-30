<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Domain\Recipes\Alergeny\DeklaracjaAlergenow;
use App\Domain\Recipes\Alergeny\OznaczAlergenyPrzepisu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Akcja `OznaczAlergenyPrzepisu` (#1902): jedyne miejsce, w którym powstaje `declared`.
 *
 * KONTROLA UJEMNA (ręcznie): (1) usunięcie `Gate::forUser(...)->authorize` z `handle()`
 * oblewa `test_cudzy_przepis_nie_da_sie_oznaczyc`; (2) usunięcie `wymagaPotwierdzenia()`
 * z `zastosuj()` oblewa `test_zaznaczenie_bez_potwierdzenia_nie_zapisuje_deklaracji`;
 * (3) dopisanie `allergen_status` do `Recipe::$fillable` oblewa
 * `test_pol_sterujacych_nie_da_sie_przypisac_masowo`.
 */
final class OznaczAlergenyPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autor_oznaczen');
        $this->przepis = Recipe::factory()->create(['author_id' => $this->autor->getKey()]);
    }

    private function akcja(): OznaczAlergenyPrzepisu
    {
        return app(OznaczAlergenyPrzepisu::class);
    }

    public function test_potwierdzona_lista_zapisuje_deklaracje_z_data_bez_powtorzen_i_w_kolejnosci_slownika(): void
    {
        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow(['milk', 'gluten', 'milk', 'banany'], true));

        $przepis = $this->przepis->refresh();
        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame(['gluten', 'milk'], $przepis->allergens);
        $this->assertNotNull($przepis->allergens_declared_at);
        $this->assertTrue($przepis->alergenyZdeklarowane());
    }

    public function test_potwierdzona_pusta_lista_jest_deklaracja_a_nie_brakiem_oznaczenia(): void
    {
        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow([], true));

        $przepis = $this->przepis->refresh();
        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
    }

    public function test_zaznaczenie_bez_potwierdzenia_nie_zapisuje_deklaracji(): void
    {
        try {
            $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow(['milk'], false));
            $this->fail('Zaznaczenie bez potwierdzenia zostało przyjęte.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(OznaczAlergenyPrzepisu::KOMUNIKAT_POTWIERDZ, $e->getMessage());
        }

        $przepis = $this->przepis->refresh();
        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
        $this->assertNull($przepis->allergens_declared_at);
    }

    public function test_odznaczenie_wszystkiego_i_potwierdzenia_cofa_oznaczenie(): void
    {
        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow(['eggs'], true));
        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow([], false));

        $przepis = $this->przepis->refresh();
        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
        $this->assertNull($przepis->allergens_declared_at);
    }

    public function test_cudzy_przepis_nie_da_sie_oznaczyc(): void
    {
        $obca = $this->user('obca_osoba');

        try {
            $this->akcja()->handle($obca, $this->przepis, new DeklaracjaAlergenow(['milk'], true));
            $this->fail('Obca osoba oznaczyła alergeny cudzego przepisu.');
        } catch (AuthorizationException) {
            $this->assertSame('unchecked', $this->przepis->refresh()->allergen_status);
        }
    }

    public function test_przepis_ukryty_przez_moderacje_jest_zamrozony(): void
    {
        $this->przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        // Odmawia już Policy `update`; `zablokowany()` w akcji jest drugą warstwą.
        $this->expectException(AuthorizationException::class);
        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow([], true));
    }

    public function test_potwierdz_ponownie_dziala_tylko_ze_stanu_do_przegladu_i_zostawia_liste(): void
    {
        // Z `unchecked` przycisk nie może zrobić z niczego deklaracji.
        try {
            $this->akcja()->potwierdzPonownie($this->autor, $this->przepis);
            $this->fail('Ponowne potwierdzenie przeszło ze stanu „nie sprawdzono”.');
        } catch (BladDlaCzlowieka) {
            $this->assertSame('unchecked', $this->przepis->refresh()->allergen_status);
        }

        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow(['sesame', 'soy'], true));
        $this->przepis->forceFill(['allergen_status' => Recipe::ALERGENY_DO_PRZEGLADU])->save();

        $this->akcja()->potwierdzPonownie($this->autor, $this->przepis);

        $przepis = $this->przepis->refresh();
        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame(['soy', 'sesame'], $przepis->allergens);
    }

    public function test_zmiana_zostawia_slad_w_dzienniku_audytu_bez_tresci_zdrowotnych_widza(): void
    {
        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow(['fish'], true), '203.0.113.7');

        $wpis = AuditLogEntry::query()->where('action', 'recipe.allergens_marked')->sole();
        $this->assertSame($this->autor->getKey(), $wpis->actor_id);
        $this->assertSame(['status' => 'declared', 'liczba_alergenow' => 1], $wpis->metadata);
    }

    public function test_zapis_bez_roznicy_nie_przesuwa_daty_potwierdzenia_ani_nie_dopisuje_sladu(): void
    {
        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow(['fish'], true));
        $pierwsza = $this->przepis->refresh()->allergens_declared_at;

        $this->travel(2)->days();
        $this->akcja()->handle($this->autor, $this->przepis, new DeklaracjaAlergenow(['fish'], true));

        $this->assertEquals($pierwsza, $this->przepis->refresh()->allergens_declared_at);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'recipe.allergens_marked')->count());
    }

    public function test_pol_sterujacych_nie_da_sie_przypisac_masowo(): void
    {
        foreach (['allergen_status' => 'declared', 'allergens' => ['milk'], 'allergens_declared_at' => now()] as $kolumna => $wartosc) {
            try {
                $model = new Recipe([$kolumna => $wartosc]);
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);

                continue;
            }

            $this->assertArrayNotHasKey($kolumna, $model->getAttributes(), "Kolumna {$kolumna} weszła masowym przypisaniem — to pole sterujące (D-006).");
        }
    }
}
