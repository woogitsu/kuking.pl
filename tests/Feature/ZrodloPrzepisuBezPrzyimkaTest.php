<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Źródło przepisu pokazuje się dosłownie — bez doklejonego przyimka.
 *
 * Zgłoszenie właściciela: „czemu źródło przepisu ma «po» przed źródłem?".
 * Na stronie przepisu stało pod nagłówkiem „Skąd ten przepis" zdanie
 * **„Po Nasze smaki."**, bo widok wklejał „Po" przed wolny tekst z pola.
 * Ten sam błąd miał podgląd w kreatorze, a podpis nad tytułem miał go dwa
 * razy naraz: „przepis **Nasze smaki**, spisany przez **Krzysztof**".
 *
 * ZASADA, KTÓREJ PILNUJE TEN PLIK: nigdy nie doklejaj przyimka ani słowa
 * niosącego przypadek do tekstu wpisanego przez człowieka ani do nazwy konta.
 * Polskiej odmiany nie da się policzyć z dowolnego ciągu znaków.
 *
 * Dlatego dane są tu WROGIE, a nie wygodne: wartość z przyimkiem („od mamy"),
 * wartość, która przyimka nie zniesie („Nasze smaki", „Halina"), wartość
 * dłuższa („z gazety Przyjaciółka") i nazwa konta odmieniająca się inaczej niż
 * męskie imię („Żaneta"). Test na samym „babci Zofii" przechodziłby także dla
 * zepsutego kodu (docs/PULAPKI_TESTOW.md).
 */
class ZrodloPrzepisuBezPrzyimkaTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const WROGIE_ZRODLA = [
        'od mamy',
        'Nasze smaki',
        'z gazety Przyjaciółka',
        'Halina',
    ];

    /** @var list<string> */
    private const NAZWY_KONT = [
        'Krzysztof',
        'Żaneta',
    ];

    public function test_strona_przepisu_pokazuje_zrodlo_doslownie_bez_doklejonego_po(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Żaneta']);

        foreach (self::WROGIE_ZRODLA as $zrodlo) {
            $przepis = Recipe::factory()->create([
                'author_id' => $basia->getKey(),
                'status' => Recipe::STATUS_PUBLISHED,
                'visibility' => 'public',
                'published_at' => now(),
                'source_person' => $zrodlo,
                'source_note' => null,
            ]);

            $odpowiedz = $this->actingAs($basia)->get(route('recipes.show', $przepis->slug));

            // Wartość dosłowna, tylko z wielką pierwszą literą — żadnego „Po"
            // przed nią i żadnej kropki doklejonej na końcu.
            $odpowiedz->assertOk()
                ->assertSee('<strong>'.Str::ucfirst($zrodlo).'</strong>', false);

            foreach ($this->zakazaneKonstrukcje($zrodlo, 'Żaneta') as $zakazana) {
                $odpowiedz->assertDontSee($zakazana);
            }
        }
    }

    public function test_podglad_w_kreatorze_pokazuje_zrodlo_doslownie_bez_doklejonego_po(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Żaneta']);

        foreach (self::WROGIE_ZRODLA as $zrodlo) {
            $komponent = Livewire::actingAs($basia)
                ->test('recipe-wizard')
                ->set('title', 'Rosół na niedzielę')
                ->set('form.source_person', $zrodlo)
                ->set('steps.0.instruction', 'Gotować trzy godziny.')
                ->set('step', 4);

            $komponent->assertSee('<strong>'.Str::ucfirst($zrodlo).'</strong>', false);

            foreach ($this->zakazaneKonstrukcje($zrodlo, 'Żaneta') as $zakazana) {
                $komponent->assertDontSee($zakazana);
            }
        }
    }

    public function test_podpis_przepisu_nie_odmienia_ani_zrodla_ani_nazwy_konta(): void
    {
        foreach (self::NAZWY_KONT as $index => $nazwa) {
            $autor = $this->user('konto'.$index, ['display_name' => $nazwa]);

            foreach (self::WROGIE_ZRODLA as $zrodlo) {
                $przepis = Recipe::factory()->create([
                    'author_id' => $autor->getKey(),
                    'source_person' => $zrodlo,
                ]);

                $podpis = $przepis->attributionLine();

                // Żadna powierzchnia nie traci informacji: w podpisie stoi
                // i to, skąd przepis pochodzi, i to, kto go tu zapisał.
                $this->assertStringContainsString($zrodlo, $podpis);
                $this->assertStringContainsString($nazwa, $podpis);

                foreach ($this->zakazaneKonstrukcje($zrodlo, $nazwa) as $zakazana) {
                    $this->assertStringNotContainsString(
                        $zakazana,
                        $podpis,
                        "Podpis odmienia cudzy tekst: [{$zakazana}] w [{$podpis}].",
                    );
                }
            }
        }
    }

    public function test_podpis_przepisu_bez_zrodla_nie_odmienia_nazwy_konta(): void
    {
        foreach (self::NAZWY_KONT as $index => $nazwa) {
            $autor = $this->user('bezzrodla'.$index, ['display_name' => $nazwa]);

            $przepis = Recipe::factory()->create([
                'author_id' => $autor->getKey(),
                'source_person' => null,
            ]);

            $podpis = $przepis->attributionLine();

            // Wariant bez źródła był zepsuty tak samo jak ten ze źródłem:
            // stało w nim „przepis Krzysztof".
            $this->assertStringContainsString($nazwa, $podpis);
            $this->assertStringNotContainsString('przepis '.$nazwa, $podpis);
            $this->assertStringNotContainsString('przez '.$nazwa, $podpis);
        }
    }

    public function test_pole_pyta_o_fraze_ktora_stoi_samodzielnie(): void
    {
        $basia = $this->user('basia');

        // Formularz na jednej stronie — droga bez JavaScriptu.
        $this->actingAs($basia)
            ->get(route('recipes.create.simple'))
            ->assertOk()
            ->assertSee('Od kogo albo skąd masz ten przepis')
            ->assertDontSee('Po kim ten przepis');

        // Kreator — to samo pytanie, ten sam przykład. Pole stoi na kroku 1,
        // czyli na tym, który kreator pokazuje od razu.
        Livewire::actingAs($basia)
            ->test('recipe-wizard')
            ->assertSee('Od kogo albo skąd masz ten przepis')
            ->assertDontSee('Po kim ten przepis');
    }

    /**
     * Konstrukcje, które dla WOLNEGO TEKSTU są niegramatyczne zawsze albo
     * prawie zawsze — i których żadna powierzchnia nie ma prawa wyprodukować.
     *
     * @return list<string>
     */
    private function zakazaneKonstrukcje(string $zrodlo, string $nazwaKonta): array
    {
        return [
            'Po '.$zrodlo,                 // „Po Nasze smaki", „Po po mamie"
            'Po '.Str::ucfirst($zrodlo),
            'przepis '.$zrodlo,            // „przepis Nasze smaki"
            'przepis '.$nazwaKonta,        // „przepis Krzysztof"
            'spisany przez',               // zawsze kończy się biernikiem
            'przez '.$nazwaKonta,          // „przez Krzysztof", „przez Żaneta"
        ];
    }
}
