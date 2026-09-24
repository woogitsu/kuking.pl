<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\ProductSignal;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #1050 — fraza, która po transliteracji (`Str::ascii`) traci całą
 * treść, nie może zamienić się we wzorzec `LIKE '%%'`, pasujący do każdego
 * przepisu i każdej osoby.
 *
 * Kontrola ujemna (ręczna, na izolowanej bazie PostgreSQL): po usunięciu
 * straży po normalizacji w `SearchQuery::doSzukania()` (powrót do samego
 * `mb_strlen($phrase) < 2` przed normalizacją) test
 * `test_przepisy_i_ludzie_zwracaja_pusto_bez_zapytan` pada — „🍲🍲" zwraca
 * wszystkie przepisy i osoby z tabeli.
 */
class PustaFrazaPoNormalizacjiTest extends TestCase
{
    use RefreshDatabase;

    /** Dwa znaki, które bieżąca transliteracja usuwa w całości. */
    private const BEZ_TRESCI = '🍲🍲';

    /** Dwa znaki, z których po transliteracji zostaje jeden. */
    private const JEDEN_PO_NORMALIZACJI = 'a🍲';

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture sprawdzana, nie zakładana — gdyby biblioteka zaczęła
        // przepisywać emoji na tekst, ten test ma to powiedzieć wprost.
        $this->assertSame(2, mb_strlen(self::BEZ_TRESCI));
        $this->assertSame('', trim(Str::ascii(self::BEZ_TRESCI)));
        $this->assertSame(2, mb_strlen(self::JEDEN_PO_NORMALIZACJI));
        $this->assertSame(1, mb_strlen(trim(Str::ascii(self::JEDEN_PO_NORMALIZACJI))));
    }

    private function ileZapytan(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });
        $akcja();

        return $ile;
    }

    private function przygotujTresci(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Żurek z jajkiem',
            'slug' => 'zurek-z-jajkiem',
        ]);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => '100% masła ciasteczka',
            'slug' => 'sto-procent-masla',
        ]);
    }

    public function test_przepisy_i_ludzie_zwracaja_pusto_bez_zapytan(): void
    {
        $this->przygotujTresci();
        $wyszukiwarka = app(SearchQuery::class);

        foreach ([self::BEZ_TRESCI, self::JEDEN_PO_NORMALIZACJI, '@'.self::BEZ_TRESCI, ' '.self::BEZ_TRESCI.' '] as $fraza) {
            // Zero zapytań = brak LIKE i brak ustawiania progu podobieństwa.
            $this->assertSame(0, $this->ileZapytan(fn () => $wyszukiwarka->recipes($fraza)), "recipes({$fraza}) odpytało bazę.");
            $this->assertSame(0, $this->ileZapytan(fn () => $wyszukiwarka->people($fraza)), "people({$fraza}) odpytało bazę.");
            $this->assertCount(0, $wyszukiwarka->recipes($fraza));
            $this->assertCount(0, $wyszukiwarka->people($fraza));
        }
    }

    public function test_kontrole_dodatnie_nadal_znajduja(): void
    {
        $this->przygotujTresci();
        $wyszukiwarka = app(SearchQuery::class);

        $this->assertSame(['Żurek z jajkiem'], $wyszukiwarka->recipes('żurek')->pluck('title')->all());
        $this->assertSame(['Żurek z jajkiem'], $wyszukiwarka->recipes('zurek')->pluck('title')->all());
        // Mieszanka liter i emoji: emoji znika, słowo zostaje.
        $this->assertSame(['Żurek z jajkiem'], $wyszukiwarka->recipes('żurek 🍲')->pluck('title')->all());
        // Dosłowny metaznak LIKE (#753) nadal działa jako tekst.
        $this->assertSame(['100% masła ciasteczka'], $wyszukiwarka->recipes('100%')->pluck('title')->all());
        $this->assertCount(1, $wyszukiwarka->people('Basia'));
        $this->assertCount(1, $wyszukiwarka->people('Basia 🍲'));
    }

    public function test_ekran_szukaj_prosi_o_slowo_i_nie_zapisuje_wyszukiwania(): void
    {
        $this->przygotujTresci();

        foreach (['wszystko', 'przepisy', 'ludzie'] as $sekcja) {
            $this->actingAs($this->user('szuka_'.$sekcja))
                ->get(route('search', ['q' => self::BEZ_TRESCI, 'sekcja' => $sekcja]))
                ->assertOk()
                ->assertSee('We frazie „'.self::BEZ_TRESCI.'” nie ma liter ani cyfr', false)
                ->assertSee('Wpisz nazwę dania, składnik albo imię osoby.')
                // Oryginalny tekst zostaje w polu.
                ->assertSee('value="'.self::BEZ_TRESCI.'"', false)
                ->assertDontSee('Żurek z jajkiem')
                ->assertDontSee('Nic nie znaleźliśmy');
        }

        $this->assertSame(0, ProductSignal::count(), 'Fraza bez treści zapisała pozorne wyszukiwanie.');
    }

    public function test_jeden_znak_po_normalizacji_to_za_krotka_fraza(): void
    {
        $this->przygotujTresci();

        $this->actingAs($this->user('szuka'))
            ->get(route('search', ['q' => self::JEDEN_PO_NORMALIZACJI]))
            ->assertOk()
            ->assertSee('za krótka, żeby zacząć szukać')
            ->assertDontSee('Żurek z jajkiem')
            ->assertDontSee('Nic nie znaleźliśmy');

        $this->assertSame(0, ProductSignal::count());
    }

    public function test_kontrola_dodatnia_zwykla_fraza_zapisuje_wyszukiwanie(): void
    {
        $this->przygotujTresci();

        $this->actingAs($this->user('szuka'))
            ->get(route('search', ['q' => 'zurek 🍲']))
            ->assertOk()
            ->assertSee('Żurek z jajkiem')
            ->assertDontSee('nie ma liter ani cyfr');

        $this->assertSame(1, ProductSignal::count());
    }

    public function test_onboarding_prosi_o_imie_zamiast_pokazywac_ludzi(): void
    {
        $this->user('halina_z_lodzi', ['display_name' => 'Halina']);

        $this->actingAs($this->user('basia'))
            ->get(route('onboarding.people', ['q' => self::BEZ_TRESCI]))
            ->assertOk()
            ->assertSee('nie ma liter ani cyfr, po których możemy szukać')
            ->assertSee('Wpisz imię albo nazwę użytkownika.')
            ->assertDontSee('Nic nie znaleźliśmy');
    }
}
