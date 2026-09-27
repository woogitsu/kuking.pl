<?php

declare(strict_types=1);

namespace Tests\Dwa;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('dwa-polaczenia')]
final class WspolnaBlokadaMigracjiTest extends TestDwochPolaczen
{
    #[Test]
    public function druga_sciezka_odmawia_migracji_dopoki_pierwsza_trzyma_blokade(): void
    {
        $komenda = 'php artisan kuking:migruj-pod-blokada --no-interaction';
        $railway = (string) file_get_contents(base_path('.railway/railway.ts'));
        $workflow = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $this->assertStringContainsString($komenda, $railway);
        $this->assertStringContainsString($komenda, $workflow);

        $pierwszy = $this->nowePolaczenie();
        $this->assertTrue($this->prawda($pierwszy->query('SELECT pg_try_advisory_lock(2082, 1)')?->fetchColumn()));

        try {
            $odmowa = $this->wTle('migruj-pod-blokada', [])->wynik();

            $this->assertTrue($odmowa['ok'], $odmowa['komunikat']);
            $this->assertSame(12, $odmowa['wartosc'], 'Zajęta blokada musi przerwać wdrożenie kodem niezerowym.');
        } finally {
            $this->assertTrue($this->prawda($pierwszy->query('SELECT pg_advisory_unlock(2082, 1)')?->fetchColumn()));
        }

        $poZwolnieniu = $this->wTle('migruj-pod-blokada', [])->wynik();

        $this->assertTrue($poZwolnieniu['ok'], $poZwolnieniu['komunikat']);
        $this->assertSame(0, $poZwolnieniu['wartosc'], 'Po zwolnieniu blokady ta sama komenda ma móc uruchomić migrator.');
    }

    private function prawda(mixed $wynik): bool
    {
        return filter_var($wynik, FILTER_VALIDATE_BOOLEAN);
    }
}
