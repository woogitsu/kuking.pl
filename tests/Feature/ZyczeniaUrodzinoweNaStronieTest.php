<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Życzenia urodzinowe od gospodarza na `/home` (issue #1755, etap b).
 *
 * Zegar zamrożony w każdym teście — „dziś" to dzień w strefie człowieka.
 */
class ZyczeniaUrodzinoweNaStronieTest extends TestCase
{
    use RefreshDatabase;

    private const ZDANIE = 'Wszystkiego dobrego z okazji urodzin, Basia.';

    private function basia(int $dzien = 12, int $miesiac = 3): User
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $basia->forceFill(['birthday_day' => $dzien, 'birthday_month' => $miesiac])->save();

        return $basia->fresh();
    }

    public function test_w_dniu_urodzin_na_stronie_glownej_sa_zyczenia_od_gospodarza(): void
    {
        config(['kuking.community.host_name' => 'Ula']);
        $basia = $this->basia();
        $this->travelTo(Carbon::parse('2026-03-12 09:00:00', 'UTC'));

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee(self::ZDANIE, escape: false)
            ->assertSee('— Ula', escape: false);
    }

    public function test_w_inny_dzien_zyczen_nie_ma(): void
    {
        $basia = $this->basia();

        foreach (['2026-03-11 12:00:00', '2026-03-13 12:00:00'] as $kiedy) {
            $this->travelTo(Carbon::parse($kiedy, 'UTC'));
            $this->actingAs($basia)->get(route('home'))->assertOk()->assertDontSee('z okazji urodzin', escape: false);
        }
    }

    /** 12 marca 00:30 w Warszawie to jeszcze 11 marca w UTC — a już urodziny. */
    public function test_dzien_liczony_w_strefie_czlowieka(): void
    {
        $basia = $this->basia();
        $this->travelTo(Carbon::parse('2026-03-11 23:30:00', 'UTC'));

        $this->actingAs($basia)->get(route('home'))->assertSee(self::ZDANIE, escape: false);
    }

    public function test_urodziny_29_lutego_obchodzimy_28_lutego_w_roku_nieprzestepnym(): void
    {
        $basia = $this->basia(29, 2);

        $this->travelTo(Carbon::parse('2027-02-28 10:00:00', 'UTC'));
        $this->actingAs($basia)->get(route('home'))->assertSee(self::ZDANIE, escape: false);

        $this->travelTo(Carbon::parse('2027-03-01 10:00:00', 'UTC'));
        $this->actingAs($basia)->get(route('home'))->assertDontSee('z okazji urodzin', escape: false);
    }

    public function test_bez_daty_nie_ma_zyczen(): void
    {
        $basia = $this->user('basia');
        $this->travelTo(Carbon::parse('2026-03-12 09:00:00', 'UTC'));

        $this->actingAs($basia)->get(route('home'))->assertOk()->assertDontSee('z okazji urodzin', escape: false);
    }

    public function test_wylacznik_przy_dacie_chowa_zyczenia(): void
    {
        $basia = $this->basia();
        $this->travelTo(Carbon::parse('2026-03-12 09:00:00', 'UTC'));

        $this->actingAs($basia)
            ->put(route('settings.birthday.preferences'), ['_formularz' => 'wybory'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($basia->fresh()->birthday_wishes_enabled);
        $this->actingAs($basia->fresh())->get(route('home'))->assertDontSee('z okazji urodzin', escape: false);

        // I da się włączyć z powrotem — wyłącznik na zawsze byłby pułapką.
        $this->actingAs($basia)
            ->put(route('settings.birthday.preferences'), ['_formularz' => 'wybory', 'birthday_wishes_enabled' => '1'])
            ->assertSessionHasNoErrors();
        $this->actingAs($basia->fresh())->get(route('home'))->assertSee(self::ZDANIE, escape: false);
    }

    public function test_zyczenia_nie_wysylaja_maila_ani_powiadomienia_i_nie_trafiaja_do_innych(): void
    {
        Mail::fake();
        $basia = $this->basia();
        $marek = $this->user('marek');
        $marek->following()->attach($basia->getKey());
        $this->travelTo(Carbon::parse('2026-03-12 09:00:00', 'UTC'));
        $powiadomienPrzed = DB::table('notifications')->count();

        $this->actingAs($basia)->get(route('home'))->assertSee(self::ZDANIE, escape: false);
        $this->actingAs($marek)->get(route('home'))->assertOk()->assertDontSee('z okazji urodzin', escape: false);

        Mail::assertNothingOutgoing();
        $this->assertSame($powiadomienPrzed, DB::table('notifications')->count());
    }

    public function test_cofniecie_migracji_odmawia_gdy_ktos_wylaczyl_zyczenia(): void
    {
        $basia = $this->basia();
        $this->actingAs($basia)->put(route('settings.birthday.preferences'), ['_formularz' => 'wybory']);
        $this->assertFalse($basia->fresh()->birthday_wishes_enabled);

        try {
            Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_09_25_200100_add_birthday_wishes_enabled_to_users.php', '--realpath' => false]);
            $this->fail('Rollback przeszedł mimo wyłączonych życzeń — DEFAULT true włączyłby je z powrotem.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(birthday_wishes_enabled = false): 1.', $e->getMessage());
        }

        $this->assertFalse($basia->fresh()->birthday_wishes_enabled);
    }

    public function test_cofniecie_migracji_przechodzi_na_wartosciach_domyslnych(): void
    {
        $this->basia();
        $sciezka = 'database/migrations/2026_09_25_200100_add_birthday_wishes_enabled_to_users.php';

        Artisan::call('migrate:rollback', ['--path' => $sciezka, '--realpath' => false]);
        $this->assertSame(0, count(DB::select("SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'birthday_wishes_enabled'")));

        Artisan::call('migrate', ['--path' => $sciezka, '--realpath' => false]);
        $this->assertSame(1, count(DB::select("SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'birthday_wishes_enabled'")));
    }
}
