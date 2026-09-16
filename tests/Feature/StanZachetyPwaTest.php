<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZanotujOstatniaWizyte;
use App\Domain\Pwa\InstallPrompt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class StanZachetyPwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_kwalifikacja_wymaga_poprzedniej_wizyty_i_pelnych_24_godzin(): void
    {
        $this->freezeTime();
        $action = new InstallPrompt;
        foreach ([null, now()->addHour(), now(), now()->subHours(24)->addSecond()] as $previous) {
            $user = $this->user();
            $this->assertFalse($action->qualify($user, $previous));
            $this->assertNull($user->fresh()->pwa_prompt_state);
        }

        foreach ([now()->subHours(24), now()->subDays(2)] as $previous) {
            $user = $this->user();
            $original = $previous->toISOString();
            $this->assertTrue($action->qualify($user, $previous));
            $this->assertSame('eligible', $user->fresh()->pwa_prompt_state);
            $this->assertFalse($action->qualify($user, $previous));
            $this->assertSame($original, $previous->toISOString());
        }
    }

    public function test_poprzednia_aktywnosc_dziala_takze_po_zapisie_trackera_bez_refresh(): void
    {
        $this->travelTo(CarbonImmutable::now('UTC')->startOfSecond());
        $user = $this->user(null, ['ostatnio_widziany_at' => now()->subDays(2)]);
        $previous = $user->ostatnio_widziany_at;
        app(ZanotujOstatniaWizyte::class)->handle($user);

        $this->assertTrue($user->fresh()->ostatnio_widziany_at->equalTo(now()));
        $this->assertTrue($user->ostatnio_widziany_at->equalTo($previous));
        $this->assertTrue((new InstallPrompt)->qualify($user, $previous));
    }

    public function test_zmiana_czasu_nie_skraca_24_godzin_do_doby_kalendarzowej(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-29 12:00:00', 'Europe/Warsaw'));
        $action = new InstallPrompt;
        $user = $this->user();
        $this->assertFalse($action->qualify($user, CarbonImmutable::parse('2026-03-28 12:00:00', 'Europe/Warsaw')));
        $this->assertTrue($action->qualify($user, CarbonImmutable::parse('2026-03-28 11:00:00', 'Europe/Warsaw')));
    }

    public function test_przejscia_sa_jawne_a_requested_nie_oznacza_installed(): void
    {
        $action = new InstallPrompt;
        $allowed = [
            'offer' => ['eligible'],
            'requestInstallation' => ['offered'],
            'dismiss' => ['offered', 'requested'],
            'installed' => ['offered', 'requested', 'dismissed'],
        ];
        $target = ['offer' => 'offered', 'requestInstallation' => 'requested', 'dismiss' => 'dismissed', 'installed' => 'installed'];

        foreach ([null, 'eligible', 'offered', 'requested', 'dismissed', 'installed'] as $state) {
            foreach ($allowed as $method => $from) {
                $user = $this->user();
                DB::table('users')->where('id', $user->getKey())->update(['pwa_prompt_state' => $state]);
                $expected = in_array($state, $from, true);
                $this->assertSame($expected, $action->{$method}($user), "$method ze stanu ".($state ?? 'NULL'));
                $this->assertSame($expected ? $target[$method] : $state, $user->fresh()->pwa_prompt_state);
            }
        }
    }

    public function test_stare_modele_nie_powtarzaja_oferty_i_nie_cofaja_odmowy(): void
    {
        $action = new InstallPrompt;
        $user = $this->user();
        $this->assertTrue($action->qualify($user, now()->subDays(2)));
        $first = $user->fresh();
        $second = $user->fresh();

        // Dwa stare odczyty i kolejne warunkowe UPDATE; to NIE jest pomiar
        // rzeczywistej współbieżności na dwóch połączeniach.
        $this->assertTrue($action->offer($first));
        $this->assertFalse($action->offer($second));
        $this->assertTrue($action->dismiss($first));
        $this->assertFalse($action->requestInstallation($second));
        $this->assertFalse($action->qualify($second, now()->subDays(2)));
        $this->assertFalse($action->offer($second));
        $this->assertSame('dismissed', $user->fresh()->pwa_prompt_state);

        // Zdarzenie instalacji może nadejść późno, odwrotne cofnięcie nie.
        $this->assertTrue($action->installed($second));
        $this->assertFalse($action->dismiss($first));
        $this->assertFalse($action->installed($second));
        $this->assertSame('installed', $user->fresh()->pwa_prompt_state);
    }

    public function test_stan_jest_osobny_dla_kont_a_zapis_nie_dotyka_updated_at(): void
    {
        $action = new InstallPrompt;
        $first = $this->user();
        $second = $this->user();
        $updated = $first->updated_at;
        $this->travel(2)->days();

        $this->assertTrue($action->qualify($first, now()->subDays(2)));
        $this->assertTrue($action->offer($first));
        $this->assertTrue($action->dismiss($first));
        $this->assertNull($second->fresh()->pwa_prompt_state);
        $this->assertTrue($first->fresh()->updated_at->equalTo($updated));
    }

    public function test_zamkniete_konto_nie_przechodzi_nawet_przez_nieodswiezony_model(): void
    {
        $action = new InstallPrompt;
        $user = $this->user();
        // Zmiana statusu po odczycie modelu odtwarza nieaktualny obiekt
        // przekazany akcji. Autoryzacja HTTP ma osobny zestaw testów.
        DB::table('users')->where('id', $user->getKey())->update(['status' => User::STATUS_BANNED]);
        $this->assertFalse($action->qualify($user, now()->subDays(2)));
        foreach (['offer' => 'eligible', 'requestInstallation' => 'offered', 'dismiss' => 'requested', 'installed' => 'dismissed'] as $method => $state) {
            DB::table('users')->where('id', $user->getKey())->update(['pwa_prompt_state' => $state]);
            $this->assertFalse($action->{$method}($user));
            $this->assertSame($state, $user->fresh()->pwa_prompt_state);
        }
    }

    public function test_baza_odrzuca_nieznany_stan(): void
    {
        $user = $this->user();
        try {
            DB::transaction(fn () => DB::table('users')->where('id', $user->getKey())->update(['pwa_prompt_state' => 'unknown']));
            $this->fail('CHECK przyjął nieznany stan PWA.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('users_pwa_prompt_state_check', $e->getMessage());
        }
        $this->assertNull($user->fresh()->pwa_prompt_state);
    }

    public function test_awaria_kwalifikacji_nie_zatruwa_transakcji_strony(): void
    {
        $user = $this->user();
        DB::statement('ALTER TABLE users ADD CONSTRAINT pwa_test_awaria CHECK (pwa_prompt_state IS NULL)');
        $failure = null;
        try {
            try {
                (new InstallPrompt)->qualify($user, now()->subDays(2));
            } catch (QueryException $e) {
                $failure = $e;
            }
            $this->assertNotNull($failure, 'Kontrola ujemna musi wywołać prawdziwy błąd SQL.');
            $this->assertSame('23514', $failure->errorInfo[0]);
            // RefreshDatabase utrzymuje transakcję nadrzędną. Bez savepointu
            // ten odczyt i następny zapis dostają 25P02 zamiast działać.
            $this->assertNull($user->fresh()->pwa_prompt_state);
            $this->assertSame(1, DB::table('users')->where('id', $user->getKey())->update(['ostatnio_widziany_at' => now()]));
        } finally {
            DB::statement('ALTER TABLE users DROP CONSTRAINT pwa_test_awaria');
        }
        $this->assertTrue((new InstallPrompt)->qualify($user, now()->subDays(2)));
    }

    public function test_rollback_chroni_jednorazowosc_i_decyzje_przed_usunieciem_kolumny(): void
    {
        $migration = require database_path('migrations/2026_09_16_200000_add_pwa_prompt_state_to_users.php');
        $user = $this->user();
        foreach (['offered', 'requested', 'dismissed', 'installed'] as $state) {
            DB::table('users')->where('id', $user->getKey())->update(['pwa_prompt_state' => $state]);
            $refusal = null;
            try {
                $migration->down();
            } catch (RuntimeException $e) {
                $refusal = $e;
            }
            $this->assertNotNull($refusal, "Rollback zgubił $state.");
            $this->assertStringContainsString('Liczba kont z zachowanym stanem: 1.', $refusal->getMessage());
            $this->assertStringContainsString('wycofaj kod', $refusal->getMessage());
            $this->assertTrue(Schema::hasColumn('users', 'pwa_prompt_state'));
            $this->assertSame($state, $user->fresh()->pwa_prompt_state);
        }
    }

    public function test_rollback_dopuszcza_domyslny_stan_i_sama_kwalifikacje(): void
    {
        $migration = require database_path('migrations/2026_09_16_200000_add_pwa_prompt_state_to_users.php');
        $first = $this->user();
        $second = $this->user();
        $this->assertTrue((new InstallPrompt)->qualify($second, now()->subDays(2)));
        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('users', 'pwa_prompt_state'));
        } finally {
            if (! Schema::hasColumn('users', 'pwa_prompt_state')) {
                $migration->up();
            }
        }
        $this->assertNull($first->fresh()->pwa_prompt_state);
        $this->assertNull($second->fresh()->pwa_prompt_state);
    }
}
