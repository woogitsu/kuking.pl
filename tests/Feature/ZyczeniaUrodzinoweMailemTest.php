<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Rocznice\OdnosnikWypisaniaZUrodzin;
use App\Domain\Users\Actions\EraseAccountData;
use App\Mail\ZyczeniaUrodzinowe;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * List z życzeniami urodzinowymi — tylko za osobną zgodą, w sufitach poczty
 * (issue #1755, etap c). Każdy test z `Mail::fake()`: żaden list nie wychodzi
 * naprawdę.
 */
class ZyczeniaUrodzinoweMailemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        config([
            'kuking.urodziny.mail_wlaczony' => true,
            'kuking.urodziny.mail_dzienny_sufit' => 20,
        ]);
        // 12 marca 2026, 09:40 w Warszawie — pora z harmonogramu.
        $this->travelTo(Carbon::parse('2026-03-12 08:40:00', 'UTC'));
    }

    private function osoba(string $nazwa, bool $zgoda = true, int $dzien = 12, int $miesiac = 3, array $atrybuty = []): User
    {
        $osoba = $this->user($nazwa, ['display_name' => ucfirst($nazwa)] + $atrybuty);
        $osoba->forceFill([
            'birthday_day' => $dzien,
            'birthday_month' => $miesiac,
            'wants_birthday_email' => $zgoda,
        ])->save();

        return $osoba->fresh();
    }

    private function wyslij(): string
    {
        Artisan::call('kuking:wyslij-zyczenia-urodzinowe');

        return Artisan::output();
    }

    public function test_list_wychodzi_tylko_do_osoby_ze_zgoda_w_dniu_urodzin(): void
    {
        $basia = $this->osoba('basia');
        $bezZgody = $this->osoba('marek', zgoda: false);
        $innyDzien = $this->osoba('zofia', dzien: 13);
        $bezZyczen = $this->osoba('halina');
        $bezZyczen->forceFill(['birthday_wishes_enabled' => false])->save();
        $niepotwierdzona = $this->osoba('jan', atrybuty: ['email_verified_at' => null]);
        $zbanowana = $this->osoba('ewa', atrybuty: ['status' => User::STATUS_BANNED]);

        $this->wyslij();

        Mail::assertQueued(ZyczeniaUrodzinowe::class, 1);
        Mail::assertQueued(ZyczeniaUrodzinowe::class, fn (ZyczeniaUrodzinowe $m) => $m->hasTo($basia->email));

        foreach ([$bezZgody, $innyDzien, $bezZyczen, $niepotwierdzona, $zbanowana] as $nie) {
            Mail::assertNotQueued(ZyczeniaUrodzinowe::class, fn (ZyczeniaUrodzinowe $m) => $m->hasTo($nie->email));
        }
    }

    public function test_drugi_przebieg_tego_samego_dnia_nie_wysyla_drugiego_listu(): void
    {
        $this->osoba('basia');

        $this->wyslij();
        $this->wyslij();

        Mail::assertQueued(ZyczeniaUrodzinowe::class, 1);
    }

    public function test_za_rok_list_wychodzi_znowu(): void
    {
        $this->osoba('basia');
        $this->wyslij();

        $this->travelTo(Carbon::parse('2027-03-12 08:40:00', 'UTC'));
        $this->wyslij();

        Mail::assertQueued(ZyczeniaUrodzinowe::class, 2);
    }

    public function test_sufit_dobowy_zatrzymuje_nadmiar_i_mowi_o_tym(): void
    {
        config(['kuking.urodziny.mail_dzienny_sufit' => 1]);
        $this->osoba('basia');
        $this->osoba('marek');

        $wynik = $this->wyslij();

        Mail::assertQueued(ZyczeniaUrodzinowe::class, 1);
        $this->assertStringContainsString('bez listu zostało dziś: 1', $wynik);
    }

    public function test_wylacznik_wysylki_nic_nie_wysyla(): void
    {
        config(['kuking.urodziny.mail_wlaczony' => false]);
        $this->osoba('basia');

        $this->wyslij();

        Mail::assertNothingQueued();
    }

    public function test_urodziny_29_lutego_wychodza_28_lutego_w_roku_nieprzestepnym(): void
    {
        $this->travelTo(Carbon::parse('2027-02-28 08:40:00', 'UTC'));
        $basia = $this->osoba('basia', dzien: 29, miesiac: 2);

        $this->wyslij();

        Mail::assertQueued(ZyczeniaUrodzinowe::class, fn (ZyczeniaUrodzinowe $m) => $m->hasTo($basia->email));
    }

    public function test_zgoda_z_ustawien_zapisuje_dowod_w_dzienniku(): void
    {
        $basia = $this->osoba('basia', zgoda: false);

        $this->actingAs($basia)->put(route('settings.birthday.preferences'), [
            '_formularz' => 'wybory',
            'original_birthday_email' => '0',
            'birthday_wishes_enabled' => '1',
            'wants_birthday_email' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($basia->fresh()->wants_birthday_email);
        $this->assertSame(1, WpisZgody::query()
            ->where('user_id', $basia->getKey())
            ->where('cel', WpisZgody::CEL_ZYCZENIA_URODZINOWE)
            ->where('czynnosc', WpisZgody::UDZIELONA)
            ->where('zrodlo', WpisZgody::ZRODLO_USTAWIENIA)
            ->count());

        // Podanie daty zgody NIE daje — sprawdzone na drugim koncie.
        $marek = $this->user('marek');
        $this->actingAs($marek)->put(route('settings.birthday.update'), ['birthday_day' => '12', 'birthday_month' => '3']);
        $this->assertFalse($marek->fresh()->wants_birthday_email);
    }

    public function test_stary_formularz_nie_zapisuje_z_powrotem_na_list(): void
    {
        // Formularz otwarty przy zgodzie, w międzyczasie wypisanie z listu.
        $basia = $this->osoba('basia', zgoda: false);

        $this->actingAs($basia)->put(route('settings.birthday.preferences'), [
            '_formularz' => 'wybory',
            'original_birthday_email' => '1',
            'birthday_wishes_enabled' => '1',
            'wants_birthday_email' => '1',
        ])->assertSessionHasErrors('wants_birthday_email');

        $this->assertFalse($basia->fresh()->wants_birthday_email);
        $this->assertSame(0, WpisZgody::query()->where('cel', WpisZgody::CEL_ZYCZENIA_URODZINOWE)->count());
    }

    public function test_odnosnik_w_liscie_wypisuje_bez_logowania_z_dowodem(): void
    {
        $basia = $this->osoba('basia');

        $this->get(OdnosnikWypisaniaZUrodzin::dla($basia))
            ->assertOk()
            ->assertSee('Nie wyślemy już listu z życzeniami', escape: false);

        $this->assertFalse($basia->fresh()->wants_birthday_email);
        $this->assertSame(1, WpisZgody::query()
            ->where('user_id', $basia->getKey())
            ->where('cel', WpisZgody::CEL_ZYCZENIA_URODZINOWE)
            ->where('czynnosc', WpisZgody::WYCOFANA)
            ->where('zrodlo', WpisZgody::ZRODLO_LINK_WYPISANIA)
            ->count());
    }

    public function test_sam_identyfikator_bez_podpisu_nie_wypisuje(): void
    {
        $basia = $this->osoba('basia');

        $this->get(route('urodziny.wypisz', $basia))->assertForbidden();

        $this->assertTrue($basia->fresh()->wants_birthday_email);
    }

    public function test_list_ma_zyczenia_i_odnosnik_wypisania(): void
    {
        config(['kuking.community.host_name' => 'Ula']);
        $basia = $this->osoba('basia');

        $html = (new ZyczeniaUrodzinowe($basia))->render();

        $this->assertStringContainsString('Wszystkiego dobrego z okazji urodzin, Basia.', $html);
        $this->assertStringContainsString('Ula z Kuking', $html);
        $this->assertStringContainsString(e(OdnosnikWypisaniaZUrodzin::dla($basia)), $html);
    }

    public function test_list_nie_wychodzi_gdy_zgode_wycofano_po_zakolejkowaniu(): void
    {
        $basia = $this->osoba('basia');
        $list = new ZyczeniaUrodzinowe($basia);

        $basia->forceFill(['wants_birthday_email' => false])->save();

        // `send()` sprawdza zgodę przed transportem — przy braku zgody wraca
        // `null` i nie dotyka poczty.
        $this->assertNull($list->send(app(MailFactory::class)));
    }

    public function test_usun_date_wycofuje_zgode_na_list(): void
    {
        $basia = $this->osoba('basia');

        $this->actingAs($basia)->delete(route('settings.birthday.destroy'))->assertRedirect();

        $this->assertFalse($basia->fresh()->wants_birthday_email);
        $this->wyslij();
        Mail::assertNothingQueued();
    }

    public function test_usuniecie_konta_wycofuje_zgode_z_dowodem(): void
    {
        $odchodzi = $this->osoba('odchodzi', atrybuty: [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
        ]);

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $odchodzi->refresh();
        $this->assertFalse($odchodzi->wants_birthday_email);
        $this->assertNull($odchodzi->birthday_email_sent_on);
        $this->assertSame(1, WpisZgody::query()
            ->where('user_id', $odchodzi->getKey())
            ->where('cel', WpisZgody::CEL_ZYCZENIA_URODZINOWE)
            ->where('zrodlo', WpisZgody::ZRODLO_USUNIECIE_KONTA)
            ->count());
    }

    public function test_cofniecie_migracji_odmawia_przy_zgodzie_i_przechodzi_bez_niej(): void
    {
        $sciezka = 'database/migrations/2026_09_25_200200_add_birthday_email_consent_to_users.php';
        $basia = $this->osoba('basia');

        try {
            Artisan::call('migrate:rollback', ['--path' => $sciezka, '--realpath' => false]);
            $this->fail('Rollback przeszedł mimo zgody na list — wróciłaby jako false bez śladu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(wants_birthday_email = true): 1.', $e->getMessage());
        }
        $this->assertTrue($basia->fresh()->wants_birthday_email);

        // Kontrola dodatnia: bez zgód i bez wierszy dziennika rollback przechodzi.
        $basia->forceFill(['wants_birthday_email' => false])->save();
        Artisan::call('migrate:rollback', ['--path' => $sciezka, '--realpath' => false]);
        $this->assertSame(0, count(DB::select("SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'wants_birthday_email'")));
        Artisan::call('migrate', ['--path' => $sciezka, '--realpath' => false]);
        $this->assertSame(1, count(DB::select("SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'wants_birthday_email'")));
    }
}
