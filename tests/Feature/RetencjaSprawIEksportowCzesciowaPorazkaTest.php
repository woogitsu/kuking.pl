<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataExport;
use App\Models\Report;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Częściowa porażka retencji spraw moderacyjnych i paczek z danymi NIE jest
 * sukcesem (#1534, wydzielone z #835; wzorzec: #1342 / #1440).
 *
 * CO ROBIŁ KOD
 * `kuking:sprzataj-sprawy-moderacyjne` i `kuking:sprzataj-eksporty` liczyły
 * nieudane wiersze, drukowały ostrzeżenie — i zwracały `SUCCESS`.
 * `Harmonogram::artisan()` zamienia w wyjątek wyłącznie kod ≠ 0, więc przebieg,
 * który zostawił w bazie przedawnione sprawy albo w storage kopię całego
 * konta, wyglądał w harmonogramie jak udany.
 *
 * Awarię spraw wstrzykuje trigger PostgreSQL `BEFORE DELETE` — działa
 * niezależnie od tego, czy retencja kasuje przez model, czy przez zapytanie
 * (DDL w PostgreSQL jest transakcyjne, więc `RefreshDatabase` go zwija).
 */
class RetencjaSprawIEksportowCzesciowaPorazkaTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // sprawy moderacyjne
    // ------------------------------------------------------------------

    private function stareZgloszenie(): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'reason' => 'spam',
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now()->subMonths(37),
        ]);
    }

    /** @return list<Report> */
    private function trzyStareZgloszenia(): array
    {
        config(['kuking.moderation.case_retention_months' => 36]);

        return [$this->stareZgloszenie(), $this->stareZgloszenie(), $this->stareZgloszenie()];
    }

    private function zablokujKasowanie(Report $zgloszenie): void
    {
        $id = $zgloszenie->getKey();

        DB::unprepared(<<<SQL
            CREATE FUNCTION test_awaria_kasowania_zgloszenia() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.id = '{$id}' THEN
                    RAISE EXCEPTION 'wstrzyknieta awaria kasowania';
                END IF;
                RETURN OLD;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER test_awaria_kasowania_zgloszenia
                BEFORE DELETE ON reports
                FOR EACH ROW EXECUTE FUNCTION test_awaria_kasowania_zgloszenia();
            SQL);
    }

    public function test_sprawy_czesciowa_porazka_konczy_sie_bledem_z_liczbami(): void
    {
        [$pierwsze, $wadliwe, $trzecie] = $this->trzyStareZgloszenia();
        $this->zablokujKasowanie($wadliwe);

        $this->artisan('kuking:sprzataj-sprawy-moderacyjne')
            ->expectsOutputToContain('Skasowano zgłoszeń (reports): 2.')
            ->expectsOutputToContain('Nie udało się skasować 1 wiersza (odwołania: 0, decyzje: 0, zgłoszenia: 1; kandydatów: 3)')
            ->assertFailed();

        // Reszta skasowana, wadliwy wiersz zostaje na następny przebieg.
        $this->assertDatabaseMissing('reports', ['id' => $pierwsze->getKey()]);
        $this->assertDatabaseMissing('reports', ['id' => $trzecie->getKey()]);
        $this->assertDatabaseHas('reports', ['id' => $wadliwe->getKey()]);
    }

    /** Kontrola dodatnia: bez awarii komenda kończy się sukcesem. */
    public function test_sprawy_bez_awarii_to_sukces(): void
    {
        $this->trzyStareZgloszenia();

        $this->artisan('kuking:sprzataj-sprawy-moderacyjne')
            ->doesntExpectOutputToContain('Nie udało się')
            ->assertSuccessful();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_sprawy_zadanie_w_harmonogramie_konczy_sie_wyjatkiem(): void
    {
        [, $wadliwe] = $this->trzyStareZgloszenia();
        $this->zablokujKasowanie($wadliwe);

        try {
            $this->zadanie('kuking:sprzataj-sprawy-moderacyjne')->run(app());
            $this->fail('Zadanie z częściową porażką zakończyło się jak udane.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(kod wyjścia: 1)', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // paczki z danymi
    // ------------------------------------------------------------------

    /** @return list<DataExport> */
    private function trzyWygaslePaczki(): array
    {
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);
        $basia = $this->user('basia');

        return array_map(function (int $n) use ($basia): DataExport {
            Storage::disk('local')->put("eksporty/paczka-{$n}.zip", 'udawana paczka');

            return DataExport::create([
                'user_id' => $basia->getKey(),
                'status' => DataExport::STATUS_READY,
                'disk' => 'local',
                'object_key' => "eksporty/paczka-{$n}.zip",
                'bytes' => 1234,
                // Gotowa paczka ma datę ukończenia — CHECK
                // `data_exports_ready_complete_check` (#1365).
                'completed_at' => now()->subDays($n + 7),
                'expires_at' => now()->subDays($n),
            ]);
        }, [1, 2, 3]);
    }

    /** Dysk, który „kasuje” wszystko poza jednym kluczem. */
    private function dyskZAwaria(string $wadliwyKlucz): void
    {
        $prawdziwy = Storage::disk('local');
        $dysk = Mockery::mock(Filesystem::class);
        $dysk->shouldReceive('delete')->andReturnUsing(
            fn (string $klucz): bool => $klucz === $wadliwyKlucz ? false : $prawdziwy->delete($klucz),
        );
        $dysk->shouldReceive('exists')->andReturnUsing(fn (string $klucz): bool => $prawdziwy->exists($klucz));
        Storage::shouldReceive('disk')->andReturn($dysk);
    }

    public function test_eksporty_czesciowa_porazka_konczy_sie_bledem_z_liczbami(): void
    {
        [$pierwsza, $wadliwa, $trzecia] = $this->trzyWygaslePaczki();
        $this->dyskZAwaria((string) $wadliwa->object_key);

        $this->artisan('kuking:sprzataj-eksporty')
            ->expectsOutputToContain('Nie udało się usunąć 1 wygasłą paczkę (kandydatów: 3, usunięto: 2, błędy: 1)')
            ->assertFailed();

        // Adres wadliwej zachowany do ponowienia, pozostałe domknięte.
        $this->assertSame('eksporty/paczka-2.zip', $wadliwa->refresh()->object_key);
        $this->assertNull($pierwsza->refresh()->object_key);
        $this->assertNull($trzecia->refresh()->object_key);
    }

    /** Kontrola dodatnia: bez awarii komenda kończy się sukcesem. */
    public function test_eksporty_bez_awarii_to_sukces(): void
    {
        $this->trzyWygaslePaczki();

        $this->artisan('kuking:sprzataj-eksporty')
            ->expectsOutputToContain('Gotowe. Usunięto 3 wygasłe paczki.')
            ->assertSuccessful();

        $this->assertSame(0, DataExport::query()->whereNotNull('object_key')->count());
    }

    public function test_eksporty_zadanie_w_harmonogramie_konczy_sie_wyjatkiem(): void
    {
        [, $wadliwa] = $this->trzyWygaslePaczki();
        $this->dyskZAwaria((string) $wadliwa->object_key);

        try {
            $this->zadanie('kuking:sprzataj-eksporty')->run(app());
            $this->fail('Zadanie z częściową porażką zakończyło się jak udane.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(kod wyjścia: 1)', $e->getMessage());
        }
    }

    private function zadanie(string $nazwa): CallbackEvent
    {
        $zadanie = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => $e->description === $nazwa);
        $this->assertInstanceOf(CallbackEvent::class, $zadanie);

        return $zadanie;
    }
}
