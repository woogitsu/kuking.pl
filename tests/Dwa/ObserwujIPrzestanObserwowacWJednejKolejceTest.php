<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Social\Actions\UnfollowUser;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2404: oba przyciski tej samej pary muszą czekać w jednej kolejce.
 *
 * Bariera zatrzymuje obie prawdziwe akcje na pierwszym wierszu konta.
 * Dawny UnfollowUser z samym detach() omijał barierę: drugi proces kończył
 * się przed zwolnieniem zamka, a test oblewał na czekajNaZablokowane(2).
 */
#[Group('dwa-polaczenia')]
final class ObserwujIPrzestanObserwowacWJednejKolejceTest extends TestDwochPolaczen
{
    public function test_pozniejsze_cofniecie_wygrywa_z_wczesniejszym_obserwowaniem(): void
    {
        [$obserwujacy, $obserwowany] = $this->paraPosortowana();

        $bariera = $this->bariera(
            'SELECT 1 FROM users WHERE id = ? FOR UPDATE',
            [(string) $obserwujacy->getKey()],
        );

        $argumenty = [
            'kto' => (string) $obserwujacy->getKey(),
            'kogo' => (string) $obserwowany->getKey(),
        ];

        $obserwowanie = $this->wTle('obserwuj', $argumenty);
        $this->czekajNaZablokowane(1);

        $cofniecie = $this->wTle('przestan-obserwowac', $argumenty);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikObserwowania = $obserwowanie->wynik();
        $wynikCofniecia = $cofniecie->wynik();

        $this->assertBezZakleszczenia($wynikObserwowania, 'Obserwuj');
        $this->assertBezZakleszczenia($wynikCofniecia, 'Przestań obserwować');
        $this->assertTrue($wynikObserwowania['ok'], $wynikObserwowania['komunikat']);
        $this->assertTrue($wynikCofniecia['ok'], $wynikCofniecia['komunikat']);
        $this->assertTrue($wynikObserwowania['wartosc'], 'Pierwszy proces nie utworzył obserwowania.');

        $this->assertSame(0, DB::table('follows')
            ->where('follower_id', $obserwujacy->getKey())
            ->where('followed_id', $obserwowany->getKey())
            ->count(), 'Późniejsze cofnięcie ma usunąć obserwowanie utworzone chwilę wcześniej.');

        app(UnfollowUser::class)->handle($obserwujacy, $obserwowany);
        $this->assertSame(0, DB::table('follows')
            ->where('follower_id', $obserwujacy->getKey())
            ->where('followed_id', $obserwowany->getKey())
            ->count(), 'Ponowne cofnięcie musi pozostać idempotentne.');
    }
}
