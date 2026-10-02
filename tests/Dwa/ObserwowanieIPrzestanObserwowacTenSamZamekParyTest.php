<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2404 (audyt A-001) NA DWÓCH POŁĄCZENIACH: „Obserwuj", „Przestań
 * obserwować" i „Zablokuj" na tej samej parze ustawiają się w JEDNEJ kolejce
 * (`ZamekPary`), więc stan końcowy wynika z kolejności żądań, a nie z tego,
 * które z nich zdążyło wykonać SQL szybciej.
 *
 * ── PRZEPLOT ──
 *
 * Bariera trzyma wiersz `users` osoby o NIŻSZYM identyfikatorze — pierwszy
 * w kolejności po danych. Pierwsza operacja staje w kolejce, potem druga;
 * `czekajNaZablokowane(2)` potwierdza, że OBIE czekają. Przed poprawką
 * `UnfollowUser` robił gołe `detach()` bez blokady: nie stawał w kolejce
 * (to ostrzeżenie „stoi 1, a miało stać 2" jest kontrolą ujemną), tylko
 * kończył się od razu, PRZED wcześniejszym „Obserwuj" — i człowiek, który
 * kliknął „Obserwuj", a potem „Przestań obserwować", zostawał z aktywnym
 * obserwowaniem.
 *
 * ── KONTROLA UJEMNA (wykonana) ──
 *
 * Cofnięcie `UnfollowUser` do gołego `detach()` bez `ZamekPary` oblewa
 * scenariusze 1, 2 i 4 (kolejka nie osiąga 2 uczestników). Usunięcie
 * `ZamekPary` z `FollowUser` oblewa scenariusz 3 i 1.
 */
#[Group('dwa-polaczenia')]
final class ObserwowanieIPrzestanObserwowacTenSamZamekParyTest extends TestDwochPolaczen
{
    public function test_przestan_obserwowac_po_obserwuj_ustawia_sie_w_kolejce_i_wygrywa_ostatnie(): void
    {
        [$nizsza, $wyzsza] = $this->paraPosortowana();
        $bariera = $this->barieraNaPierwszymWierszu($nizsza);

        $obserwuj = $this->wTle('obserwuj', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(1);
        $przestan = $this->wTle('przestan-obserwowac', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikObserwuj = $obserwuj->wynik();
        $wynikPrzestan = $przestan->wynik();
        $this->assertCzysty($wynikObserwuj, 'Obserwuj');
        $this->assertCzysty($wynikPrzestan, 'Przestań obserwować');

        $this->assertSame(
            0,
            $this->ileObserwowan($nizsza, $wyzsza),
            'Późniejsze „Przestań obserwować" zostało nadpisane wcześniejszym „Obserwuj".',
        );
    }

    public function test_obserwuj_po_przestan_obserwowac_ustawia_sie_w_kolejce_i_wygrywa_ostatnie(): void
    {
        [$nizsza, $wyzsza] = $this->paraPosortowana();
        $this->obserwujBezposrednio($nizsza, $wyzsza);
        $bariera = $this->barieraNaPierwszymWierszu($nizsza);

        $przestan = $this->wTle('przestan-obserwowac', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(1);
        $obserwuj = $this->wTle('obserwuj', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $this->assertCzysty($przestan->wynik(), 'Przestań obserwować');
        $wynikObserwuj = $obserwuj->wynik();
        $this->assertCzysty($wynikObserwuj, 'Obserwuj');

        $this->assertTrue($wynikObserwuj['ok'], 'Obserwuj padło: '.$wynikObserwuj['komunikat']);
        $this->assertSame(
            1,
            $this->ileObserwowan($nizsza, $wyzsza),
            'Późniejsze „Obserwuj" zostało cofnięte wcześniejszym „Przestań obserwować".',
        );
    }

    public function test_dwa_obserwuj_naraz_daja_jeden_wiersz_i_zero_bledow_serwera(): void
    {
        [$nizsza, $wyzsza] = $this->paraPosortowana();
        $bariera = $this->barieraNaPierwszymWierszu($nizsza);

        $pierwsze = $this->wTle('obserwuj', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(1);
        $drugie = $this->wTle('obserwuj', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwsze->wynik(), $drugie->wynik()];
        foreach ($wyniki as $nr => $wynik) {
            $this->assertCzysty($wynik, 'Obserwuj #'.($nr + 1));
            $this->assertTrue($wynik['ok'], 'Obserwuj #'.($nr + 1).' padło: '.$wynik['komunikat']);
        }

        $this->assertSame(
            [true, false],
            [$wyniki[0]['wartosc'], $wyniki[1]['wartosc']],
            'Jedno żądanie ma dopiąć obserwowanie, drugie zobaczyć je gotowe (idempotencja).',
        );
        $this->assertSame(1, $this->ileObserwowan($nizsza, $wyzsza));
    }

    public function test_przestan_obserwowac_kontra_blokada_konczy_bez_obserwowania_i_zakleszczenia(): void
    {
        [$nizsza, $wyzsza] = $this->paraPosortowana();
        $this->obserwujBezposrednio($nizsza, $wyzsza);
        $bariera = $this->barieraNaPierwszymWierszu($nizsza);

        // Przeciwne kierunki: „Przestań obserwować" niższy → wyższy,
        // „Zablokuj" wyższy → niższy.
        $przestan = $this->wTle('przestan-obserwowac', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(1);
        $blokada = $this->wTle('zablokuj', $this->para($wyzsza, $nizsza));
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikPrzestan = $przestan->wynik();
        $wynikBlokada = $blokada->wynik();
        $this->assertCzysty($wynikPrzestan, 'Przestań obserwować');
        $this->assertCzysty($wynikBlokada, 'Zablokuj');
        $this->assertTrue($wynikBlokada['ok'], 'Blokada nie przeszła: '.$wynikBlokada['komunikat']);

        $poWyscigu = [
            'blokada' => DB::table('blocks')->where('blocker_id', $wyzsza->getKey())->where('blocked_id', $nizsza->getKey())->count(),
            'obserwowania' => $this->ileObserwowan($nizsza, $wyzsza),
        ];
        $this->assertSame(['blokada' => 1, 'obserwowania' => 0], $poWyscigu);
    }

    public function test_obserwuj_kontra_blokada_w_tym_samym_kierunku_blokada_wygrywa(): void
    {
        [$nizsza, $wyzsza] = $this->paraPosortowana();
        $bariera = $this->barieraNaPierwszymWierszu($nizsza);

        $obserwuj = $this->wTle('obserwuj', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(1);
        $blokada = $this->wTle('zablokuj', $this->para($nizsza, $wyzsza));
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikObserwuj = $obserwuj->wynik();
        $wynikBlokada = $blokada->wynik();
        $this->assertCzysty($wynikObserwuj, 'Obserwuj');
        $this->assertCzysty($wynikBlokada, 'Zablokuj');
        $this->assertTrue($wynikBlokada['ok'], 'Blokada nie przeszła: '.$wynikBlokada['komunikat']);

        $poWyscigu = [
            'blokada' => DB::table('blocks')->where('blocker_id', $nizsza->getKey())->where('blocked_id', $wyzsza->getKey())->count(),
            'obserwowania' => $this->ileObserwowan($nizsza, $wyzsza),
        ];
        $this->assertSame(['blokada' => 1, 'obserwowania' => 0], $poWyscigu);
    }

    /**
     * Żadnego 40P01 ani 23505 (naruszenie klucza głównego `follows`) do człowieka.
     *
     * @param  array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string}  $wynik
     */
    private function assertCzysty(array $wynik, string $ktoTo): void
    {
        $this->assertBezZakleszczenia($wynik, $ktoTo);
        $this->assertNotSame(
            '23505',
            $wynik['sqlstate'] ?? null,
            "Naruszenie unikalności (23505) przy: {$ktoTo}.\n".$wynik['komunikat'],
        );
    }

    private function barieraNaPierwszymWierszu(User $nizsza): PDO
    {
        return $this->bariera(
            'SELECT 1 FROM users WHERE id = ? FOR UPDATE',
            [(string) $nizsza->getKey()],
        );
    }

    /** @return array{kto: string, kogo: string} */
    private function para(User $kto, User $kogo): array
    {
        return ['kto' => (string) $kto->getKey(), 'kogo' => (string) $kogo->getKey()];
    }

    private function obserwujBezposrednio(User $kto, User $kogo): void
    {
        DB::table('follows')->insert([
            'follower_id' => $kto->getKey(),
            'followed_id' => $kogo->getKey(),
            'created_at' => now(),
        ]);
    }

    private function ileObserwowan(User $a, User $b): int
    {
        return DB::table('follows')
            ->whereIn('follower_id', [$a->getKey(), $b->getKey()])
            ->whereIn('followed_id', [$a->getKey(), $b->getKey()])
            ->count();
    }
}
