<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `importy_przepisow`: retencja 30/90 dni, eksport RODO, kasowanie z kontem
 * i ograniczenia w bazie (D-298).
 */
final class ZleceniaImportuRetencjaIKontoTest extends TestCase
{
    use RefreshDatabase;

    public function test_odpowiedz_modelu_znika_po_30_dniach_wiersz_po_90(): void
    {
        $osoba = User::factory()->create();
        $opublikowany = Recipe::factory()->for($osoba, 'author')->create(['status' => Recipe::STATUS_PUBLISHED]);

        $swiezy = $this->zlecenie($osoba, $opublikowany, now()->subDays(10));
        $miesieczny = $this->zlecenie($osoba, $opublikowany, now()->subDays(31));
        $stary = $this->zlecenie($osoba, $opublikowany, now()->subDays(91));

        $this->artisan('kuking:sprzataj-importy')->assertSuccessful();

        $this->assertNotNull($swiezy->fresh()?->odpowiedz_modelu);
        $this->assertNotNull($miesieczny->fresh());
        $this->assertNull($miesieczny->fresh()->odpowiedz_modelu);
        $this->assertNull($stary->fresh());
    }

    public function test_zlecenie_szkicu_ktory_nadal_jest_szkicem_zostaje_jako_bramka_publikacji(): void
    {
        $osoba = User::factory()->create();
        $szkic = Recipe::factory()->for($osoba, 'author')->create(['status' => Recipe::STATUS_DRAFT]);
        $bezPrzepisu = $this->zlecenie($osoba, null, now()->subDays(95));
        $bramka = $this->zlecenie($osoba, $szkic, now()->subDays(95));

        $this->artisan('kuking:sprzataj-importy')->assertSuccessful();

        $this->assertNull($bezPrzepisu->fresh());
        $this->assertNotNull($bramka->fresh(), 'Zlecenie szkicu nadal niesie bramkę sprawdzenia odczytanego tekstu.');
        $this->assertNull($bramka->fresh()->odpowiedz_modelu);
    }

    public function test_eksport_danych_zawiera_zlecenia_bez_surowej_odpowiedzi(): void
    {
        $osoba = User::factory()->create();
        $szkic = Recipe::factory()->for($osoba, 'author')->create(['status' => Recipe::STATUS_DRAFT, 'title' => 'Sernik z kartki']);
        $this->zlecenie($osoba, $szkic, now()->subDay());

        $paczka = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::parse('2026-09-26 12:00', 'UTC'));

        $this->assertCount(1, $paczka['odczyty_przepisow']);
        $this->assertSame('zdjecie', $paczka['odczyty_przepisow'][0]['zrodlo']);
        $this->assertSame('Sernik z kartki', $paczka['odczyty_przepisow'][0]['szkic_przepisu']);
        $this->assertStringNotContainsString('TAJNY-TEKST-Z-KARTKI', json_encode($paczka, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_wymazanie_konta_kasuje_zlecenia_tej_osoby_i_tylko_jej(): void
    {
        $odchodzi = User::factory()->create([
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
        ]);
        $zostaje = User::factory()->create();
        $this->zlecenie($odchodzi, null, now()->subDay());
        $cudze = $this->zlecenie($zostaje, null, now()->subDay());

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(0, ImportPrzepisu::query()->where('user_id', $odchodzi->getKey())->count());
        $this->assertNotNull($cudze->fresh());
    }

    public function test_baza_odrzuca_kod_bledu_przy_udanym_zleceniu_i_nieznany_status(): void
    {
        $osoba = User::factory()->create();

        foreach ([
            ['status' => 'gotowy', 'kod_bledu' => 'nieczytelne'],
            ['status' => 'nieudany', 'kod_bledu' => null],
            ['status' => 'opublikowany', 'kod_bledu' => null],
            ['status' => 'nieudany', 'kod_bledu' => 'cokolwiek'],
        ] as $zle) {
            try {
                DB::transaction(fn () => DB::table('importy_przepisow')->insert([
                    'user_id' => $osoba->getKey(), 'zrodlo' => 'zdjecie', 'created_at' => now(), 'updated_at' => now(), ...$zle,
                ]));
                $this->fail('Baza przyjęła niespójne zlecenie: '.json_encode($zle));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_status_nie_jest_w_fillable(): void
    {
        $this->assertNotContains('status', (new ImportPrzepisu)->getFillable());
        $this->assertNotContains('kod_bledu', (new ImportPrzepisu)->getFillable());
    }

    private function zlecenie(User $osoba, ?Recipe $przepis, Carbon $kiedy): ImportPrzepisu
    {
        $zlecenie = new ImportPrzepisu;
        $zlecenie->forceFill([
            'user_id' => $osoba->getKey(),
            'recipe_id' => $przepis?->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
            'status' => ImportPrzepisu::STATUS_GOTOWY,
            'odpowiedz_modelu' => ['output_text' => 'TAJNY-TEKST-Z-KARTKI'],
        ]);
        $zlecenie->created_at = $kiedy;
        $zlecenie->updated_at = $kiedy;
        $zlecenie->save();

        return $zlecenie;
    }
}
