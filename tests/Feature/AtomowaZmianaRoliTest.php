<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\NadajRole;
use App\Domain\Users\Actions\ChangeUserRole;
use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\LoginLinkToken;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** Kontrakty jednego połączenia; rzeczywisty wyścig mierzy Tests\Dwa. */
final class AtomowaZmianaRoliTest extends TestCase
{
    use RefreshDatabase;

    public function test_awaria_audytu_cofa_zapis_roli(): void
    {
        $user = $this->user('rola');
        $event = 'eloquent.creating: '.AuditLogEntry::class;
        Event::listen($event, static function (AuditLogEntry $entry): void {
            if ($entry->action === 'user.role_changed') {
                throw new RuntimeException('Sonda awarii zapisu audytu.');
            }
        });
        $caught = null;
        try {
            app(ChangeUserRole::class)->handle($user, User::ROLE_ADMIN);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            Event::forget($event);
        }
        $this->assertSame('Sonda awarii zapisu audytu.', $caught?->getMessage());
        $this->assertSame(User::ROLE_USER, $user->refresh()->role, 'Awaria audytu pozostawiła zmienioną rolę.');
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'user.role_changed')->count());
        app(ChangeUserRole::class)->handle($user, User::ROLE_ADMIN);
        $this->assertSame(User::ROLE_ADMIN, $user->refresh()->role);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'user.role_changed')->count());
    }

    /**
     * #1315: odwołanie sesji stoi w transakcji zmiany roli. Awaria audytu
     * i odmowa (ostatni administrator) nie wylogowują nikogo.
     */
    public function test_awaria_audytu_i_odmowa_nie_odwoluja_sesji_ani_linku(): void
    {
        config(['session.driver' => 'database']);
        $ula = $this->user('rola');
        $szef = $this->user('szef', ['role' => User::ROLE_ADMIN]);
        $event = 'eloquent.creating: '.AuditLogEntry::class;
        Event::listen($event, static function (AuditLogEntry $entry): void {
            throw new RuntimeException('Sonda awarii zapisu audytu.');
        });

        foreach ([[$ula, User::ROLE_MODERATOR, RuntimeException::class], [$szef, User::ROLE_USER, DomainException::class]] as [$konto, $rola, $wyjatek]) {
            $this->poswiadczenia($konto);
            $token = $konto->fresh()->remember_token;
            $caught = null;
            try {
                app(ChangeUserRole::class)->handle($konto, $rola);
            } catch (RuntimeException|DomainException $exception) {
                $caught = $exception;
            }
            $this->assertInstanceOf($wyjatek, $caught);
            $this->assertNotSame($rola, $konto->fresh()->role);
            $this->assertDatabaseHas('sessions', ['id' => 'sesja-'.$konto->getKey()]);
            $this->assertSame(1, LoginLinkToken::query()->where('user_id', $konto->getKey())->count());
            $this->assertSame($token, $konto->fresh()->remember_token);
        }
        Event::forget($event);

        // Kontrola dodatnia: udana zmiana w tym samym układzie odwołuje wszystko.
        app(ChangeUserRole::class)->handle($ula, User::ROLE_MODERATOR);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesja-'.$ula->getKey()]);
        $this->assertSame(0, LoginLinkToken::query()->where('user_id', $ula->getKey())->count());
    }

    public function test_po_pytaniu_ponownie_sprawdza_role_i_ostatniego_admina(): void
    {
        $user = $this->user('rola');
        $level = DB::transactionLevel();
        [$code, $output] = $this->commandAtConfirmation($user, static function () use ($user, $level): void {
            self::assertSame($level, DB::transactionLevel(), 'Pytanie nie może trzymać transakcji zmiany roli.');
            $user->promoteTo(User::ROLE_ADMIN);
        });
        $this->assertSame(1, $code, 'Komenda zaufała roli sprzed pytania.');
        $this->assertStringContainsString('ostatnie czynne konto administratora', $output);
        $this->assertSame(User::ROLE_ADMIN, $user->refresh()->role);
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'user.role_changed')->count());
    }

    public function test_po_pytaniu_ponownie_sprawdza_status(): void
    {
        $user = $this->user('rola');
        [$code, $output] = $this->commandAtConfirmation($user, static function () use ($user): void {
            $user->forceFill(['status' => User::STATUS_BANNED])->save();
        });
        $this->assertSame(1, $code);
        $this->assertStringContainsString('najpierw przywróć konto', $output);
        $this->assertSame(User::ROLE_USER, $user->refresh()->role);
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'user.role_changed')->count());
    }

    public function test_audyt_czyta_poprzednia_role_z_bazy_a_nie_ze_starego_modelu(): void
    {
        $user = $this->user('rola');
        $user->fresh()->promoteTo(User::ROLE_MODERATOR);
        $result = app(ChangeUserRole::class)->handle($user, User::ROLE_ADMIN);
        $this->assertSame(User::ROLE_MODERATOR, $result['previous']);
        $entry = AuditLogEntry::query()->where('action', 'user.role_changed')->sole();
        $this->assertSame(User::ROLE_MODERATOR, $entry->metadata['from']);
        $this->assertNull($entry->actor_id);
        $this->assertNull($entry->ip_hash);
    }

    public function test_bezposrednia_akcja_takze_odmawia_degradacji_ostatniego_admina(): void
    {
        $user = $this->user('rola', ['role' => User::ROLE_ADMIN]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ostatnie czynne konto administratora');
        app(ChangeUserRole::class)->handle($user, User::ROLE_USER);
    }

    public function test_odwrocona_kolejnosc_zamkow_jest_odrzucana(): void
    {
        $user = $this->user('rola');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('przed założeniem blokady konta');
        ZamekKonta::zablokuj($user, static fn () => app(ChangeUserRole::class)->handle($user, User::ROLE_ADMIN));
    }

    private function poswiadczenia(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        DB::table('sessions')->insert([
            'id' => 'sesja-'.$user->getKey(),
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'przegladarka',
            'payload' => '',
            'last_activity' => time(),
        ]);
        $link = new LoginLinkToken;
        $link->user_id = $user->getKey();
        $link->token_hash = LoginLinkToken::skrot(Str::random(40));
        $link->created_at = now();
        $link->expires_at = now()->addMinutes(30);
        $link->save();
    }

    /** @return array{int, string} */
    private function commandAtConfirmation(User $user, Closure $duringConfirmation): array
    {
        $command = new class($duringConfirmation) extends NadajRole
        {
            public function __construct(private Closure $duringConfirmation)
            {
                parent::__construct();
            }

            public function confirm($question, $default = false): bool
            {
                ($this->duringConfirmation)();

                return true;
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $code = $command->run(new ArrayInput(['login' => $user->email, 'rola' => User::ROLE_MODERATOR]), $output);

        return [$code, $output->fetch()];
    }
}
