<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ekran „Kolejka zadań” w kompozycji panelu moderacji (#581).
 *
 * Ekran powstał po porcie panelu do marki (#587) i został w układzie sprzed
 * niego. Test pilnuje trzech rzeczy, które da się zepsuć w ciszy — odstępy
 * i rozmiar pisma sprawdza osobno miernik przeglądarkowy panelu
 * (`scripts/panel-marki.mjs`, rodzina `kolejka`), tu jest to, co widać w HTML:
 *
 *  1. ekran leży w ramie panelu, a lista nieudanych zadań jest JEDNĄ grupą
 *     (`.panel-grupa`): nagłówek, zdanie wstępne i karty w jednej sekcji
 *     opisanej tym nagłówkiem — nie luźny `<h2>` między dwiema kartami;
 *  2. dane zadania (klasa, wyjątek, kolejka, daty) są w `.dane-zadania`, nie
 *     w drobnym `.meta`, a liczby pod kartą stanu w `.panel-liczby`;
 *  3. pusta tabela też jest grupą — ten sam nagłówek, bez pustych kart.
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić), wykonane przy zmianie:
 * zdjęcie `panel-grupa` z sekcji oblewa test 1 i 3; powrót `dl` do klasy
 * `meta` oblewa test 2; wyjęcie `<h2>` z sekcji (przed nią) oblewa test 1.
 */
class KolejkaZadanKompozycjaPaneluTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_zadan_jest_jedna_grupa_w_ramie_panelu(): void
    {
        $this->wstawNieudane('App\\Notifications\\PotwierdzenieAdresu', 2);
        $this->wstawNieudane('App\\Jobs\\ProcessUploadedImage', 1);

        $xpath = $this->xpath($this->actingAs($this->admin())->get(route('admin.kolejka'))->assertOk()->getContent());

        $this->assertSame(1, $xpath->query('//*[@data-marka-panel]//*[contains(concat(" ", normalize-space(@class), " "), " marka-panel-tresc ")]')->length,
            'Ekran nie leży w ramie panelu moderacji.');

        $grupy = $xpath->query('//main//section[contains(concat(" ", normalize-space(@class), " "), " panel-grupa ")]');
        $this->assertSame(1, $grupy->length, 'Lista nieudanych zadań ma być jedną grupą.');
        $grupa = $grupy->item(0);
        $this->assertInstanceOf(DOMElement::class, $grupa);

        // Grupa jest opisana swoim nagłówkiem i to on stoi w środku — nie przed nią.
        $naglowek = $xpath->query('.//h2[@id="kolejka-co-lezy"]', $grupa);
        $this->assertSame(1, $naglowek->length, 'Nagłówek „Co leży w tabeli” wypadł z grupy.');
        $this->assertSame('kolejka-co-lezy', $grupa->getAttribute('aria-labelledby'));
        $this->assertSame(1, $xpath->query('./h2/following-sibling::p[1][contains(., "Wierszy razem")]', $grupa)->length,
            'Zdanie wstępne nie stoi tuż pod nagłówkiem grupy.');

        // Dwie grupy zadań = dwie karty treści w grupie; nie ma tu panelu formularza.
        $this->assertSame(2, $xpath->query('.//li[contains(concat(" ", normalize-space(@class), " "), " card ")]', $grupa)->length);
        $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " panel-formularza ")]')->length,
            'Ekran nie ma formularza, więc nie może mieć panelu formularza.');
    }

    public function test_dane_zadania_sa_pismem_podstawowym_nie_meta(): void
    {
        $this->wstawNieudane('App\\Notifications\\PotwierdzenieAdresu', 1);

        $xpath = $this->xpath($this->actingAs($this->admin())->get(route('admin.kolejka'))->assertOk()->getContent());

        $dl = $xpath->query('//main//li//dl[contains(concat(" ", normalize-space(@class), " "), " dane-zadania ")]');
        $this->assertSame(1, $dl->length, 'Dane zadania nie są w `.dane-zadania`.');
        $this->assertSame(5, $xpath->query('.//dt', $dl->item(0))->length);
        $this->assertSame(5, $xpath->query('.//dd', $dl->item(0))->length);
        $this->assertStringNotContainsString('meta', (string) $dl->item(0)?->getAttribute('class'),
            'Dane, które administrator czyta, wróciły do drobnego `.meta` (16 px).');

        // Liczby pod kartą stanu — też nie `.meta`.
        $this->assertSame(1, $xpath->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " panel-liczby ")][contains(., "nieudanych w tabeli razem")]')->length);
    }

    public function test_pusta_tabela_jest_ta_sama_grupa_bez_kart_zadan(): void
    {
        $xpath = $this->xpath($this->actingAs($this->admin())->get(route('admin.kolejka'))->assertOk()->getContent());

        $grupa = $xpath->query('//main//section[contains(concat(" ", normalize-space(@class), " "), " panel-grupa ")][@aria-labelledby="kolejka-co-lezy"]');
        $this->assertSame(1, $grupa->length, 'Pusta tabela nie jest grupą z nagłówkiem.');
        $this->assertSame(1, $xpath->query('.//p[contains(., "Tabela jest pusta")]', $grupa->item(0))->length);
        $this->assertSame(0, $xpath->query('.//dl', $grupa->item(0))->length, 'Pusta tabela nie ma czego opisywać parami podpis — wartość.');
    }

    private function wstawNieudane(string $klasa, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['displayName' => $klasa], JSON_UNESCAPED_SLASHES),
                'exception' => 'RuntimeException: komunikat lokalny testu 581.',
                'failed_at' => now()->subHours(3 + $i),
            ]);
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dokument);
    }
}
