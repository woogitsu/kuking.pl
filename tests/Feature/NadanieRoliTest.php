<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `kuking:nadaj-role` — jedyna droga do roli administratora (D-039).
 *
 * DLACZEGO TO MA TEST, A NIE „PRZECIEŻ TO JEDNA LINIJKA"
 * Bo od tej komendy zależy, czy w serwisie w ogóle JEST ktoś, kto może
 * zamknąć odwołanie od decyzji moderacyjnej. `UserPolicy::resolveAppeals()`
 * przepuszcza wyłącznie `role === 'admin'`, a poza tą komendą nic tej roli
 * nie nadaje — żaden seeder, żaden ekran. Zepsuta komenda znaczy: odwołania
 * nie do zamknięcia, a termin z DSA art. 20 biegnie dalej.
 */
class NadanieRoliTest extends TestCase
{
    use RefreshDatabase;

    public function test_nadaje_role_administratora_i_zapisuje_to_w_dzienniku(): void
    {
        $user = $this->user('ula', ['email' => 'ula@kuking.pl']);
        $this->assertSame(User::ROLE_USER, $user->role);

        $this->artisan('kuking:nadaj-role', ['login' => 'ula@kuking.pl', 'rola' => 'admin', '--tak' => true])
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->isAdmin());

        // Zmiana roli musi zostawić ślad: to ona decyduje, kto zamyka czyjeś
        // odwołanie. Bez wpisu w dzienniku nie da się potem powiedzieć, kiedy
        // i z czego ta rola się wzięła.
        $wpis = AuditLogEntry::query()->where('action', 'user.role_changed')->sole();

