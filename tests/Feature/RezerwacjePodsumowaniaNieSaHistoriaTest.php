<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\OdbiorcyDigestu;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `weekly_digest_sends` nie jest historią wysyłek (#2280, audyt 30.09 Z4).
 *
 * Polityka prywatności mówi o podsumowaniu tygodnia: „zapis ostatniego
 * tygodnia wysyłki, a nie historia wysyłek” i że znika z kontem. Przed
 * poprawką każdy tydzień dokładał wiersz, nic ich nie kasowało, a wymazanie
 * konta zostawiało je przy zanonimizowanym `user_id`.
 */
class RezerwacjePodsumowaniaNieSaHistoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nowa_rezerwacja_kasuje_starsze_tej_samej_osoby_i_tylko_jej(): void
    {
        $osoba = $this->user('czyta_listy', ['wants_weekly_digest' => true]);
        $inna = $this->user('tez_czyta', ['wants_weekly_digest' => true]);
        $odbiorcy = app(OdbiorcyDigestu::class);

        $this->assertTrue($odbiorcy->zarezerwuj($inna, '2026-09-07'));
        $this->assertTrue($odbiorcy->zarezerwuj($osoba, '2026-09-07'));
        $this->assertTrue($odbiorcy->zarezerwuj($osoba, '2026-09-14'));
        $this->assertTrue($odbiorcy->zarezerwuj($osoba, '2026-09-21'));

        $this->assertSame(
            ['2026-09-21'],
            DB::table('weekly_digest_sends')->where('user_id', $osoba->getKey())->pluck('week_start')
                ->map(fn ($d): string => substr((string) $d, 0, 10))->all(),
            'Po trzech tygodniach zostały trzy wiersze — to jest historia wysyłek, której polityka nie opisuje.',
        );

        // Kontrola dodatnia: sprzątanie dotyczy jednej osoby, nie wszystkich.
        $this->assertSame(1, DB::table('weekly_digest_sends')->where('user_id', $inna->getKey())->count());

        // Bariera tygodnia działa dalej: ten sam tydzień drugi raz odmawia.
        $this->assertFalse($odbiorcy->zarezerwuj($osoba, '2026-09-21'));
    }

    public function test_wymazanie_konta_usuwa_rezerwacje_podsumowania(): void
    {
        $odchodzi = $this->user('odchodzi', [
            'wants_weekly_digest' => true,
            'weekly_digest_sent_at' => now()->subDays(3),
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);
        $zostaje = $this->user('zostaje', ['wants_weekly_digest' => true]);

        foreach ([$odchodzi, $zostaje] as $osoba) {
            DB::table('weekly_digest_sends')->insert([
                'user_id' => $osoba->getKey(),
                'week_start' => '2026-09-21',
                'reserved_at' => now(),
            ]);
        }

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertNotNull($odchodzi->refresh()->data_erased_at);
        $this->assertNull($odchodzi->weekly_digest_sent_at, 'Wymazane konto zostawiło datę ostatniego podsumowania.');
        $this->assertSame(
            0,
            DB::table('weekly_digest_sends')->where('user_id', $odchodzi->getKey())->count(),
            'Wymazane konto zostawiło rezerwacje tygodnia podsumowania.',
        );
        $this->assertSame(1, DB::table('weekly_digest_sends')->where('user_id', $zostaje->getKey())->count());
    }
}
