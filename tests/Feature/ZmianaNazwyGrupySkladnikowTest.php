<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\ZmianaNazwyGrupy;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\KomponentKreatoraPrzepisu;
use Tests\TestCase;

/**
 * Zbiorcza zmiana nazwy grupy składników w kreatorze (V2, #2444).
 *
 * Grupa to tylko pole `group_name` przy składnikach (D-033). Akcja zmienia
 * to pole we WSZYSTKICH wierszach grupy wyznaczonej przez
 * `GrupySkladnikow::klucz()` — i niczego więcej.
 */
class ZmianaNazwyGrupySkladnikowTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'recipe-wizard';

    /** @return list<array<string, mixed>> */
    private function wiersze(): array
    {
        return [
            ['_key' => 'a', 'group_name' => 'Ciasto', 'text' => '2 szklanki mąki', 'note' => 'przesiana', 'substitutes' => 'mąka żytnia', 'no_amount' => false],
            ['_key' => 'b', 'group_name' => 'Sos', 'text' => 'pomidory', 'note' => '', 'substitutes' => '', 'no_amount' => false],
            ['_key' => 'c', 'group_name' => '  ciasto ', 'text' => 'jajko', 'note' => '', 'substitutes' => '', 'no_amount' => false],
            ['_key' => 'd', 'group_name' => 'Sós', 'text' => 'sól', 'note' => '', 'substitutes' => '', 'no_amount' => true],
            ['_key' => 'e', 'group_name' => '', 'text' => 'woda', 'note' => '', 'substitutes' => '', 'no_amount' => false],
            ['_key' => 'f', 'group_name' => 'CIASTO', 'text' => 'szczypta soli', 'note' => '', 'substitutes' => '', 'no_amount' => true],
        ];
    }

    public function test_zakres_to_wszystkie_wiersze_grupy_takze_nieciagle_i_z_inna_pisownia(): void
    {
        $opis = ZmianaNazwyGrupy::opis($this->wiersze(), 'ciasto', 'Spód');

        $this->assertNotNull($opis);
        $this->assertSame(3, $opis['liczba']);
        $this->assertSame('Ciasto', $opis['obecna']);
        $this->assertSame('Spód', $opis['nowa']);
        $this->assertNull($opis['scala']);

        $po = ZmianaNazwyGrupy::zastosuj($this->wiersze(), 'ciasto', 'Spód');

        $this->assertSame(['Spód', 'Sos', 'Spód', 'Sós', '', 'Spód'], array_column($po, 'group_name'));
    }

    public function test_polskie_znaki_pozostaja_znaczace_sos_i_sos_to_dwie_grupy(): void
    {
        $po = ZmianaNazwyGrupy::zastosuj($this->wiersze(), 'sos', 'Zalewa');

        $this->assertSame('Zalewa', $po[1]['group_name']);
        $this->assertSame('Sós', $po[3]['group_name']);
    }

    public function test_zmienia_sie_tylko_group_name_a_kolejnosc_i_metadane_zostaja(): void
    {
        $przed = $this->wiersze();
        $po = ZmianaNazwyGrupy::zastosuj($przed, 'ciasto', 'Spód');

        $this->assertSame(array_column($przed, '_key'), array_column($po, '_key'));

        foreach ($przed as $i => $wiersz) {
            unset($wiersz['group_name'], $po[$i]['group_name']);
            $this->assertSame($wiersz, $po[$i], 'Poza group_name wiersz ma zostać bez zmian.');
        }
    }

    public function test_sama_zmiana_pisowni_jest_mozliwa_a_ta_sama_nazwa_to_brak_zmiany(): void
    {
        $opis = ZmianaNazwyGrupy::opis($this->wiersze(), 'ciasto', 'ciasto');
        $this->assertNotNull($opis);
        $this->assertFalse($opis['bezZmiany'], 'Wiersze mają różną pisownię, więc ujednolicenie jest zmianą.');
        $this->assertNull($opis['scala'], 'Ta sama grupa po kluczu nie jest scaleniem z inną.');

        $jednolite = [['group_name' => 'Ciasto', 'text' => 'a'], ['group_name' => 'Ciasto', 'text' => 'b']];
        $this->assertTrue(ZmianaNazwyGrupy::opis($jednolite, 'ciasto', ' Ciasto ')['bezZmiany'] ?? false);
        $this->assertFalse(ZmianaNazwyGrupy::opis($jednolite, 'ciasto', 'CIASTO')['bezZmiany'] ?? true);
    }

    public function test_nazwa_istniejacej_grupy_to_scalenie_z_pisownia_grupy_docelowej(): void
    {
        $opis = ZmianaNazwyGrupy::opis($this->wiersze(), 'sos', 'ciasto');

        $this->assertNotNull($opis);
        $this->assertSame(['nazwa' => 'Ciasto', 'liczba' => 3], $opis['scala']);
        $this->assertSame('Ciasto', $opis['nowa']);

        $po = ZmianaNazwyGrupy::zastosuj($this->wiersze(), 'sos', 'ciasto');
        $this->assertSame('Ciasto', $po[1]['group_name']);
    }

    public function test_pusta_nazwa_zdejmuje_naglowek_i_nie_usuwa_skladnikow(): void
    {
        $opis = ZmianaNazwyGrupy::opis($this->wiersze(), 'ciasto', '   ');
        $this->assertNotNull($opis);
        $this->assertTrue($opis['bezNaglowka']);

        $po = ZmianaNazwyGrupy::zastosuj($this->wiersze(), 'ciasto', '   ');

        $this->assertCount(6, $po);
        $this->assertSame(['', 'Sos', '', 'Sós', '', ''], array_column($po, 'group_name'));
    }

    public function test_nieistniejaca_grupa_nie_zmienia_niczego(): void
    {
        $this->assertNull(ZmianaNazwyGrupy::opis($this->wiersze(), 'nie-ma', 'X'));
        $this->assertSame($this->wiersze(), ZmianaNazwyGrupy::zastosuj($this->wiersze(), 'nie-ma', 'X'));
    }

    private function kreator(string $nazwa = 'Szarlotka'): Testable
    {
        $komponent = Livewire::actingAs($this->user('grupy'))
            ->test(self::COMPONENT)
            ->set('form.title', $nazwa)
            ->set('step', 2);

        foreach ([['Ciasto', 'mąka'], ['Jabłka', 'jabłka'], ['ciasto', 'masło']] as $i => [$grupa, $tekst]) {
            // Kreator startuje z trzema pustymi wierszami — wystarczy.
            $komponent->set("ingredients.$i.group_name", $grupa)->set("ingredients.$i.text", $tekst);
        }

        return $komponent;
    }

    public function test_przepis_bez_grup_nie_pokazuje_akcji(): void
    {
        Livewire::actingAs($this->user('bezgrup'))
            ->test(self::COMPONENT)
            ->set('step', 2)
            ->set('ingredients.0.text', 'mąka')
            ->assertDontSee('Zmiana nazwy całej grupy');
    }

    public function test_akcja_pokazuje_liczbe_i_nic_nie_zmienia_do_zatwierdzenia(): void
    {
        $k = $this->kreator()
            ->assertSee('Zmień nazwę grupy „Ciasto” (2 składniki)')
            ->call('wybierzGrupeDoZmiany', 'ciasto')
            ->set('nowaNazwaGrupy', 'Spód')
            ->call('pokazZmianeGrupy')
            ->assertSee('Obecna nazwa:')
            ->assertSee('Objęte składniki:')
            ->assertSet('ingredients.0.group_name', 'Ciasto')
            ->assertSet('ingredients.2.group_name', 'ciasto');

        $k->call('anulujZmianeGrupy')
            ->assertSet('ingredients.0.group_name', 'Ciasto')
            ->assertSet('ingredients.2.group_name', 'ciasto')
            ->assertSet('grupaDoZmiany', null);
    }

    public function test_zatwierdzenie_zmienia_wszystkie_wiersze_grupy_i_zapisuje_szkic(): void
    {
        $this->kreator()
            ->call('wybierzGrupeDoZmiany', 'ciasto')
            ->set('nowaNazwaGrupy', 'Spód')
            ->call('pokazZmianeGrupy')
            ->call('zatwierdzZmianeGrupy')
            ->assertSet('ingredients.0.group_name', 'Spód')
            ->assertSet('ingredients.1.group_name', 'Jabłka')
            ->assertSet('ingredients.2.group_name', 'Spód')
            ->assertSet('ingredients.0.text', 'mąka')
            ->assertSet('ingredients.2.text', 'masło')
            ->assertSet('grupaDoZmiany', null)
            ->assertSee('Zmieniono nazwę grupy „Ciasto” na „Spód”. Objęte składniki: 2.');

        $recipe = Recipe::where('title', 'Szarlotka')->firstOrFail();
        $this->assertSame(['Spód', 'Jabłka', 'Spód'], $recipe->ingredients->pluck('group_name')->all());
        $this->assertSame(['mąka', 'jabłka', 'masło'], $recipe->ingredients->pluck('ingredient_text')->all());
    }

    public function test_zatwierdzenie_bez_podgladu_nie_zmienia_niczego(): void
    {
        $this->kreator()
            ->call('wybierzGrupeDoZmiany', 'ciasto')
            ->set('nowaNazwaGrupy', 'Spód')
            ->call('zatwierdzZmianeGrupy')
            ->assertSet('ingredients.0.group_name', 'Ciasto');
    }

    public function test_scalenie_wymaga_ostrzezenia_w_podgladzie(): void
    {
        $this->kreator()
            ->call('wybierzGrupeDoZmiany', 'jabłka')
            ->set('nowaNazwaGrupy', 'Ciasto')
            ->call('pokazZmianeGrupy')
            ->assertSee('Grupa „Ciasto” już istnieje (2 składniki).')
            ->assertSee('Połącz grupy i zmień nazwę')
            ->assertSet('ingredients.1.group_name', 'Jabłka')
            ->call('zatwierdzZmianeGrupy')
            ->assertSet('ingredients.1.group_name', 'Ciasto');
    }

    public function test_usuniecie_naglowka_tlumaczy_ze_skladniki_zostaja(): void
    {
        $this->kreator()
            ->call('wybierzGrupeDoZmiany', 'ciasto')
            ->set('nowaNazwaGrupy', '')
            ->call('pokazZmianeGrupy')
            ->assertSee('Składniki przejdą do części bez nagłówka. Żaden składnik nie zostanie usunięty.')
            ->call('zatwierdzZmianeGrupy')
            ->assertSet('ingredients.0.group_name', '')
            ->assertSet('ingredients.0.text', 'mąka')
            ->assertSet('ingredients.2.group_name', '');
    }

    public function test_za_dluga_nazwa_daje_blad_przy_polu_i_nie_jest_obcinana(): void
    {
        $dluga = str_repeat('Ś', 121);

        $k = $this->kreator()
            ->call('wybierzGrupeDoZmiany', 'ciasto')
            ->set('nowaNazwaGrupy', $dluga)
            ->call('pokazZmianeGrupy')
            ->assertHasErrors('nowaNazwaGrupy')
            ->assertSet('nowaNazwaGrupy', $dluga)
            ->assertSet('podgladGrupy', null)
            ->assertSet('ingredients.0.group_name', 'Ciasto');

        /** @var KomponentKreatoraPrzepisu $komponent */
        $komponent = $k->instance();
        $this->assertSame(2, $komponent->stepForKey('nowaNazwaGrupy'));
    }

    public function test_ta_sama_nazwa_to_blad_z_instrukcja(): void
    {
        Livewire::actingAs($this->user('tasama'))
            ->test(self::COMPONENT)
            ->set('step', 2)
            ->set('ingredients.0.group_name', 'Farsz')
            ->set('ingredients.0.text', 'mięso')
            ->call('wybierzGrupeDoZmiany', 'farsz')
            ->call('pokazZmianeGrupy')
            ->assertHasErrors('nowaNazwaGrupy')
            ->assertSee('To jest ta sama nazwa.');
    }

    public function test_zmiana_wierszy_po_podgladzie_wymaga_ponownego_zatwierdzenia(): void
    {
        $k = $this->kreator()
            ->call('wybierzGrupeDoZmiany', 'ciasto')
            ->set('nowaNazwaGrupy', 'Spód')
            ->call('pokazZmianeGrupy');

        // Dopisanie wiersza do tej grupy po podglądzie zmienia zakres.
        $k->set('ingredients.1.group_name', 'Ciasto')
            ->call('zatwierdzZmianeGrupy')
            ->assertSet('ingredients.0.group_name', 'Ciasto')
            ->assertSet('ingredients.2.group_name', 'ciasto')
            ->assertSee('Składniki zmieniły się od chwili podglądu')
            ->assertSet('podgladGrupy.liczba', 3);

        $k->call('zatwierdzZmianeGrupy')
            ->assertSet('ingredients.0.group_name', 'Spód')
            ->assertSet('ingredients.1.group_name', 'Spód')
            ->assertSet('ingredients.2.group_name', 'Spód');
    }

    public function test_grupa_zmieniona_po_podgladzie_nie_rusza_innych_wierszy(): void
    {
        $k = $this->kreator()
            ->call('wybierzGrupeDoZmiany', 'jabłka')
            ->set('nowaNazwaGrupy', 'Nadzienie')
            ->call('pokazZmianeGrupy');

        // Wiersz z jabłkami przestaje należeć do grupy; wskazanie po dawnym
        // indeksie trafiłoby teraz w inną grupę.
        $k->set('ingredients.1.group_name', 'Lukier')
            ->call('zatwierdzZmianeGrupy')
            ->assertSet('ingredients.0.group_name', 'Ciasto')
            ->assertSet('ingredients.1.group_name', 'Lukier')
            ->assertSet('ingredients.2.group_name', 'ciasto')
            ->assertSee('nic nie zmieniono');
    }

    public function test_zmiana_wpisanej_nazwy_po_podgladzie_uniewaznia_podglad(): void
    {
        $this->kreator()
            ->call('wybierzGrupeDoZmiany', 'ciasto')
            ->set('nowaNazwaGrupy', 'Spód')
            ->call('pokazZmianeGrupy')
            ->set('nowaNazwaGrupy', 'Placek')
            ->assertSet('podgladGrupy', null)
            ->call('zatwierdzZmianeGrupy')
            ->assertSet('ingredients.0.group_name', 'Ciasto');
    }

    public function test_nieaktualna_edycja_nie_nadpisuje_przepisu_zmiana_grupy(): void
    {
        $k = $this->kreator();
        $recipe = Recipe::where('title', 'Szarlotka')->firstOrFail();
        $przed = $recipe->ingredients->pluck('group_name')->all();

        // Przepis zmieniono w innej karcie — rewizja treści w bazie poszła do przodu.
        $recipe->forceFill(['content_revision' => $recipe->content_revision + 1])->save();

        $k->call('wybierzGrupeDoZmiany', 'ciasto')
            ->set('nowaNazwaGrupy', 'Spód')
            ->call('pokazZmianeGrupy')
            ->call('zatwierdzZmianeGrupy');

        $this->assertSame(
            $przed,
            $recipe->fresh()->ingredients->pluck('group_name')->all(),
            'Zapis ze starej rewizji nie może nadpisać przepisu.',
        );
    }
}
