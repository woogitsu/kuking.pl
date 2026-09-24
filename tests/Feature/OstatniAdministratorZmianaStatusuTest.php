<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\OdmowaOstatniegoAdministratora;
use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\PotwierdzenieZadaniaRodo;
use App\Models\Report;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ostatni czynny administrator nie traci aktywności przez ZMIANĘ STATUSU
 * (#1016, etap B). Etap A chronił tylko degradację roli; zawieszenie, ban
 * i własne żądanie usunięcia konta zostawiały rolę `admin` na koncie, które
 * nie wejdzie do panelu. Wyścig na dwóch połączeniach: Tests\Dwa.
 */
final class OstatniAdministratorZmianaStatusuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{Closure(User): void, string}> */
    public static function przejscia(): array
    {
        return [
            'zawieszenie' => [static fn (User $u) => $u->suspend(), User::STATUS_SUSPENDED],
            'zawieszenie czasowe' => [static fn (User $u) => $u->suspend(now()->addDays(7)), User::STATUS_SUSPENDED],
            'ban' => [static fn (User $u) => $u->ban(), User::STATUS_BANNED],
            'żądanie usunięcia' => [static fn (User $u) => $u->markForDeletion(), User::STATUS_PENDING_DELETE],
        ];
    }

    #[DataProvider('przejscia')]
    public function test_ostatni_czynny_administrator_nie_traci_aktywnosci(Closure $przejscie, string $status): void
    {
        $admin = $this->user('admin', ['role' => User::ROLE_ADMIN]);

        try {
            $przejscie($admin);
            $this->fail('Ostatni administrator stracił aktywność ('.$status.').');
        } catch (OdmowaOstatniegoAdministratora $odmowa) {
            $this->assertStringContainsString('nadaj rolę administratora innemu czynnemu kontu', $odmowa->getMessage());
        }

        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status);
    }

    #[DataProvider('przejscia')]
    public function test_przy_drugim_czynnym_administratorze_przejscie_jest_dozwolone(Closure $przejscie, string $status): void
    {
        $admin = $this->user('admin', ['role' => User::ROLE_ADMIN]);
        $this->user('drugi', ['role' => User::ROLE_ADMIN]);

        $przejscie($admin);

        $this->assertSame($status, $admin->fresh()->status);
    }

    #[DataProvider('przejscia')]
    public function test_nieczynny_administrator_nie_jest_zastepstwem(Closure $przejscie, string $status): void
    {
        $admin = $this->user('admin', ['role' => User::ROLE_ADMIN]);
        $this->user('zawieszony', ['role' => User::ROLE_ADMIN, 'status' => User::STATUS_SUSPENDED]);
        $this->user('zablokowany', ['role' => User::ROLE_ADMIN, 'status' => User::STATUS_BANNED]);

        $this->expectException(OdmowaOstatniegoAdministratora::class);
        $przejscie($admin);
    }

    public function test_straznik_czyta_role_z_bazy_a_nie_ze_starego_modelu(): void
    {
        // Model sprzed awansu mówi „user"; w bazie to już jedyny administrator.
        $stary = $this->user('stary');
        User::query()->whereKey($stary->getKey())->update(['role' => User::ROLE_ADMIN]);

        $this->expectException(OdmowaOstatniegoAdministratora::class);
        $stary->ban();
    }

    public function test_zwykle_konto_bez_administratorow_nadal_mozna_zawiesic(): void
    {
        // Zero administratorów to stan do naprawy, nie powód, żeby
        // zablokować moderację zwykłych kont.
        $basia = $this->user('basia');

        $basia->suspend();

        $this->assertSame(User::STATUS_SUSPENDED, $basia->fresh()->status);
    }

    public function test_odwrocona_kolejnosc_zamkow_jest_odrzucana(): void
    {
        $basia = $this->user('basia');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('przed blokadą konta');
        ZamekKonta::zablokuj($basia, static fn (?User $u) => $u?->ban());
    }

    public function test_formularz_usuniecia_konta_odmawia_ostatniemu_administratorowi(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('settings.data'))
            ->post(route('settings.data.delete'), [
                'password' => 'haslo-testowe-123',
                'confirm' => '1',
                'usun_tresci' => '1',
            ])
            ->assertRedirect(route('settings.data'))
            ->assertSessionHasErrors(['confirm' => 'Jesteś ostatnim czynnym administratorem serwisu. Zanim usuniesz konto, nadaj rolę administratora innemu czynnemu kontu — bez tego nikt nie rozpatrzy odwołań.'])
            ->assertSessionHasInput('usun_tresci', '1');

        $admin->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $admin->status);
        $this->assertNull($admin->delete_requested_at);
        $this->assertSame(0, PotwierdzenieZadaniaRodo::query()->count(), 'Odmowa otworzyła sprawę w rejestrze RODO.');
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'account.delete_requested')->count());
    }

    public function test_kontrola_dodatnia_formularz_przy_drugim_administratorze(): void
    {
        $admin = $this->admin();
        $this->user('drugi', ['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('settings.data.delete'), [
                'password' => 'haslo-testowe-123',
                'confirm' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_PENDING_DELETE, $admin->fresh()->status);
    }

    public function test_odmowa_w_panelu_nie_zostawia_decyzji_ani_powiadomienia(): void
    {
        // Polityka (#1408) już nie pozwala karać administratora. Zdejmujemy
        // ją tu świadomie, żeby zmierzyć DRUGĄ warstwę: strażnika w modelu
        // i to, że jego odmowa wycofuje całą decyzję.
        $sedzia = $this->moderator();
        $cel = $this->user('cel', ['role' => User::ROLE_ADMIN]);
        Gate::before(static fn (User $aktor, string $zdolnosc) => $zdolnosc === 'sanctionAccount' ? true : null);

        $report = Report::create([
            'reporter_id' => $this->user('zglaszajaca')->getKey(),
            'target_type' => 'user',
            'target_id' => $cel->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($sedzia)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_BAN,
                'reason_code' => 'nekanie',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame(User::STATUS_ACTIVE, $cel->fresh()->status);
        $this->assertSame(Report::STATUS_OPEN, $report->fresh()->status);
        $this->assertSame(0, ModerationAction::query()->count(), 'Odmowa zostawiła decyzję sugerującą wykonaną karę.');
        $this->assertSame(0, $cel->notifications()->count(), 'Odmowa wysłała powiadomienie o karze.');
    }
}
