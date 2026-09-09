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

    /**
     * Nazwa cudzej firmy bez źródła to nie jest mocniejszy argument — to jest
     * ten sam ogólnik, tylko z nazwiskiem. Wolno pisać wprost o dwóch
     * istniejących serwisach, dopóki mówi się prawdę, a prawdę trzeba mieć
     * czym pokazać czytelnikowi, nie tylko sobie w notatce badawczej.
     *
     * Test wiąże jedno z drugim: jeśli ktoś kiedyś usunie odnośniki, zostaną
     * same twierdzenia o cudzych firmach — i wtedy ten test ma zaboleć.
     */
    public function test_twierdzenia_o_cudzych_serwisach_maja_zrodla(): void
    {
        $response = $this->get(route('about'));

        $response->assertOk();
        $response->assertSee('Skąd to wiemy', false);
        $response->assertSee('wiki.archiveteam.org/index.php/Garnek.pl', false);
        $response->assertSee('durszlakpl-zeszyty-z-przepisami-przepadly', false);
    }

    /**
     * Roku zamknięcia Durszlaka nie udało się potwierdzić w źródle, które
     * podaje go wprost — więc strona go NIE podaje. Fakt bez daty jest
     * prawdziwy; fakt z datą „mniej więcej" już nie, a mówimy o cudzej firmie.
     */
    public function test_o_durszlaku_nie_podajemy_niepotwierdzonej_daty(): void
    {
        $tresc = (string) $this->get(route('about'))->assertOk()->getContent();

        $od = mb_strpos($tresc, 'Durszlak.pl');

        $this->assertNotFalse($od, 'Na stronie nie ma już Durszlaka — poprawcie ten test razem ze zmianą.');

        $akapit = mb_substr($tresc, $od, 400);

        $this->assertDoesNotMatchRegularExpression(
            '/\b20\d{2}\b/u',
            $akapit,
            'Przy Durszlaku pojawił się rok. Nie mamy na niego źródła podającego go wprost '
            .'(patrz docs/research/COMPETITIVE_LANDSCAPE.md — „rok: [do weryfikacji]"). '
            .'Albo znajdź źródło i dopisz je obok, albo zostaw fakt bez daty.',
        );
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
