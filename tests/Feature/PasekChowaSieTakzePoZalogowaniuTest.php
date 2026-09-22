<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
