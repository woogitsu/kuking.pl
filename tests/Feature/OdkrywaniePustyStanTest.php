<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pusty stan „Świeżo z Kuking" (/odkryj), gdy w całym serwisie nie ma
 * jeszcze ani jednego wpisu.
 *
 * POMIAR SPRZED ZMIANY
 *
 * `components/empty-state.blade.php` ma w komentarzu twardą regułę:
 * „Nigdy «Brak danych». Zawsze: co tu będzie, dlaczego jest pusto i JEDEN
 * WYRAŹNY PRZYCISK Z TEKSTEM." `discover.blade.php` łamał tę regułę
 * podwójnie:
 *
 *   1. Wołał `<x-empty-state title="Jeszcze nic tu nie ma">Kuking dopiero
 *      się zaczyna.</x-empty-state>` BEZ `action`/`href` — komponent bez
 *      tych dwóch propsów nie renderuje żadnego przycisku (`@if($action &&
 *      $href)`). Pusty stan kończył się na zdaniu, bez żadnej drogi dalej —
 *      dokładnie ten „ślepy zaułek", przed którym ostrzega
 *      `docs/product/SOUL.md` 4.11 (cytowany już w `search.blade.php`).
 *   2. Tekst wyjaśnienia („Kuking dopiero się zaczyna.") nie jest tekstem
 *      z `docs/brand/COPY_STYLE.md` §6 „Puste stany" — tam dla „pusty feed"
 *      jest dosłownie: „Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie
 *      musi być ładne — ma być prawdziwe." Ten sam tekst, który
 *      `home.blade.php` już poprawnie używa dla identycznej sytuacji.
 *
 * DLACZEGO ASERCJE CZYTAJĄ TYLKO WNĘTRZE `.empty-state`, NIE CAŁĄ STRONĘ
 * Pierwsza wersja tego testu sprawdzała całą stronę wyrażeniem regularnym —
 * i „przycisk do rejestracji dla gościa" przechodził już PRZED naprawą,
 * bo na każdej stronie serwisu gość i tak widzi „Załóż konto" w górnej
 * belce. Test nic wtedy nie mierzył: byłby zielony nawet, gdyby pusty stan
 * w ogóle nie miał własnego przycisku. Skopowanie do samego `.empty-state`
 * usuwa ten fałszywy trop.
 *
 * NAPRAWA to bugfix (AGENTS.md: bugfix zawsze z testem regresyjnym), więc
 * ten plik zostaje jako test regresyjny, nie tylko pomiar jednorazowy.
 */
class OdkrywaniePustyStanTest extends TestCase
{
    use RefreshDatabase;

    /** Wnętrze `<div class="empty-state">` jako HTML, do sprawdzenia OSOBNO od reszty strony. */
    private function pustyStanZDiscover(string $html): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $wezel = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' empty-state ')]")->item(0);

        $this->assertNotNull(
            $wezel,
            'Kontrola: na stronie /odkryj nie ma w ogóle `.empty-state` — test nie sprawdza pustego '.
            'stanu, tylko coś innego (np. błąd albo listę wpisów).',
        );

        return (string) $dom->saveHTML($wezel);
    }

    public function test_zalogowany_dostaje_tekst_z_copy_style_i_przycisk_dodania_zdjecia(): void
    {
        $ktosNowy = $this->user('ktos_nowy');

        $html = $this->actingAs($ktosNowy)
            ->get(route('discover'))
            ->assertOk()
            ->getContent();

        $pustyStan = $this->pustyStanZDiscover((string) $html);

        $this->assertStringContainsString('Jeszcze nic tu nie ma', $pustyStan);
        $this->assertStringContainsString(
            'Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.',
            $pustyStan,
        );

        $this->assertMatchesRegularExpression(
            '~<a class="btn btn-primary" href="[^"]*'.preg_quote(route('posts.create'), '~').'"~',
            $pustyStan,
            'Pusty stan „Świeżo z Kuking" dla zalogowanej osoby nie ma (wewnątrz `.empty-state`) '.
            'przycisku prowadzącego do dodania pierwszego zdjęcia.',
        );
    }

    public function test_gosc_dostaje_ta_sama_tresc_i_przycisk_zalozenia_konta(): void
    {
        $html = $this->get(route('discover'))
            ->assertOk()
            ->getContent();

        $pustyStan = $this->pustyStanZDiscover((string) $html);

        $this->assertStringContainsString('Jeszcze nic tu nie ma', $pustyStan);

        // Gość nie ma dostępu do `/dodaj/zdjecie` (wymaga konta) — dostaje
        // więc drogę do rejestracji, tak jak `landing.blade.php` robi to
        // już dla identycznego przypadku „nic tu jeszcze nie ma".
        $this->assertMatchesRegularExpression(
            '~<a class="btn btn-primary" href="[^"]*'.preg_quote(route('register'), '~').'"~',
            $pustyStan,
            'Pusty stan „Świeżo z Kuking" dla gościa nie prowadzi (wewnątrz `.empty-state`) do rejestracji.',
        );

        // I odwrotnie: link do dodania zdjęcia (wymaga konta) NIE ma prawa
        // stać w pustym stanie widzianym przez gościa.
        $this->assertStringNotContainsString(route('posts.create'), $pustyStan);
    }

    public function test_stary_niezgodny_z_copy_style_tekst_znika(): void
    {
        $html = $this->get(route('discover'))->assertOk()->getContent();

        // Kontrola odwrotna: dawny tekst NIE MA prawa się już pojawić —
        // inaczej naprawa dopisała nowy tekst obok starego, zamiast go
        // zastąpić.
        $this->assertStringNotContainsString('Kuking dopiero się zaczyna.', (string) $html);
    }
}
