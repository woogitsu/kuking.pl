<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #30: „Twoje przepisy nie zginą” jako drugi przekaz obok społeczności
 * ma być dowodem, nie ogólnikiem. Dowodem są dwa konkretne, polskie serwisy,
 * które zamknęły się i zabrały ze sobą przepisy ludzi — Garnek.pl i Durszlak.pl
 * (`docs/research/COMPETITIVE_LANDSCAPE.md`, `docs/RESEARCH.md`).
 *
 * Bez tego testu ktoś kiedyś przepisze `/o-kuking` na ogólnik („serwisy
 * czasem znikają") i argument zniknie bez śladu — dokładnie tak, jak dziś
 * opisuje to komentarz weryfikacyjny w issue #30.
 */
class OKukingWymieniaGarnekIDurszlakTest extends TestCase
{
    public function test_strona_o_kuking_nazywa_wprost_garnek_i_durszlak(): void
    {
        $response = $this->get('/o-kuking');

        $response->assertOk();
        $response->assertSee('Garnek.pl', false);
        $response->assertSee('Durszlak.pl', false);
    }

    public function test_strona_o_kuking_nie_skladaja_obietnicy_prawnej_o_uprzedzeniu(): void
    {
        // Issue #30 odsyła zapis o uprzedzaniu użytkowników przed ewentualnym
        // zamknięciem serwisu do przemyślenia z prawnikiem (#8). Dopóki to nie
        // jest rozstrzygnięte, `/o-kuking` nie może dawać takiej obietnicy.
        $response = $this->get('/o-kuking');

        $response->assertOk();
        $response->assertDontSee('dni wcześniej', false);
        $response->assertDontSee('z wyprzedzeniem', false);
        $response->assertDontSee('powiadomimy', false);
    }
}
