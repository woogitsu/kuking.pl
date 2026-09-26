<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pasek górny chowa się przy przewijaniu w dół w OBU stanach zalogowania.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Chowanie paska powstało 15 września 2026 (Alfa 0.37) na prośbę o pasek
 * „z logo, logowaniem i rejestracją" — czyli o widok gościa. Atrybut
 * `data-pasek-przewijany`, którego szuka `resources/js/pasek-przewijany.js`,
 * stał więc pod `@guest`. Po zalogowaniu pasek zostawał przypięty na stałe
 * i właściciel zgłosił to jako rozjazd: ten sam serwis zachowywał się
 * inaczej po zalogowaniu, bez powodu widocznego dla człowieka.
 *
 * DLACZEGO TEST NA ATRYBUT, A NIE NA RUCH PASKA
 * Bo ruchu nie da się zmierzyć w PHPUnicie — to zachowanie przeglądarki,
 * i mierzy je `scripts/pasek-przewijany.mjs` w jobie przeglądarkowym CI.
 * Ten test pilnuje rzeczy, którą tamten pomiar przegapi, jeśli ktoś
 * przywróci warunek: **czy atrybut w ogóle dochodzi do obu widoków**.
 * Pomiar bez atrybutu nie oblewa — on po prostu nie ma czego mierzyć
 * i kończy się zielono na samych ekranach gościa.
 *
 * Te dwa sprawdzenia odpowiadają więc na różne pytania i żadne nie
 * zastępuje drugiego.
 */
class PasekChowaSieTakzePoZalogowaniuTest extends TestCase
{
    use RefreshDatabase;

    /** Nazwa atrybutu, na którym opiera się cały mechanizm. */
    private const ATRYBUT = 'data-pasek-przewijany';

    public function test_gosc_dostaje_pasek_chowany_przy_przewijaniu(): void
    {
        $odpowiedz = $this->get(route('landing'));

        $odpowiedz->assertOk();
        $this->assertStringContainsString(self::ATRYBUT, (string) $odpowiedz->getContent(),
            'Widok gościa stracił atrybut chowania paska — to była pierwotna funkcja z Alfy 0.37.');
    }

    public function test_zalogowana_osoba_tez_dostaje_pasek_chowany(): void
    {
        $odpowiedz = $this->actingAs($this->user('basia'))->get(route('home'));

        $odpowiedz->assertOk();
        $this->assertStringContainsString(self::ATRYBUT, (string) $odpowiedz->getContent(),
            'Po zalogowaniu pasek znowu jest przypięty na stałe — atrybut `'.self::ATRYBUT.'` nie doszedł do widoku.');
    }

    /**
     * Kontrola, że mierzymy WŁAŚCIWY element. Sam napis w HTML-u mógłby
     * przecież stać gdziekolwiek; atrybut ma siedzieć na tym samym węźle,
     * co klasa `topbar` — bo to jego geometrię czyta skrypt.
     */
    public function test_atrybut_siedzi_na_gornym_pasku_a_nie_gdziekolwiek(): void
    {
        foreach ([[null, 'landing'], [$this->user('basia'), 'home']] as [$osoba, $trasa]) {
            $zadanie = $osoba === null ? $this : $this->actingAs($osoba);
            $html = (string) $zadanie->get(route($trasa))->getContent();

            $this->assertMatchesRegularExpression(
                '/<header[^>]*class="[^"]*\btopbar\b[^"]*"[^>]*'.preg_quote(self::ATRYBUT, '/').'/',
                $html,
                "Na trasie `{$trasa}` atrybut nie stoi na elemencie `header.topbar`.",
            );
        }
    }

    /**
     * DŁUG WERYFIKACYJNY #713, POZYCJA D6 — DWA WŁASNE UKŁADY.
     *
     * Rejestr zapisał, że po #711 nikt nie sprawdził trybu gotowania ani
     * panelu moderacji, bo „oba mają własne układy". Ten test domyka tę
     * część, którą da się domknąć w PHPUnicie: mechanizm DOCHODZI do obu
     * ekranów i siedzi na `header.topbar`. Panel ma w layoucie kilka gałęzi
     * `@if($wTrybiePanelu)`, więc dopisanie tam warunku nad atrybutem
     * wyłączyłoby chowanie paska tylko moderatorom — i żaden inny test by
     * tego nie zauważył.
     *
     * Czego ten test NIE dowodzi: ruchu paska i jego współgrania z paskiem
     * kroku (`cook-topbar`) ani z nawigacją panelu. To mierzy przeglądarka
     * (`scripts/pasek-przewijany.mjs`), a ten pomiar dziś otwiera wyłącznie
     * adres startowy serwisu (gość i zalogowana osoba) — rozszerzenie go
     * o te dwa ekrany zostaje w #713.
     *
     * Kontrola ujemna wykonana przy dopisaniu: `@unless($wTrybiePanelu)`
     * wokół atrybutu w `layout.blade.php` — test oblewa na panelu,
     * przywrócone z kontrolą MD5.
     */
    public function test_tryb_gotowania_i_panel_moderacji_tez_dostaja_pasek_chowany(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autorka')->getKey()]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Wymieszaj.']);

        $ekrany = [
            'tryb gotowania' => [$this->user('kucharka'), route('cooking.show', $przepis->slug)],
            'panel moderacji' => [$this->moderator(), route('admin.reports')],
        ];

        foreach ($ekrany as $nazwa => [$osoba, $adres]) {
            $html = (string) $this->actingAs($osoba)->get($adres)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<header[^>]*class="[^"]*\btopbar\b[^"]*"[^>]*'.preg_quote(self::ATRYBUT, '/').'/',
                $html,
                "Na ekranie „{$nazwa}” górny pasek nie dostał atrybutu `".self::ATRYBUT.'`.',
            );
        }
    }
}
