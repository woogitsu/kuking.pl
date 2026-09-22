<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Moderation\CelZgloszenia;
use PHPUnit\Framework\TestCase;

class CelZgloszeniaTest extends TestCase
{
    public function test_odmiana_imion_w_dopelniaczu(): void
    {
        // Żeńskie na -ia
        $this->assertSame('Basi', CelZgloszenia::dopełniaczImienia('Basia'));
        $this->assertSame('Kasi', CelZgloszenia::dopełniaczImienia('Kasia'));
        $this->assertSame('Ani', CelZgloszenia::dopełniaczImienia('Ania'));
        $this->assertSame('Zosi', CelZgloszenia::dopełniaczImienia('Zosia'));

        // Żeńskie na -ja
        $this->assertSame('Mai', CelZgloszenia::dopełniaczImienia('Maja'));
        $this->assertSame('Alicji', CelZgloszenia::dopełniaczImienia('Alicja'));
        $this->assertSame('Julii', CelZgloszenia::dopełniaczImienia('Julia'));

        // Żeńskie na -ka, -ga, -la
        $this->assertSame('Moniki', CelZgloszenia::dopełniaczImienia('Monika'));
        $this->assertSame('Kingi', CelZgloszenia::dopełniaczImienia('Kinga'));
        $this->assertSame('Kamili', CelZgloszenia::dopełniaczImienia('Kamila'));

        // Inne żeńskie na -a
        $this->assertSame('Haliny', CelZgloszenia::dopełniaczImienia('Halina'));
        $this->assertSame('Danuty', CelZgloszenia::dopełniaczImienia('Danuta'));
        $this->assertSame('Ewy', CelZgloszenia::dopełniaczImienia('Ewa'));
        $this->assertSame('Anny', CelZgloszenia::dopełniaczImienia('Anna'));

        // Męskie na -ek, -eł, -er
        $this->assertSame('Marka', CelZgloszenia::dopełniaczImienia('Marek'));
        $this->assertSame('Jacka', CelZgloszenia::dopełniaczImienia('Jacek'));
        $this->assertSame('Pawła', CelZgloszenia::dopełniaczImienia('Paweł'));
        $this->assertSame('Kacpra', CelZgloszenia::dopełniaczImienia('Kacper'));

        // Męskie na spółgłoskę
        $this->assertSame('Krzysztofa', CelZgloszenia::dopełniaczImienia('Krzysztof'));
        $this->assertSame('Tomasza', CelZgloszenia::dopełniaczImienia('Tomasz'));
        $this->assertSame('Adama', CelZgloszenia::dopełniaczImienia('Adam'));
        $this->assertSame('Jana', CelZgloszenia::dopełniaczImienia('Jan'));
        $this->assertSame('Piotra', CelZgloszenia::dopełniaczImienia('Piotr'));

        // Imię i nazwisko
        $this->assertSame('Basi Kowalskiej', CelZgloszenia::dopełniaczImienia('Basia Kowalska'));
        $this->assertSame('Jana Kowalskiego', CelZgloszenia::dopełniaczImienia('Jan Kowalski'));
    }

    public function test_formatowanie_cytatu_i_obcinanie(): void
    {
        // 1. Pusty tekst lub null -> null (bez cudzysłowu)
        $this->assertNull(CelZgloszenia::formatujCytat(null));
        $this->assertNull(CelZgloszenia::formatujCytat(''));
        $this->assertNull(CelZgloszenia::formatujCytat("   \n\t  "));

        // 2. Krótki tekst (<= 200 znaków) -> w cudzysłowie, bez wielokropka
        $krotki = 'Smaczny obiad.';
        $this->assertSame('„Smaczny obiad.”', CelZgloszenia::formatujCytat($krotki));

        // 3. Tekst dokładnie 200 znaków -> w cudzysłowie, bez wielokropka
        $dokladnie200 = str_repeat('a', 190).' '.str_repeat('b', 9);
        $this->assertSame(200, mb_strlen($dokladnie200));
        $this->assertSame("„{$dokladnie200}”", CelZgloszenia::formatujCytat($dokladnie200));

        // 4. Tekst > 200 znaków -> cięty po granicy słowa, wielokropek WEWNĄTRZ cudzysłowu
        $tekst = str_repeat('slowo ', 40); // 240 znaków
        $cytat = CelZgloszenia::formatujCytat($tekst);

        $this->assertNotNull($cytat);
        $this->assertStringStartsWith('„', $cytat);
        $this->assertStringEndsWith('…”', $cytat);

        // Treść wewnątrz cudzysłowu bez wielokropka ma <= 200 znaków
        preg_match('/^„(.+)…”$/u', $cytat, $m);
        $this->assertNotEmpty($m);
        $this->assertLessThanOrEqual(200, mb_strlen($m[1]));

        // Cięcie nastąpiło po całym słowie
        $this->assertStringEndsWith('slowo', $m[1]);
    }
}
