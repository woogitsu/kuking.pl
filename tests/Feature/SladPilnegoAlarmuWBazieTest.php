<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Migracja `2026_09_23_110000_dodaj_slad_pilnego_alarmu_do_reports`
 * (issue #1051): CHECK `reports_alarm_pilny_spojny_check` i cofnięcie, które
 * odmawia, gdy jest co stracić (D-088).
 *
 * Każda próba zapisu niespójnego stanu idzie w zagnieżdżonej transakcji
 * (savepoint): w PostgreSQL nieudane zapytanie przerywa całą transakcję,
 * a `RefreshDatabase` trzyma test w jednej.
 *
 * @bez-kontroli-dodatniej plik migracji jest wczytywany tylko po to, żeby wywołać `up()`/`down()` na bazie; asercje dotyczą zachowania PostgreSQL, a kontrola dodatnia jest w samym teście (`test_kontrola_dodatnia_kazdy_nazwany_stan_przechodzi`).
 */
class SladPilnegoAlarmuWBazieTest extends TestCase
{
    use RefreshDatabase;

    private const OGRANICZENIE = 'reports_alarm_pilny_spojny_check';

    private const FURTKA = 'KUKING_ROLLBACK_KASUJ_SLAD_ALARMOW';

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_23_110000_dodaj_slad_pilnego_alarmu_do_reports.php');
    }

    /** @param  array<string, mixed>  $alarm */
    private function oznaczenie(array $alarm = []): Report
    {
        $sprawa = Report::create([
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'automat_model',
            'details' => 'Automat oznaczył tę treść do przeglądu.',
            'status' => Report::STATUS_OPEN,
        ]);

        if ($alarm !== []) {
            Report::query()->whereKey($sprawa->getKey())->update($alarm);
        }

        return $sprawa->refresh();
    }

    /** @param  array<string, mixed>  $alarm */
    private function assertBazaOdrzuca(array $alarm, string $dlaczego): void
    {
        $sprawa = $this->oznaczenie();
        $blad = null;

        try {
            DB::transaction(fn () => Report::query()->whereKey($sprawa->getKey())->update($alarm));
        } catch (QueryException $e) {
            $blad = $e;
        }

        $this->assertNotNull($blad, 'PostgreSQL przyjął niespójny stan alarmu: '.$dlaczego);
        $this->assertStringContainsString(self::OGRANICZENIE, $blad->getMessage(), $dlaczego);
    }

    private function kolumnaIstnieje(): bool
    {
        return DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['reports', 'alarm_pilny_stan'],
        ) !== [];
    }

    public function test_baza_odrzuca_stan_spoza_slownika_i_sprzeczna_pare(): void
    {
        $this->assertBazaOdrzuca(['alarm_pilny_stan' => 'wyslany'], 'stan spoza Report::ALARM_*');
        $this->assertBazaOdrzuca(['alarm_pilny_stan' => Report::ALARM_ZLECONY], '`zlecony` bez znacznika');
        $this->assertBazaOdrzuca(
            ['alarm_pilny_stan' => Report::ALARM_NIEUDANY, 'alarm_pilny_zlecony_at' => now()],
            '`nieudany` ze znacznikiem zlecenia',
        );
        $this->assertBazaOdrzuca(['alarm_pilny_zlecony_at' => now()], 'znacznik przy sprawie niepilnej');
    }

    public function test_kontrola_dodatnia_kazdy_nazwany_stan_przechodzi(): void
    {
        // Te same kolumny, te same drogi zapisu — tylko wartości spójne.
        // Bez tego testu CHECK odrzucający WSZYSTKO też byłby „zielony".
        foreach ([Report::ALARM_ZALEGLY, Report::ALARM_BEZ_ADRESU, Report::ALARM_NIEUDANY] as $stan) {
            $this->assertSame($stan, $this->oznaczenie(['alarm_pilny_stan' => $stan])->alarm_pilny_stan);
        }

        $zlecony = $this->oznaczenie(['alarm_pilny_stan' => Report::ALARM_ZLECONY, 'alarm_pilny_zlecony_at' => now()]);
        $this->assertNotNull($zlecony->alarm_pilny_zlecony_at);

        $this->assertNull($this->oznaczenie()->alarm_pilny_stan);
    }

    public function test_check_nie_styka_sie_z_rozstrzygnieciem_sprawy(): void
    {
        // #1441: zamknięcie sprawy wymaga `resolved_at`, alarm go nie dotyka.
        // Zamknięta sprawa z zaległym alarmem to legalny, opisany stan
        // (`Report::scopePilneBezAlarmu()` — status tu nie wchodzi).
        $sprawa = $this->oznaczenie(['alarm_pilny_stan' => Report::ALARM_ZALEGLY]);

        Report::query()->whereKey($sprawa->getKey())->update([
            'status' => Report::STATUS_REJECTED,
            'resolved_at' => now(),
        ]);

        $this->assertSame(1, Report::query()->pilneBezAlarmu()->count());
    }

    public function test_cofniecie_odmawia_gdy_jest_slad_alarmu(): void
    {
        $this->oznaczenie(['alarm_pilny_stan' => Report::ALARM_NIEUDANY]);

        // Odmowę oceniamy poza blokiem `try` (D-133).
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało ślad po pilnym alarmie.');
        $this->assertStringContainsString('Liczba spraw, których to dotyczy: 1.', $odmowa->getMessage());
        $this->assertStringContainsString(self::FURTKA, $odmowa->getMessage());

        // Strażnik stoi PRZED kasowaniem.
        $this->assertTrue($this->kolumnaIstnieje(), 'Kolumna zniknęła mimo odmowy.');
        $this->assertSame(1, Report::query()->pilneBezAlarmu()->count());
    }

    public function test_bez_sladu_cofniecie_przechodzi_a_ponowne_up_odtwarza_check(): void
    {
        $this->oznaczenie();

        $this->migracja()->down();
        $this->assertFalse($this->kolumnaIstnieje(), 'Cofnięcie nie zdjęło kolumn.');

        $this->migracja()->up();
        $this->assertTrue($this->kolumnaIstnieje());

        // Po powrocie CHECK znowu stoi — cofnięcie i ponowienie nie zostawiają
        // kolumn bez ograniczenia.
        $this->assertBazaOdrzuca(['alarm_pilny_stan' => 'wyslany'], 'CHECK nie wrócił po ponownym up()');
    }

    public function test_furtka_ze_srodowiska_procesu_przepuszcza_cofniecie(): void
    {
        $this->oznaczenie(['alarm_pilny_stan' => Report::ALARM_ZALEGLY]);

        $poprzednia = getenv(self::FURTKA);
        putenv(self::FURTKA.'=true');

        try {
            $this->migracja()->down();

            $this->assertFalse($this->kolumnaIstnieje(), 'Furtka nie zadziałała: kolumna została mimo jawnej zgody.');
        } finally {
            if ($poprzednia === false) {
                putenv(self::FURTKA);
            } else {
                putenv(self::FURTKA.'='.$poprzednia);
            }
        }
    }
}
