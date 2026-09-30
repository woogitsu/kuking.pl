<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audyt UX 50+, formularz odwołania bez logowania (`/odwolanie`):
 *  - „Nie pamiętam hasła” stało obok „Wyślij odwołanie”, więc kliknięcie go
 *    po napisaniu wyjaśnienia porzucało cały wpisany tekst. Odnośnik stoi
 *    teraz przy polu hasła, PRZED polem wyjaśnienia;
 *  - adres kontaktowy jest odnośnikiem `mailto:`.
 * Testy czytają wyrenderowany HTML.
 */
final class OdwolanieGosciaNieGubiWpisanegoTekstuTest extends TestCase
{
    use RefreshDatabase;

    public function test_nie_pamietam_hasla_stoi_przed_polem_wyjasnienia_a_nie_obok_wysylki(): void
    {
        $html = $this->get(route('appeals.guest'))->assertOk()->getContent();

        $odnosnik = strpos($html, 'href="'.route('password.request').'"');
        $this->assertNotFalse($odnosnik, 'Brak odnośnika „Nie pamiętam hasła”.');
        $this->assertGreaterThan((int) strpos($html, 'id="f-password"'), $odnosnik, 'Odnośnik stoi przed polem hasła.');
        $this->assertLessThan((int) strpos($html, 'id="f-body"'), $odnosnik, 'Odnośnik stoi za polem wyjaśnienia — porzuca wpisany tekst.');

        $this->assertSame(1, preg_match('~<div class="form-actions">(.*?)</div>~s', $html, $m));
        $this->assertStringContainsString('Wyślij odwołanie', $m[1]);
        $this->assertStringNotContainsString(route('password.request'), $m[1], 'Obok „Wyślij odwołanie” nadal stoi odnośnik wyprowadzający ze strony.');
    }
}
