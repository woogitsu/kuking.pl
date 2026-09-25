<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Rocznice\Urodziny;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Urodziny w ustawieniach: dzień i miesiąc, bez roku (issue #1755, etap a).
 *
 * Połowa testów sprawdza, czego NIE ma: roku, daty na profilu publicznym,
 * daty po usunięciu konta. To są obietnice z polityki prywatności.
 */
class UrodzinyWProfiluTest extends TestCase
{
    use RefreshDatabase;

    public function test_zapis_dnia_i_miesiaca(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->from(route('settings.birthday'))
            ->put(route('settings.birthday.update'), ['birthday_day' => '12', 'birthday_month' => '3'])
            ->assertRedirect(route('settings.birthday'))
            ->assertSessionHasNoErrors();

        $basia->refresh();
        $this->assertSame(12, $basia->birthday_day);
        $this->assertSame(3, $basia->birthday_month);

        $this->actingAs($basia)->get(route('settings.birthday'))
            ->assertOk()
            ->assertSee('12 marca', escape: false)
            ->assertSee('Usuń datę', escape: false);
    }

    public function test_formularz_nie_pyta_o_rok(): void
    {
        $html = (string) $this->actingAs($this->user('basia'))
            ->get(route('settings.birthday'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="birthday_day"', $html);
        $this->assertStringContainsString('name="birthday_month"', $html);
        $this->assertStringNotContainsString('name="birthday_year"', $html);
        $this->assertStringNotContainsString('Rok urodzenia', $html);
    }

    public function test_29_lutego_da_sie_zapisac(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->put(route('settings.birthday.update'), ['birthday_day' => '29', 'birthday_month' => '2'])
            ->assertSessionHasNoErrors();

        $this->assertSame(29, $basia->fresh()->birthday_day);
    }

    public function test_dzien_ktorego_nie_ma_w_miesiacu_wraca_z_bledem_i_zostawia_wybor(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->from(route('settings.birthday'))
            ->put(route('settings.birthday.update'), ['birthday_day' => '31', 'birthday_month' => '4'])
            ->assertRedirect(route('settings.birthday'))
            ->assertSessionHasErrors(['birthday_month' => 'Ten miesiąc nie ma 31 dni. Wybierz inny dzień albo inny miesiąc.'])
            ->assertSessionHasInput('birthday_day', '31')
            ->assertSessionHasInput('birthday_month', '4');

        $this->assertNull($basia->fresh()->birthday_month);

        // Po powrocie na formularz wybór stoi na swoim miejscu (AGENTS.md §5).
        $this->actingAs($basia)->get(route('settings.birthday'))
            ->assertSee('<option value="31" selected', escape: false)
            ->assertSee('<option value="4" selected', escape: false);
    }

    public function test_brak_miesiaca_mowi_co_zrobic(): void
    {
        $this->actingAs($this->user('basia'))
            ->put(route('settings.birthday.update'), ['birthday_day' => '5'])
            ->assertSessionHasErrors(['birthday_month' => 'Wybierz miesiąc urodzin z listy.']);
    }

    public function test_smiec_w_dniu_nie_przechodzi(): void
    {
        $this->actingAs($this->user('basia'))
            ->put(route('settings.birthday.update'), ['birthday_day' => '45', 'birthday_month' => '3'])
            ->assertSessionHasErrors('birthday_day')
            ->assertSessionDoesntHaveErrors('birthday_month');
    }

    public function test_usun_date_czysci_oba_pola(): void
    {
        $basia = $this->user('basia');
        $basia->forceFill(['birthday_day' => 12, 'birthday_month' => 3])->save();

        $this->actingAs($basia)
            ->delete(route('settings.birthday.destroy'))
            ->assertRedirect(route('settings.birthday'));

        $basia->refresh();
        $this->assertNull($basia->birthday_day);
        $this->assertNull($basia->birthday_month);
    }

    public function test_gosc_nie_wchodzi(): void
    {
        $this->get(route('settings.birthday'))->assertRedirect(route('login'));
        $this->put(route('settings.birthday.update'), ['birthday_day' => '1', 'birthday_month' => '1'])
            ->assertRedirect(route('login'));
    }

    public function test_data_nie_pojawia_sie_na_profilu_publicznym(): void
    {
        $basia = $this->user('basia');
        $basia->forceFill(['birthday_day' => 12, 'birthday_month' => 3])->save();

        foreach ([$this->user('marek'), $basia] as $ogladajacy) {
            $this->actingAs($ogladajacy)->get(route('profile.show', 'basia'))
                ->assertOk()
                ->assertDontSee('12 marca', escape: false)
                ->assertDontSee('12-03', escape: false);
        }
    }

    public function test_usuniecie_konta_zeruje_urodziny(): void
    {
        $odchodzi = $this->user('odchodzi', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
        ]);
        $odchodzi->forceFill(['birthday_day' => 12, 'birthday_month' => 3])->save();

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $odchodzi->refresh();
        $this->assertNull($odchodzi->birthday_day, 'Dzień urodzin został po wymazanym koncie.');
        $this->assertNull($odchodzi->birthday_month);
    }

    public function test_baza_odrzuca_dzien_spoza_miesiaca_i_pol_daty(): void
    {
        $basia = $this->user('basia');

        foreach ([['birthday_day' => 31, 'birthday_month' => 4], ['birthday_day' => 30, 'birthday_month' => 2], ['birthday_day' => 5, 'birthday_month' => null], ['birthday_day' => 1, 'birthday_month' => 13]] as $zle) {
            try {
                // Savepoint: błąd CHECK-a nie może zerwać transakcji testu.
                DB::transaction(fn () => DB::table('users')->where('id', $basia->getKey())->update($zle));
                $this->fail('Baza przyjęła niepoprawną datę: '.json_encode($zle));
            } catch (QueryException $e) {
                $this->assertStringContainsString('users_birthday_', $e->getMessage());
            }
        }

        // Kontrola dodatnia: poprawna para przechodzi przez te same CHECK-i.
        DB::table('users')->where('id', $basia->getKey())->update(['birthday_day' => 29, 'birthday_month' => 2]);
        $this->assertSame(29, $basia->fresh()->birthday_day);
    }

    public function test_reguly_daty(): void
    {
        $this->assertTrue(Urodziny::poprawna(29, 2));
        $this->assertFalse(Urodziny::poprawna(30, 2));
        $this->assertFalse(Urodziny::poprawna(31, 11));
        $this->assertTrue(Urodziny::poprawna(31, 12));

        // 29 lutego w roku nieprzestępnym — 28 lutego, a 1 marca już nie.
        $this->assertTrue(Urodziny::wypadaDnia(29, 2, Carbon::parse('2027-02-28 12:00', 'UTC')));
        $this->assertFalse(Urodziny::wypadaDnia(29, 2, Carbon::parse('2027-03-01 12:00', 'UTC')));
        $this->assertTrue(Urodziny::wypadaDnia(29, 2, Carbon::parse('2028-02-29 12:00', 'UTC')));
        $this->assertFalse(Urodziny::wypadaDnia(29, 2, Carbon::parse('2028-02-28 12:00', 'UTC')));

        // Dzień w strefie człowieka: 12 marca 00:30 w Warszawie to 11 marca w UTC.
        $this->assertTrue(Urodziny::wypadaDnia(12, 3, Carbon::parse('2027-03-11 23:30', 'UTC')));
        $this->assertFalse(Urodziny::wypadaDnia(11, 3, Carbon::parse('2027-03-11 23:30', 'UTC')));
    }
}
