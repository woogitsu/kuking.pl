<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\FacebookConnectionProof;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Issue #2319: wygasłe dowody połączenia z Facebookiem nie zostają w bazie.
 *
 * Dowód (#2085) żyje dziesięć minut i przestaje działać sam, ale fizyczny
 * wiersz — `user_id` i cztery skróty HMAC — kasowało tylko udane użycie albo
 * kolejna prośba. Porzucona prośba zostawała bez terminu, a przy wymazaniu
 * konta nie znikała wcale (kaskada klucza obcego nie działa, bo kont się
 * nie kasuje, D-022).
 *
 * KONTROLA UJEMNA (wykonana): warunek `expires_at > now()` zamiast
 * `expires_at < now()` w `PrzedawnioneDowodyFacebooka` oblewa pierwszy test
 * (wygasły zostaje, żywy znika); usunięcie linii z `EraseAccountData` oblewa
 * test wymazania; usunięcie zadania z `routes/console.php` oblewa test
 * harmonogramu.
 */
class RetencjaDowodowPolaczeniaFacebookaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nocne_sprzatanie_kasuje_wygasle_dowody_a_zywe_zostawia(): void
    {
        $porzucony = $this->dowod($this->user('basia'), now()->subMinutes(11));
        $swiezy = $this->dowod($this->user('halina'), now());

        $this->artisan('kuking:sprzataj-dowody-facebooka')
            ->expectsOutputToContain('Skasowano 1 wygasły dowód połączenia')
            ->assertSuccessful();

        $this->assertDatabaseMissing('facebook_connection_proofs', ['id' => $porzucony->getKey()]);
        // Kontrola dodatnia: dowód w swoich dziesięciu minutach dalej działa.
        $this->assertDatabaseHas('facebook_connection_proofs', ['id' => $swiezy->getKey()]);
    }

    public function test_na_sucho_liczy_i_niczego_nie_kasuje(): void
    {
        $porzucony = $this->dowod($this->user('basia'), now()->subDay());

        $this->artisan('kuking:sprzataj-dowody-facebooka', ['--na-sucho' => true])
            ->expectsOutputToContain('Do skasowania: 1 wygasły dowód')
            ->assertSuccessful();

        $this->assertDatabaseHas('facebook_connection_proofs', ['id' => $porzucony->getKey()]);
    }

    public function test_wymazanie_konta_zabiera_oczekujacy_dowod(): void
    {
        $basia = $this->user('basia');
        $halina = $this->user('halina');
        $jejDowod = $this->dowod($basia, now());
        $cudzy = $this->dowod($halina, now());

        $basia->fresh()->markForDeletion();
        $this->assertTrue(app(EraseAccountData::class)->handle($basia->fresh()));

        $this->assertDatabaseMissing('facebook_connection_proofs', ['id' => $jejDowod->getKey()]);
        $this->assertDatabaseHas('facebook_connection_proofs', ['id' => $cudzy->getKey()]);
    }

    public function test_sprzatanie_dowodow_jest_w_harmonogramie(): void
    {
        $nazwy = array_map(fn ($e) => $e->description, app(Schedule::class)->events());

        $this->assertContains('kuking:sprzataj-dowody-facebooka', $nazwy);
    }

    private function dowod(User $user, Carbon $utworzono): FacebookConnectionProof
    {
        $dowod = new FacebookConnectionProof;
        $dowod->forceFill([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', 'token-'.$user->getKey()),
            'session_hash' => hash('sha256', 'sesja'),
            'facebook_id_hash' => hash('sha256', 'facebook'),
            'account_state_hash' => hash('sha256', 'stan'),
            'created_at' => $utworzono,
            'expires_at' => $utworzono->copy()->addMinutes(10),
        ])->save();

        return $dowod;
    }
}
