<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Pantry\Opakowanie;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AnonimoweSygnalySzanujaSprzeciwTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private const LICZBY = [
        ZapiszSygnal::COOKING_LAST_STEP_REACHED => 3,
        ZapiszSygnal::COOKING_LAST_STEP_COOKED => 2,
        ZapiszSygnal::COOKING_FOLLOWUP_SHOWN => 1,
        ZapiszSygnal::COOKING_FOLLOWUP_DISMISSED => 1,
        ZapiszSygnal::PANTRY_PRIORITY_VIEWED => 1,
        ZapiszSygnal::PANTRY_EXPIRY_SET => 1,
        ZapiszSygnal::PANTRY_COOK_PRIORITY_VIEWED => 1,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sprzeciw_zatrzymuje_siedem_typow_z_osmiu_sciezek_bez_zatrzymania_funkcji(): void
    {
        $osoba = $this->user('bez_statystyk');
        $this->actingAs($osoba)->post(route('settings.privacy.sprzeciw-statystyk'))
            ->assertRedirect(route('settings.privacy').'#statystyki');
        $this->assertTrue($osoba->refresh()->sprzeciwWobecStatystyk());

        $this->scenariusze($osoba);

        foreach (self::LICZBY as $typ => $liczba) {
            $this->assertSame(0, ProductSignal::query()->where('signal_name', $typ)->count(),
                'SPRZECIW_2837_ANI_ANONIMOWO: '.$typ.' (bez sprzeciwu byłoby '.$liczba.').');
        }
    }

    public function test_bez_sprzeciwu_wszystkie_sciezki_nadal_sa_anonimowe(): void
    {
        $osoba = $this->user('ze_statystykami');

        $this->scenariusze($osoba);

        foreach (self::LICZBY as $typ => $liczba) {
            $wiersze = ProductSignal::query()->where('signal_name', $typ)->get();
            $this->assertCount($liczba, $wiersze, 'Kontrola dodatnia: '.$typ);
            foreach ($wiersze as $sygnal) {
                $this->assertNull($sygnal->user_id, 'SPRZECIW_2837_BEZ_POWIAZANIA: '.$typ);
                if ($typ === ZapiszSygnal::COOKING_LAST_STEP_COOKED) {
                    $this->assertSame(['po_pytaniu'], array_keys($sygnal->properties));
                    $this->assertIsBool($sygnal->properties['po_pytaniu']);
                } else {
                    $this->assertSame([], $sygnal->properties, 'Sygnał nie może zawierać identyfikatorów.');
                }
            }
        }
    }

    public function test_anonimowy_zapis_czyta_sprzeciw_ponownie_z_bazy(): void
    {
        $osoba = $this->user('stary_model');
        $staryModel = User::query()->findOrFail($osoba->getKey());

        $this->actingAs($osoba)->post(route('settings.privacy.sprzeciw-statystyk'))
            ->assertRedirect(route('settings.privacy').'#statystyki');
        $this->assertFalse($staryModel->sprzeciwWobecStatystyk(), 'Model w pamięci ma pozostać nieaktualny w tej kontroli.');

        app(ZapiszSygnal::class)->handleAnonimowo($staryModel, ZapiszSygnal::PANTRY_PRIORITY_VIEWED);

        $this->assertSame(0, ProductSignal::query()->count(), 'SPRZECIW_2837_SWIEZA_BLOKADA: nieaktualny model ominął sprzeciw.');
    }

    public function test_cofniecie_sprzeciwu_liczy_tylko_nowe_gotowanie_bez_odtwarzania_pominietego(): void
    {
        $osoba = $this->user('wracam_do_statystyk');
        $autor = $this->user('autor');
        $przed = $this->przepis($autor, 'Gotowanie przed sprzeciwem');
        $pominiete = $this->przepis($autor, 'Gotowanie przy sprzeciwie');
        $po = $this->przepis($autor, 'Gotowanie po cofnięciu');

        $licznik = static fn (): int => ProductSignal::query()
            ->where('signal_name', ZapiszSygnal::COOKING_LAST_STEP_REACHED)->count();

        $this->actingAs($osoba)->get(route('cooking.show', [$przed->slug, 'krok' => 2]))->assertOk();
        $this->assertSame(1, $licznik());
        $this->actingAs($osoba)->post(route('settings.privacy.sprzeciw-statystyk'))->assertRedirect();
        $this->actingAs($osoba)->get(route('cooking.show', [$pominiete->slug, 'krok' => 2]))->assertOk();
        $this->assertSame(1, $licznik(), 'SPRZECIW_2837_ANI_ANONIMOWO: pominięte gotowanie dopisało sygnał.');

        $this->actingAs($osoba)->delete(route('settings.privacy.sprzeciw-statystyk.cofnij'))->assertRedirect();
        $this->assertSame(1, $licznik(), 'Cofnięcie nie może dopisać historii pominiętych działań.');
        $this->actingAs($osoba)->get(route('cooking.show', [$po->slug, 'krok' => 2]))->assertOk();
        $this->assertSame(2, $licznik(), 'Przyszłe gotowanie znów zapisuje sygnał.');
        $this->assertSame(0, ProductSignal::query()->where('signal_name', ZapiszSygnal::COOKING_LAST_STEP_REACHED)
            ->whereNotNull('user_id')->count(), 'Cofnięcie sprzeciwu nie przypisuje historii do konta.');
    }

    private function scenariusze(User $osoba): void
    {
        $autor = $this->user('autor');
        $pierwszy = $this->przepis($autor, 'Pierwsze gotowanie');
        $drugi = $this->przepis($autor, 'Drugie gotowanie');
        $trzeci = $this->przepis($autor, 'Trzecie gotowanie');

        $this->actingAs($osoba)->get(route('cooking.show', [$pierwszy->slug, 'krok' => 2]))->assertOk();
        $this->actingAs($osoba)->get(route('home'))->assertOk()->assertSee('jak wyszło?');
        $this->actingAs($osoba)->post(route('jak_wyszlo.zamknij', $pierwszy->slug))->assertRedirect(route('home'));

        $this->actingAs($osoba)->get(route('cooking.show', [$drugi->slug, 'krok' => 2]))->assertOk();
        $this->actingAs($osoba)->post(route('cooked.store', $drugi->slug), ['note' => 'Ugotowane od razu.'])->assertRedirect();

        $this->actingAs($osoba)->get(route('cooking.show', [$trzeci->slug, 'krok' => 2]))->assertOk();
        app(RecordCookedEvent::class)->handle($osoba, $trzeci);
        $this->actingAs($osoba)->get(route('home'))->assertOk();

        $produkt = $osoba->pantryItems()->create(['name' => 'mleko']);
        $odcisk = Opakowanie::pierwsze($produkt)->odcisk();
        $this->actingAs($osoba)->from(route('pantry.edit', $produkt))
            ->put(route('pantry.update', $produkt), ['_formularz' => 'termin', 'rodzaj' => 'use_by', 'za' => '3', 'odcisk' => $odcisk])
            ->assertRedirect(route('pantry.index'));
        $this->assertNotNull($produkt->fresh()?->expires_on, 'Termin produktu ma zostać zapisany mimo sprzeciwu.');
        $this->actingAs($osoba)->get(route('pantry.index'))->assertOk()->assertSee('Zużyj w pierwszej kolejności');
        $this->actingAs($osoba)->get(route('pantry.cook', ['najpierw' => 'termin']))->assertOk();
    }

    private function przepis(User $autor, string $tytul): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => $tytul]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Przygotuj składniki.']);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 1, 'instruction' => 'Ugotuj danie.']);

        return $przepis;
    }
}
