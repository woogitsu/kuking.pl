<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Import\BudzetAi;
use App\Support\Czas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /** Dni wyścigu miesięcznego (#2013) — do sprzątania. */
    private const DNI_MIESIACA = ['2026-05-15', '2026-05-16'];

    protected function tearDown(): void
    {
        if ($this->dzien !== null) {
            // Rezerwacje najpierw: `ai_rezerwacje.dzien` ma klucz obcy RESTRICT
            // do `ai_budzet_dzienny` — w odwrotnej kolejności DELETE pada.
            DB::table('ai_rezerwacje')->where('dzien', $this->dzien)->delete();
            DB::table('ai_budzet_dzienny')->where('dzien', $this->dzien)->delete();
        }

        DB::table('ai_rezerwacje')->whereIn('dzien', self::DNI_MIESIACA)->delete();
        DB::table('ai_budzet_dzienny')->whereIn('dzien', self::DNI_MIESIACA)->delete();

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

    /**
     * #2013: rezerwacje z RÓŻNYCH dni tego samego miesiąca (tuż przed i tuż po
     * północy) blokowały różne wiersze dzienne, obie czytały tę samą sumę
     * miesiąca i obie ją przekraczały. Limit miesięczny mieści dokładnie jedną
     * rezerwację; bariera trzyma blokadę miesiąca, więc oba procesy stają w tej
     * samej kolejce. Odmowa musi zapaść PRZED płatnym wywołaniem: atrapa
     * żądania HTTP liczy wywołania modelu — ma być dokładnie jedno.
     */
    public function test_rownolegle_odczyty_z_roznych_dni_nie_przekraczaja_miesiecznego_budzetu(): void
    {
        DB::table('ai_rezerwacje')->whereIn('dzien', self::DNI_MIESIACA)->delete();
        DB::table('ai_budzet_dzienny')->whereIn('dzien', self::DNI_MIESIACA)->delete();

        $osoba = $this->konto();
        $probyId = [];
        foreach ([0, 1] as $numer) {
            $probyId[$numer] = (string) Str::uuid();
            DB::table('proby_importu')->insert([
                'id' => $probyId[$numer], 'user_id' => $osoba->getKey(), 'zrodlo' => 'url',
                'klucz_wyslania' => (string) Str::uuid(), 'status' => 'w_toku',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $bariera = $this->bariera(
            'SELECT pg_advisory_xact_lock(?, hashtext(?))',
            [BudzetAi::KLASA_BLOKADY_MIESIACA, '2026-05'],
        );
        $procesy = [];
        foreach ([0, 1] as $numer) {
            $procesy[$numer] = $this->budzetWTle(['dzien' => self::DNI_MIESIACA[$numer], 'kto' => (string) $osoba->getKey(), 'proba' => $probyId[$numer]]);
            $this->czekajNaZablokowane($numer + 1);
        }
        $this->zwolnijBariere($bariera);

        $powody = [];
        $wywolania = 0;
        foreach ($procesy as $numer => $proces) {
            $wynik = $proces->wynik();
            $this->assertBezZakleszczenia($wynik, 'odczyt '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Odczyt '.($numer + 1).' padł: '.$wynik['komunikat']);
            $powody[] = $wynik['wartosc']['powod'];
            $wywolania += $wynik['wartosc']['wywolan'];
        }

        sort($powody);
        $this->assertSame(['odmowa', 'wyslano'], $powody);
        $this->assertSame(1, $wywolania, 'Odmowa miesięczna musi zapaść przed płatnym wywołaniem modelu.');
        $this->assertSame(1, DB::table('ai_rezerwacje')->whereIn('dzien', self::DNI_MIESIACA)->count());

        $lacznie = (int) DB::table('ai_budzet_dzienny')->whereIn('dzien', self::DNI_MIESIACA)
            ->sum(DB::raw('zarezerwowano_mikrousd + wydano_mikrousd'));
        $this->assertSame((int) DB::table('ai_rezerwacje')->whereIn('dzien', self::DNI_MIESIACA)->value('mikrousd'), $lacznie);
    }

    /** @param  array<string, string>  $argumenty */
    private function budzetWTle(array $argumenty): ProcesRownolegly
    {
        return ProcesRownolegly::start(
            __DIR__.'/bin/budzet-ai.php',
            'odczyt',
            $argumenty,
            [
                'DB_DATABASE' => $this->baza,
                'APP_ENV' => 'testing',
                'BCRYPT_ROUNDS' => '4',
                'MAIL_MAILER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'KUKING_LOCK_TIMEOUT' => self::LOCK_TIMEOUT,
                'KUKING_STATEMENT_TIMEOUT' => self::STATEMENT_TIMEOUT,
            ],
        );
    }
}
