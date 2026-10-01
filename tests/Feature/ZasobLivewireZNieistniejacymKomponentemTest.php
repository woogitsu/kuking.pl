<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `GET /livewire-<hash>/js/{component}.js` (i `css/`, `.global.css`) z nazwą
 * komponentu, którego nie ma, to zwykły zły adres: 404, nie HTTP 500
 * (`ComponentNotFoundException`, audyt odporności, fuzz HTTP).
 */
class ZasobLivewireZNieistniejacymKomponentemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function adresy(): array
    {
        return [
            'js' => ['/js/nie-ma-takiego.js'],
            'css' => ['/css/nie-ma-takiego.css'],
            'css globalny' => ['/css/nie-ma-takiego.global.css'],
        ];
    }

    #[DataProvider('adresy')]
    public function test_nieistniejacy_komponent_to_404(string $koniec): void
    {
        $this->get('/'.EndpointResolver::prefix().$koniec)->assertNotFound();
    }

    /** KONTROLA DODATNIA: wyjątek poza adresami zasobów nadal jest błędem (500). */
    public function test_ten_sam_wyjatek_poza_zasobami_nie_jest_maskowany(): void
    {
        Route::get('/__proba-livewire', fn () => Livewire::new('nie-ma-takiego'));

        $this->get('/__proba-livewire')->assertStatus(500);
    }
}
