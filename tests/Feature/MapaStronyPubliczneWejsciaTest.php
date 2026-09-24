<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Publiczne huby i strony stałe w mapie strony (#1032).
 *
 * Mapa ogłaszała ręcznie cztery adresy — stronę główną, Odkrywaj, Pomoc
 * i Zasady — a pomijała równorzędne publiczne wejścia: Poradźcie, Tagi,
 * O Kuking, Regulamin, Prywatność i „Napisz do nas". Test porównuje
 * DOKŁADNY zbiór adresów spoza treści z listą oczekiwaną, więc usunięcie
 * któregokolwiek hubu (albo dopisanie strony z `noindex`) go oblewa.
 *
 * Każdy oczekiwany adres jest też otwierany jako gość: musi dać 200
 * i nie mieć `noindex` — mapa nie może ogłaszać drzwi, które sama zamyka.
 */
class MapaStronyPubliczneWejsciaTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function adresyZMapy(): array
    {
        Cache::flush();

        $xml = simplexml_load_string((string) $this->get(route('sitemap'))->assertOk()->getContent());
        $this->assertNotFalse($xml, 'Mapa strony nie jest poprawnym XML-em.');

        $adresy = [];
        foreach ($xml->url as $url) {
            $adresy[] = (string) $url->loc;
        }
        sort($adresy);

        return $adresy;
    }

    /** @return list<string> */
    private function oczekiwane(bool $pytania): array
    {
        $nazwy = ['landing', 'discover', 'tags.index', 'about', 'help', 'rules', 'kontakt', 'terms', 'privacy'];
        if ($pytania) {
            $nazwy[] = 'questions.index';
        }
        $adresy = array_map(fn (string $nazwa) => route($nazwa), $nazwy);
        sort($adresy);

        return $adresy;
    }

    public function test_mapa_ogłasza_dokładnie_publiczne_huby_przy_włączonych_pytaniach(): void
    {
        config(['kuking.questions.enabled' => true]);

        $adresy = $this->adresyZMapy();

        // Pusta baza: w mapie są wyłącznie huby i strony stałe.
        $this->assertSame($this->oczekiwane(true), $adresy);
        $this->assertContains(route('questions.index'), $adresy, 'Brak hubu Poradźcie w mapie.');
        $this->assertContains(route('tags.index'), $adresy, 'Brak indeksu tagów w mapie.');
        $this->assertContains(route('about'), $adresy, 'Brak strony O Kuking w mapie.');
    }

    public function test_poradźcie_znika_z_mapy_przy_wyłączonej_fladze_pytań(): void
    {
        config(['kuking.questions.enabled' => false]);

        $adresy = $this->adresyZMapy();

        $this->assertSame($this->oczekiwane(false), $adresy);
        $this->assertNotContains(route('questions.index'), $adresy);
        // Kontrola dodatnia: ten sam hub przy wyłączonej fladze naprawdę daje 404.
        $this->get(route('questions.index'))->assertNotFound();
    }

    public function test_każdy_ogłaszany_hub_jest_publiczny_i_indeksowalny(): void
    {
        config(['kuking.questions.enabled' => true]);

        foreach ($this->adresyZMapy() as $adres) {
            $this->assertStringNotContainsString('?', $adres, "Mapa ogłasza wariant z parametrami: {$adres}");

            $odpowiedz = $this->get($adres)->assertOk();
            $this->assertFalse(
                $odpowiedz->headers->has('X-Robots-Tag'),
                "Mapa ogłasza adres z nagłówkiem noindex: {$adres}",
            );
            $this->assertStringNotContainsString(
                '<meta name="robots" content="noindex',
                (string) $odpowiedz->getContent(),
                "Mapa ogłasza adres z meta noindex: {$adres}",
            );
        }
    }
}
