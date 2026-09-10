<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etap D kitu v2 (ekran 03_desktop_search_discover) — układ strony wyszukiwania.
 *
 * POMIAR SPRZED ZMIANY (6/7 września 2026, ta sama gałąź)
 *
 * `search.blade.php` miał już zakresy (chipsy) i uczciwe puste stany — to
 * zrobiła wcześniejsza część etapu D (patrz `SearchTest`). Brakowało dwóch
 * rzeczy widocznych na makiecie na pierwszy rzut oka:
 *
 *   1. PRAWEJ SZYNY. Kit ma dwukolumnowy układ: wyniki i obok podpowiedzi
 *      (u nas: „kuKINGi na dziś" — patrz raport, dlaczego nie dosłowne
 *      „Smaki września" z liczbami przepisów). `<x-layout>` rezerwuje trzecią
 *      kolumnę siatki NA KAŻDYM ekranie zalogowanego, niezależnie od tego,
 *      czy strona poda slot `rail` (patrz komentarz „TRZECIA KOLUMNA JEST
 *      ZAWSZE" w `resources/css/app.css`) — więc bez slotu kolumna stała
 *      po prostu pusta, a strona wyglądała na jednokolumnową.
 *   2. IKONY LUPY w polu wyszukiwania. Pole było zwykłym, małym polem
 *      formularza — bez ikony, bez powiększonego rozmiaru z makiety.
 *
 * Test pilnuje NIEZMIENNIKA (jest element `.app-rail` z realną treścią;
 * w formularzu szukania jest ikona), nie literału klasy CSS, z tego samego
 * powodu co `UkladGosciaTest` — obietnica w komentarzu jest tańsza niż
 * sprawdzenie w kodzie.
 */
class WyszukiwanieUkladTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Asercja kontrolna: strona MUSI się w ogóle wyrenderować i pokazać
     * prawdziwe wyniki. Bez tego test „nie ma szyny" przechodziłby też
     * wtedy, gdyby /szukaj zwracało pustą stronę błędu.
     */
    private function odwiedzWynikiWyszukiwania(): string
    {
        $basia = $this->user('basia_szukanie', ['display_name' => 'Basia z Podkarpacia']);

        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Żurek testowy',
            'slug' => 'zurek-testowy-uklad',
        ]);

        // Ktoś inny publikuje coś dziś, żeby `DailyBoard::forViewer()` miało
        // z czego zbudować szynę — bez tego test szyny byłby pusty z powodu
        // braku danych, a nie z powodu braku slotu, i przechodziłby fałszywie.
        $autorSzyny = $this->user('kucharz_szyny', ['display_name' => 'Marek z Szyny']);
        Post::factory()->create(['author_id' => $autorSzyny->getKey()]);

        $html = $this->get(route('search', ['q' => 'zurek']))
            ->assertOk()
            ->assertSee('Żurek testowy')
            ->getContent();

        return (string) $html;
    }

    public function test_wyniki_wyszukiwania_maja_prawa_szyne_z_podpowiedziami(): void
    {
        $html = $this->odwiedzWynikiWyszukiwania();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $szyna = $xpath->query("//aside[contains(concat(' ', normalize-space(@class), ' '), ' app-rail ')]");

        $this->assertGreaterThan(
            0,
            $szyna->length,
            'Strona wyników wyszukiwania nie ma prawej szyny (`<aside class="app-rail">`) — '.
            'kit (ekran 03) ma tam podpowiedzi obok wyników, a u nas ta kolumna jest pusta.',
        );

        // Kontrola: szyna nie może być pustą skorupką — ma zawierać
        // rzeczywistą podpowiedź (osobę z tablicy „kuKINGi na dziś").
        $tresc = (string) $dom->saveHTML($szyna->item(0));
        $this->assertStringContainsString(
            'Marek z Szyny',
            $tresc,
            'Prawa szyna istnieje, ale nie pokazuje osoby z tablicy „kuKINGi na dziś" — '.
            'slot jest pusty albo podpięty do złych danych.',
        );
    }

    public function test_pole_wyszukiwania_ma_ikone_lupy(): void
    {
        $html = $this->odwiedzWynikiWyszukiwania();

        // Kit (ekran 03 i 07) rysuje lupę wewnątrz pola wyszukiwania.
        // U nas formularz na `/szukaj` nie miał ŻADNEJ ikony — samo pole
        // tekstowe z etykietą nad nim.
        $this->assertMatchesRegularExpression(
            '~<form class="[^"]*" method="GET" action="[^"]*'.preg_quote(route('search'), '~').'"~',
            $html,
            'Kontrola: formularz szukania w ogóle nie istnieje w wyniku — reszta '.
            'testu i tak nic by wtedy nie sprawdzała.',
        );

        $this->assertMatchesRegularExpression(
            // `class="ikona[^"]*"`, nie `class="ikona"` — komponent `<x-ikona>`
            // SCALA teraz klasę z wywołania z własną, więc w atrybucie stoi
            // „ikona wyszukiwarka-ikona". Wcześniej wzorzec pasował tylko
            // dlatego, że klasa z wywołania była po cichu gubiona.
            '~<form class="[^"]*" method="GET" action="[^"]*szukaj[^"]*"[^>]*>.*?<svg class="ikona[^"]*"[^>]*>.*?</form>~s',
            $html,
            'W formularzu wyszukiwania nie ma ikony lupy (`<x-ikona nazwa="search">`).',
        );
    }
}
