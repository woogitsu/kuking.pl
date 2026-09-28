<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Import\LimitImportowOsoby;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_cztery_z_pieciu_dziennych_miejsc_dwa_polaczenia_dostaja_tylko_jedno_wolne(): void
    {
        $this->sprawdzWyscig('url', 'pdf', 5, 30, 4, LimitImportowOsoby::DZIEN);
    }

    public function test_dwadziescia_dziewiec_z_trzydziestu_miesiecznych_miejsc_dwa_polaczenia_dostaja_tylko_jedno_wolne(): void
    {
        // Wysoki próg dzienny izoluje miesięczny limit: odmowa nie może
        // pochodzić z innego licznika niż ten, o który ścigają się żądania.
        $this->sprawdzWyscig('pdf', 'url', 100, 30, 29, LimitImportowOsoby::MIESIAC);
    }

    public function test_kontrola_dodatnia_dwa_wolne_miejsca_pozwalaja_obu_polaczeniom_rezerwowac(): void
    {
        $this->sprawdzWyscig('url', 'pdf', 5, 30, 3, LimitImportowOsoby::DZIEN, ['rezerwacja', 'rezerwacja']);
    }

    public function test_kontrola_ujemna_pelny_limit_odmawia_obu_polaczeniom(): void
    {
        $this->sprawdzWyscig('url', 'pdf', 5, 30, 5, LimitImportowOsoby::DZIEN, ['odmowa', 'odmowa']);
    }

    /** @param array{string, string} $oczekiwane */
    private function sprawdzWyscig(
        string $pierwszeZrodlo,
        string $drugieZrodlo,
        int $limitDzienny = 1,
        int $limitMiesieczny = 30,
        int $zajete = 0,
        ?string $wyczerpany = LimitImportowOsoby::DZIEN,
        array $oczekiwane = ['rezerwacja', 'odmowa'],
    ): void {
        $osoba = $this->konto();
        config([
            'kuking.import.limity.na_osobe_dzien' => $limitDzienny,
            'kuking.import.limity.na_osobe_miesiac' => $limitMiesieczny,
        ]);
        if ($zajete > 0) {
            $teraz = now();
            $wiersze = [];
            for ($i = 0; $i < $zajete; $i++) {
                $wiersze[] = [
                    'id' => (string) Str::uuid(),
                    'user_id' => $osoba->getKey(),
                    'zrodlo' => 'pdf',
                    'klucz_wyslania' => (string) Str::uuid(),
                    'status' => 'gotowy',
                    'created_at' => $teraz,
                    'updated_at' => $teraz,
                ];
            }
            DB::table('proby_importu')->insert($wiersze);
        }

        $this->assertSame($zajete, DB::table('proby_importu')->where('user_id', $osoba->getKey())->count());
        if ($zajete < min($limitDzienny, $limitMiesieczny)) {
            $this->assertNull(app(LimitImportowOsoby::class)->przekroczony($osoba), 'Przed wyścigiem musi zostać wolne miejsce.');
        }

        $bariera = $this->bariera(
            "SELECT pg_advisory_xact_lock(hashtext('kuking:import:' || ?))",
            [(string) $osoba->getKey()],
        );

        $argumenty = [
            'kto' => (string) $osoba->getKey(),
            'limit_dzienny' => (string) $limitDzienny,
            'limit_miesieczny' => (string) $limitMiesieczny,
        ];
        $pierwszy = $this->wTle('wspolny-limit-importu', $argumenty + ['zrodlo' => $pierwszeZrodlo]);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('wspolny-limit-importu', $argumenty + ['zrodlo' => $drugieZrodlo]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $a = $pierwszy->wynik();
        $b = $drugi->wynik();
        $this->assertBezZakleszczenia($a, 'pierwsze źródło');
        $this->assertBezZakleszczenia($b, 'drugie źródło');
        $this->assertTrue($a['ok'], $a['komunikat']);
        $this->assertTrue($b['ok'], $b['komunikat']);
        $this->assertEqualsCanonicalizing($oczekiwane, [$a['wartosc'], $b['wartosc']]);
        $this->assertSame($zajete + count(array_filter($oczekiwane, fn (string $wynik): bool => $wynik === 'rezerwacja')),
            DB::table('proby_importu')->where('user_id', $osoba->getKey())->count());
        $this->assertSame($wyczerpany, app(LimitImportowOsoby::class)->przekroczony($osoba));
    }
}
