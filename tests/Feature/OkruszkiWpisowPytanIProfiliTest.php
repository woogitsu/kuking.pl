<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CzytaJsonLd;
use Tests\TestCase;

/**
 * Ścieżka nadrzędna publicznego wpisu, pytania i profilu (#1033).
 *
 * `docs/seo/SEO_TECHNICAL.md` §2.3 wymaga `BreadcrumbList` na każdej
 * publicznej stronie treści, a emitował go tylko przepis. Tu sprawdzamy
 * zdekodowany JSON-LD (kolejność, pozycje, nazwy, adresy) i to, że widoczne
 * okruszki prowadzą tą samą drogą co dane strukturalne.
 *
 * Kontrola ujemna: `test_kazda_strona_tresci_ma_dokladnie_jeden_breadcrumblist`
 * oblewa, gdy którykolwiek z czterech widoków przestanie emitować
 * `BreadcrumbList` (sprawdzone usunięciem `<x-json-ld>` z widoku wpisu).
 */
class OkruszkiWpisowPytanIProfiliTest extends TestCase
{
    use CzytaJsonLd;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kuking.questions.enabled' => true]);
    }

    public function test_profil_ma_sciezke_kuking_i_nazwe(): void
    {
        $wpis = Post::factory()->create();
        $nazwa = $wpis->author->profile->username;
        $html = $this->get(route('profile.show', $nazwa))->assertOk()->getContent();

        $this->assertSame([
            ['position' => 1, 'name' => 'Kuking', 'item' => route('landing')],
            ['position' => 2, 'name' => '@'.$nazwa],
        ], $this->sciezka($html));
        $this->assertSame([['Kuking', route('landing')]], $this->widoczneOkruszki($html));
    }

    public function test_wpis_prowadzi_przez_profil_autora_i_ma_uczciwa_nazwe(): void
    {
        $wpis = Post::factory()->create(['body' => "Pierwszy raz   pierogi z kaszą\ni twarogiem, jak u babci w Radomiu po niedzieli"]);
        $nazwa = $wpis->author->profile->username;
        $html = $this->get(route('posts.show', $wpis))->assertOk()->getContent();

        $sciezka = $this->sciezka($html);
        $this->assertSame([
            ['position' => 1, 'name' => 'Kuking', 'item' => route('landing')],
            ['position' => 2, 'name' => '@'.$nazwa, 'item' => route('profile.show', $nazwa)],
            ['position' => 3, 'name' => 'Pierwszy raz pierogi z kaszą i twarogiem, jak…'],
        ], $sciezka);
        $this->assertStringNotContainsString((string) $wpis->getKey(), $sciezka[2]['name']);
        $this->assertSame([
            ['Kuking', route('landing')],
            ['@'.$nazwa, route('profile.show', $nazwa)],
        ], $this->widoczneOkruszki($html));

        // Adresy z okruszków otwiera gość.
        foreach ($this->widoczneOkruszki($html) as [, $adres]) {
            $this->get($adres)->assertOk();
        }
    }

    public function test_wpis_samym_zdjeciem_nazywa_sie_data_a_nie_wymyslonym_daniem(): void
    {
        $wpis = Post::factory()->create(['body' => null, 'published_at' => '2026-09-03 12:00:00']);
        $html = $this->get(route('posts.show', $wpis))->assertOk()->getContent();

        $this->assertSame('Wpis z 3 września 2026', $this->sciezka($html)[2]['name']);
    }

    public function test_pytanie_prowadzi_przez_poradzcie(): void
    {
        $pytanie = Post::factory()->question()->create(['title' => 'Czym zagęścić żurek?']);
        $html = $this->get(route('questions.show', $pytanie))->assertOk()->getContent();

        $this->assertSame([
            ['position' => 1, 'name' => 'Kuking', 'item' => route('landing')],
            ['position' => 2, 'name' => 'Poradźcie', 'item' => route('questions.index')],
            ['position' => 3, 'name' => 'Czym zagęścić żurek?'],
        ], $this->sciezka($html));
        $this->assertSame([
            ['Kuking', route('landing')],
            ['Poradźcie', route('questions.index')],
        ], $this->widoczneOkruszki($html));
        $this->get(route('questions.index'))->assertOk();
    }

    public function test_kazda_strona_tresci_ma_dokladnie_jeden_breadcrumblist(): void
    {
        $wpis = Post::factory()->create();
        $pytanie = Post::factory()->question()->create(['title' => 'Ile soli do kiszenia?']);
        $przepis = Recipe::factory()->create();

        foreach ([
            'profil' => route('profile.show', $wpis->author->profile->username),
            'wpis' => route('posts.show', $wpis),
            'pytanie' => route('questions.show', $pytanie),
            'przepis' => route('recipes.show', $przepis),
        ] as $strona => $adres) {
            $html = $this->get($adres)->assertOk()->getContent();

            $this->assertCount(1, $this->blokiTypu($html, 'BreadcrumbList'), "Strona „{$strona}” musi mieć jeden `BreadcrumbList`.");
        }
    }

    public function test_tresc_niepubliczna_nie_emituje_sciezki(): void
    {
        $autor = User::factory()->create()->fresh();
        $wpisDlaZnajomych = Post::factory()->followersOnly()->create(['author_id' => $autor->getKey(), 'body' => 'Tajny bigos cioci']);
        $prywatny = Post::factory()->private()->create(['author_id' => $autor->getKey(), 'body' => 'Prywatne gołąbki']);
        $szkic = Post::factory()->draft()->create(['author_id' => $autor->getKey(), 'body' => 'Szkic sernika']);
        $pytaniePrywatne = Post::factory()->question()->private()->create(['author_id' => $autor->getKey(), 'title' => 'Sekretne pytanie']);

        // Autor widzi swoje treści, ale znacznik dla wyszukiwarki nie powstaje.
        $this->actingAs($autor);
        foreach ([
            route('posts.show', $wpisDlaZnajomych),
            route('posts.show', $prywatny),
            route('posts.show', $szkic),
            route('questions.show', $pytaniePrywatne),
        ] as $adres) {
            $html = $this->get($adres)->assertOk()->getContent();
            $this->assertSame([], $this->blokiTypu($html, 'BreadcrumbList'), "Niepubliczna {$adres} emituje `BreadcrumbList`.");
        }

        // Gość nie dostaje ani strony, ani tytułu.
        auth()->logout();
        foreach ([$wpisDlaZnajomych, $prywatny, $szkic] as $wpis) {
            $odpowiedz = $this->get(route('posts.show', $wpis));
            $this->assertContains($odpowiedz->getStatusCode(), [403, 404]);
            $this->assertStringNotContainsString((string) $wpis->body, (string) $odpowiedz->getContent());
        }
        $odpowiedz = $this->get(route('questions.show', $pytaniePrywatne));
        $this->assertContains($odpowiedz->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('Sekretne pytanie', (string) $odpowiedz->getContent());
    }

    /**
     * Pozycje `BreadcrumbList` bez `@type` elementów — do porównania całą listą.
     *
     * @return list<array<string, mixed>>
     */
    private function sciezka(string $html): array
    {
        $bloki = $this->blokiTypu($html, 'BreadcrumbList');
        $this->assertCount(1, $bloki);
        $this->assertSame('https://schema.org', $bloki[0]['@context']);

        return array_map(function (array $element): array {
            $this->assertSame('ListItem', $element['@type']);
            unset($element['@type']);

            return $element;
        }, $bloki[0]['itemListElement']);
    }

    /** @return list<array{0: string, 1: string}> */
    private function widoczneOkruszki(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $odnosniki = (new DOMXPath($dom))->query('//nav[@aria-label="Gdzie jesteś"]/ol[contains(@class,"okruchy")]/li/a');
        $wynik = [];
        foreach ($odnosniki as $a) {
            $wynik[] = [trim($a->textContent), $a->getAttribute('href')];
        }

        return $wynik;
    }
}
