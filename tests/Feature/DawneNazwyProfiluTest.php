<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Actions\ZalozKonto;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dawna nazwa profilu przekierowuje 301 na aktualny profil (decyzja właściciela
 * z 1.10.2026, wiersz w D-333): wydrukowane karty z kodem QR i stare linki
 * mają działać po zmianie nazwy.
 */
class DawneNazwyProfiluTest extends TestCase
{
    use RefreshDatabase;

    private function zmienNazwe(User $u, string $nowa): void
    {
        $this->actingAs($u)->put('/ustawienia/profil', [
            'display_name' => 'Basia',
            'username' => $nowa,
        ])->assertSessionHasNoErrors();
    }

    private function wierszy(): int
    {
        return DB::table('profile_username_redirects')->count();
    }

    public function test_dawny_adres_przekierowuje_301_na_aktualny_profil(): void
    {
        $basia = $this->user('basia');

        // Kontrola dodatnia: bez zmiany nazwy stary adres to po prostu profil.
        $this->get('/@basia')->assertOk();

        $this->zmienNazwe($basia, 'barbara');
        auth()->logout();

        $this->get('/@basia')->assertStatus(301)->assertRedirect('/@barbara');
        $this->get('/@Basia')->assertStatus(301)->assertRedirect('/@barbara');
        $this->get('/@barbara')->assertOk();
        $this->get('/@nigdy-nie-bylo')->assertNotFound();
    }

    public function test_podstrony_i_kanal_atom_tez_przekierowuja_z_zachowaniem_zapytania(): void
    {
        $basia = $this->user('basia');
        $this->zmienNazwe($basia, 'barbara');
        auth()->logout();

        $this->get('/@basia/obserwujacy')->assertStatus(301)->assertRedirect('/@barbara/obserwujacy');
        $this->get('/@basia/obserwowani?page=2')->assertStatus(301)->assertRedirect('/@barbara/obserwowani?page=2');
        $this->get('/@basia/kanal')->assertStatus(301)->assertRedirect('/@barbara/kanal');
        $this->get('/@barbara/kanal')->assertOk();
        $this->get('/@basia?zakladka=przepisy')->assertStatus(301)->assertRedirect('/@barbara?zakladka=przepisy');
    }

    public function test_lancuch_zmian_prowadzi_wszystkie_dawne_nazwy_do_ostatniej_bez_petli(): void
    {
        $u = $this->user('aaa');
        $this->zmienNazwe($u, 'bbb');
        $this->zmienNazwe($u, 'ccc');
        auth()->logout();

        $this->get('/@aaa')->assertStatus(301)->assertRedirect('/@ccc');
        $this->get('/@bbb')->assertStatus(301)->assertRedirect('/@ccc');
        $this->get('/@ccc')->assertOk();
    }

    public function test_powrot_do_dawnej_nazwy_kasuje_przekierowanie_i_profil_odpowiada(): void
    {
        $u = $this->user('aaa');
        $this->zmienNazwe($u, 'bbb');
        $this->zmienNazwe($u, 'aaa');
        auth()->logout();

        $this->assertDatabaseMissing('profile_username_redirects', ['username' => 'aaa']);
        $this->assertDatabaseHas('profile_username_redirects', ['username' => 'bbb']);
        $this->get('/@aaa')->assertOk();
        $this->get('/@bbb')->assertStatus(301)->assertRedirect('/@aaa');
    }

    public function test_zmiana_samej_wielkosci_liter_nie_tworzy_przekierowania(): void
    {
        $u = $this->user('basia');
        $this->zmienNazwe($u, 'Basia');

        $this->assertSame(0, $this->wierszy());
    }

    public function test_zywy_profil_ma_pierwszenstwo_a_zajecie_dawnej_nazwy_kasuje_przekierowanie(): void
    {
        $a = $this->user('stara');
        $this->zmienNazwe($a, 'nowa');
        $this->assertSame(1, $this->wierszy());

        // Ktoś inny zajmuje dawną nazwę (wolno: nic jej nie rezerwuje).
        $b = $this->user('inna');
        $this->zmienNazwe($b, 'stara');
        auth()->logout();

        $this->assertDatabaseMissing('profile_username_redirects', ['username' => 'stara']);
        $this->get('/@stara')->assertOk()->assertDontSee('/@nowa');

        // Kiedy nowa właścicielka odejdzie z nazwy, dawny adres NIE ożywa
        // dla poprzedniej osoby (`/@nowa`) — prowadzi do tej, która ją miała
        // jako ostatnia.
        $this->zmienNazwe($b, 'jeszczeinna');
        auth()->logout();
        $this->get('/@stara')->assertStatus(301)->assertRedirect('/@jeszczeinna');
    }

