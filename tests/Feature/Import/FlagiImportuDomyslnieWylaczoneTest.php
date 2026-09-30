<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\ZlecImportPrzepisu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * #2214, D-333: przełączniki importu (adres strony, PDF, zdjęcie kartki) mają
 * w konfiguracji domyślnie `false`. Włączenie jest świadome — zmienną
 * środowiskową, po podpisaniu DPA z OpenAI i domknięciu polityki prywatności.
 * Samo dodanie `OPENAI_IMPORT_KEY` nie może otworzyć importu.
 */
class FlagiImportuDomyslnieWylaczoneTest extends TestCase
{
    use RefreshDatabase;

    private const ZMIENNE = ['KUKING_IMPORT_URL', 'KUKING_IMPORT_PDF', 'KUKING_IMPORT_ZDJECIE'];

    public function test_bez_zmiennych_srodowiska_wszystkie_trzy_przelaczniki_sa_wylaczone(): void
    {
        $repo = Env::getRepository();
        $zapamietane = [];

        foreach (self::ZMIENNE as $nazwa) {
            $zapamietane[$nazwa] = $repo->get($nazwa);
            $repo->clear($nazwa);
        }

        try {
            $konfiguracja = require base_path('config/kuking.php');
        } finally {
            foreach ($zapamietane as $nazwa => $wartosc) {
                if ($wartosc !== null) {
                    $repo->set($nazwa, $wartosc);
                }
            }
        }

        $this->assertFalse($konfiguracja['import']['url']['wlaczony'], 'KUKING_IMPORT_URL ma domyślnie false.');
        $this->assertFalse($konfiguracja['import']['pdf']['wlaczony'], 'KUKING_IMPORT_PDF ma domyślnie false.');
        $this->assertFalse($konfiguracja['import']['zrodla']['zdjecie'], 'KUKING_IMPORT_ZDJECIE ma domyślnie false.');
    }

    public function test_sam_klucz_openai_nie_otwiera_importu(): void
    {
        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
            'kuking.import.url.wlaczony' => false,
            'kuking.import.pdf.wlaczony' => false,
            'kuking.import.zrodla.zdjecie' => false,
        ]);

        $this->assertFalse(ZlecImportPrzepisu::dostepnyOdczytZdjecia());

        $this->actingAs($this->user())
            ->get(route('recipes.import.url'))
            ->assertNotFound();
    }
}
