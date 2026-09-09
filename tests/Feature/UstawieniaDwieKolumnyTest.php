<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spis „Wszystkie ustawienia" stoi w prawej szynie, nie pod formularzem.
 *
 * NA CZYM POLEGAŁ BŁĄD (zgłoszenie właściciela, Full HD 1920×1080)
 * Formularz zajmował środek ekranu, cała prawa połowa stała pusta, a spis
 * „Wszystkie ustawienia" leżał POD formularzem — poza pierwszym ekranem.
 * Każdy ekran ustawień renderował ten komponent jako zwykłego sąsiada
 * formularza w głównej kolumnie (`<main>`), więc nie było go czym przenieść
 * na szeroki ekran bez osobnego CSS-u dla samych ustawień.
 *
 * POPRAWKA — nie osobny CSS, tylko istniejący mechanizm `<x-slot:rail>`
 * Każda podstrona wkłada `<x-ustawienia-nawigacja>` do `$rail`, czyli do tej
 * samej prawej szyny (`.app-rail`), którą od dawna mają „Start" i „Szukaj".
 * Ten test pilnuje TEGO — że komponent trafia do `<aside class="app-rail">`,
 * a nie do `<main>` — więc jest bezwartościowy, jeśli przechodzi też na
 * kodzie sprzed poprawki. Sprawdzone: na starej wersji (komponent wewnątrz
 * `<main>`) `test_spis_stoi_w_prawej_szynie_a_nie_pod_formularzem` się
 * wywala, bo `<aside class="app-rail">` nie istnieje na tych stronach wcale.
 *
 * Reszta (nav z dostępną nazwą, bieżący ekran oznaczony `aria-current`) jest
 * już pilnowana przez `UstawieniaNawigacjaTest` — nie duplikujemy tego tutaj,
 * tylko sprawdzamy, że TA SAMA treść stoi w odpowiednim miejscu dokumentu.
 */
class UstawieniaDwieKolumnyTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function ekrany(): array
    {
        return [
            'settings.profile',
            'settings.accessibility',
            'settings.tags',
            'settings.email',
            'settings.security',
            'settings.two_factor.edit',
            'settings.privacy',
            'settings.data',
        ];
    }

    public function test_spis_stoi_w_prawej_szynie_a_nie_pod_formularzem(): void
    {
        $user = $this->user('ustawieniaszyna');

        foreach ($this->ekrany() as $trasa) {
            $html = (string) $this->actingAs($user)
                ->get(route($trasa))
                ->assertOk()
                ->getContent();

            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);

            $szyna = $xpath->query("//aside[contains(concat(' ', normalize-space(@class), ' '), ' app-rail ')]")->item(0);

            $this->assertNotNull(
                $szyna,
                "Ekran {$trasa}: brak <aside class=\"app-rail\"> — spis ustawień nie ma gdzie stanąć obok formularza.",
            );

            $spisWSzynie = $xpath->query(".//nav[@aria-label='Wszystkie ustawienia']", $szyna)->length > 0;

            $this->assertTrue(
                $spisWSzynie,
                "Ekran {$trasa}: spis „Wszystkie ustawienia” nie stoi w prawej szynie (.app-rail) — "
                .'wrócił pod formularz, czyli poza pierwszy ekran na szerokim monitorze.',
            );

            $main = $xpath->query("//main[@id='tresc']")->item(0);
            $this->assertNotNull($main, "Ekran {$trasa}: brak <main id=\"tresc\">.");

            $spisWTresci = $xpath->query(".//nav[@aria-label='Wszystkie ustawienia']", $main)->length > 0;

            $this->assertFalse(
                $spisWTresci,
                "Ekran {$trasa}: spis „Wszystkie ustawienia” jest zduplikowany — stoi zarówno w <main>, jak i w szynie.",
            );
        }
    }

    /**
     * DOM ma nieść formularz PRZED spisem, nie odwrotnie — kolejność w źródle
     * decyduje o kolejności `Tab` i o tym, co czytnik ekranu przeczyta
     * najpierw. CSS-owy `order` (gdyby ktoś go tu kiedyś dodał) zmienia
     * wygląd, ale NIE zmienia tej kolejności — stąd test na surowej
     * kolejności znaczników, nie na tym, co widać.
     */
    public function test_formularz_jest_w_kodzie_przed_spisem_ustawien(): void
    {
        $html = (string) $this->actingAs($this->user('ustawieniaszyna2'))
            ->get(route('settings.profile'))
            ->assertOk()
            ->getContent();

        $pozycjaFormularza = strpos($html, 'id="tresc"');
        $pozycjaSpisu = strpos($html, 'aria-label="Wszystkie ustawienia"');

        $this->assertIsInt($pozycjaFormularza);
        $this->assertIsInt($pozycjaSpisu);

        $this->assertLessThan(
            $pozycjaSpisu,
            $pozycjaFormularza,
            'Spis ustawień wyprzedza formularz w kodzie strony — kolejność Tab i czytnika ekranu byłaby odwrócona.',
        );
    }
}
