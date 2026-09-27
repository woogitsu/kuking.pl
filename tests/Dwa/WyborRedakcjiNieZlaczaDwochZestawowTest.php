<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Feed\Actions\ZapiszKolaz;
use App\Domain\Feed\Actions\ZapiszTabliceDnia;
use App\Models\AuditLogEntry;
use App\Models\DailyPick;
use App\Models\HeroPick;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regresja #1027: dwa równoległe zastąpienia wyboru redakcyjnego RÓŻNYMI,
 * rozłącznymi zestawami kończą się zestawem A albo B — nigdy A ∪ B.
 *
 * PRZEPLOT. Test trzyma blokadę doradczą-barierę. Uczestnik A (osobny
 * proces, prawdziwa akcja domenowa) wykonuje swój `DELETE` i staje na
 * barierze przed pierwszym `INSERT`-em. Dopiero wtedy startuje B:
 *
 *  - z blokadą zestawu B czeka na blokadę dnia/kolażu trzymaną przez A,
 *    więc po zwolnieniu bariery A zatwierdza, a B kasuje JEGO wybór;
 *  - bez niej (kontrola ujemna, zmierzona) B robi własny `DELETE` — który
 *    nie widzi niezatwierdzonych wstawień A — staje na tej samej barierze,
 *    a po zwolnieniu oba zestawy lądują w bazie.
 *
 * W obu wariantach w kolejce stoi dwóch uczestników, więc
 * `czekajNaZablokowane(2)` nie przesądza wyniku — przesądza go blokada.
 */
