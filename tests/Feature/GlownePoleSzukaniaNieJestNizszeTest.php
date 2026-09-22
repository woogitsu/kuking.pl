<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Główne pole strony szukania nie jest niższe od zwykłego pola.
 *
 * REGRESJA: `.wyszukiwarka-input` brało wysokość z `--control-height-touch`
 * (60 px), czyli z minimum KONTROLKI DOTYKOWEJ, a nie z minimum POLA. Gdy
 * `--pole-wysokosc-min` urosło do 64 px (#365), pole opisane we własnym
 * komentarzu jako „wyżej niż zwykłe `.field-input` — to jest GŁÓWNE pole na
 * tej stronie" stało się o 4 px NIŻSZE niż każde zwykłe pole w serwisie.
 *
 * Widać to było tylko w kaskadzie: `ekran-wyszukiwania.css` jest importowany
 * po `tokens.css`, obie reguły siedzą w `@layer components` i mają tę samą
 * wagę, więc wygrywa późniejsza.
 *
 * Test pilnuje ZALEŻNOŚCI, nie liczby: wysokość tego pola ma być liczona od
 * `--pole-wysokosc-min`, żeby następne podniesienie minimum pociągnęło je
 * za sobą samo.
 */
class GlownePoleSzukaniaNieJestNizszeTest extends TestCase
{
    public function test_wysokosc_pola_szukania_liczy_sie_od_minimum_pola(): void
    {
        $arkusz = (string) file_get_contents(resource_path('css/ekran-wyszukiwania.css'));

        $this->assertSame(
            1,
            preg_match('/\.wyszukiwarka-input\s*\{(.+?)\}/s', $arkusz, $trafienie),
            'W `ekran-wyszukiwania.css` nie ma reguły `.wyszukiwarka-input`.',
        );

        $this->assertMatchesRegularExpression(
            '/min-height:[^;]*--pole-wysokosc-min/',
            $trafienie[1],
            'Wysokość głównego pola szukania nie jest liczona od `--pole-wysokosc-min`. '
            .'Sztywna wartość albo `--control-height-touch` zostaje w tyle przy pierwszym '
            .'podniesieniu minimum pola i główne pole strony robi się niższe od zwykłego.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/min-height:[^;]*--control-height-touch/',
            $trafienie[1],
            '`--control-height-touch` to minimum pozycji dolnego paska, nie pola do pisania.',
        );
    }
}
