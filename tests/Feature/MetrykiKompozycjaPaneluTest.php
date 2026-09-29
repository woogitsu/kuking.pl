<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ekran „Metryki doboru” w kompozycji panelu moderacji (#492, #581).
 *
 * Ekran powstał (#1814) po porcie panelu do marki i został w układzie sprzed
 * niego. Test pilnuje tego, co widać w HTML; rozmiar pisma i brak przepełnienia
 * mierzy osobno miernik przeglądarkowy panelu (`scripts/panel-marki.mjs`,
 * rodzina `metryki`), a treść liczb — `MetrykiDoboruTest`.
 *
 *  1. ekran leży w ramie panelu i ma wyłącznie karty treści (jest tylko do
 *     odczytu — panel formularza obiecywałby czynność, której nie ma, D-053);
 *  2. uwagi pod kartami są w `.panel-liczby` (pismo podstawowe), a w ramie
 *     treści nie zostało ani jedno drobne `.meta` 16 px;
 *  3. tabela dni ma podpis dla czytnika ekranu, nagłówki kolumn, dzień jako
 *     nagłówek wiersza i 28 wierszy; goły `<table>` bez klasy wrócić nie może.
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić), wykonane przy zmianie:
 * powrót jednej uwagi do `class="meta"` oblewa test 2; zdjęcie `<caption>`
 * albo zamiana `<th scope="row">` na `<td>` oblewa test 3; zdjęcie klasy
 * `tabela-dni` z tabeli oblewa test 3; `panel-formularza` dodany do karty
 * oblewa test 1.
 */
class MetrykiKompozycjaPaneluTest extends TestCase
{
    use RefreshDatabase;

    public function test_ekran_lezy_w_ramie_panelu_i_ma_tylko_karty_tresci(): void
    {
        $xpath = $this->xpath($this->actingAs($this->admin())->get(route('admin.metryki'))->assertOk()->getContent());

        $this->assertSame(1, $xpath->query('//*[@data-marka-panel]//*[contains(concat(" ", normalize-space(@class), " "), " marka-panel-tresc ")]')->length,
            'Ekran nie leży w ramie panelu moderacji.');

        $karty = $xpath->query('//main//section[contains(concat(" ", normalize-space(@class), " "), " card ")][@data-metryka]');
        $this->assertSame(5, $karty->length, 'Ekran ma pięć kart: progi, pierwsza strona, ludzie, równość i dni.');
        foreach ($karty as $karta) {
            $this->assertInstanceOf(DOMElement::class, $karta);
            $this->assertNotSame('', $karta->getAttribute('aria-labelledby'), 'Karta nie jest opisana własnym nagłówkiem.');
            $this->assertSame(1, $xpath->query('.//h2[@id="'.$karta->getAttribute('aria-labelledby').'"]', $karta)->length,
                'Nagłówek karty wypadł z karty.');
        }

        $this->assertSame(0, $xpath->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " panel-formularza ")]')->length,
            'Ekran tylko czyta, więc nie może mieć panelu formularza.');
        $this->assertSame(0, $xpath->query('//main//form')->length, 'Ekran metryk nie ma formularza.');
    }

    public function test_uwagi_sa_pismem_podstawowym_nie_meta(): void
    {
        $xpath = $this->xpath($this->actingAs($this->admin())->get(route('admin.metryki'))->assertOk()->getContent());

        $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " marka-panel-tresc ")]//*[contains(concat(" ", normalize-space(@class), " "), " meta ")]')->length,
            'W treści panelu wróciło drobne `.meta` (16 px) — uwagi pod kartami mają być w `.panel-liczby`.');

        $this->assertSame(1, $xpath->query('//section[@data-metryka="progi"]//p[contains(concat(" ", normalize-space(@class), " "), " panel-liczby ")][contains(., "wszystkich trzech naraz")]')->length,
            'Uwaga pod progami rewizji nie jest w `.panel-liczby`.');
        $this->assertSame(1, $xpath->query('//section[@data-metryka="pierwsza-strona"]//p[contains(concat(" ", normalize-space(@class), " "), " panel-liczby ")][contains(., "Wskaźnik zastępczy")]')->length,
            'Wyjaśnienie wskaźnika zastępczego nie jest w `.panel-liczby`.');
    }

    public function test_tabela_dni_ma_podpis_naglowki_i_dwadziescia_osiem_wierszy(): void
    {
        // Z danymi i bez nich: układ tabeli nie zależy od tego, czy ktoś pisał.
        $autor = User::factory()->create();
        Post::factory()->create(['author_id' => $autor->id, 'published_at' => now()->subDays(2)]);

        foreach ([true, false] as $zDanymi) {
            if (! $zDanymi) {
                Post::query()->delete();
            }
            $xpath = $this->xpath($this->actingAs($this->admin())->get(route('admin.metryki'))->assertOk()->getContent());

            $tabele = $xpath->query('//main//table');
            $this->assertSame(1, $tabele->length);
            $tabela = $tabele->item(0);
            $this->assertInstanceOf(DOMElement::class, $tabela);
            $this->assertStringContainsString('tabela-dni', $tabela->getAttribute('class'), 'Tabela dni wróciła do gołego `<table>` bez klasy panelu.');

            $this->assertSame(1, $xpath->query('./caption', $tabela)->length, 'Tabela nie ma podpisu dla czytnika ekranu.');
            $this->assertSame(2, $xpath->query('./thead/tr/th[@scope="col"]', $tabela)->length);
            $this->assertSame(28, $xpath->query('./tbody/tr', $tabela)->length);
            $this->assertSame(28, $xpath->query('./tbody/tr/th[@scope="row"]', $tabela)->length,
                'Dzień ma być nagłówkiem wiersza, nie zwykłą komórką.');
            $this->assertSame(28, $xpath->query('./tbody/tr/td', $tabela)->length);
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dokument);
    }
}
