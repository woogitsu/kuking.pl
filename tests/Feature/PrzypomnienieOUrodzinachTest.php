<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * „Dziś urodziny: Ania" dla obserwujących (issue #1755, etap d).
 *
 * Połowa testów sprawdza, kiedy przypomnienia NIE MA: bez włączenia przez
 * solenizanta, w ciszy nocnej, ponad dobowy limit, drugi raz tego samego
 * dnia, przy blokadzie. I że nic nie trafia do feedu.
 */
class PrzypomnienieOUrodzinachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['kuking.urodziny.przypomnienia_na_odbiorce_dziennie' => 3]);
        // 12 marca 2026, 09:00 w Warszawie (zima, UTC+1) — po ciszy nocnej.
        $this->travelTo(Carbon::parse('2026-03-12 08:00:00', 'UTC'));
    }

    private function solenizant(string $nazwa, bool $pokazuj = true, int $dzien = 12, int $miesiac = 3): User
    {
        $osoba = $this->user($nazwa, ['display_name' => ucfirst($nazwa)]);
        $osoba->forceFill([
            'birthday_day' => $dzien,
            'birthday_month' => $miesiac,
            'birthday_visible_to_followers' => $pokazuj,
        ])->save();

        return $osoba->fresh();
    }

    private function przypomnij(): void
    {
        Artisan::call('kuking:przypomnij-o-urodzinach');
    }

    private function urodzinowe(User $odbiorca): int
    {
        return Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', Notification::TYPE_BIRTHDAY)
            ->count();
    }

    public function test_obserwujacy_dostaje_przypomnienie_a_obcy_nie(): void
    {
        $ania = $this->solenizant('ania');
        $marek = $this->user('marek');
        $obcy = $this->user('obcy');
        $marek->following()->attach($ania->getKey());

        $this->przypomnij();

        $this->assertSame(1, $this->urodzinowe($marek));
        $this->assertSame(0, $this->urodzinowe($obcy));
        $this->assertSame(0, $this->urodzinowe($ania), 'Solenizant nie dostaje przypomnienia o sobie.');
        $this->assertSame(
            (string) $ania->getKey(),
            (string) Notification::query()->where('user_id', $marek->getKey())->value('actor_id'),
        );
        Mail::assertNothingOutgoing();
    }

    public function test_bez_wlaczenia_przez_solenizanta_nic_nie_powstaje(): void
    {
        $ania = $this->solenizant('ania', pokazuj: false);
        $marek = $this->user('marek');
        $marek->following()->attach($ania->getKey());

        $this->przypomnij();

        $this->assertSame(0, $this->urodzinowe($marek));
    }

    public function test_w_inny_dzien_nic_nie_powstaje(): void
    {
        $ania = $this->solenizant('ania', dzien: 13);
        $marek = $this->user('marek');
        $marek->following()->attach($ania->getKey());

        $this->przypomnij();

        $this->assertSame(0, $this->urodzinowe($marek));
    }

    public function test_drugi_przebieg_tego_samego_dnia_nie_dubluje(): void
    {
        $ania = $this->solenizant('ania');
        $marek = $this->user('marek');
        $marek->following()->attach($ania->getKey());

        $this->przypomnij();
        $this->przypomnij();

        $this->assertSame(1, $this->urodzinowe($marek));
    }

    public function test_dobowy_limit_na_odbiorce(): void
    {
        config(['kuking.urodziny.przypomnienia_na_odbiorce_dziennie' => 1]);
        $ania = $this->solenizant('ania');
        $zosia = $this->solenizant('zosia');
        $marek = $this->user('marek');
        $marek->following()->attach([$ania->getKey(), $zosia->getKey()]);

        $this->przypomnij();

        $this->assertSame(1, $this->urodzinowe($marek));
    }

    public function test_w_ciszy_nocnej_nic_nie_powstaje(): void
    {
        // 22:00 w Warszawie.
        $this->travelTo(Carbon::parse('2026-03-12 21:00:00', 'UTC'));
        $ania = $this->solenizant('ania');
        $marek = $this->user('marek');
        $marek->following()->attach($ania->getKey());

        $this->przypomnij();

        $this->assertSame(0, $this->urodzinowe($marek));
    }

    public function test_blokada_odcina_przypomnienie(): void
    {
        $ania = $this->solenizant('ania');
        $marek = $this->user('marek');
        $marek->following()->attach($ania->getKey());
        $ania->blocking()->attach($marek->getKey());

        $this->przypomnij();

        $this->assertSame(0, $this->urodzinowe($marek));
    }

    public function test_przypomnienie_nie_trafia_do_feedu(): void
    {
        $ania = $this->solenizant('ania');
        $marek = $this->user('marek');
        $marek->following()->attach($ania->getKey());
        $wpisowPrzed = DB::table('posts')->count();

        $this->przypomnij();

        $this->assertSame($wpisowPrzed, DB::table('posts')->count(), 'Przypomnienie nie może być wpisem w feedzie.');
        $this->actingAs($marek)->get(route('home'))->assertOk()->assertDontSee('Dziś urodziny', escape: false);
    }

    public function test_lista_powiadomien_mowi_kto_ma_urodziny_i_prowadzi_na_profil(): void
    {
        $ania = $this->solenizant('ania');
        $marek = $this->user('marek');
        $marek->following()->attach($ania->getKey());

        $this->przypomnij();

        $this->actingAs($marek)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Dziś urodziny: Ania.', escape: false);

        $powiadomienie = Notification::query()->where('user_id', $marek->getKey())->firstOrFail();
        $this->assertSame(route('profile.show', 'ania'), $powiadomienie->adresDocelowy());
    }

    public function test_wlacznik_w_ustawieniach_i_usun_date(): void
    {
        $ania = $this->solenizant('ania', pokazuj: false);

        $this->actingAs($ania)->put(route('settings.birthday.preferences'), [
            '_formularz' => 'wybory',
            'original_birthday_email' => '0',
            'birthday_wishes_enabled' => '1',
            'birthday_visible_to_followers' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($ania->fresh()->birthday_visible_to_followers);

        $this->actingAs($ania)->delete(route('settings.birthday.destroy'));
        $this->assertFalse($ania->fresh()->birthday_visible_to_followers, '„Usuń datę" ma wyłączyć też przypomnienie.');
    }

    public function test_cofniecie_migracji_odmawia_gdy_ktos_wlaczyl_i_przechodzi_gdy_nie(): void
    {
        $sciezka = 'database/migrations/2026_09_25_200300_add_birthday_visible_to_followers_to_users.php';
        $ania = $this->solenizant('ania');

        try {
            Artisan::call('migrate:rollback', ['--path' => $sciezka, '--realpath' => false]);
            $this->fail('Rollback przeszedł mimo włączonego przypomnienia.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(birthday_visible_to_followers = true): 1.', $e->getMessage());
        }
        $this->assertTrue($ania->fresh()->birthday_visible_to_followers);

        $ania->forceFill(['birthday_visible_to_followers' => false])->save();
        Artisan::call('migrate:rollback', ['--path' => $sciezka, '--realpath' => false]);
        $this->assertSame(0, count(DB::select("SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'birthday_visible_to_followers'")));
        Artisan::call('migrate', ['--path' => $sciezka, '--realpath' => false]);
    }
}