#[Group('dwa-polaczenia')]
final class WyborRedakcjiNieZlaczaDwochZestawowTest extends TestDwochPolaczen
{
    /** Data tablicy tylko tego przebiegu — dane zostają zatwierdzone naprawdę. */
    private string $dzien;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dzien = now()->addYears(150)->addDays(random_int(0, 20_000))->toDateString();
    }

    protected function tearDown(): void
    {
        // `daily_picks.subject_id` nie ma klucza obcego, więc kaskada z kont
        // ich nie zabierze. Sprzątamy po własnej, losowej dacie.
        DailyPick::query()->whereDate('shown_on', $this->dzien)->delete();

        parent::tearDown();
    }

    public function test_tablica_dnia_pusta_na_starcie_konczy_sie_jednym_zestawem(): void
    {
        $this->sprawdzTablice(poczatkowy: []);
    }

    public function test_tablica_dnia_z_wyborem_na_starcie_konczy_sie_jednym_zestawem(): void
    {
        $this->sprawdzTablice(poczatkowy: $this->wpisy(2));
    }

    public function test_kolaz_pusty_na_starcie_konczy_sie_jednym_zestawem(): void
    {
        // Kolaż jest jeden na całą bazę. Baza `kuking_race_*` należy do tego
        // przebiegu (zasada 2), a sama akcja i tak kasuje cały zestaw.
        HeroPick::query()->delete();

        $this->sprawdzKolaz(poczatkowy: []);
    }

    public function test_kolaz_z_wyborem_na_starcie_konczy_sie_jednym_zestawem(): void
    {
        $this->sprawdzKolaz(poczatkowy: $this->zdjecia(2));
    }

    /** @param list<string> $poczatkowy */
    private function sprawdzTablice(array $poczatkowy): void
    {
        $gospodarzA = $this->konto();
        $gospodarzB = $this->konto();
        $zestawA = $this->wpisy(4);
        $zestawB = $this->wpisy(4);

        if ($poczatkowy !== []) {
            app(ZapiszTabliceDnia::class)->zastap($this->konto(), [], $poczatkowy, [], 0, count($poczatkowy), null, $this->dzien);
            // Kontrola dodatnia: stan początkowy naprawdę nie jest pusty.
            $this->assertSame(count($poczatkowy), DailyPick::query()->whereDate('shown_on', $this->dzien)->count());
        }

        [$a, $b] = $this->wyscig(
            'tablica-dnia',
            ['kto' => (string) $gospodarzA->getKey(), 'wpisy' => json_encode($zestawA), 'dzien' => $this->dzien],
            ['kto' => (string) $gospodarzB->getKey(), 'wpisy' => json_encode($zestawB), 'dzien' => $this->dzien],
        );

        $pozycje = DailyPick::query()->forDate(new \DateTimeImmutable($this->dzien))->get();

        // B wszedł drugi, więc jego pełny wybór jest ostatnim zapisem.
        $this->assertSame($zestawB, $pozycje->pluck('subject_id')->all(), 'Tablica nie jest dokładnie zestawem B — zapisy się złączyły.');
        $this->assertSame([0, 1, 2, 3], $pozycje->pluck('position')->all());
        $this->assertSame([(string) $gospodarzB->getKey()], $pozycje->pluck('curator_id')->unique()->values()->all());

        // Wpis audytu B opisuje stan końcowy; A opisuje swój, już zastąpiony.
        $this->assertSame(4, $this->audyt('daily_board.updated', $gospodarzB)['wpisy']);
        $this->assertSame(4, $this->audyt('daily_board.updated', $gospodarzA)['wpisy']);
        $this->assertSame(4, $a[DailyPick::TYPE_POST] ?? 0);
        $this->assertSame(4, $b[DailyPick::TYPE_POST] ?? 0);
    }

    /** @param array<string, string> $poczatkowy */
    private function sprawdzKolaz(array $poczatkowy): void
    {
        $gospodarzA = $this->konto();
        $gospodarzB = $this->konto();
        $zestawA = $this->zdjecia(3);
        $zestawB = $this->zdjecia(3);

        if ($poczatkowy !== []) {
            app(ZapiszKolaz::class)->zastap($this->konto(), array_keys($poczatkowy), $poczatkowy, null);
            $this->assertSame(count($poczatkowy), HeroPick::query()->count());
        }

        $this->wyscig(
            'kolaz',
            ['kto' => (string) $gospodarzA->getKey(), 'zdjecia' => json_encode($zestawA)],
            ['kto' => (string) $gospodarzB->getKey(), 'zdjecia' => json_encode($zestawB)],
        );

        $pozycje = HeroPick::query()->orderBy('position')->orderBy('id')->get();

        $this->assertSame(array_keys($zestawB), $pozycje->pluck('media_id')->map(fn ($id) => (string) $id)->all(), 'Kolaż nie jest dokładnie zestawem B — zapisy się złączyły.');
        $this->assertSame([0, 1, 2], $pozycje->pluck('position')->all());
        $this->assertSame([(string) $gospodarzB->getKey()], $pozycje->pluck('curator_id')->unique()->values()->all());
        $this->assertSame(3, $this->audyt('hero_kolaz.updated', $gospodarzB)['zapisanych']);
    }

    /**
     * @param  array<string, string>  $argumentyA
     * @param  array<string, string>  $argumentyB
     * @return array{0: mixed, 1: mixed} wartości zwrócone przez A i B
     */
    private function wyscig(string $scenariusz, array $argumentyA, array $argumentyB): array
    {
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(91027, 1)', []);
        $pierwszy = $this->wTle($scenariusz, $argumentyA);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle($scenariusz, $argumentyB);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwszy->wynik(), $drugi->wynik()];

        foreach ($wyniki as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'zastąpienie wyboru '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Zastąpienie wyboru '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        return [$wyniki[0]['wartosc'], $wyniki[1]['wartosc']];
    }

    /** @return list<string> publiczne wpisy, każdy od innej osoby */
    private function wpisy(int $ile): array
    {
        $wpisy = [];

        for ($i = 0; $i < $ile; $i++) {
            $wpisy[] = (string) Post::factory()->for($this->konto(), 'author')->create()->getKey();
        }

        return $wpisy;
    }

    /** @return array<string, string> zdjęcie → wpis */
    private function zdjecia(int $ile): array
    {
        $zdjecia = [];

        for ($i = 0; $i < $ile; $i++) {
            $autor = $this->konto();
            $wpis = Post::factory()->for($autor, 'author')->create();
            $zdjecie = Media::factory()->for($autor, 'owner')->create();
            $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
            $zdjecia[(string) $zdjecie->getKey()] = (string) $wpis->getKey();
        }

        return $zdjecia;
    }

    /** @return array<string, int> */
    private function audyt(string $akcja, User $kto): array
    {
        $wpisy = AuditLogEntry::query()->where('action', $akcja)->where('actor_id', $kto->getKey())->get();
        $this->assertCount(1, $wpisy, "Oczekiwany dokładnie jeden wpis {$akcja} od gospodarza.");

        return $wpisy->first()->metadata;
    }
}
