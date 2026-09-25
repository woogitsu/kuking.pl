<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * BAN I ŻĄDANIE USUNIĘCIA RÓWNOCZEŚNIE, NA DWÓCH POŁĄCZENIACH (issue #980).
 *
 * Obaj uczestnicy czytają konto (`active`) ZANIM staną w kolejce po wiersz —
 * bariera trzyma `FOR UPDATE` na `users`. Po zwolnieniu przechodzą kolejno,
 * w wymuszonej kolejności. Wynik ma być ten sam dla obu kolejności:
 * `pending_delete` (egzekucja karencji trwa) + `punishment_status = banned`
 * (kara wróci po cofnięciu usunięcia).
 *
 * ── KONTROLA UJEMNA (wykonana, nie zaplanowana) ──
 *
 * `User::przejdz()` przekazujące do zmiany `$this` zamiast świeżego wiersza
 * spod blokady (czyli decyzja na modelu z chwili odczytu, jak przed #980):
 * kolejność ban→usuń kończy się `punishment_status = NULL` (ban zgubiony),
 * kolejność usuń→ban — `status = banned` (konto wypada spod egzekutora).
 */
#[Group('dwa-polaczenia')]
final class BanIUsuniecieKontaRownolegleTest extends TestDwochPolaczen
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function kolejnosci(): array
    {
        return [
            'najpierw ban, potem żądanie usunięcia' => ['zbanuj', 'usun'],
            'najpierw żądanie usunięcia, potem ban' => ['usun', 'zbanuj'],
        ];
    }

    #[DataProvider('kolejnosci')]
    public function test_ban_i_zadanie_usuniecia_nie_gubia_sie_nawzajem(string $pierwsze, string $drugie): void
    {
        $konto = $this->konto();

        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [(string) $konto->getKey()]);
        $a = $this->wTle('stan-konta-980', ['konto' => (string) $konto->getKey(), 'przejscie' => $pierwsze]);
        $this->czekajNaZablokowane(1);
        $b = $this->wTle('stan-konta-980', ['konto' => (string) $konto->getKey(), 'przejscie' => $drugie]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$a->wynik(), $b->wynik()] as $wynik) {
            $this->assertBezZakleszczenia($wynik, 'ban/usunięcie');
            $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        }

        $stan = $konto->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status, 'Ban wyjął konto spod egzekucji karencji.');
        $this->assertNotNull($stan->delete_requested_at);
        $this->assertSame(User::STATUS_BANNED, $stan->punishment_status, 'Żądanie usunięcia zgubiło ban.');
    }
}
