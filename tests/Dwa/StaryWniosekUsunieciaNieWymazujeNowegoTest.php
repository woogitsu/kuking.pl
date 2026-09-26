<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2023: worker ma w pamięci wygasły wniosek, ale czeka na users FOR UPDATE.
 * Drugie połączenie zatwierdza nowy wniosek, zanim worker dostanie lock.
 * Kontrola ujemna: bez porównania generacji i terminu pod lockiem worker
 * zwraca true i trwale wymazuje konto w nowej karencji.
 */
#[Group('dwa-polaczenia')]
final class StaryWniosekUsunieciaNieWymazujeNowegoTest extends TestDwochPolaczen
{
    public function test_stary_worker_nie_wymazuje_konta_po_ponownym_zgloszeniu(): void
    {
        $konto = $this->konto([
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);
        $id = (string) $konto->getKey();
        $staryTermin = $konto->delete_requested_at;

        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id]);
        $worker = $this->wTle('kasowanie-wygaslego-wniosku', ['konto' => $id]);
        $this->czekajNaZablokowane(1);

        // Ten sam wiersz, nowa generacja po cofnięciu i ponownym zgłoszeniu.
        // Zmiana jest zatwierdzona przed wznowieniem workera.
        $nowyTermin = now();
        $zmiana = $bariera->prepare('UPDATE users SET delete_requested_at = ? WHERE id = ?');
        $zmiana->execute([$nowyTermin->toDateTimeString(), $id]);
        $this->assertSame(1, $zmiana->rowCount());
        $bariera->commit();

        $wynik = $worker->wynik();
        $this->assertBezZakleszczenia($wynik, 'stary worker wymazania');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertFalse($wynik['wartosc'], 'Stary worker wymazał dane z nowego wniosku.');

        $stan = $konto->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status);
        $this->assertNull($stan->data_erased_at);
        $this->assertTrue($stan->delete_requested_at->isAfter($staryTermin));
    }
}
