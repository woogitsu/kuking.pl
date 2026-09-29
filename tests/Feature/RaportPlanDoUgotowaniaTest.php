<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\PlanDoUgotowania;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `plan → ugotowałem` w `kuking:raport` (#27, D-310): pozycje planera z pełnej,
 * zamkniętej kohorty dni planu.
 *
 * Dla TERAZ = 8.09.2026 12:00 UTC (14:00 w Warszawie) kohorta to dni planu
 * od 6.08.2026 do 4.09.2026 włącznie; okno ugotowania to dzień planu + 3 dni.
 */
class RaportPlanDoUgotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const TERAZ = '2026-09-08 12:00:00';

    private User $autorka;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TERAZ, 'UTC'));
        $this->autorka = $this->user('autorka');
        $this->przepis = Recipe::factory()->create(['author_id' => $this->autorka->getKey()]);
    }

    /** Dzień planu `dni` dni przed dzisiejszym (8.09.2026), jako data. */
    private function dzien(int $dni): string
    {
        return CarbonImmutable::parse('2026-09-08', 'UTC')->subDays($dni)->toDateString();
    }

    /** Chwila w UTC — domyślnie południe dnia planu (14:00 w Warszawie). */
    private function o(string $dzien, string $godzina = '12:00:00'): CarbonImmutable
    {
        return CarbonImmutable::parse("{$dzien} {$godzina}", 'UTC');
    }

    private function plan(User $kto, string $dzien, ?Recipe $przepis = null, ?CarbonImmutable $dodano = null): void
    {
        DB::table('meal_plan_entries')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $kto->getKey(),
            'day' => $dzien,
            'recipe_id' => ($przepis ?? $this->przepis)->getKey(),
            'created_at' => $dodano ?? $this->o($dzien)->subDays(2),
            'updated_at' => $dodano ?? $this->o($dzien)->subDays(2),
        ]);
    }

    private function ugotowanie(User $kto, CarbonImmutable $kiedy, ?Recipe $przepis = null): void
    {
        CookedEvent::factory()->create([
            'user_id' => $kto->getKey(),
            'recipe_id' => ($przepis ?? $this->przepis)->getKey(),
            'cooked_at' => $kiedy,
        ]);
    }

    /** @return array{dzien_od: string, dzien_do: string, w_kohorcie: int, ugotowane: int, procent: float|null, w_oknie_obserwacji: int} */
    private function wynik(): array
    {
        return app(PlanDoUgotowania::class)->policz();
    }

    public function test_ugotowanie_w_dniu_planu_i_do_trzech_dni_po_nim_jest_konwersja(): void
    {
        $wDniu = $this->user('w_dniu');
        $this->plan($wDniu, $this->dzien(20));
        $this->ugotowanie($wDniu, $this->o($this->dzien(20), '18:00:00'));

        $granica = $this->user('granica');
        $this->plan($granica, $this->dzien(20));
        // Ostatnia chwila okna: 23:30 czasu polskiego trzeciego dnia po planie.
        $this->ugotowanie($granica, $this->o($this->dzien(17), '21:30:00'));

        $wynik = $this->wynik();

        $this->assertSame(2, $wynik['w_kohorcie']);
        $this->assertSame(2, $wynik['ugotowane']);
        // Dwie pozycje to anegdota — bez procentu.
        $this->assertNull($wynik['procent']);
    }

    public function test_ugotowanie_po_oknie_przed_planem_i_przed_dodaniem_nie_jest_konwersja(): void
    {
        $spozniona = $this->user('spozniona');
        $this->plan($spozniona, $this->dzien(20));
        // 22:30 UTC = 00:30 czasu polskiego czwartego dnia po planie.
        $this->ugotowanie($spozniona, $this->o($this->dzien(17), '22:30:00'));

        $wczesna = $this->user('wczesna');
        $this->plan($wczesna, $this->dzien(20));
        $this->ugotowanie($wczesna, $this->o($this->dzien(21), '12:00:00'));

        $przedPlanem = $this->user('przed_planem');
        // Dodane do planu dopiero po ugotowaniu (wpisane „wstecz").
        $this->ugotowanie($przedPlanem, $this->o($this->dzien(20), '08:00:00'));
        $this->plan($przedPlanem, $this->dzien(20), null, $this->o($this->dzien(20), '10:00:00'));

        $wynik = $this->wynik();

        $this->assertSame(3, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['ugotowane']);
    }

    public function test_granica_dnia_jest_liczona_w_strefie_polskiej_a_nie_utc(): void
    {
        $osoba = $this->user('nocna');
        $this->plan($osoba, $this->dzien(20));
        // 23:30 UTC dnia poprzedniego = 01:30 czasu polskiego dnia planu (lato).
        // Dodane do planu wcześniej, więc liczy się jako dzień planu.
        $this->ugotowanie($osoba, $this->o($this->dzien(21), '23:30:00'));

        $this->assertSame(1, $this->wynik()['ugotowane']);
    }

    public function test_kilka_ugotowan_w_oknie_to_jedna_konwersja(): void
    {
        $osoba = $this->user('osoba');
        $this->plan($osoba, $this->dzien(20));
        $this->ugotowanie($osoba, $this->o($this->dzien(20), '13:00:00'));
        $this->ugotowanie($osoba, $this->o($this->dzien(19), '13:00:00'));

        $wynik = $this->wynik();

        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(1, $wynik['ugotowane']);
    }

    public function test_ugotowanie_innego_przepisu_albo_przez_inna_osobe_nie_zalicza_planu(): void
    {
        $osoba = $this->user('osoba');
        $inny = Recipe::factory()->create(['author_id' => $this->autorka->getKey()]);
        $this->plan($osoba, $this->dzien(20));
        $this->ugotowanie($osoba, $this->o($this->dzien(20), '18:00:00'), $inny);
        $this->ugotowanie($this->user('ktos_inny'), $this->o($this->dzien(20), '18:00:00'));

        $wynik = $this->wynik();

        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['ugotowane']);
    }

    public function test_kohorta_jest_zamknieta_a_nowsze_dni_liczone_osobno(): void
    {
        $osoba = $this->user('osoba');
        // Ostatni dzień kohorty (4.09) i pierwszy dzień poza nią (5.09).
        $this->plan($osoba, $this->dzien(4));
        $this->plan($osoba, $this->dzien(3));
        // Dziś i przyszłość — w oknie obserwacji.
        $this->plan($osoba, $this->dzien(0));
        $this->plan($osoba, $this->dzien(-5));
        // Pierwszy dzień kohorty (6.08) i jeden dzień starszy (5.08).
        $this->plan($osoba, $this->dzien(33));
        $this->plan($osoba, $this->dzien(34));

        $wynik = $this->wynik();

        $this->assertSame('2026-08-06', $wynik['dzien_od']);
        $this->assertSame('2026-09-04', $wynik['dzien_do']);
        $this->assertSame(2, $wynik['w_kohorcie']);
        $this->assertSame(3, $wynik['w_oknie_obserwacji']);
    }

    public function test_wpisy_wlasne_i_pozycje_po_usunietym_przepisie_nie_licza_sie(): void
    {
        $osoba = $this->user('osoba');

        DB::table('meal_plan_entries')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $osoba->getKey(),
            'day' => $this->dzien(20),
            'label' => 'Obiad u mamy',
            'created_at' => $this->o($this->dzien(22)),
            'updated_at' => $this->o($this->dzien(22)),
        ]);

        $usuniety = Recipe::factory()->create(['author_id' => $this->autorka->getKey()]);
        $this->plan($osoba, $this->dzien(20), $usuniety);
        DB::table('recipes')->where('id', $usuniety->getKey())->delete();

        $wynik = $this->wynik();

        $this->assertSame(0, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['w_oknie_obserwacji']);
    }

    public function test_wlasny_przepis_liczy_sie_a_konta_wykluczone_nie(): void
    {
        $this->plan($this->autorka, $this->dzien(20));
        $this->ugotowanie($this->autorka, $this->o($this->dzien(20), '18:00:00'));

        config(['kuking.account.test_usernames' => ['qa_wewnetrzne']]);
        $testowe = $this->user('qa_wewnetrzne');
        $this->plan($testowe, $this->dzien(20));
        $this->ugotowanie($testowe, $this->o($this->dzien(20), '18:00:00'));

        $zalazkowe = $this->user('zalazek', ['is_seeded' => true]);
        $this->plan($zalazkowe, $this->dzien(20));

        $wynik = $this->wynik();

        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(1, $wynik['ugotowane']);
    }

    public function test_procent_od_minimum_pozycji(): void
    {
        for ($i = 0; $i < PlanDoUgotowania::MINIMUM_POZYCJI; $i++) {
            $osoba = $this->user("osoba_{$i}");
            $this->plan($osoba, $this->dzien(20));

            if ($i < 5) {
                $this->ugotowanie($osoba, $this->o($this->dzien(20), '18:00:00'));
            }
        }

        $wynik = $this->wynik();

        $this->assertSame(20, $wynik['w_kohorcie']);
        $this->assertSame(5, $wynik['ugotowane']);
        $this->assertSame(25.0, $wynik['procent']);

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Ugotowane z planu: 5 z 20 pozycji · 25,0%');
    }

    public function test_ponizej_progu_raport_pokazuje_same_liczniki(): void
    {
        $osoba = $this->user('osoba');
        $this->plan($osoba, $this->dzien(20));
        $this->ugotowanie($osoba, $this->o($this->dzien(20), '18:00:00'));

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Plan → „Ugotowałem” — przepis w planerze ugotowany w dniu planu albo do 3 dni po nim, dni planu 06.08.2026–04.09.2026.')
            ->expectsOutputToContain('Ugotowane z planu: 1 z 1 pozycji · za mało danych (mniej niż 20 pozycji w kohorcie)');
    }

    public function test_raport_bez_danych_osobowych(): void
    {
        $osoba = $this->user('cicha_osoba');
        $this->plan($osoba, $this->dzien(20));
        DB::table('meal_plan_entries')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $osoba->getKey(),
            'day' => $this->dzien(20),
            'label' => 'Obiad u mamy',
            'created_at' => $this->o($this->dzien(22)),
            'updated_at' => $this->o($this->dzien(22)),
        ]);

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('cicha_osoba')
            ->doesntExpectOutputToContain('Obiad u mamy')
            ->doesntExpectOutputToContain($this->przepis->title);
    }

    public function test_za_wczesnie_na_wniosek_i_pusta_baza(): void
    {
        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Brak zaplanowanych przepisów — jeszcze nie da się tego policzyć.');

        $this->plan($this->user('osoba'), $this->dzien(1));

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Za wcześnie na wniosek: w tej kohorcie nie ma pozycji')
            ->expectsOutputToContain('Pozycje z dzisiejszym, świeżym albo przyszłym dniem: 1 — jeszcze w oknie, nie wliczone.');
    }
}
