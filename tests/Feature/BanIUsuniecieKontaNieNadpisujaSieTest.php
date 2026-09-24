<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\CancelAccountDeletion;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * KARA I USUNIĘCIE KONTA NIE NADPISUJĄ SIĘ (issue #980).
 *
 * Kontrakt: w cyklu usunięcia `status` mówi o usuwaniu, kara czeka
 * w `punishment_status`. Ban nie zatrzymuje egzekucji karencji, a cofnięcie
 * usunięcia nie zdejmuje kary. Wyścig na dwóch połączeniach:
 * `tests/Dwa/BanIUsuniecieKontaRownolegleTest`.
 */
class BanIUsuniecieKontaNieNadpisujaSieTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'database/migrations/2026_09_24_100000_add_punishment_status_to_users.php';

    public function test_ban_po_zgloszeniu_usuniecia_nie_zatrzymuje_egzekucji(): void
    {
        $osoba = $this->user('ban_po_usunieciu');
        $osoba->markForDeletion();
        $osoba->ban();

        $stan = $osoba->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status, 'Ban wyjął konto spod egzekutora karencji.');
        $this->assertSame(User::STATUS_BANNED, $stan->punishment_status);
        $this->assertNotNull($stan->delete_requested_at);

        $this->travel((int) config('kuking.account.delete_grace_days') + 1)->days();
        Artisan::call('kuking:usun-wygasle-konta');

        $poEgzekucji = $osoba->fresh();
        $this->assertSame(User::STATUS_ERASED, $poEgzekucji->status, 'Żądanie usunięcia danych nie zostało wykonane.');
        $this->assertNotNull($poEgzekucji->data_erased_at);
        // Zapis stanu kary zostaje na wymazanym wierszu.
        $this->assertSame(User::STATUS_BANNED, $poEgzekucji->punishment_status);
    }

    public function test_zgloszenie_usuniecia_ze_starego_modelu_nie_gubi_bana(): void
    {
        $osoba = $this->user('stary_model');
        $zFormularza = User::query()->findOrFail($osoba->getKey());

        // Moderator banuje świeżą instancję, formularz trzyma starą (`active`).
        User::query()->findOrFail($osoba->getKey())->ban();
        $this->assertSame(User::STATUS_ACTIVE, $zFormularza->status, 'Model formularza miał być nieaktualny.');

        $zFormularza->markForDeletion();

        $stan = $osoba->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status);
        $this->assertSame(User::STATUS_BANNED, $stan->punishment_status, 'Zgłoszenie usunięcia zgubiło ban.');

        (new CancelAccountDeletion)->handle($stan);

        $poCofnieciu = $osoba->fresh();
        $this->assertSame(User::STATUS_BANNED, $poCofnieciu->status, 'Cofnięcie usunięcia zdjęło ban.');
        $this->assertNull($poCofnieciu->punishment_status);
        $this->assertNull($poCofnieciu->delete_requested_at);
        $this->assertFalse($poCofnieciu->mozeCzytac(), 'Po cofnięciu usunięcia zablokowane konto znowu wchodzi do serwisu.');
    }

    public function test_zawieszenie_w_karencji_wraca_po_cofnieciu_z_terminem(): void
    {
        $osoba = $this->user('zawieszenie_w_karencji');
        $osoba->markForDeletion();
        $termin = now()->addDays(7)->startOfSecond();
        $osoba->suspend($termin);

        $stan = $osoba->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status);
        $this->assertNull($stan->status_expires_at);
        $this->assertSame(User::STATUS_SUSPENDED, $stan->punishment_status);

        (new CancelAccountDeletion)->handle($stan);

        $poCofnieciu = $osoba->fresh();
        $this->assertSame(User::STATUS_SUSPENDED, $poCofnieciu->status);
        $this->assertTrue($termin->equalTo($poCofnieciu->status_expires_at), 'Zawieszenie wróciło bez terminu.');
    }

    public function test_zgloszenie_usuniecia_przy_zawieszeniu_odklada_kare(): void
    {
        $osoba = $this->user('zawieszony_usuwa');
        $termin = now()->addDays(3)->startOfSecond();
        $osoba->suspend($termin);

        $osoba->markForDeletion(User::DELETE_SCOPE_EVERYTHING);

        $stan = $osoba->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status);
        $this->assertSame(User::DELETE_SCOPE_EVERYTHING, $stan->delete_scope);
        $this->assertSame(User::STATUS_SUSPENDED, $stan->punishment_status);
        $this->assertTrue($termin->equalTo($stan->punishment_expires_at));
        $this->assertNull($stan->status_expires_at, 'Termin zawieszenia został przy pending_delete.');

        (new CancelAccountDeletion)->handle($stan);
        $this->assertSame(User::STATUS_SUSPENDED, $osoba->fresh()->status);
    }

    public function test_uchylenie_kary_w_karencji_nie_anuluje_usuniecia(): void
    {
        $osoba = $this->user('uchylenie_w_karencji');
        $osoba->markForDeletion();
        $osoba->ban();

        // Odwołanie uwzględnione (`ResolveAppeal::cofnij()` woła `reinstate()`).
        $osoba->fresh()->reinstate();

        $stan = $osoba->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status, 'Uchylenie bana anulowało żądanie usunięcia danych.');
        $this->assertNull($stan->punishment_status);

        (new CancelAccountDeletion)->handle($stan);
        $this->assertSame(User::STATUS_ACTIVE, $osoba->fresh()->status);
    }

    public function test_kontrola_dodatnia_zwykle_usuniecie_i_cofniecie_wraca_do_active(): void
    {
        $osoba = $this->user('zwykle_cofniecie');
        $osoba->markForDeletion();

        $this->assertSame(User::STATUS_PENDING_DELETE, $osoba->status, 'Model wołającego nie dostał stanu z bazy.');
        $this->assertNull($osoba->fresh()->punishment_status);

        (new CancelAccountDeletion)->handle($osoba);

        $this->assertSame(User::STATUS_ACTIVE, $osoba->fresh()->status);
    }

    public function test_drugie_zgloszenie_nie_przestawia_zegara_karencji(): void
    {
        $osoba = $this->user('dwa_klikniecia');
        $zDrugiejKarty = User::query()->findOrFail($osoba->getKey());
        $osoba->markForDeletion();
        $pierwsze = $osoba->fresh()->delete_requested_at;

        $this->travel(5)->days();

        try {
            $zDrugiejKarty->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
            $this->fail('Drugie zgłoszenie ze starego modelu przeszło.');
        } catch (BladDlaCzlowieka) {
        }

        $stan = $osoba->fresh();
        $this->assertTrue($pierwsze->equalTo($stan->delete_requested_at));
        $this->assertSame(User::DELETE_SCOPE_MINIMUM, $stan->delete_scope);
    }

    public function test_baza_odrzuca_kare_odlozona_poza_cyklem_usuniecia(): void
    {
        $osoba = $this->user('check_kary');

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $osoba->getKey())->update(['punishment_status' => User::STATUS_BANNED]);
    }

    public function test_cofniecie_migracji_odmawia_gdy_jest_kara_odlozona(): void
    {
        $osoba = $this->user('rollback_z_kara');
        $osoba->markForDeletion();
        $osoba->ban();

        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA, '--realpath' => false]);
            $this->fail('Cofnięcie migracji przeszło mimo kary odłożonej na czas usuwania.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('users.punishment_status', $e->getMessage());
        }

        $this->assertSame(User::STATUS_BANNED, $osoba->fresh()->punishment_status, 'Odmowa nie ochroniła danych.');
    }

    public function test_cofniecie_migracji_przechodzi_bez_kar_odlozonych(): void
    {
        $this->user('rollback_bez_kary')->ban();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA, '--realpath' => false]);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('users', 'punishment_status'));

        Artisan::call('migrate', ['--path' => self::MIGRACJA, '--realpath' => false]);
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('users', 'punishment_status'));
    }
}
