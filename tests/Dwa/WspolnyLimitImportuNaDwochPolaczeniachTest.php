<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** OCR, adres i PDF ścigają się o to samo ostatnie miejsce bez wywołania sieci/modelu. */
#[Group('dwa-polaczenia')]
final class WspolnyLimitImportuNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_rownolegle_ocr_i_pdf_nie_przekraczaja_wspolnego_limitu(): void
    {
        $this->sprawdzWyscig('zdjecie', 'pdf');
    }

    public function test_rownolegle_ocr_i_url_nie_przekraczaja_wspolnego_limitu(): void
    {
        $this->sprawdzWyscig('zdjecie', 'url');
    }

    public function test_rownolegle_url_i_pdf_nie_przekraczaja_wspolnego_limitu(): void
    {
        $this->sprawdzWyscig('url', 'pdf');
    }

    private function sprawdzWyscig(string $pierwszeZrodlo, string $drugieZrodlo): void
    {
        $osoba = $this->konto();
        $bariera = $this->bariera(
            "SELECT pg_advisory_xact_lock(hashtext('kuking:import:' || ?))",
            [(string) $osoba->getKey()],
        );

        $pierwszy = $this->wTle('wspolny-limit-importu', ['kto' => (string) $osoba->getKey(), 'zrodlo' => $pierwszeZrodlo]);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('wspolny-limit-importu', ['kto' => (string) $osoba->getKey(), 'zrodlo' => $drugieZrodlo]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $a = $pierwszy->wynik();
        $b = $drugi->wynik();
        $this->assertBezZakleszczenia($a, 'pierwsze źródło');
        $this->assertBezZakleszczenia($b, 'drugie źródło');
        $this->assertTrue($a['ok'], $a['komunikat']);
        $this->assertTrue($b['ok'], $b['komunikat']);
        $this->assertEqualsCanonicalizing(['rezerwacja', 'odmowa'], [$a['wartosc'], $b['wartosc']]);
        $this->assertSame(1, DB::table('proby_importu')->where('user_id', $osoba->getKey())->count());
    }
}
