<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wymazanie konta odpina od niego ślady nieudanych listów (audyt B5,
 * znalezisko 9). `mail_failures.user_id` ma `nullOnDelete()`, ale kont się
 * nie kasuje (D-022), więc po wymazaniu wiersz wskazywał konto bez końca.
 * Sam ślad zostaje — to wiedza operatora, że list nie doszedł.
 */
class WymazanieKontaOdpinaSladyNieudanychListowTest extends TestCase
{
    use RefreshDatabase;

    private function slad(User $kto): string
    {
        $id = (string) DB::table('mail_failures')->insertGetId([
            'powod' => 'trwala',
            'rodzaj' => 'App\\Notifications\\Test',
            'user_id' => $kto->getKey(),
            'failed_at' => now(),
        ]);

        return $id;
    }

    public function test_po_wymazaniu_slad_zostaje_bez_konta_a_cudzy_nietkniety(): void
    {
        $odchodzi = $this->user('odchodzi', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);
        $zostaje = $this->user('zostaje');
        $jego = $this->slad($odchodzi);
        $cudzy = $this->slad($zostaje);

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertDatabaseHas('mail_failures', ['id' => $jego, 'user_id' => null]);
        // Kontrola dodatnia: odpięcie nie zabiera cudzych śladów ani wierszy.
        $this->assertDatabaseHas('mail_failures', ['id' => $cudzy, 'user_id' => $zostaje->getKey()]);
        $this->assertSame(2, DB::table('mail_failures')->count());
    }
}
