<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Domain\Recipes\Historia\PorownanieWersji;
use PHPUnit\Framework\TestCase;

/**
 * Porównanie dwóch migawek (issue #2024) — czysta funkcja, bez bazy.
 */
final class PorownanieWersjiTest extends TestCase
{
    private function skladnik(string $tekst, ?string $grupa = null): array
    {
        return ['group_name' => $grupa, 'text' => $tekst, 'note' => null, 'substitutes' => null, 'position' => 0];
    }

    private function krok(string $tresc, ?int $timer = null): array
    {
        return ['position' => 0, 'instruction' => $tresc, 'timer_seconds' => $timer];
    }

    public function test_identyczne_migawki_nie_maja_roznic_mimo_innej_wielkosci_liter_w_dopasowaniu(): void
    {
        $m = ['title' => 'Rosół', 'ingredients' => [$this->skladnik('2 marchewki')], 'steps' => [$this->krok('Gotuj.')]];

        $wynik = PorownanieWersji::porownaj($m, $m);

        $this->assertTrue($wynik['brakZmian']);
        $this->assertSame([], $wynik['bezDanych']);
    }

    public function test_skladniki_dodany_usuniety_i_zmieniony(): void
    {
        $stara = ['ingredients' => [$this->skladnik('Sól'), $this->skladnik('Pieprz'), $this->skladnik('Mąka', null)]];
        $nowa = ['ingredients' => [$this->skladnik('sól', 'Przyprawy'), $this->skladnik('Masło'), $this->skladnik('Mąka')]];

        $wynik = PorownanieWersji::porownaj($stara, $nowa)['skladniki'];
        $rodzaje = array_column($wynik, 'rodzaj');

        $this->assertSame([PorownanieWersji::ZMIENIONO, PorownanieWersji::DODANO, PorownanieWersji::USUNIETO], $rodzaje);
        $this->assertSame('sól (grupa: Przyprawy)', $wynik[0]['po']);
        $this->assertSame('Masło', $wynik[1]['po']);
        $this->assertSame('Pieprz', $wynik[2]['przed']);
    }

    public function test_powtorzone_skladniki_paruja_sie_po_kolei(): void
    {
        $stara = ['ingredients' => [$this->skladnik('woda'), $this->skladnik('woda')]];
        $nowa = ['ingredients' => [$this->skladnik('woda')]];

        $wynik = PorownanieWersji::porownaj($stara, $nowa)['skladniki'];

        $this->assertCount(1, $wynik);
        $this->assertSame(PorownanieWersji::USUNIETO, $wynik[0]['rodzaj']);
    }

    public function test_kroki_wstawiony_w_srodku_nie_przesuwa_reszty_w_zmiany(): void
    {
        $stara = ['steps' => [$this->krok('A'), $this->krok('B'), $this->krok('C')]];
        $nowa = ['steps' => [$this->krok('A'), $this->krok('Nowy'), $this->krok('B'), $this->krok('C')]];

        $wynik = PorownanieWersji::porownaj($stara, $nowa)['kroki'];

        $this->assertCount(1, $wynik);
        $this->assertSame(PorownanieWersji::DODANO, $wynik[0]['rodzaj']);
        $this->assertSame(2, $wynik[0]['numer']);
    }

    public function test_krok_usuniety_i_zmieniony_minutnik(): void
    {
        $stara = ['steps' => [$this->krok('A', 600), $this->krok('B'), $this->krok('C')]];
        $nowa = ['steps' => [$this->krok('A', 900), $this->krok('C')]];

        $wynik = PorownanieWersji::porownaj($stara, $nowa)['kroki'];

        $this->assertSame(PorownanieWersji::ZMIENIONO, $wynik[0]['rodzaj']);
        $this->assertStringContainsString('minutnik: 10 min', $wynik[0]['przed']);
        $this->assertStringContainsString('minutnik: 15 min', $wynik[0]['po']);
        $this->assertSame(PorownanieWersji::USUNIETO, $wynik[1]['rodzaj']);
        $this->assertSame(2, $wynik[1]['numer']);
    }

    public function test_brak_klucza_w_starszej_migawce_to_brak_danych_a_nie_zmiana(): void
    {
        $stara = ['title' => 'Rosół', 'source_type' => 'external'];
        $nowa = ['title' => 'Rosół', 'source_type' => 'external', 'source_url' => 'https://a.example', 'family_since_year' => null];

        $wynik = PorownanieWersji::porownaj($stara, $nowa);

        $this->assertSame([], $wynik['pola']);
        $this->assertSame(['Adres strony, z której pochodzi przepis'], $wynik['bezDanych']);
        // #2240: pole z wartością tylko po jednej stronie to porównanie
        // niepełne, nie „brak zmian".
        $this->assertFalse($wynik['brakZmian'], 'Niepełne porównanie oznaczone jako „brak zmian”.');
        $this->assertTrue($wynik['bezWykrytychZmian']);
    }

