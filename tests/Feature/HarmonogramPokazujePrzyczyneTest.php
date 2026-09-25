<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Harmonogram;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * Wyjątek harmonogramu niesie przyczynę porażki, nie tylko kod (issue #835,
 * 1. komentarz). Komendy są atrapami rejestrowanymi w teście — prawdziwy
 * `Artisan::call()` i prawdziwy bufor wyjścia, bez skutków ubocznych.
 */
class HarmonogramPokazujePrzyczyneTest extends TestCase
{
    private function wyjatek(string $komenda): RuntimeException
    {
        try {
            (Harmonogram::wykonaj($komenda))();
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('Komenda z kodem 1 nie rzuciła wyjątku.');
    }

    public function test_komunikat_bledu_komendy_trafia_do_wyjatku(): void
    {
        Artisan::command('kuking:atrapa-porazka', function () {
            $this->error('Kolejka stoi od 3 godzin — sprawdź worker.');

            return 1;
        });

        $e = $this->wyjatek('kuking:atrapa-porazka');

        $this->assertStringStartsWith("Komenda harmonogramu 'kuking:atrapa-porazka' zakończyła się niepowodzeniem (kod wyjścia: 1).", $e->getMessage());
        $this->assertStringContainsString('Kolejka stoi od 3 godzin — sprawdź worker.', $e->getMessage());
    }

    public function test_dlugie_wyjscie_jest_przyciete_do_ogona(): void
    {
        Artisan::command('kuking:atrapa-gadatliwa', function () {
            $this->line('POCZATEK-'.str_repeat('x', 5000));
            $this->line('KONIEC-PRZYCZYNA');

            return 1;
        });

        $e = $this->wyjatek('kuking:atrapa-gadatliwa');

        $this->assertStringContainsString('KONIEC-PRZYCZYNA', $e->getMessage());
        $this->assertStringNotContainsString('POCZATEK-', $e->getMessage());
        $this->assertLessThan(Harmonogram::OGON_ZNAKI + 300, mb_strlen($e->getMessage()));
    }

    /**
     * Para asercji jak w HarmonogramNieWypisujeDanychOsobowychTest: znacznik
     * z tej samej linii JEST w wyjątku (ogon naprawdę dotarł), a adres
     * e-mail i hasło z URL-a — NIE.
     */
    public function test_adres_e_mail_i_haslo_w_url_sa_zamaskowane(): void
    {
        Artisan::command('kuking:atrapa-wrazliwa', function () {
            $this->error('ZNACZNIK-7 konto jan.kowalski@example.org, baza pgsql://kuking:tajne-haslo@db.example.org/kuking');

            return 1;
        });

        $e = $this->wyjatek('kuking:atrapa-wrazliwa');

        $this->assertStringContainsString('ZNACZNIK-7 konto [e-mail]', $e->getMessage());
        $this->assertStringContainsString('pgsql://[dane-logowania]@db.example.org', $e->getMessage());
        $this->assertStringNotContainsString('jan.kowalski', $e->getMessage());
        $this->assertStringNotContainsString('tajne-haslo', $e->getMessage());
    }

    public function test_komenda_bez_wyjscia_zostawia_sam_kod(): void
    {
        Artisan::command('kuking:atrapa-cicha', fn () => 2);

        $this->assertSame(
            "Komenda harmonogramu 'kuking:atrapa-cicha' zakończyła się niepowodzeniem (kod wyjścia: 2).",
            $this->wyjatek('kuking:atrapa-cicha')->getMessage(),
        );
    }

    /**
     * Kontrola dodatnia: bufor `Artisan::output()` należy tylko do OSTATNIEGO
     * wywołania — następne `call()` go nadpisuje. To jest powód, dla którego
     * adapter musi odczytać wyjście od razu, zanim ruszy cokolwiek innego.
     * Gdyby ta asercja padła, uzasadnienie w Harmonogram.php byłoby nieaktualne.
     */
    public function test_kontrola_dodatnia_nastepne_wywolanie_nadpisuje_bufor(): void
    {
        Artisan::command('kuking:atrapa-pierwsza', function () {
            $this->line('PRZYCZYNA-PIERWSZEJ');

            return 1;
        });
        Artisan::command('kuking:atrapa-druga', fn () => 0);

        Artisan::call('kuking:atrapa-pierwsza');
        $this->assertStringContainsString('PRZYCZYNA-PIERWSZEJ', Artisan::output());
        Artisan::call('kuking:atrapa-druga');
        $this->assertStringNotContainsString('PRZYCZYNA-PIERWSZEJ', Artisan::output());
    }
}
