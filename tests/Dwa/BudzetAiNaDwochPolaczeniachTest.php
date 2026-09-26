<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Support\Czas;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dwie rezerwacje budżetu modelu naraz nie przebijają dziennego limitu (D-297).
 *
 * Limit mieści DOKŁADNIE jedną rezerwację. Bariera trzyma wiersz dnia
 * `FOR UPDATE`, oba procesy ustawiają się w kolejce po tę samą blokadę,
 * a po zwolnieniu jeden rezerwuje, drugi dostaje odmowę. Bez blokady
 * wiersza w `BudzetAi::zarezerwuj()` oba przeczytałyby „jest miejsce”
 * i suma rezerwacji przekroczyłaby limit.
 */
#[Group('dwa-polaczenia')]
final class BudzetAiNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    private ?string $dzien = null;

    protected function tearDown(): void
    {
        if ($this->dzien !== null) {
            // Rezerwacje najpierw: `ai_rezerwacje.dzien` ma klucz obcy RESTRICT
            // do `ai_budzet_dzienny` — w odwrotnej kolejności DELETE pada.
            DB::table('ai_rezerwacje')->where('dzien', $this->dzien)->delete();
            DB::table('ai_budzet_dzienny')->where('dzien', $this->dzien)->delete();
        }

        parent::tearDown();
    }

    public function test_dwie_rownolegle_rezerwacje_nie_przekraczaja_dziennego_limitu(): void
    {
        $this->dzien = Czas::dzisiajData();
        DB::table('ai_rezerwacje')->where('dzien', $this->dzien)->delete();
        DB::table('ai_budzet_dzienny')->where('dzien', $this->dzien)->delete();
        DB::table('ai_budzet_dzienny')->insert(['dzien' => $this->dzien, 'created_at' => now(), 'updated_at' => now()]);

        // Limit 1 USD, dwie rezerwacje po 0,6 USD: zmieści się jedna.
        $argumenty = ['limit_usd' => '1', 'kwota' => '600000'];

        $bariera = $this->bariera('SELECT 1 FROM ai_budzet_dzienny WHERE dzien = ? FOR UPDATE', [$this->dzien]);
        $pierwszy = $this->wTle('rezerwacja-budzetu', $argumenty);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('rezerwacja-budzetu', $argumenty);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwszy->wynik(), $drugi->wynik()];

        foreach ($wyniki as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'rezerwacja '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Rezerwacja '.($numer + 1).' padła: '.$wynik['komunikat']);
        }

        $wartosci = [$wyniki[0]['wartosc'], $wyniki[1]['wartosc']];
        sort($wartosci);
        $this->assertSame(['odmowa:dzien', 'zarezerwowano'], $wartosci);

        $zarezerwowano = (int) DB::table('ai_budzet_dzienny')->where('dzien', $this->dzien)->value('zarezerwowano_mikrousd');
        $this->assertSame(600000, $zarezerwowano);
    }
}