    public function test_brak_klucza_bez_wartosci_po_drugiej_stronie_nie_psuje_braku_zmian(): void
    {
        // Kontrola dodatnia #2240: klucza brakuje, ale po drugiej stronie też
        // nic nie ma — nie ma czego nie wiedzieć, więc to dalej „brak zmian".
        $stara = ['title' => 'Rosół'];
        $nowa = ['title' => 'Rosół', 'source_url' => null];

        $wynik = PorownanieWersji::porownaj($stara, $nowa);

        $this->assertSame([], $wynik['bezDanych']);
        $this->assertTrue($wynik['brakZmian']);
        $this->assertFalse($wynik['bezWykrytychZmian']);
    }

    public function test_pole_dodane_i_usuniete_ma_wlasny_rodzaj(): void
    {
        $stara = ['title' => 'A', 'summary' => 'Opis', 'family_since_year' => null];
        $nowa = ['title' => 'B', 'summary' => null, 'family_since_year' => 1980];

        $rodzaje = array_column(PorownanieWersji::porownaj($stara, $nowa)['pola'], 'rodzaj', 'etykieta');

        $this->assertSame(PorownanieWersji::ZMIENIONO, $rodzaje['Nazwa']);
        $this->assertSame(PorownanieWersji::USUNIETO, $rodzaje['Opis']);
        $this->assertSame(PorownanieWersji::DODANO, $rodzaje['W rodzinie od roku']);
    }

    public function test_uszkodzona_migawka_nie_wywala_odczytu(): void
    {
        $m = new MigawkaWersji(['ingredients' => 'x', 'steps' => [1, ['instruction' => ['a']]], 'servings' => 'abc']);

        $this->assertSame([], $m->skladniki());
        $this->assertSame([], $m->kroki());
        $this->assertNull($m->pole('servings'));
    }

    public function test_zmiana_samych_alergenow_jest_widoczna_w_porownaniu_gdy_flaga_wlaczona(): void
    {
        $stara = ['title' => 'Rosół', 'allergen_status' => 'declared', 'allergens' => ['celery']];
        $nowa = ['title' => 'Rosół', 'allergen_status' => 'declared', 'allergens' => ['celery', 'milk']];

        $wynik = PorownanieWersji::porownaj($stara, $nowa, true);

        $this->assertFalse($wynik['brakZmian']);
        $this->assertSame([
            ['etykieta' => 'Alergeny według autora', 'rodzaj' => PorownanieWersji::ZMIENIONO, 'przed' => 'seler', 'po' => 'mleko, seler'],
        ], $wynik['pola']);
    }

    public function test_alergeny_do_przegladu_i_pusta_lista_maja_osobne_zdania(): void
    {
        $zaznaczone = ['allergen_status' => 'declared', 'allergens' => []];
        $doPrzegladu = ['allergen_status' => 'needs_review', 'allergens' => []];

        $wynik = PorownanieWersji::porownaj($zaznaczone, $doPrzegladu, true)['pola'];

        $this->assertSame('autor nie wskazał żadnych', $wynik[0]['przed']);
        $this->assertSame('do ponownego sprawdzenia przez autora', $wynik[0]['po']);
    }

    public function test_przy_wylaczonej_fladze_alergeny_nie_wchodza_do_porownania(): void
    {
        $stara = ['title' => 'Rosół', 'allergen_status' => 'declared', 'allergens' => ['celery']];
        $nowa = ['title' => 'Rosół', 'allergen_status' => 'declared', 'allergens' => ['milk']];

        $this->assertTrue(PorownanieWersji::porownaj($stara, $nowa)['brakZmian']);
        $this->assertTrue(PorownanieWersji::porownaj($stara, $nowa, false)['brakZmian']);
    }

    public function test_starsza_migawka_bez_oznaczenia_alergenow_to_brak_danych_nie_zmiana(): void
    {
        $stara = ['title' => 'Rosół'];
        $nowa = ['title' => 'Rosół', 'allergen_status' => 'declared', 'allergens' => ['celery']];

        $wynik = PorownanieWersji::porownaj($stara, $nowa, true);

        $this->assertSame([], $wynik['pola']);
        $this->assertSame(['Alergeny według autora'], $wynik['bezDanych']);
    }
}