        $this->assertSame((string) $user->getKey(), (string) $wpis->subject_id);
        $this->assertSame(User::ROLE_USER, $wpis->metadata['from']);
        $this->assertSame(User::ROLE_ADMIN, $wpis->metadata['to']);
        $this->assertSame('console:kuking:nadaj-role', $wpis->metadata['source']);
    }

    public function test_bez_potwierdzenia_nic_sie_nie_zmienia(): void
    {
        $user = $this->user('ula', ['email' => 'ula@kuking.pl']);

        // ADRES W PYTANIU JEST W SKRÓCIE (issue #1026). Komendę uruchamia się
        // także z konsoli platformy hostingowej, a wszystko, co wypisze,
        // zostaje w logu tej platformy. `u***@kuking.pl` odpowiada na jedyne
        // pytanie, jakie właściciel zadaje w tym momencie — login i tak podał
        // przed chwilą sam.
        $this->artisan('kuking:nadaj-role', ['login' => 'ula@kuking.pl', 'rola' => 'admin'])
            ->expectsConfirmation('Zmienić rolę konta u***@kuking.pl z „user" na „admin"?', 'no')
            ->assertSuccessful();

        $this->assertSame(User::ROLE_USER, $user->refresh()->role);
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'user.role_changed')->count());
    }

    public function test_nieznana_rola_jest_odrzucana(): void
    {
        $user = $this->user('ula', ['email' => 'ula@kuking.pl']);

        $this->artisan('kuking:nadaj-role', ['login' => 'ula@kuking.pl', 'rola' => 'wlasciciel', '--tak' => true])
            ->assertFailed();

        $this->assertSame(User::ROLE_USER, $user->refresh()->role);
    }

    public function test_nieznane_konto_jest_odrzucane(): void
    {
        $this->artisan('kuking:nadaj-role', ['login' => 'nie-ma@kuking.pl', 'rola' => 'admin', '--tak' => true])
            ->assertFailed();
    }

    /**
     * Rola na koncie zablokowanym nie jest uprawnieniem, tylko pułapką:
     * konto i tak nie wejdzie do panelu, a zapytanie po roli pokaże
     * administratora, którego naprawdę nie ma.
     */
    public function test_konto_niepelnoprawne_nie_dostaje_roli(): void
    {
        $user = $this->user('ula', ['email' => 'ula@kuking.pl', 'status' => User::STATUS_BANNED]);

        $this->artisan('kuking:nadaj-role', ['login' => 'ula@kuking.pl', 'rola' => 'admin', '--tak' => true])
            ->assertFailed();

        $this->assertSame(User::ROLE_USER, $user->refresh()->role);
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * Odebranie roli ostatniemu administratorowi zamyka odwołania równie
     * skutecznie, co zawężenie Policy przy zerze administratorów — z tą
     * różnicą, że widać to dopiero wtedy, gdy ktoś się odwoła. `--tak` tego
     * NIE omija: to nie jest pytanie o wygodę operatora.
     */
    public function test_nie_da_sie_odebrac_roli_ostatniemu_administratorowi(): void
    {
        $admin = $this->user('ula', ['email' => 'ula@kuking.pl', 'role' => User::ROLE_ADMIN]);

        $this->artisan('kuking:nadaj-role', ['login' => 'ula@kuking.pl', 'rola' => 'moderator', '--tak' => true])
            ->assertFailed();

        $this->assertTrue($admin->refresh()->isAdmin());
    }

    public function test_przedostatniemu_administratorowi_wolno_odebrac_role(): void
    {
        $pierwszy = $this->user('ula', ['email' => 'ula@kuking.pl', 'role' => User::ROLE_ADMIN]);
        $this->user('drugi', ['email' => 'drugi@kuking.pl', 'role' => User::ROLE_ADMIN]);

        $this->artisan('kuking:nadaj-role', ['login' => 'ula@kuking.pl', 'rola' => 'moderator', '--tak' => true])
            ->assertSuccessful();

        $this->assertSame(User::ROLE_MODERATOR, $pierwszy->refresh()->role);
    }

    /**
     * ASERCJA KONTROLNA dla poprzedniego testu: drugi administrator liczy się
     * tylko wtedy, gdy jego konto jest CZYNNE. Bez tego warunku zablokowane
     * konto administratora „chroniłoby" przed odebraniem roli ostatniemu
     * czynnemu — czyli dokładnie odwrotnie, niż ta reguła ma działać.
     */
    public function test_zablokowany_administrator_nie_liczy_sie_jako_zastepstwo(): void
    {
        $czynny = $this->user('ula', ['email' => 'ula@kuking.pl', 'role' => User::ROLE_ADMIN]);
        $this->user('zablokowany', [
            'email' => 'zablokowany@kuking.pl',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_BANNED,
        ]);

        $this->artisan('kuking:nadaj-role', ['login' => 'ula@kuking.pl', 'rola' => 'moderator', '--tak' => true])
            ->assertFailed();

        $this->assertTrue($czynny->refresh()->isAdmin());
    }

    public function test_ta_sama_rola_drugi_raz_niczego_nie_zapisuje(): void
    {
        $this->user('ula', ['email' => 'ula@kuking.pl', 'role' => User::ROLE_MODERATOR]);

        $this->artisan('kuking:nadaj-role', ['login' => 'ula@kuking.pl', 'rola' => 'moderator', '--tak' => true])
            ->assertSuccessful();

        $this->assertSame(0, AuditLogEntry::query()->where('action', 'user.role_changed')->count());
    }

    /**
     * KONTROLA GRANICY DLA D-065 (`docs/DECISIONS.md`), nie dla tej komendy.
     *
     * Cały argument za tym, żeby dziś NIE brać `spatie/laravel-permission`,
     * stoi na jednym zdaniu: „`role IN ('user','moderator','admin')` jest
     * pilnowane w jednym miejscu prawdy — w bazie, nie tylko w PHP".
     * `test_nieznana_rola_jest_odrzucana()` wyżej sprawdza wyłącznie walidację
     * PHP w `promoteTo()`/`kuking:nadaj-role` — omija ją każde zapisanie
     * wiersza z pominięciem modelu (migracja danych, ręczny `UPDATE`, przyszły
     * bug w innym miejscu). Ten test pomija PHP całkowicie i pisze wprost do
     * bazy, żeby sprawdzić, czy backstop, na którym stoi cała decyzja
     * (`users_role_check`, `database/migrations/0001_01_01_000001_create_users_table.php`),
     * naprawdę istnieje — a nie tylko tak jest opisany w dokumentacji.
     */
    public function test_baza_odrzuca_role_spoza_trzech_dozwolonych_wartosci(): void
    {
        $user = $this->user('ula', ['email' => 'ula@kuking.pl']);

        $this->expectException(QueryException::class);

        DB::table('users')
            ->where('id', $user->getKey())
            ->update(['role' => 'superadmin']);
    }
}
