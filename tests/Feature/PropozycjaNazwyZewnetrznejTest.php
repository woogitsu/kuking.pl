<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Rules\ReservedUsername;
use App\Support\NazwaUzytkownika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropozycjaNazwyZewnetrznejTest extends TestCase
{
    use RefreshDatabase;

    public static function names(): array
    {
        return array_map(fn ($name) => [$name], ['admin', 'pomoc', 'ądmin', '4dm1n', 'Basia', 'Adminowicz']);
    }

    #[DataProvider('names')]
    public function test_niepusta_propozycja_przechodzi_regule_rezerwacji(string $name): void
    {
        $suggestion = NazwaUzytkownika::wolnaPropozycja($name);
        $this->assertNotNull($suggestion);
        $this->assertTrue(Validator::make(['username' => $suggestion], ['username' => [new ReservedUsername]])->passes(), $suggestion);
    }

    public function test_zajetosc_i_brak_zrodla_nadal_dzialaja(): void
    {
        $this->user('Basia');
        $this->assertSame('basia_2', NazwaUzytkownika::wolnaPropozycja('basia'));
        $this->assertNull(NazwaUzytkownika::wolnaPropozycja('!!!'));
    }

    public function test_kolejny_kandydat_tez_sprawdza_rezerwacje(): void
    {
        config(['kuking.account.reserved_usernames' => ['admin', 'admin_2']]);
        $this->assertSame('admin_3', NazwaUzytkownika::wolnaPropozycja('admin'));
    }

    public function test_formularze_dostawcow_podpowiadaja_dopuszczalna_nazwe_z_adresu(): void
    {
        foreach (['google', 'facebook'] as $provider) {
            config(["kuking.$provider.wlaczone" => true, "kuking.$provider.identyfikator_klienta" => '123', "kuking.$provider.sekret_klienta" => 'test']);
            $identity = ['sub' => '123', 'identyfikator' => '123', 'email' => 'pomoc@example.test', 'imie' => '', 'od' => now()->timestamp];
            $response = $this->withSession(["wejscie_$provider.tozsamosc" => $identity])->get(route("$provider.finish"))->assertOk();
            $suggestion = $response->viewData('proponowanaNazwa');
            $this->assertNotEmpty($suggestion);
            $this->assertTrue(Validator::make(['username' => $suggestion], ['username' => [new ReservedUsername]])->passes(), $provider.': '.$suggestion);
        }
    }
}
