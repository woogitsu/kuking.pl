<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\AuditLogEntry;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

#[Group('dwa-polaczenia')]
final class OstatniAdministratorTest extends TestDwochPolaczen
{
    public function test_dwie_degradacje_zostawiaja_jednego_czynnego_administratora(): void
    {
        $this->assertSame(0, User::query()->where('role', User::ROLE_ADMIN)->count(), 'Baza wyścigu ma obcych administratorów.');
        $first = $this->konto(['role' => User::ROLE_ADMIN]);
        $second = $this->konto(['role' => User::ROLE_ADMIN]);

        // Uczestnik zatrzymuje się PO rzeczywistym zapytaniu o innych
        // administratorów. Bez wspólnego zamka oba odczyty widzą dwóch;
        // z zamkiem drugi czeka jeszcze PRZED swoim odczytem.
        $barrier = $this->bariera('SELECT pg_advisory_xact_lock(1016, 2)', []);
        $a = $this->wTle('nadaj-role', ['login' => $first->email, 'rola' => 'moderator']);
        $this->czekajNaZablokowane(1);
        $b = $this->wTle('nadaj-role', ['login' => $second->email, 'rola' => 'user']);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($barrier);

        $results = [$a->wynik(), $b->wynik()];
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], $result['komunikat']);
        }
        $this->assertSame(1, User::query()->where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->count(), 'Równoległe degradacje usunęły ostatniego administratora.');
        $codes = array_column(array_column($results, 'wartosc'), 'code');
        sort($codes);
        $this->assertSame([0, 1], $codes);
        $this->assertStringContainsString('ostatnie czynne konto administratora', implode(' ', array_column(array_column($results, 'wartosc'), 'output')));
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'user.role_changed')->whereIn('subject_id', $this->konta)->count());
        $this->assertSame(1, User::query()->whereIn('id', $this->konta)->whereIn('role', ['user', 'moderator'])->count());
    }
}
