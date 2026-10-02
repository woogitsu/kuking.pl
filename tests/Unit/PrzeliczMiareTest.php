<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\Porcje\PrzeliczMiare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Równoważniki miar przy składniku (#2533). Tylko masa↔masa i objętość↔objętość.
 *
 * KONTROLA UJEMNA (ręcznie): zmiana mnożnika `dag` z 10.0 na 1.0 w `MASA_G`
 * oblewa `test_przelicza` na „25 dag” (oczekiwane „250 g”).
 */
final class PrzeliczMiareTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function przypadki(): array
    {
        return [
            'dag na g i kg' => ['25 dag sera', ['250 g', '0,25 kg']],
            'dkg to dag' => ['20 dkg mąki', ['200 g', '0,2 kg']],
            'kg na g i dag' => ['1 kg ziemniaków', ['1000 g', '100 dag']],
            'przecinek' => ['mąka – 0,5 kg', ['500 g', '50 dag']],
            'g na dag i kg' => ['250 g cukru', ['25 dag', '0,25 kg']],
            'odmiana słowna' => ['2 kilogramy jabłek', ['2000 g', '200 dag']],
            'l na ml' => ['1,5 l mleka', ['1500 ml']],
            'ml na l' => ['250 ml śmietany', ['0,25 l']],
            'szklanka' => ['1 szklanka mleka', ['ok. 250 ml']],
            'pół szklanki ułamek' => ['½ szklanki cukru', ['ok. 125 ml']],
            'ćwierć szklanki' => ['¼ szklanki oleju', ['ok. 65 ml']],
            'łyżka' => ['1 łyżka oleju', ['ok. 15 ml']],
            'łyżeczka' => ['1 łyżeczka soli', ['ok. 5 ml']],
            'trzy łyżki' => ['3 łyżki cukru', ['ok. 45 ml']],
            'zakres' => ['2–3 łyżki miodu', ['ok. 30–45 ml']],
            'mała łyżeczka nie zero' => ['⅛ łyżeczki pieprzu', ['ok. 5 ml']],
            'mały ułamek kg' => ['0,001 kg soli', ['1 g', '0,1 dag']],
        ];
    }

    /** @param list<string> $oczekiwane */
    #[DataProvider('przypadki')]
    public function test_przelicza(string $skladnik, array $oczekiwane): void
    {
        $this->assertSame($oczekiwane, PrzeliczMiare::dla($skladnik));
    }

    /** @return array<string, array{string}> */
    public static function bezPrzeliczenia(): array
    {
        return [
            'sztuki' => ['2 jajka'],
            'sztuki z jednostką' => ['3 szt. cebuli'],
            'puszki' => ['2 puszki (po 400 g) pomidorów'],
            'bez liczby' => ['sól'],
            'do smaku' => ['sól do smaku'],
            'szczypta' => ['szczypta pieprzu'],
            'zero' => ['0 g soli'],
        ];
    }

    #[DataProvider('bezPrzeliczenia')]
    public function test_nie_przelicza(string $skladnik): void
    {
        $this->assertSame([], PrzeliczMiare::dla($skladnik));
    }

    public function test_masa_nigdy_nie_daje_objetosci_a_objetosc_masy(): void
    {
        foreach (['25 dag sera', '1 kg cukru', '200 g mleka'] as $masa) {
            foreach (PrzeliczMiare::dla($masa) as $wynik) {
                $this->assertDoesNotMatchRegularExpression('/\bml\b|\bl\b/u', $wynik, $masa);
            }
        }

        foreach (['1 szklanka mąki', '2 łyżki oleju', '1 l wody', '250 ml mleka'] as $objetosc) {
            foreach (PrzeliczMiare::dla($objetosc) as $wynik) {
                $this->assertDoesNotMatchRegularExpression('/\bg\b|\bdag\b|\bkg\b/u', $wynik, $objetosc);
            }
        }
    }
}