    public function test_rejestracja_na_dawnej_nazwie_kasuje_przekierowanie(): void
    {
        $a = $this->user('stara');
        $this->zmienNazwe($a, 'nowa');
        auth()->logout();

        $this->assertSame(1, $this->wierszy());

        app(ZalozKonto::class)->handle(
            email: 'nowy@example.com',
            displayName: 'Nowy',
            username: 'stara',
            zrodloAkceptacji: 'rejestracja_haslo',
            haslo: 'haslo-testowe-123',
        );

        $this->assertDatabaseMissing('profile_username_redirects', ['username' => 'stara']);
        $this->get('/@stara')->assertOk();
    }

    public function test_przekierowanie_nie_ujawnia_nowej_nazwy_osoby_ktorej_profilu_nie_widac(): void
    {
        foreach (['banned', 'pending_delete'] as $i => $stan) {
            $u = $this->user('stara'.$i);
            $this->zmienNazwe($u, 'nowa'.$i);
            auth()->logout();

            // Kontrola dodatnia: dopóki konto jest aktywne, jest 301.
            $this->get('/@stara'.$i)->assertStatus(301);

            $u->forceFill(['status' => $stan])->save();

            $this->get('/@stara'.$i)->assertNotFound()->assertHeaderMissing('Location');
            $this->get('/@stara'.$i.'/obserwujacy')->assertNotFound();
            $this->get('/@stara'.$i.'/kanal')->assertNotFound();
        }
    }

    public function test_zablokowany_widz_dostaje_404_a_nie_adres_nowej_nazwy(): void
    {
        $a = $this->user('stara');
        $this->zmienNazwe($a, 'nowa');
        $widz = $this->user('widz');
        $widz->blockedBy()->attach($a->getKey(), ['created_at' => now()]);
        $obcy = $this->user('obcy');

        $this->actingAs($widz)->get('/@stara')->assertNotFound()->assertHeaderMissing('Location');
        $this->actingAs($widz)->get('/@stara/obserwujacy')->assertNotFound();

        // Kontrola dodatnia: ktoś bez blokady dostaje 301.
        $this->actingAs($obcy)->get('/@stara')->assertStatus(301)->assertRedirect('/@nowa');
    }

    public function test_wymazanie_konta_kasuje_dawne_nazwy_i_nie_zostawia_sladu(): void
    {
        $u = $this->user('stara', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_request_generation' => (string) Str::uuid(),
        ]);
        DB::table('profile_username_redirects')->insert(['username' => 'stara', 'user_id' => $u->getKey(), 'created_at' => now()]);
        DB::table('profile_username_redirects')->insert(['username' => 'jeszczestarsza', 'user_id' => $u->getKey(), 'created_at' => now()]);
        $obca = $this->user('obca');
        DB::table('profile_username_redirects')->insert(['username' => 'cudza_dawna', 'user_id' => $obca->getKey(), 'created_at' => now()]);

        $this->assertTrue(app(EraseAccountData::class)->handleExpiredRequest($u));

        $this->assertDatabaseMissing('profile_username_redirects', ['user_id' => $u->getKey()]);
        $this->assertDatabaseHas('profile_username_redirects', ['username' => 'cudza_dawna']);
        $this->get('/@stara')->assertNotFound();
        $this->assertNotNull(Profile::query()->where('user_id', $u->getKey())->first());
    }

    public function test_baza_pilnuje_formatu_i_unikalnosci_dawnej_nazwy(): void
    {
        $u = $this->user('basia');
        $wstaw = fn (string $nazwa) => DB::table('profile_username_redirects')
            ->insert(['username' => $nazwa, 'user_id' => $u->getKey(), 'created_at' => now()]);

        $wstaw('dobra_nazwa');

        foreach (['Wielka', 'ab', 'z kropka.', str_repeat('a', 41)] as $zla) {
            try {
                DB::transaction(fn () => $wstaw($zla));
                $this->fail("Baza przyjęła niepoprawną dawną nazwę „{$zla}”.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction(fn () => $wstaw('dobra_nazwa'));
    }

    public function test_kasowanie_konta_kaskadowo_zabiera_dawne_nazwy(): void
    {
        $u = $this->user('basia');
        $this->zmienNazwe($u, 'barbara');
        $this->assertSame(1, $this->wierszy());

        DB::table('users')->where('id', $u->getKey())->delete();

        $this->assertSame(0, $this->wierszy());
    }

    public function test_przekierowanie_kanalu_nie_nadaje_sie_do_zapamietania_na_stale(): void
    {
        $this->zmienNazwe($this->user('basia'), 'barbara');
        auth()->logout();

        // Kanał Atom stoi poza grupą `web`, więc nagłówek musi dać sama odpowiedź:
        // zapamiętane 301 A → B i B → A (powrót do dawnej nazwy) to pętla.
        foreach (['/@basia/kanal', '/@basia', '/@basia/obserwujacy'] as $adres) {
            $naglowek = (string) $this->get($adres)->assertStatus(301)->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $naglowek, "301 z {$adres} bez zakazu cache.");
        }
    }
}
