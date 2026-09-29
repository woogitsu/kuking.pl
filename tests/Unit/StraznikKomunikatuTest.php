<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Nowy komunikat po akcji MUSI mieć jawny rodzaj (issue #988).
 *
 * PO CO
 * Layout rysuje `session('status')` na zielono, jeśli nie ma rodzaju. Gołe
 * `->with('status', 'Nie udało się…')` wyglądało więc jak „Zapisane". Wszystkie
 * zapisy w `app/` przeszły na `App\Support\Komunikat::sukces() / informacja() /
 * blad()`, a ten test nie pozwala wrócić do gołego klucza.
 *
 * Czego NIE robi: nie zgaduje rodzaju po treści w ogólności („Nie obserwujesz
 * już tagu" to poprawny sukces). Łapie tylko jedno jednoznaczne zjawisko —
 * literał w `sukces()`, który zaczyna się od „Nie udało się" albo „Nie da się":
 * to po prostu błąd nazwany sukcesem.
 *
 * Wzorzec „Test czyta źródło, więc ma dowód, że umie zapalić" — wpis w
 * `scripts/kontrole-negatywne-alfa08.py` („Goły ->with('status') wraca do
 * kontrolera").
 */
class StraznikKomunikatuTest extends TestCase
{
    /** Zapisy klucza `status` z pominięciem `Komunikat`. */
    private const GOLY_STATUS = [
        '/->with\(\s*[\'"]status[\'"]/',
        '/->with\(\s*\[\s*[\'"]status[\'"]/',
        '/->(?:flash|put|push)\(\s*[\'"]status[\'"]/',
        '/(?:session|Session::(?:put|flash))\(\s*\[\s*[\'"]status[\'"]/',
        '/Session::(?:put|flash)\(\s*[\'"]status[\'"]/',
        '/withStatus\(/',
    ];

    /** Literał w `sukces()` mówiący, że się NIE udało. */
    private const ODMOWA_JAKO_SUKCES = '/Komunikat::sukces\(\s*[\'"](?:Nie udało się|Nie da się|Nie mogliśmy|Nie połączyliśmy|Nie zapisaliśmy|Nie wysłaliśmy)/u';

    public function test_w_app_nie_ma_golego_zapisu_statusu_bez_rodzaju(): void
    {
        $naruszenia = [];

        foreach ($this->pliki() as $sciezka => $tresc) {
            foreach (self::GOLY_STATUS as $wzorzec) {
                if (preg_match_all($wzorzec, $tresc, $trafienia, PREG_OFFSET_CAPTURE)) {
                    foreach ($trafienia[0] as [, $pozycja]) {
                        $naruszenia[] = $sciezka.':'.$this->numerWiersza($tresc, $pozycja);
                    }
                }
            }
        }

        $this->assertSame([], $naruszenia, implode("\n", [
            'Goły zapis klucza `status` wygląda jak zielony sukces — także wtedy, gdy tekst jest odmową.',
            'Użyj App\Support\Komunikat: sukces() (zrobione), informacja() (nic się nie zmieniło, nic nie trzeba naprawiać)',
            'albo blad() (czynność nie zaszła / człowiek musi coś zrobić). Poza redirectem: Komunikat::wSesji().',
            'Miejsca:',
        ]));
    }

    /**
     * Komponenty Livewire w widokach też potrafią zapisać status w sesji
     * (kreator przepisu). Tu sprawdzamy tylko zapisy do sesji — w Blade
     * `->with('status'` bywa w komentarzu opisującym historię usterki.
     */
    public function test_widoki_nie_zapisuja_statusu_do_sesji_bez_rodzaju(): void
    {
        $naruszenia = [];

        foreach ($this->pliki('resources/views') as $sciezka => $tresc) {
            foreach (array_slice(self::GOLY_STATUS, 2, 3) as $wzorzec) {
                if (preg_match_all($wzorzec, $tresc, $trafienia, PREG_OFFSET_CAPTURE)) {
                    foreach ($trafienia[0] as [, $pozycja]) {
                        $naruszenia[] = $sciezka.':'.$this->numerWiersza($tresc, $pozycja);
                    }
                }
            }
        }

        $this->assertSame([], $naruszenia, implode("\n", [
            'Widok zapisuje `status` w sesji bez rodzaju. Użyj Komunikat::wSesji(session()->driver(), Komunikat::sukces(...)).',
            'Miejsca:',
        ]));
    }

    public function test_sukces_nie_niesie_tekstu_odmowy(): void
    {
        $naruszenia = [];

        foreach ($this->pliki() as $sciezka => $tresc) {
            if (preg_match_all(self::ODMOWA_JAKO_SUKCES, $tresc, $trafienia, PREG_OFFSET_CAPTURE)) {
                foreach ($trafienia[0] as [, $pozycja]) {
                    $naruszenia[] = $sciezka.':'.$this->numerWiersza($tresc, $pozycja);
                }
            }
        }

        $this->assertSame([], $naruszenia, implode("\n", [
            'Komunikat::sukces() z tekstem „Nie udało się…" — to błąd, nie sukces. Użyj Komunikat::blad().',
            'Miejsca:',
        ]));
    }

    /**
     * Kontrola z drugiej strony: wzorce faktycznie łapią to, co mają. Bez tego
     * literówka we wzorcu dawałaby zielony test, który niczego nie pilnuje.
     */
    public function test_wzorce_lapia_gole_zapisy_i_puszczaja_komunikat(): void
    {
        $zle = [
            "return back()->with('status', 'Zapisane.');",
            "return back()->with(\n            'status',\n            'x');",
            "return back()->with(['status' => 'x']);",
            "\$request->session()->flash('status', 'x');",
            "session(['status' => 'x']);",
            "Session::flash('status', 'x');",
        ];

        foreach ($zle as $kod) {
            $this->assertTrue($this->lapie($kod), 'Wzorzec nie złapał: '.$kod);
        }

        $dobre = [
            "return back()->with(Komunikat::sukces('Zapisane.'));",
            "return back()->with(Komunikat::blad('Nie udało się.'));",
            "\$request->session()->flash('status_rodzaj', 'blad');",
            "->with('status_powrot', ['akcja' => 'x']);",
            "->withInput(\$request->only(['status']));",
        ];

        foreach ($dobre as $kod) {
            $this->assertFalse($this->lapie($kod), 'Wzorzec złapał poprawny kod: '.$kod);
        }

        $this->assertSame(1, preg_match(self::ODMOWA_JAKO_SUKCES, "->with(Komunikat::sukces('Nie udało się wysłać.'))"));
        $this->assertSame(0, preg_match(self::ODMOWA_JAKO_SUKCES, "->with(Komunikat::sukces('Nie obserwujesz już tagu.'))"));
    }

    public function test_straznik_widzi_kontrolery_aplikacji(): void
    {
        $pliki = $this->pliki();

        $this->assertGreaterThan(100, count($pliki), 'Strażnik czyta za mało plików — zła ścieżka albo filtr.');
        $this->assertArrayHasKey('app/Http/Controllers/TagFollowController.php', $pliki);
        $this->assertArrayNotHasKey('app/Support/Komunikat.php', $pliki, 'Sam Komunikat jest jedynym miejscem, które składa klucz `status`.');
    }

    private function lapie(string $kod): bool
    {
        foreach (self::GOLY_STATUS as $wzorzec) {
            if (preg_match($wzorzec, $kod) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> ścieżka względem katalogu głównego => treść */
    private function pliki(string $katalog = 'app'): array
    {
        $korzen = dirname(__DIR__, 2);
        $wynik = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($korzen.'/'.$katalog, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $plik) {
            if (! $plik->isFile() || ! in_array($plik->getExtension(), ['php'], true)) {
                continue;
            }

            $sciezka = $katalog.'/'.ltrim(substr($plik->getPathname(), strlen($korzen.'/'.$katalog)), '/');

            // Jedyne miejsce, które składa parę `status` + `status_rodzaj`.
            if ($sciezka === 'app/Support/Komunikat.php') {
                continue;
            }

            $wynik[$sciezka] = (string) file_get_contents($plik->getPathname());
        }

        ksort($wynik);

        return $wynik;
    }

    private function numerWiersza(string $tresc, int $pozycja): int
    {
        return substr_count(substr($tresc, 0, $pozycja), "\n") + 1;
    }
}
