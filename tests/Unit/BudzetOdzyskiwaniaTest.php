<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\OdzyskanyFormularz;
use App\Models\Recipe;
use App\Support\LimityTekstuPrzepisu;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;

class BudzetOdzyskiwaniaTest extends TestCase
{
    public function test_maksymalne_pola_przepisu_z_encjami_i_utf8_wraca_jeden_do_jednego(): void
    {
        $dane = [];
        foreach (LimityTekstuPrzepisu::POLA as $name => $max) {
            /** @var array<string, mixed> $dane */
            $value = str_repeat('"', $max - 1).'ą';
            if (str_starts_with($name, 'ingredients.')) {
                for ($i = 0; $i < Recipe::MAX_INGREDIENTS; $i++) {
                    $dane['ingredients'][$i][substr($name, strrpos($name, '.') + 1)] = $value;
                    $dane['ingredients'][$i]['no_amount'] = '1';
                }
            } elseif (str_starts_with($name, 'steps.')) {
                for ($i = 0; $i < Recipe::MAX_STEPS; $i++) {
                    $dane['steps'][$i]['instruction'] = $value;
                    $dane['steps'][$i]['id'] = '019a52f0-0000-4000-8000-000000000001';
                    $dane['steps'][$i]['timer_minutes'] = '10080';
                    $dane['steps'][$i]['remove_photo'] = '1';
                }
            } else {
                $dane[$name] = $value;
            }
        }
        foreach (['recipes.store', 'recipes.update'] as $route) {
            $form = $this->form($dane, $route);
            $this->assertFalse($form->obciete);
            $this->assertCount(727, $form->pola);
            $this->assertSame(str_repeat('"', 3999).'ą', collect($form->pola)->firstWhere('nazwa', 'steps[59][instruction]')['wartosc']);
            $this->assertSame(str_repeat('"', 299).'ą', collect($form->pola)->firstWhere('nazwa', 'ingredients[119][note]')['wartosc']);
            $encoded = [];
            foreach ($form->pola as $pole) {
                $encoded[] = rawurlencode($pole['nazwa']).'='.rawurlencode($pole['wartosc']);
            }
            parse_str(implode('&', $encoded), $odzyskane);
            $this->assertSame($dane, $odzyskane);
        }
    }

    public function test_liczba_pol_jest_ograniczona_takze_dla_jednoznakowych_wartosci(): void
    {
        $dane = [];
        for ($i = 0; $i <= LimityTekstuPrzepisu::maksPol(); $i++) {
            $dane['p'.$i] = 'x';
        }
        $form = $this->form($dane);
        $this->assertTrue($form->obciete);
        $this->assertCount(LimityTekstuPrzepisu::maksPol(), $form->pola);
        $this->assertSame('x', $form->pola[0]['wartosc']);
    }

    public function test_dlugie_nazwy_i_glebokie_tablice_nie_rosna_bez_granicy(): void
    {
        $form = $this->form(['title' => 'Zupa', str_repeat('n', 257) => 'x']);
        $this->assertTrue($form->obciete);
        $this->assertCount(1, $form->pola);
        $deep = 'x';
        for ($i = 0; $i < 20; $i++) {
            $deep = ['a' => $deep];
        }
        $form = $this->form(['title' => 'Zupa', 'd' => $deep]);
        $this->assertTrue($form->obciete);
        $this->assertCount(1, $form->pola);
    }

    public function test_nadal_odcina_nadmiar_i_nie_oddaje_sekretow(): void
    {
        $form = $this->form(['title' => 'Zupa', 'body' => str_repeat('a', 200001)]);
        $this->assertTrue($form->obciete);
        $this->assertCount(1, $form->pola);
        $form = $this->form(['body' => str_repeat('a', 199000), 'dalsze' => str_repeat('b', 2000)], 'posts.store');
        $this->assertTrue($form->obciete);
        $this->assertCount(1, $form->pola);
        $form = $this->form(['title' => 'Zupa', 'password' => 'sekret', 'steps' => [['backup_code' => 'sekret']]]);
        $this->assertFalse($form->obciete);
        $this->assertCount(1, $form->pola);
        $this->assertSame([], $this->form(['title' => 'Zupa'], 'login')->pola);
    }

    public function test_budzet_bajtow_obejmuje_takze_escapowany_adres_akcji(): void
    {
        $request = Request::create('http://localhost/'.str_repeat('"', 1100000), 'POST', ['title' => 'Zupa']);
        $request->setRouteResolver(fn () => (new Route('POST', '/dodaj/przepis', fn () => null))->name('recipes.store'));
        $form = OdzyskanyFormularz::zZadania($request);
        $this->assertTrue($form->obciete);
        $this->assertSame([], $form->pola);
    }

    private function form(array $dane, string $route = 'recipes.store'): OdzyskanyFormularz
    {
        $request = Request::create('http://localhost/dodaj/przepis', 'POST', $dane);
        $request->setRouteResolver(fn () => (new Route('POST', '/dodaj/przepis', fn () => null))->name($route));

        return OdzyskanyFormularz::zZadania($request);
    }
}
