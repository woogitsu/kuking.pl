<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Profile\ProfilRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profil publiczny po wyjęciu wejścia z kontrolera (issue #970):
 * zakładka, rok i fraza z adresu żyją w `ProfilRequest`.
 *
 * Zachowanie ekranu pilnują istniejące testy profilu — przez prawdziwe żądania.
 * Tu: kontrakt wejścia bez uruchamiania ekranu oraz to, że przeniesienie
 * NIE WPROWADZIŁO odsyłania z błędem walidacji (pierwszy test — łapie
 * dopisanie choćby jednej reguły do `rules()`).
 */
class ProfilRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_bledne_parametry_nie_odsylaja_z_bledem_walidacji(): void
    {
        $this->user('basia970');

        $this->get(route('profile.show', [
            'username' => 'basia970',
            'zakladka' => 'usuniete-na-zawsze',
            'rok' => 'cokolwiek',
            'szukaj' => 'x',
        ]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertViewHas('tab', 'wszystko')
            ->assertViewHas('rok', null);
    }

    public function test_zakladka_z_adresu_ma_zdefiniowany_wynik(): void
    {
        $this->assertSame('przepisy', $this->zadanie(['zakladka' => 'przepisy'])->zakladka());
        $this->assertSame('ugotowane', $this->zadanie(['zakladka' => 'ugotowane'])->zakladka());
        $this->assertSame('wszystko', $this->zadanie([])->zakladka());
        $this->assertSame('wszystko', $this->zadanie(['zakladka' => 'wszystko'])->zakladka());
        $this->assertSame('wszystko', $this->zadanie(['zakladka' => 'PRZEPISY'])->zakladka());
        // Tablica w adresie (`?zakladka[]=przepisy`) też nie wywala ekranu.
        $this->assertSame('wszystko', $this->zadanie(['zakladka' => ['przepisy']])->zakladka());
    }

    public function test_rok_z_adresu_tylko_gdy_wyglada_na_rok(): void
    {
        $this->assertSame(2024, $this->zadanie(['rok' => '2024'])->rok());
        $this->assertSame(1990, $this->zadanie(['rok' => '1990'])->rok());
        $this->assertSame(2999, $this->zadanie(['rok' => '2999'])->rok());

        $this->assertNull($this->zadanie([])->rok());
        $this->assertNull($this->zadanie(['rok' => '1989'])->rok());
        $this->assertNull($this->zadanie(['rok' => '3000'])->rok());
        $this->assertNull($this->zadanie(['rok' => '-2024'])->rok());
        $this->assertNull($this->zadanie(['rok' => 'cokolwiek'])->rok());
    }

    public function test_fraza_przechodzi_surowa_bez_reguly_odsylajacej(): void
    {
        $this->assertSame('  zurek ', $this->zadanie(['szukaj' => '  zurek '])->szukaj());
        $this->assertNull($this->zadanie([])->szukaj());
        $this->assertSame([], (new ProfilRequest)->rules());
    }

    public function test_wejscie_nie_jest_autoryzacja_adresu_profilu(): void
    {
        // Request sam nie przepuszcza niczego ponad kontrolerem: Policy
        // `viewProfile` nadal decyduje o dostępie do konta (konto
        // zawieszone/nieistniejące nie otwiera się mimo poprawnych parametrów).
        $this->get(route('profile.show', ['username' => 'nie-ma-takiej', 'zakladka' => 'przepisy']))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $parametry
     */
    private function zadanie(array $parametry): ProfilRequest
    {
        return ProfilRequest::create('/@basia', 'GET', $parametry);
    }
}
